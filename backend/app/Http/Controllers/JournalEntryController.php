<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'symbol' => 'nullable|string|max:20',
            'backtest_run_id' => 'nullable|integer',
            'custom_strategy_id' => 'nullable|integer',
        ]);

        $userId = $request->user('sanctum')->id;

        if (! $this->ownedLinkIsValid($validated['backtest_run_id'] ?? null, BacktestRun::class, $userId)) {
            return response()->json(['error' => 'The selected backtest run does not exist or does not belong to you.'], 422);
        }

        if (! $this->ownedLinkIsValid($validated['custom_strategy_id'] ?? null, CustomStrategy::class, $userId)) {
            return response()->json(['error' => 'The selected strategy does not exist or does not belong to you.'], 422);
        }

        $entry = JournalEntry::create([
            'user_id' => $userId,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'symbol' => $validated['symbol'] ?? null,
            'backtest_run_id' => $validated['backtest_run_id'] ?? null,
            'custom_strategy_id' => $validated['custom_strategy_id'] ?? null,
        ]);

        return response()->json($entry, 201);
    }

    /**
     * A bare 'exists:table,id' Laravel validation rule would accept ANY
     * user's row, not just the caller's -- this checks ownership
     * explicitly. Reused by update().
     */
    private function ownedLinkIsValid(?int $id, string $modelClass, int $userId): bool
    {
        if ($id === null) {
            return true;
        }

        return $modelClass::where('id', $id)->where('user_id', $userId)->exists();
    }
}
