<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use App\Models\User;
use Illuminate\Console\Command;

class PurgeUser extends Command
{
    protected $signature = 'user:purge {email : Email of the account to delete} {--force : Skip the confirmation prompt}';

    protected $description = 'Permanently delete a user account and everything it owns (tokens, backtest runs, strategies, journal entries). Built for cleaning up QA/smoke-test fixtures; refuses platform operators.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        if ($user->is_platform_operator) {
            $this->error("{$email} is a platform operator — refusing. Demote the account first if this is really intended.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently delete {$user->name} <{$email}> and all their data?")) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        // Owned rows are removed explicitly rather than trusting DB-level
        // cascades: backtest_runs.user_id is nullOnDelete (a plain user
        // delete would strand them as anonymous rows, not remove them),
        // and Sanctum tokens are morph-owned with no FK to users at all.
        $counts = [
            'backtest runs' => BacktestRun::where('user_id', $user->id)->delete(),
            'strategies' => $user->customStrategies()->delete(),
            'journal entries' => $user->journalEntries()->delete(),
            'tokens' => $user->tokens()->delete(),
        ];

        $user->delete();

        foreach ($counts as $label => $count) {
            $this->line("  deleted {$count} {$label}");
        }
        $this->info("Purged {$email}.");

        return self::SUCCESS;
    }
}
