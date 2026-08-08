<?php

namespace App\Http\Controllers;

use App\Models\KnowledgePack;
use App\Services\ObservationPackGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgePackController extends Controller
{
    public function __construct(
        private readonly ObservationPackGenerator $generator,
    ) {
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy_class' => 'required|string|in:' . implode(',', ObservationPackGenerator::knownStrategyClasses()),
            'period' => 'nullable|date_format:Y-m',
        ]);

        $result = $this->generator->generateForPeriod($validated['strategy_class'], $validated['period'] ?? null);

        return response()->json([
            'generated' => $result['generated'],
            'reason' => $result['reason'],
            'account_count' => $result['account_count'],
            'pack' => $result['pack'] ? ['id' => $result['pack']->id, 'pack_id' => $result['pack']->pack_id] : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $packs = KnowledgePack::orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (KnowledgePack $pack) => [
                'id' => $pack->id,
                'pack_id' => $pack->pack_id,
                'title' => $pack->title,
                'payload_type' => $pack->payload_type,
                'strategy_class' => $pack->strategy_class,
                'account_count' => $pack->account_count,
                'confidence' => $pack->envelope['confidence'] ?? null,
                'created_at' => $pack->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $packs->items(), 'meta' => ['current_page' => $packs->currentPage(), 'last_page' => $packs->lastPage()]]);
    }

    public function show(int $id): JsonResponse
    {
        $pack = KnowledgePack::findOrFail($id);

        return response()->json(['data' => $pack->envelope]);
    }
}
