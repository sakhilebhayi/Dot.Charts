<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgePackTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_casts_envelope_as_array(): void
    {
        $pack = KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:11111111-1111-4111-8111-111111111111',
            'payload_type' => 'metric',
            'strategy_class' => 'ma_crossover',
            'account_count' => 54,
            'pack_version' => '1.0.0',
            'title' => 'Test Pack',
            'summary' => 'A test pack',
            'period' => '2026-08',
            'envelope' => ['pack_id' => 'dkp:dot-charts:11111111-1111-4111-8111-111111111111', 'confidence' => 0.6],
            'created_at' => now(),
        ]);

        $fresh = KnowledgePack::find($pack->id);
        $this->assertIsArray($fresh->envelope);
        $this->assertEqualsWithDelta(0.6, $fresh->envelope['confidence'], 0.001);
        $this->assertSame('2026-08', $fresh->period);
    }
}
