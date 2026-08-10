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

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user('sanctum')->id;

        $query = JournalEntry::where('user_id', $userId)->orderByDesc('created_at');

        if ($request->filled('symbol')) {
            $query->where('symbol', $request->query('symbol'));
        }
        if ($request->filled('backtest_run_id')) {
            $query->where('backtest_run_id', $request->query('backtest_run_id'));
        }
        if ($request->filled('custom_strategy_id')) {
            $query->where('custom_strategy_id', $request->query('custom_strategy_id'));
        }

        $entries = $query->paginate(20)->appends($request->query());

        return response()->json($entries);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        return response()->json($entry);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:200',
            'body' => 'sometimes|required|string',
            'symbol' => 'nullable|string|max:20',
            'backtest_run_id' => 'nullable|integer',
            'custom_strategy_id' => 'nullable|integer',
        ]);

        $userId = $request->user('sanctum')->id;

        if (array_key_exists('backtest_run_id', $validated)
            && ! $this->ownedLinkIsValid($validated['backtest_run_id'], BacktestRun::class, $userId)) {
            return response()->json(['error' => 'The selected backtest run does not exist or does not belong to you.'], 422);
        }

        if (array_key_exists('custom_strategy_id', $validated)
            && ! $this->ownedLinkIsValid($validated['custom_strategy_id'], CustomStrategy::class, $userId)) {
            return response()->json(['error' => 'The selected strategy does not exist or does not belong to you.'], 422);
        }

        $entry->update($validated);

        return response()->json($entry->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        $entry->delete();

        return response()->json(['success' => true]);
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
