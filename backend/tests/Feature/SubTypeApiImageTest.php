<?php

namespace Tests\Feature;

use App\Models\SubType;
use Tests\TestCase;

/**
 * Issue 4 — /api/all_sub_type must expose a resolvable ABSOLUTE image URL.
 *
 * Read-only: it hits the real endpoint and inspects existing rows. It never
 * writes to or migrates the database, so it is safe to run against any
 * environment (including the production DB over SSH). When no sub-type has an
 * image yet, the test is skipped rather than failing.
 */
class SubTypeApiImageTest extends TestCase
{
    public function test_sub_type_api_returns_absolute_image_urls(): void
    {
        // Make the CheckApi middleware pass deterministically for this request.
        config(['services.public_api_key' => 'test-api-code']);

        $response = $this->withHeaders(['Api-Code' => 'test-api-code'])
            ->getJson('/api/all_sub_type');

        $response->assertOk();

        $items = $response->json();
        $this->assertIsArray($items);

        $withImages = array_values(array_filter(
            $items,
            fn ($it) => ! empty($it['image']),
        ));

        if (empty($withImages)) {
            $this->markTestSkipped('No sub-type has an image yet — upload one to exercise the URL.');
        }

        foreach ($withImages as $it) {
            $this->assertArrayHasKey('image_url', $it, 'API must append image_url');
            $this->assertNotNull($it['image_url']);
            // Absolute (crawler/next-image resolvable), and pointing at the
            // correct Sub_type folder unless it is an already-absolute source.
            $this->assertMatchesRegularExpression('#^https?://#', $it['image_url']);
            if (! str_starts_with($it['image'], 'http')) {
                $this->assertStringContainsString('/Uploads_Images/Sub_type/', $it['image_url']);
            }
        }
    }
}
