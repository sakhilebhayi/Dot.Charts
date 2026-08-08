<?php

namespace Tests\Unit;

use App\Services\DkpSigner;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class DkpSignerTest extends TestCase
{
    use UsesDkpTestKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDkpTestKey();
    }

    protected function tearDown(): void
    {
        $this->tearDownDkpTestKey();
        parent::tearDown();
    }

    public function test_canonicalize_is_deterministic_regardless_of_key_insertion_order(): void
    {
        $signer = new DkpSigner();

        $a = ['b' => 2, 'a' => 1, 'c' => ['y' => 2, 'x' => 1]];
        $b = ['a' => 1, 'c' => ['x' => 1, 'y' => 2], 'b' => 2];

        $this->assertSame($signer->canonicalize($a), $signer->canonicalize($b));
    }

    public function test_sign_then_verify_round_trip(): void
    {
        $signer = new DkpSigner();
        $envelope = ['pack_id' => 'dkp:dot-charts:test', 'title' => 'Test Pack'];

        $envelope['signatures'] = $signer->sign($envelope);

        $this->assertTrue($signer->verify($envelope));
        $this->assertSame('dot-charts-dkp-v1', $envelope['signatures'][0]['key_id']);
        $this->assertSame('ed25519-jcs', $envelope['signatures'][0]['algorithm']);
    }

    public function test_tampering_with_the_envelope_after_signing_fails_verification(): void
    {
        $signer = new DkpSigner();
        $envelope = ['pack_id' => 'dkp:dot-charts:test', 'title' => 'Test Pack'];
        $envelope['signatures'] = $signer->sign($envelope);

        $envelope['title'] = 'Tampered Title';

        $this->assertFalse($signer->verify($envelope));
    }

    public function test_verify_returns_false_when_no_signatures_present(): void
    {
        $signer = new DkpSigner();
        $envelope = ['pack_id' => 'dkp:dot-charts:test'];

        $this->assertFalse($signer->verify($envelope));
    }
}
