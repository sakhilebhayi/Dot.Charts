<?php

namespace Tests\Feature;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class GenerateRecommendationCommandTest extends TestCase
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

    public function test_command_generates_the_recommendation_pack_on_first_run(): void
    {
        $this->artisan('dkp:generate-recommendation')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
        $this->assertSame('recommendation', KnowledgePack::first()->payload_type);
    }

    public function test_command_reports_already_generated_on_second_run(): void
    {
        $this->artisan('dkp:generate-recommendation')->assertSuccessful();
        $this->artisan('dkp:generate-recommendation')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
    }
}
