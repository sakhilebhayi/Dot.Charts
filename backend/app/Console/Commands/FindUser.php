<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class FindUser extends Command
{
    protected $signature = 'user:find {needle : Substring to match against user emails}';

    protected $description = 'Read-only lookup of accounts whose email contains the given substring. Companion to user:purge for auditing QA/smoke-test fixtures.';

    public function handle(): int
    {
        $needle = $this->argument('needle');
        $matches = User::where('email', 'like', '%'.$needle.'%')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'is_platform_operator', 'created_at']);

        $this->table(
            ['id', 'email', 'name', 'operator', 'created'],
            $matches->map(fn (User $u) => [
                $u->id,
                $u->email,
                $u->name,
                $u->is_platform_operator ? 'YES' : 'no',
                (string) $u->created_at,
            ]),
        );
        $this->info("{$matches->count()} of ".User::count()." accounts match '{$needle}'.");

        return self::SUCCESS;
    }
}
