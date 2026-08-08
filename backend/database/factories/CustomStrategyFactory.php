<?php

namespace Database\Factories;

use App\Models\CustomStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomStrategyFactory extends Factory
{
    protected $model = CustomStrategy::class;

    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'name' => 'EMA Crossover',
            'description' => null,
            'rules' => [
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
            ],
        ];
    }
}
