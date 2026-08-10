<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Services\AnalyticsServiceClient;
use App\Services\DisclosureFormatter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BacktestController extends Controller
{
    /**
     * Matches the analytics service's own MAX_RANGE_DAYS
     * (analytics/data/cache.py) -- validated here too so a caller gets a
     * fast 422 without waiting on a round trip to the analytics service,
     * but the analytics-side check is the one that actually closes the
     * gap: that service has no auth of its own, so anyone who reaches it
     * directly bypasses this validation entirely.
     */
    private const MAX_RANGE_DAYS = 1825;

    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
        private readonly DisclosureFormatter $disclosureFormatter,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:20',
            'asset_class' => 'required|in:equity,crypto,commodity,forex',
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714,breakout,bollinger_mean_reversion,momentum,pairs_trading,ml_signal,custom',
            'params' => 'nullable|array',
            'start_date' => 'required|date',
            'end_date' => [
                'required',
                'date',
                'after:start_date',
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    // Both dates already individually passed the 'date' rule
                    // by the time a multi-field closure rule runs, but guard
                    // the parse anyway rather than assume rule-execution
                    // order -- a malformed start_date should surface as
                    // *its own* validation error, not an uncaught Carbon
                    // exception here.
                    try {
                        $days = Carbon::parse($request->input('start_date'))->diffInDays(Carbon::parse($value));
                    } catch (\Throwable) {
                        return;
                    }

                    if ($days > self::MAX_RANGE_DAYS) {
                        $fail('The date range cannot exceed '.self::MAX_RANGE_DAYS.' days.');
                    }
                },
            ],
        ]);

        // pairs_trading is the one strategy that needs a second instrument
        // -- stored inside params (already a persisted JSON column) rather
        // than adding a dedicated symbol_b column, since nothing else
        // needs to query on it independently. This is a manual check, not
        // a 'params.symbol_b' => '...' validation rule: adding a
        // dot-notation rule for one params sub-key makes Laravel's
        // validate() strip every *other* params sub-key not covered by an
        // explicit rule -- which would silently break the custom
        // strategy's arbitrary rule-object params.
        if ($validated['strategy'] === 'pairs_trading' && empty($validated['params']['symbol_b'] ?? null)) {
            throw ValidationException::withMessages([
                'params.symbol_b' => 'The params.symbol_b field is required when strategy is pairs_trading.',
            ]);
        }

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
