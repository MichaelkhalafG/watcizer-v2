<?php

namespace Tests\Unit;

use App\Models\SubType;
use Tests\TestCase;

/**
 * Issue 4 — sub-type images render on the storefront.
 *
 * The API (AllSubType) returns SubType models directly, so the appended
 * `image_url` attribute IS the value the Next.js frontend consumes. These
 * assertions run with no database: they exercise the accessor that builds the
 * absolute URL, which is the exact value serialized into the API response.
 */
class SubTypeImageUrlTest extends TestCase
{
    private function make(?string $image): SubType
    {
        $s = new SubType();
        // forceFill so we don't depend on $fillable/casts; set the raw column.
        $s->forceFill(['image' => $image]);

        return $s;
    }

    public function test_bare_filename_becomes_absolute_sub_type_url(): void
    {
        config(['services.asset_base' => 'https://dash.watchizereg.com']);

        $url = $this->make('169_abc.webp')->image_url;

        $this->assertSame(
            'https://dash.watchizereg.com/Uploads_Images/Sub_type/169_abc.webp',
            $url,
        );
        // Resolvable + absolute: a crawler / next/image can fetch it as-is.
        $this->assertMatchesRegularExpression('#^https?://#', $url);
    }

    public function test_trailing_slash_on_asset_base_is_normalized(): void
    {
        config(['services.asset_base' => 'https://dash.watchizereg.com/']);

        $this->assertSame(
            'https://dash.watchizereg.com/Uploads_Images/Sub_type/x.webp',
            $this->make('x.webp')->image_url,
        );
    }

    public function test_full_url_passes_through_untouched(): void
    {
        config(['services.asset_base' => 'https://dash.watchizereg.com']);

        $abs = 'https://cdn.example.com/logos/sports.png';
        $this->assertSame($abs, $this->make($abs)->image_url);
    }

    public function test_value_with_folder_segment_is_used_as_is(): void
    {
        config(['services.asset_base' => 'https://dash.watchizereg.com']);

        $this->assertSame(
            'https://dash.watchizereg.com/Uploads_Images/Sub_type/sports.webp',
            $this->make('Sub_type/sports.webp')->image_url,
        );
    }

    public function test_missing_image_yields_null(): void
    {
        config(['services.asset_base' => 'https://dash.watchizereg.com']);

        $this->assertNull($this->make(null)->image_url);
        $this->assertNull($this->make('')->image_url);
    }
}
