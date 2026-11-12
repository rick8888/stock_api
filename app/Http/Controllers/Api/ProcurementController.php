<?php

namespace App\Http\Controllers\Api;

use App\Services\ProcurementBoardService;
use App\Services\ProcurementService;
use App\Services\ProcurementSourcingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcurementController extends ApiController
{
    public function __construct(
        private readonly ProcurementService $procurement,
        private readonly ProcurementBoardService $board,
        private readonly ProcurementSourcingService $sourcing,
    ) {}

    public function index()
    {
        return $this->items($this->procurement->list());
    }

    public function board(Request $request): JsonResponse
    {
        return response()->json($this->board->board(
            (string) $request->query('section', 'requests'),
            $request->query('filter'),
            (int) $request->query('page', 1),
            (int) $request->query('pageSize', 8),
            $request->query('focusId'),
        ));
    }

    public function sourcing(Request $request): JsonResponse
    {
        $ids = $request->query('ids', []);
        if (is_string($ids)) {
            $ids = array_filter(explode(',', $ids));
        }
        if (! is_array($ids)) {
            $ids = [];
        }

        return response()->json($this->sourcing->offers(array_values($ids)));
    }

    public function store(Request $request)
    {
        return $this->one($this->procurement->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->procurement->updateStatus(
            $id,
            $request->string('status')->toString(),
            $request->except('status'),
        ));
    }
}
