<?php

namespace App\Http\Controllers;

use App\Models\CustomStrategy;
use App\Services\AnalyticsServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomStrategyController extends Controller
{
    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'rules' => 'required|array',
        ]);

        $validation = $this->analyticsClient->validateRule($validated['rules']);

        if (($validation['valid'] ?? false) !== true) {
            return response()->json([
                'error' => $validation['error'] ?? 'Invalid strategy rules',
            ], 422);
        }

        $strategy = CustomStrategy::create([
            'user_id' => $request->user('sanctum')->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'rules' => $validated['rules'],
        ]);

        return response()->json($strategy, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $strategies = CustomStrategy::where('user_id', $request->user('sanctum')->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->appends($request->query());

        return response()->json($strategies);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $strategy = CustomStrategy::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        return response()->json($strategy);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $strategy = CustomStrategy::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        $strategy->delete();

        return response()->json(['success' => true]);
    }
}
