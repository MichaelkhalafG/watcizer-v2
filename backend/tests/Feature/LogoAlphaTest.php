<?php

namespace Tests\Feature;

use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Issue 3 — a transparent logo must keep REAL alpha end-to-end (source → WebP).
 *
 * Builds a transparent PNG (opaque disc on a transparent field), runs it through
 * ImageService with the logo (transparent) preset, and asserts the encoded WebP
 * still has transparent pixels where the source did. On the server this exercises
 * the Imagick path; on a GD-only box it exercises the GD alpha handling.
 */
class LogoAlphaTest extends TestCase
{
    private ?string $outPath = null;
    private ?string $srcPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD needed to build the fixture image.');
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->outPath, $this->srcPath] as $p) {
            if ($p && is_file($p)) {
                @unlink($p);
            }
        }
        $dir = public_path('Uploads_Images/ZZTestLogo');
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function test_transparent_logo_keeps_alpha_through_webp(): void
    {
        // ── Build a transparent PNG fixture ──────────────────────────────────
        $w = $h = 200;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127)); // transparent
        imagefilledellipse($img, $w / 2, $h / 2, 120, 120, imagecolorallocate($img, 200, 30, 30)); // opaque disc

        $this->srcPath = tempnam(sys_get_temp_dir(), 'wzlogo') . '.png';
        imagepng($img, $this->srcPath);
        imagedestroy($img);

        // ── Run it through the real logo pipeline ────────────────────────────
        $file = new UploadedFile($this->srcPath, 'logo.png', 'image/png', null, true);
        $name = (new ImageService)->process($file, 'ZZTestLogo', [
            'max_width'   => 400,
            'max_height'  => 400,
            'quality'     => 85,
            'transparent' => true,
        ]);

        $this->outPath = public_path('Uploads_Images/ZZTestLogo/' . $name);
        $this->assertFileExists($this->outPath);

        // ── Decode the WebP output and check alpha survived ──────────────────
        $bytes = file_get_contents($this->outPath);
        $out   = @imagecreatefromstring($bytes);
        if (! ($out instanceof \GdImage)) {
            $this->markTestSkipped('GD cannot decode the WebP output on this box to verify alpha.');
        }
        imagealphablending($out, false);
        imagesavealpha($out, true);

        $cornerAlpha = (imagecolorat($out, 3, 3) >> 24) & 0x7F;         // was transparent
        $centerAlpha = (imagecolorat($out, imagesx($out) / 2, imagesy($out) / 2) >> 24) & 0x7F; // was opaque
        imagedestroy($out);

        $this->assertGreaterThan(64, $cornerAlpha, 'corner must remain transparent (alpha preserved)');
        $this->assertLessThan(16, $centerAlpha, 'centre disc must remain opaque');
    }
}
