<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgePackTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_casts_payload_as_array(): void
    {
        $pack = KnowledgePack::create([
            'pack_id' => 'dkp:charts:obs:2026-08-01:0001',
            'payload_type' => 'observation',
            'strategy_class' => 'ma_crossover',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'account_count' => 54,
            'payload' => ['strategy_class' => 'ma_crossover', 'account_count' => 54],
            'signature' => 'deadbeef',
            'signing_key_version' => 'v1',
            'created_at' => now(),
        ]);

        $fresh = KnowledgePack::find($pack->id);
        $this->assertIsArray($fresh->payload);
        $this->assertSame(54, $fresh->payload['account_count']);
        $this->assertSame('dkp:charts:obs:2026-08-01:0001', $fresh->pack_id);
    }
}
