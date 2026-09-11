<?php

use App\Domain\Media\MediaCapabilities;
use App\Domain\Media\MediaStore;
use Illuminate\Http\UploadedFile;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The media pipeline (study §5.4): the shared tree, the legacy folder names, the legacy filename
 * scheme, a WebP master and the renditions this host can actually write.
 *
 * Every test cleans up the files it wrote — the tree is SHARED with the running legacy app, so a
 * leftover test file is litter in production's own directory.
 */

/** A real PNG of a given size, written to a temp file and wrapped as an upload. */
function fakeUpload(int $width = 1400, int $height = 900, string $name = 'probe.png'): UploadedFile
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    $blue = imagecolorallocate($image, 30, 90, 200);
    $yellow = imagecolorallocate($image, 240, 200, 40);
    imagefill($image, 0, 0, $blue === false ? 0 : $blue);
    // A second block, so a resized copy is provably not a solid colour.
    imagefilledrectangle($image, 0, 0, (int) ($width / 2), (int) ($height / 2), $yellow === false ? 0 : $yellow);
    $path = tempnam(sys_get_temp_dir(), 'media').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

/** @param array<string, mixed> $stored */
function forgetStored(array $stored): void
{
    $directory = MediaStore::directory(T::str($stored['folder'] ?? ''));
    $base = preg_replace('/\.webp$/', '', T::str($stored['file'] ?? ''));
    foreach (glob($directory.'/'.$base.'*') ?: [] as $file) {
        @unlink($file);
    }
}

it('reports what this host can encode', function () {
    $report = MediaCapabilities::report();

    expect($report['gd'])->toBeTrue()
        ->and($report['write_webp'])->toBeTrue('WebP is the master format (§5.4)')
        ->and(MediaCapabilities::renditionFormats())->toContain('webp');
});

it('stores a master into the LEGACY folder with the LEGACY filename scheme', function () {
    $stored = app(MediaStore::class)->store(fakeUpload(), 'product');

    try {
        expect($stored['folder'])->toBe('Product', 'both apps write the same tree during the transition')
            // `<unix>_<Y-m-d>_<uniqid>.webp`, exactly as ImageService::filename() produces.
            ->and($stored['file'])->toMatch('/^\d{10}_\d{4}-\d{2}-\d{2}_[0-9a-f]+\.webp$/')
            ->and($stored['url'])->toContain('/Uploads_Images/Product/')
            // The pad-square preset: a 1400x900 source becomes a 1200x1200 master.
            ->and($stored['width'])->toBe(1200)
            ->and($stored['height'])->toBe(1200)
            ->and(file_exists(MediaStore::directory('Product').'/'.$stored['file']))->toBeTrue();
    } finally {
        forgetStored($stored);
    }
});

it('writes renditions at the declared widths, in every format this host supports', function () {
    $stored = app(MediaStore::class)->store(fakeUpload(1400, 1400), 'product');

    try {
        // 320/480/640/960 are all below the 1200 master; 1280 is not and must be skipped.
        // The keys are INTEGERS here: PHP casts a numeric string array key to an int, whatever the
        // code writes. `json_encode` turns them back into object keys, which is why the TypeScript
        // side types them as `Record<string, …>`.
        expect(array_keys($stored['renditions']))->toContain(320)->toContain(960)
            ->and(array_keys($stored['renditions']))->not->toContain(1280)
            ->and($stored['renditions'][320])->toHaveKey('webp');

        foreach ($stored['renditions'] as $width => $formats) {
            foreach ($formats as $file) {
                expect(file_exists(MediaStore::directory('Product').'/'.$file))->toBeTrue("rendition {$width} is missing on disk");
            }
        }
    } finally {
        forgetStored($stored);
    }
});

it('never upscales, and says why a rendition was skipped', function () {
    // A 300px source against the brand preset (400px master, renditions 160 and 320): the master
    // stays 300px — no invented detail — the 160 rendition is written, and 320 is skipped BECAUSE
    // it would be an upscale. The skip is reported rather than silent.
    $stored = app(MediaStore::class)->store(fakeUpload(300, 300), 'brand');

    try {
        expect($stored['width'])->toBe(300, 'a 300px source must not be blown up to the 400px preset')
            ->and(array_keys($stored['renditions']))->toBe([160])
            ->and(implode(' ', $stored['skipped']))->toContain('320w: source is only 300px wide');
    } finally {
        forgetStored($stored);
    }
});

