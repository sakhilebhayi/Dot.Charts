<?php

namespace App\Console\Commands;

use App\Services\RecommendationPackGenerator;
use Illuminate\Console\Command;

class GenerateRecommendationPack extends Command
{
    protected $signature = 'dkp:generate-recommendation';

    protected $description = 'Generate the real recommendation pack reporting the loss-honesty structural-invariant design, with impact numbers computed from actual published packs. Idempotent.';

    public function handle(RecommendationPackGenerator $generator): int
    {
        $result = $generator->generate();

        if ($result['generated']) {
            $this->info("Generated recommendation pack {$result['pack']->pack_id}.");
        } else {
            $this->info("Skipped: recommendation already generated ({$result['pack']->pack_id}).");
        }

        return self::SUCCESS;
    }
}
