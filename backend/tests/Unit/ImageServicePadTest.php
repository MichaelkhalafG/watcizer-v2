<?php

namespace Tests\Unit;

use App\Services\ImageService;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Tests\TestCase;

/**
 * Issue 1 — product images are letterboxed onto a 1200x1200 white square.
 *
 * Uses padExistingToSquare (the in-place path the reprocess command runs) so
 * the assertion needs a real file on disk but no HTTP/UploadedFile plumbing.
 * Requires the GD extension (already used by ImageService in production).
 */
class ImageServicePadTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }
        $this->tmpDir = sys_get_temp_dir() . '/wz_img_test_' . uniqid();
        @mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** Write a solid-colour WxH image to disk and return its path. */
    private function makeImage(int $w, int $h, string $ext = 'webp'): string
    {
        $manager = new ImageManager(new Driver());
        $img     = $manager->create($w, $h)->fill('cccccc');
        $path    = $this->tmpDir . '/img_' . $w . 'x' . $h . '.' . $ext;
        $img->save($path);
        return $path;
    }

    public function test_non_square_image_is_padded_to_1200_square(): void
    {
        $path = $this->makeImage(1000, 500);     // wide, mixed aspect ratio

        $r = (new ImageService)->padExistingToSquare($path, 1200, 85, false);

        $this->assertFalse($r['skipped']);
        $this->assertSame(1000, $r['old_w']);
        $this->assertSame(500, $r['old_h']);
        $this->assertSame(1200, $r['new_w']);
        $this->assertSame(1200, $r['new_h']);

        // Verify on disk too.
        $out = (new ImageManager(new Driver()))->read($path);
        $this->assertSame(1200, $out->width());
        $this->assertSame(1200, $out->height());
    }

    public function test_small_square_image_is_padded_not_upscaled_content(): void
    {
        // 400x400 is square but NOT 1200x1200 → must be padded to the canvas,
        // proving "square" alone is not treated as already-processed.
        $path = $this->makeImage(400, 400);

        $r = (new ImageService)->padExistingToSquare($path, 1200, 85, false);

        $this->assertFalse($r['skipped'], '400x400 must NOT be skipped');
        $this->assertSame(1200, $r['new_w']);
        $this->assertSame(1200, $r['new_h']);
    }

    public function test_exactly_1200_square_is_skipped_untouched(): void
    {
        $path   = $this->makeImage(1200, 1200);
        $before = filemtime($path);

        $r = (new ImageService)->padExistingToSquare($path, 1200, 85, false);

        $this->assertTrue($r['skipped']);
        $this->assertSame(1200, $r['old_w']);
        $this->assertSame(1200, $r['old_h']);
        // Untouched on disk (not re-encoded).
        clearstatcache();
        $this->assertSame($before, filemtime($path));
    }

    public function test_dry_run_does_not_write(): void
    {
        $path   = $this->makeImage(800, 600);
        $before = filemtime($path);

        $r = (new ImageService)->padExistingToSquare($path, 1200, 85, true);

        $this->assertFalse($r['skipped']);
        $this->assertSame(1200, $r['new_w']);   // reports the would-be size
        clearstatcache();
        $this->assertSame($before, filemtime($path), 'dry-run must not modify the file');
    }
}
