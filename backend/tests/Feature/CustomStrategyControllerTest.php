<?php

namespace Tests\Feature;

use App\Models\CustomStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomStrategyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validRules(): array
    {
        return [
            'entry' => [
                'combinator' => 'all',
                'conditions' => [
                    ['left' => ['indicator' => 'ema', 'length' => 5], 'comparator' => 'crosses_above', 'right' => ['indicator' => 'ema', 'length' => 20]],
                ],
            ],
            'exit' => [
                'combinator' => 'all',
                'conditions' => [
                    ['left' => ['indicator' => 'ema', 'length' => 5], 'comparator' => 'crosses_below', 'right' => ['indicator' => 'ema', 'length' => 20]],
                ],
            ],
        ];
    }

    public function test_store_persists_a_valid_strategy_for_the_authenticated_user(): void
    {
        Http::fake(['*/validate-rule' => Http::response(['valid' => true], 200)]);
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/strategies', [
            'name' => 'EMA Crossover',
            'rules' => $this->validRules(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'EMA Crossover');
        $this->assertDatabaseHas('custom_strategies', ['name' => 'EMA Crossover', 'user_id' => $user->id]);
    }

    public function test_store_returns_422_when_analytics_marks_the_rule_invalid(): void
    {
        Http::fake(['*/validate-rule' => Http::response(['valid' => false, 'error' => 'Unknown comparator: bogus'], 200)]);
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/strategies', [
            'name' => 'Bad Strategy',
            'rules' => $this->validRules(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('custom_strategies', ['name' => 'Bad Strategy']);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/strategies', ['name' => 'X', 'rules' => $this->validRules()]);

        $response->assertStatus(401);
    }

    public function test_index_returns_only_the_authenticated_users_strategies(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        CustomStrategy::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);
        CustomStrategy::factory()->create(['user_id' => $otherUser->id, 'name' => 'Not Mine']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/strategies');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Not Mine'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/strategies');

        $response->assertStatus(401);
    }

    public function test_show_returns_an_owned_strategy(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/strategies/{$strategy->id}");

        $response->assertOk();
        $response->assertJsonPath('name', 'Mine');
    }

    public function test_show_returns_404_for_another_users_strategy(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/strategies/{$strategy->id}");

        $response->assertStatus(404);
    }

    public function test_destroy_removes_an_owned_strategy(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/strategies/{$strategy->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('custom_strategies', ['id' => $strategy->id]);
    }

    public function test_destroy_returns_404_for_another_users_strategy_and_does_not_delete_it(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/strategies/{$strategy->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('custom_strategies', ['id' => $strategy->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $strategy = CustomStrategy::factory()->create();

        $response = $this->deleteJson("/api/strategies/{$strategy->id}");

        $response->assertStatus(401);
    }
}
