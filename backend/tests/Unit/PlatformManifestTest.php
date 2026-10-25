<?php

namespace Tests\Unit;

use App\Services\DkpSigner;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class PlatformManifestTest extends TestCase
{
    public function test_manifest_has_every_field_the_real_schema_requires(): void
    {
        $manifest = json_decode(file_get_contents(base_path('platform.dkp.json')), true);

        $this->assertSame('dot-charts', $manifest['platform']);
        $this->assertSame('Dot.Charts', $manifest['display_name']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $manifest['dkp_version']);
        $this->assertArrayHasKey('publish_topic', $manifest['endpoints']);
        $this->assertArrayHasKey('response_topic', $manifest['endpoints']);
        $this->assertArrayHasKey('pr_repository', $manifest['endpoints']);
        $this->assertNotEmpty($manifest['contacts']);
    }

    public function test_manifest_key_algorithm_is_ed25519(): void
    {
        $manifest = json_decode(file_get_contents(base_path('platform.dkp.json')), true);

        $this->assertSame('ed25519', $manifest['keys'][0]['algorithm']);
        $this->assertSame('dot-charts-dkp-v1', $manifest['keys'][0]['key_id']);
    }

    // Needs the real, gitignored DKP signing key, which exists only on the
    // server and the owner's machine. CI excludes this group
    // (`--exclude-group=owner-machine`); the test itself is unchanged and
    // still runs in full wherever the key exists.
    #[Group('owner-machine')]
    public function test_manifest_public_key_matches_the_configured_signing_key(): void
    {
        $manifest = json_decode(file_get_contents(base_path('platform.dkp.json')), true);
        $manifestPublicKey = base64_decode($manifest['keys'][0]['public_key']);

        $derivedPublicKey = (new DkpSigner())->publicKey();

        $this->assertSame($derivedPublicKey, $manifestPublicKey);
    }
}
