<?php

use App\Domain\Media\MediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdvPayload;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * PORTED 2026-09-11 from the wave-4B adversarial review (axis 6 — hostile input on every screen
 * a human can reach). The reviewer drove 24 crafted query strings across 6 list screens, a set of
 * unicode/RTL titles and slugs, six upload attacks (twice: once with framework fakes, once with
 * real bytes on disk), and bulk actions with hostile ids.
 *
 * Two of those probes printed instead of asserting — the unicode round trip and the bulk row
 * count. They assert here: anything a screen accepts must come back out the same, and anything it
 * refuses must leave nothing behind (no row, and no file in the SHARED media tree).
 */

/**
 * The six list screens a hostile query string can reach.
 *
 * @return list<string>
 */
function advLists(): array
{
    return [
        '/manage/storefronts/1/products',
        '/manage/storefronts/2/products',
        '/manage/storefronts/1/placement',
        '/manage/storefronts/2/placement',
        '/manage/storefronts/1/categories',
        '/manage/lookups/colors',
    ];
}

it('never 500s on any of 24 hostile paging, sorting, search and filter parameters', function () {
    $admin = Staff::admin();

    $hostile = [
        'page=-1',
        'page=0',
        'page=99999',
        'page=abc',
        'page[]=1',
        'sort=id;DROP TABLE catalog_products',
        'sort=catalog_products.password',
        "sort=title' OR '1'='1",
        'sort=(select 1)',
        'direction=UNION',
        'sort=title&direction=; drop',
        'q='.rawurlencode("%' OR 1=1 -- "),
        'q='.rawurlencode('ساعة'),
        'q='.rawurlencode('__'),
        'q='.rawurlencode(str_repeat('a', 5000)),
        'brand_id=-1',
        'brand_id=99999999',
        'brand_id[]=1',
        'category=999999',
        'category=-5',
        'flag=nonsense',
        'flag[]=x',
        'visible=maybe',
        'per_page=100000',
    ];

    $bad = [];
    foreach (advLists() as $url) {
        foreach ($hostile as $query) {
            $status = actingAs($admin)->get($url.'?'.$query)->getStatusCode();
            if ($status >= 500) {
                $bad[] = "{$url}?{$query} -> {$status}";
            }
        }
    }

    // 144 requests. The list is the point: a whitelist that silently falls through to raw SQL, a
    // paginator that divides by a zero per-page, or a filter that casts an array to a string all
    // show up here as a 5xx.
    expect($bad)->toBe([], 'a hostile list parameter produced a server error');
});

it('keeps an unwhitelisted sort column out of the SQL entirely', function () {
    $admin = Staff::admin();

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    actingAs($admin)->get('/manage/storefronts/1/products?sort=catalog_products.secret&direction=desc')->assertOk();
    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    $sql = '';
    foreach ($log as $entry) {
        $sql .= $entry['query'].' | ';
    }

    // Not "it did not error" — the string must never reach the database. `TableQuery` answers an
    // unknown sort with its default, and this is what proves the whitelist is a whitelist.
    expect($sql)->not->toContain('secret')
        ->and($sql)->not->toBe('', 'the query log was empty, so this assertion proved nothing');
});

it('stores unicode, RTL and emoji titles byte-for-byte, and never stores a dangerous slug', function () {
    CatalogFixture::assumeSwitched();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');
    $admin = Staff::admin();

    $cases = [
        'rtl-override' => "\u{202E}gnp.exe",
        'zero-width' => "sa\u{200B}at",
        'combining' => "za\u{0301}lim",
        'emoji' => '⌚ ساعة 🙂',
        'tab' => "tab\there",
        'html' => '<script>alert(1)</script>',
        'quote' => "O'Brien \"watch\"",
    ];

    foreach ($cases as $name => $value) {
        actingAs($admin)->post('/manage/storefronts/1/products', AdvPayload::product($bags['id'], [
            'title' => ['ar' => $value, 'en' => $value],
            'slug' => $value,
        ], 'adv6'));

        $id = AdvPayload::newestProductId();
        $storedTitle = DB::table('catalog_product_translations')
            ->where('product_id', $id)->where('locale', 'ar')->value('title');
        $slug = AdvPayload::slug($id);

        /*
         * Titles: stored verbatim or the product was refused outright — never silently mangled.
         * A title is content, and an operator who types an emoji into a title has not made a
         * mistake; escaping happens where it is RENDERED, not where it is stored.
         */
        if (is_string($storedTitle) && $storedTitle !== '') {
            expect($storedTitle)->toBe($value, "[{$name}] title was altered on the way into the database");
        }

        /*
         * Slugs: a slug reaches a URL, so it is the one field that must be narrow. Whatever
         * survives here holds to the slug charset — and in particular carries no bidi override,
         * no zero-width character, no space and no markup.
         */
        expect($slug)->toMatch('/^[a-z0-9-]*$/', "[{$name}] a slug outside the slug charset was stored [{$slug}]")
            ->and($slug)->not->toContain("\u{202E}")
            ->and($slug)->not->toContain("\u{200B}");
    }
});