it('uploads through the endpoint and answers with what a form must remember', function () {
    $response = actingAs(Staff::dataEntry())
        ->postJson('/manage/media', ['type' => 'brand', 'file' => fakeUpload(600, 600)]);

    $response->assertCreated()->assertJsonStructure(['file', 'folder', 'url', 'width', 'height', 'bytes', 'renditions', 'skipped']);

    /** @var array<string, mixed> $stored */
    $stored = $response->json();
    forgetStored($stored);
});

it('refuses an unknown media type and a non-image', function () {
    actingAs(Staff::dataEntry())->postJson('/manage/media', ['type' => 'not-a-type', 'file' => fakeUpload()])
        ->assertStatus(422)->assertJsonValidationErrors('type');

    actingAs(Staff::dataEntry())->postJson('/manage/media', [
        'type' => 'product',
        'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
    ])->assertStatus(422)->assertJsonValidationErrors('file');
});

/*
 * REVIEW 🟡-5 — the endpoint answered 500 for a file it could not decode.
 *
 * `mimes:` runs on the GUESSED type, and the guesser reads the first bytes. A file that starts with
 * PNG magic and continues with rubbish passes validation and dies in `imagecreatefromstring()`,
 * which used to surface as a 500: an operator with a truncated download was told the dashboard was
 * broken. It is the FILE that is broken, and 422 is how this application says so everywhere else.
 */

/**
 * A file that SNIFFS as a PNG and cannot be decoded — a truncated download, in other words.
 *
 * The magic bytes alone are NOT enough: `finfo` calls eight bytes of signature plus rubbish
 * `application/octet-stream`, which the validator would refuse and this test would then be proving
 * the validator instead of the decode path. A complete signature and IHDR chunk (100×100, RGBA)
 * followed by a corrupt body sniffs as `image/png`, passes `mimes:`, and still returns false from
 * `imagecreatefromstring()` — measured, not assumed, and asserted in the test below.
 */
function halfBakedPng(): UploadedFile
{
    $header = "\x89PNG\r\n\x1a\n"
        .pack('N', 13).'IHDR'.pack('NN', 100, 100)."\x08\x06\x00\x00\x00".pack('N', 0);
    $path = tempnam(sys_get_temp_dir(), 'media').'.png';
    file_put_contents($path, $header.str_repeat('garbage', 100));

    return new UploadedFile($path, 'photo.png', 'image/png', null, true);
}

it('answers 422, not 500, for a file whose bytes are not an image', function () {
    // The guesser must really let it through, or the test would be proving the validator instead
    // of the decode path.
    expect(halfBakedPng()->guessExtension())->toBe('png', 'the probe must pass the mimes: rule');

    $response = actingAs(Staff::dataEntry())
        ->postJson('/manage/media', ['type' => 'product', 'file' => halfBakedPng()]);

    $response->assertStatus(422)->assertJsonValidationErrors('file');

    // Arabic, and it names what to do instead — this message reaches a non-technical operator.
    expect(T::str($response->json('message')))->toContain('ليس صورة صالحة');
});

it('still answers 500 when the HOST cannot do the work', function () {
    /*
     * The other half of the split: an unwritable target is not the operator's fault and must keep
     * its 500. `MediaStore::directory()` throws a plain RuntimeException for that, and the catch
     * order in the controller is what decides — so the two cases are asserted together, because a
     * catch written the other way round would turn every host fault into a 422 and hide an outage.
     *
     * (An `is_subclass_of()` check stood here and was removed: PHPStan answers it at analysis
     * time, so it was not a test. The behaviour below is.)
     */
    config()->set('media.root', 'Z:/definitely-not-mounted-'.uniqid());

    actingAs(Staff::dataEntry())
        ->postJson('/manage/media', ['type' => 'product', 'file' => fakeUpload(300, 300)])
        ->assertStatus(500);
});

it('declares one folder per type, and they are the legacy folders', function () {
    $folders = array_map(fn (string $type): string => MediaStore::typeConfig($type)['folder'], MediaStore::types());

    expect($folders)->toBe(['Product', 'Product_image', 'Brand', 'Category_type', 'Banner_home']);
});
