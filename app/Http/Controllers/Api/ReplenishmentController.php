<?php

namespace App\Http\Controllers\Api;

use App\Services\Replenishment\DemandForecastService;
use App\Services\Replenishment\ReplenishmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReplenishmentController extends ApiController
{
    public function __construct(
        private readonly ReplenishmentService $replenishment,
        private readonly DemandForecastService $forecast,
    ) {}

    /** Dernières propositions calculées, filtrables par entrepôt. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->items(
            $this->replenishment->list($request->query('warehouseId'))
        );
    }

    /**
     * Rafraîchit la demande observée puis recalcule les propositions de l'entrepôt.
     * Le recalcul de la demande est optionnel car il balaye tout l'historique.
     */
    public function compute(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'warehouseId' => ['required', 'string'],
            'refreshDemand' => ['sometimes', 'boolean'],
            'windowDays' => ['sometimes', 'integer', 'min:7', 'max:730'],
        ]);

        if ($request->boolean('refreshDemand')) {
            $this->forecast->recompute(
                [$validated['warehouseId']],
                (int) ($validated['windowDays'] ?? DemandForecastService::DEFAULT_WINDOW_DAYS),
            );
        }

        return $this->items($this->replenishment->compute($validated['warehouseId']));
    }

    /** Simulation : évalue sans persister, y compris les articles sans besoin. */
    public function simulate(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'warehouseId' => ['required', 'string'],
            'serviceLevel' => ['sometimes', 'numeric', 'min:0.8', 'max:0.999'],
            'leadTimeDays' => ['sometimes', 'numeric', 'min:1', 'max:90'],
        ]);

        $serviceLevel = isset($validated['serviceLevel']) ? (float) $validated['serviceLevel'] : null;
        $leadTime = isset($validated['leadTimeDays']) ? (float) $validated['leadTimeDays'] : null;

        return $this->items(
            $this->replenishment->evaluateWarehouse($validated['warehouseId'], $serviceLevel, $leadTime)
                ->filter(fn (array $row) => ($row['suggestedQuantity'] ?? 0) > 0)
                ->values()
        );
    }
}
