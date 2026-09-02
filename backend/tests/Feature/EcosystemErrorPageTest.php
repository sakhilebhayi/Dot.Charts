<?php

namespace Tests\Feature;

use Tests\TestCase;

class EcosystemErrorPageTest extends TestCase
{
    public function test_html_404_renders_the_ecosystem_discovery_page(): void
    {
        $response = $this->get('/definitely-not-a-real-page');

        $response->assertNotFound();
        $response->assertSee('the rest of the Dot Ecosystem', escape: false);
        // A sibling platform pill from the shared registry is present...
        $response->assertSee('Dot.Mines');
        $response->assertSee('https://mines.infodot.app');
        // ...but Dot.Charts never advertises itself to itself: its own
        // registry URL must not appear as a discovery pill.
        $response->assertDontSee(config('ecosystem.platforms.charts.url'));
    }

    public function test_api_json_404_is_unaffected_by_the_error_views(): void
    {
        $response = $this->getJson('/api/definitely-not-a-real-route');

        $response->assertNotFound();
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_registry_platform_entries_are_well_formed(): void
    {
        $platforms = config('ecosystem.platforms');

        $this->assertNotEmpty($platforms);

        foreach ($platforms as $key => $platform) {
            $this->assertArrayHasKey('name', $platform, "platform {$key} has no name");
            $this->assertArrayHasKey('url', $platform, "platform {$key} has no url");
            $this->assertStringStartsWith('https://', $platform['url'], "platform {$key} url is not https");
        }
    }
}
