<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Services\AnalyticsServiceClient;
use App\Services\DisclosureFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BacktestController extends Controller
{
    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
        private readonly DisclosureFormatter $disclosureFormatter,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:20',
            'asset_class' => 'required|in:equity,crypto,commodity',
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714',
            'params' => 'nullable|array',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $run = BacktestRun::create([
            // $request->user() resolves via the default guard ('web',
            // session-based) — it never inspects a Bearer token on a route
            // with no auth:sanctum middleware (this route stays open to
            // anonymous callers by design), so it would always be null even
            // for a real authenticated request. Naming the 'sanctum' guard
            // explicitly makes token resolution work regardless.
            'user_id' => $request->user('sanctum')?->id,
            'symbol' => $validated['symbol'],
            'asset_class' => $validated['asset_class'],
            'strategy' => $validated['strategy'],
            'params' => $validated['params'] ?? [],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => 'queued',
        ]);

        try {
            $result = $this->analyticsClient->runBacktest([
                'symbol' => $validated['symbol'],
                'asset_class' => $validated['asset_class'],
                'strategy' => $validated['strategy'],
                'params' => $validated['params'] ?? [],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ]);
        } catch (RuntimeException $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 503);
        }

        $formatted = $this->disclosureFormatter->format($result);

        $run->update(['status' => 'complete', 'results' => $formatted]);

        return response()->json([
            'success' => true,
            'backtest_run_id' => $run->id,
            'result' => $formatted,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = BacktestRun::where('user_id', $request->user('sanctum')->id)
            ->orderByDesc('created_at');

        if ($request->filled('strategy')) {
            $query->where('strategy', $request->string('strategy'));
        }
        if ($request->filled('asset_class')) {
            $query->where('asset_class', $request->string('asset_class'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $runs = $query->paginate(20)->appends($request->query());

        return response()->json($runs);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $run = BacktestRun::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        return response()->json($run);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $run = BacktestRun::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        $run->delete();

        return response()->json(['success' => true]);
    }
}
