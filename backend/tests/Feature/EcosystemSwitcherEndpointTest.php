<?php

namespace Tests\Feature;

use Tests\TestCase;

class EcosystemSwitcherEndpointTest extends TestCase
{
    public function test_the_endpoint_serves_the_registry_from_config_with_no_login(): void
    {
        $response = $this->getJson('/api/ecosystem/switcher');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'max-age=300, public');
        $response->assertJsonPath('current', 'charts');
        $response->assertJsonPath('infodot_url', config('ecosystem.platforms.infodot.url'));

        $keys = collect($response->json('platforms'))->pluck('key')->all();
        $this->assertSame(array_keys(config('ecosystem.platforms')), $keys, 'the list is the registry, in the registry order');

        $mines = collect($response->json('platforms'))->firstWhere('key', 'mines');
        $this->assertSame('Dot.Mines', $mines['name']);
        $this->assertSame('https://mines.infodot.co.za', $mines['url']);
    }

    public function test_a_platform_added_to_the_registry_appears_without_any_other_edit(): void
    {
        config(['ecosystem.platforms.newcomer' => ['name' => 'Dot.Newcomer', 'url' => 'https://new.infodot.co.za', 'accent' => '#abcdef']]);

        $this->getJson('/api/ecosystem/switcher')->assertJsonFragment(['key' => 'newcomer', 'name' => 'Dot.Newcomer']);
    }

    public function test_it_never_claims_to_know_the_company_or_the_person(): void
    {
        $response = $this->getJson('/api/ecosystem/switcher');

        $response->assertJsonPath('state', 'degraded');
        $response->assertJsonPath('notice', 'Could not check your platforms from here');
        $this->assertStringNotContainsString('entitled', $response->getContent());
        $this->assertStringNotContainsString('company', strtolower((string) $response->json('infodot_url')));
        $this->assertArrayNotHasKey('company', $response->json());
        $this->assertArrayNotHasKey('secret', $response->json());
    }

    public function test_malformed_registry_rows_are_skipped_not_fatal(): void
    {
        config(['ecosystem.platforms.broken' => ['name' => 'No url']]);

        $keys = collect($this->getJson('/api/ecosystem/switcher')->assertOk()->json('platforms'))->pluck('key');

        $this->assertNotContains('broken', $keys);
    }

    public function test_the_built_frontend_pages_carry_no_second_copy_of_the_registry(): void
    {
        $js = file_get_contents(base_path('../frontend/src/ecosystem.js'));

        $this->assertStringNotContainsString('const PLATFORMS', $js);
        $this->assertStringNotContainsString('infodot.co.za', $js);
    }
}
