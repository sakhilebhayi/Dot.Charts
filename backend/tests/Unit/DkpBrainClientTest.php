<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\DkpBrainClient;
use App\Services\KnowledgePackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

/**
 * There is no real Dot.Brain endpoint anywhere in the ecosystem (see
 * DkpBrainClient's docblock and wiki.md §5), so every test here uses
 * Http::fake() -- these prove the client's own request-building and
 * response-handling logic is correct against the documented contract, not
 * that a real integration works end-to-end (nothing could prove that today).
 */
class DkpBrainClientTest extends TestCase
{
    use RefreshDatabase;
    use UsesDkpTestKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDkpTestKey();
        // DkpBrainClient retries a non-2xx response up to 5 times with real
        // exponential backoff between attempts (brain.dkp.md §8) -- without
        // faking Sleep, every test exercising a non-202 response actually
        // waits through that backoff for real (~30s+ per test).
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownDkpTestKey();
        parent::tearDown();
    }

    private function approvedPack(): KnowledgePack
    {
        $envelope = [
            'dkp_version' => '1.0.0',
            'pack_id' => 'dkp:dot-charts:'.Str::uuid(),
            'title' => 'Test pack',
            'summary' => 'Test summary',
            'payloads' => [['payload_type' => 'insight', 'body' => ['statement' => 'test']]],
            'confidence' => 0.9,
            'signatures' => [],
        ];

        $pack = KnowledgePack::create([
            'pack_id' => $envelope['pack_id'],
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Test pack',
            'summary' => 'Test summary',
            'period' => 'test-slug-'.Str::random(8),
            'envelope' => $envelope,
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        return (new KnowledgePackApprovalService)->approve($pack, User::factory()->create());
    }

    public function test_is_configured_reflects_the_endpoint_config(): void
    {
        $this->assertFalse((new DkpBrainClient(null))->isConfigured());
        $this->assertTrue((new DkpBrainClient('https://example.test/v1/dkp'))->isConfigured());
    }

    public function test_publish_refuses_to_attempt_a_call_when_unconfigured(): void
    {
        Http::fake();
        $pack = $this->approvedPack();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Dot.Brain DKP endpoint is configured');

        try {
            (new DkpBrainClient(null))->publish($pack);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_publish_refuses_a_pack_that_is_not_approved(): void
    {
        Http::fake();
        $pack = KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Unapproved',
            'summary' => 'Test',
            'period' => 'unapproved-slug',
            'envelope' => ['payloads' => [], 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not "approved"');

        try {
            (new DkpBrainClient('https://example.test/v1/dkp'))->publish($pack);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_publish_sends_the_packs_own_signed_envelope_as_the_request_body(): void
    {
        Http::fake(['*' => Http::response(['receipt_id' => 'r-1'], 202)]);
        $pack = $this->approvedPack();

        (new DkpBrainClient('https://example.test/v1/dkp'))->publish($pack);

        Http::assertSent(function ($request) use ($pack) {
            return $request->url() === 'https://example.test/v1/dkp'
                && $request['pack_id'] === $pack->envelope['pack_id']
                && $request['signatures'] === $pack->envelope['signatures'];
        });
    }

    public function test_publish_returns_ingested_on_a_202_response(): void
    {
        Http::fake(['*' => Http::response(['receipt_id' => 'receipt-abc-123'], 202)]);
        $pack = $this->approvedPack();

        $result = (new DkpBrainClient('https://example.test/v1/dkp'))->publish($pack);

        $this->assertSame('ingested', $result['status']);
        $this->assertSame('receipt-abc-123', $result['receipt_id']);
        $this->assertNull($result['error_code']);
    }

    public function test_publish_returns_rate_limited_on_a_429_response(): void
    {
        Http::fake(['*' => Http::response(['retry_after' => 30], 429)]);
        $pack = $this->approvedPack();

        $result = (new DkpBrainClient('https://example.test/v1/dkp'))->publish($pack);

        $this->assertSame('rate_limited', $result['status']);
        $this->assertSame('DKP_RATE_LIMITED', $result['error_code']);
    }

    public function test_publish_treats_dkp_duplicate_as_already_ingested_not_a_rejection(): void
    {
        Http::fake(['*' => Http::response(['error_code' => 'DKP_DUPLICATE', 'receipt_id' => 'receipt-dup'], 409)]);
        $pack = $this->approvedPack();

        $result = (new DkpBrainClient('https://example.test/v1/dkp'))->publish($pack);

        $this->assertSame('already_ingested', $result['status']);
        $this->assertSame('DKP_DUPLICATE', $result['error_code']);
    }

    public function test_publish_returns_rejected_with_the_real_error_code_on_a_genuine_4xx(): void
    {
        Http::fake(['*' => Http::response(['error_code' => 'DKP_SCHEMA_INVALID', 'message' => 'missing field x'], 422)]);
        $pack = $this->approvedPack();

        $result = (new DkpBrainClient('https://example.test/v1/dkp'))->publish($pack);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('DKP_SCHEMA_INVALID', $result['error_code']);
        $this->assertSame('missing field x', $result['message']);
    }
}
