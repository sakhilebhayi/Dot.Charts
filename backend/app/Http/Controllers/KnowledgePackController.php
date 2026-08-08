<?php

namespace App\Http\Controllers;

use App\Models\KnowledgePack;
use App\Services\InboundMnpiGate;
use App\Services\KnowledgePackApprovalService;
use App\Services\ObservationPackGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgePackController extends Controller
{
    public function __construct(
        private readonly ObservationPackGenerator $generator,
        private readonly InboundMnpiGate $gate,
        private readonly KnowledgePackApprovalService $approvalService,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy_class' => 'required|string|in:'.implode(',', ObservationPackGenerator::knownStrategyClasses()),
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
                'status' => $pack->status,
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

    public function ingestCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'summary' => 'required|string',
            'payloads' => 'nullable|array',
        ]);

        $result = $this->gate->screen($validated);

        return response()->json($result);
    }

    public function pending(): JsonResponse
    {
        $packs = KnowledgePack::where('status', 'pending_approval')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (KnowledgePack $pack) => [
                'id' => $pack->id,
                'pack_id' => $pack->pack_id,
                'title' => $pack->title,
                'summary' => $pack->summary,
                'payload_type' => $pack->payload_type,
                'body' => $pack->envelope['payloads'][0]['body'] ?? null,
                'created_at' => $pack->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $packs]);
    }

    public function approve(int $id, Request $request): JsonResponse
    {
        $pack = KnowledgePack::findOrFail($id);
        $pack = $this->approvalService->approve($pack, $request->user());

        return response()->json(['pack_id' => $pack->pack_id, 'status' => $pack->status]);
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string']);
        $pack = KnowledgePack::findOrFail($id);
        $pack = $this->approvalService->reject($pack, $request->user(), $validated['reason']);

        return response()->json(['pack_id' => $pack->pack_id, 'status' => $pack->status, 'rejected_reason' => $pack->rejected_reason]);
    }
}
