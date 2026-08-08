<?php

namespace Tests\Feature;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class GenerateIncidentCommandTest extends TestCase
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

    public function test_command_generates_the_incident_pack_on_first_run(): void
    {
        $this->artisan('dkp:generate-incident')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
        $pack = KnowledgePack::first();
        $this->assertSame('incident_report', $pack->payload_type);
        $this->assertStringContainsString('storage/framework', $pack->envelope['payloads'][0]['body']['root_cause']['statement']);
    }

    public function test_command_reports_already_generated_on_second_run(): void
    {
        $this->artisan('dkp:generate-incident')->assertSuccessful();
        $this->artisan('dkp:generate-incident')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
    }
}
