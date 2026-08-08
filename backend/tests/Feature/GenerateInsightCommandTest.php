<?php

namespace Tests\Feature;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class GenerateInsightCommandTest extends TestCase
{
    use RefreshDatabase;
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

    public function test_command_generates_the_insight_pack_on_first_run(): void
    {
        $this->artisan('dkp:generate-insight')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
        $pack = KnowledgePack::first();
        $this->assertSame('insight', $pack->payload_type);
        $this->assertStringContainsString('is_demo', $pack->envelope['payloads'][0]['body']['statement']);
    }

    public function test_command_reports_already_generated_on_second_run(): void
    {
        $this->artisan('dkp:generate-insight')->assertSuccessful();
        $this->artisan('dkp:generate-insight')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
    }
}
