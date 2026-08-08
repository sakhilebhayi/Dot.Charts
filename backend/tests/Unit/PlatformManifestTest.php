<?php

namespace Tests\Unit;

use Tests\TestCase;

class PlatformManifestTest extends TestCase
{
    public function test_manifest_is_valid_json_and_declares_only_implemented_payload_types(): void
    {
        $manifest = json_decode(file_get_contents(base_path('platform.dkp.json')), true);

        $this->assertSame('dot-charts', $manifest['platform']);
        $this->assertSame(['observation'], $manifest['publishes']);
        $this->assertSame([], $manifest['subscribes']);
        $this->assertSame('restricted', $manifest['default_classification']);
        $this->assertSame(50, $manifest['tenancy']['aggregation_floor']);
    }
}