it('refuses every upload attack and stores a real image, with framework fakes', function () {
    $admin = Staff::admin();
    $post = fn (UploadedFile $file, string $type = 'product') => actingAs($admin)
        ->postJson('/manage/media', ['type' => $type, 'file' => $file]);

    $refused = [
        'php-as-jpg' => $post(UploadedFile::fake()->createWithContent('shell.jpg', "<?php echo 'pwned'; ?>")),
        'svg' => $post(UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')),
        '40MB' => $post(UploadedFile::fake()->create('big.jpg', 40 * 1024, 'image/jpeg')),
        'double-ext' => $post(UploadedFile::fake()->createWithContent('x.php.jpg', 'GIF89a')),
        // A declared type that is not a declared media type, and one shaped like a path traversal:
        // the folder comes from `config/media.php`, never from the request.
        'bad-type' => $post(UploadedFile::fake()->image('good.jpg', 40, 40), 'etc-passwd'),
        'type-traversal' => $post(UploadedFile::fake()->image('good.jpg', 40, 40), '../../../../etc'),
    ];

    foreach ($refused as $name => $response) {
        expect($response->getStatusCode())->toBe(422, "[{$name}] was not refused with a 422");
    }

    $real = $post(UploadedFile::fake()->image('good.jpg', 40, 40));
    $real->assertCreated();

    /** @var array<string, mixed> $stored */
    $stored = $real->json();
    expect(T::str($stored['file'] ?? ''))->toEndWith('.webp', 'the master is always WebP (§5.4)');

    // The tree is SHARED with the running legacy app: a test that leaves a file behind is litter
    // in production's own directory.
    $directory = MediaStore::directory(T::str($stored['folder'] ?? ''));
    $base = preg_replace('/\.webp$/', '', T::str($stored['file'] ?? ''));
    foreach (glob($directory.'/'.$base.'*') ?: [] as $file) {
        @unlink($file);
    }
});

it('refuses uploads of REAL hostile bytes on disk and writes nothing into the media tree', function () {
    /*
     * The same attacks again, but as actual files with actual content, because a framework fake
     * carries a declared mime and the validator may be trusting it. Here the guesser sniffs the
     * bytes, exactly as it does for a browser upload.
     *
     * The assertion the reviewer cared about is the FILE COUNT: a refusal that still wrote a
     * master into the shared tree would be a shell on disk next to production's images.
     */
    $admin = Staff::admin();
    $root = T::str(config('media.root'));
    $root = str_starts_with($root, '/') || preg_match('/^[A-Za-z]:/', $root) === 1 ? $root : base_path($root);

    $countFiles = function () use ($root): int {
        if (! is_dir($root)) {
            return -1;
        }
        $n = 0;
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $ignored) {
            $n++;
        }

        return $n;
    };

    $dir = sys_get_temp_dir().'/adv-up-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    $files = [
        'php-as-jpg' => ['shell.jpg', "<?php echo 'pwned'; ?>"],
        'double-ext' => ['x.php.jpg', "<?php system(\$_GET['c']); ?>"],
        'html-as-png' => ['p.png', '<html><body>hi</body></html>'],
        'zip-as-jpg' => ['a.jpg', "PK\x03\x04".str_repeat("\x00", 40)],
    ];

    $before = $countFiles();
    $statuses = [];
    foreach ($files as $name => [$filename, $content]) {
        $path = $dir.'/'.$filename;
        file_put_contents($path, $content);
        clearstatcache(true, $path);
        // Fourth argument null, fifth true: real path, no declared mime — the guesser must decide.
        $statuses[$name] = actingAs($admin)
            ->postJson('/manage/media', ['type' => 'product', 'file' => new UploadedFile($path, $filename, null, null, true)])
            ->getStatusCode();
    }
    $after = $countFiles();

    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);

    expect($before)->toBeGreaterThan(-1, 'the media root does not exist, so the file-count guard proved nothing')
        ->and($after)->toBe($before, 'a refused upload still wrote a file into the shared media tree');

    foreach ($statuses as $name => $status) {
        expect($status)->not->toBe(201, "[{$name}] was STORED")
            ->and($status)->toBeLessThan(500, "[{$name}] came back as a server error instead of a refusal");
    }
});

it('refuses a bad bulk action and an oversized id list, and archives no ghost ids', function () {
    CatalogFixture::assumeSwitched();
    $admin = Staff::admin();
    $before = T::int(DB::table('catalog_products')->whereNull('deleted_at')->count());

    // Ids that do not exist: accepted shape, nothing to act on, nothing changed.
    actingAs($admin)->postJson('/manage/storefronts/1/products/bulk', [
        'action' => 'archive', 'ids' => [-1, 0, 999999999],
    ]);

    // An action outside the whitelist, and a list past the cap: both refused at the field.
    actingAs($admin)->postJson('/manage/storefronts/1/products/bulk', [
        'action' => 'drop_table', 'ids' => [1],
    ])->assertStatus(422);

    actingAs($admin)->postJson('/manage/storefronts/1/products/bulk', [
        'action' => 'archive', 'ids' => range(1, 600),
    ])->assertStatus(422);

    expect(T::int(DB::table('catalog_products')->whereNull('deleted_at')->count()))->toBe($before);
});
