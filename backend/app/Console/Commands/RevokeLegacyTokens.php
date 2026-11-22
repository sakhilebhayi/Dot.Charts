<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Deletes the personal access tokens that never expire (every token minted before R-54). This is the owner's switch for
 * "the legacy bearer-token API ends now"; nothing runs it automatically. Without --force it only counts.
 */
class RevokeLegacyTokens extends Command
{
    protected $signature = 'tokens:revoke-legacy {--force : Actually delete them (default is a dry run)}';

    protected $description = 'Delete API tokens that have no expiry (pre-R-54 tokens). Dry run unless --force.';

    public function handle(): int
    {
        $query = PersonalAccessToken::query()->whereNull('expires_at');
        $count = $query->count();

        if (! $this->option('force')) {
            $this->info("{$count} token(s) have no expiry. Dry run: nothing deleted. Re-run with --force to delete them.");

            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Deleted {$count} token(s) with no expiry.");

        return self::SUCCESS;
    }
}
