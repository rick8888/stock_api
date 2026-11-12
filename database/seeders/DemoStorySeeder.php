<?php

namespace Database\Seeders;

use App\Models\PurchaseOrder;
use App\Services\AlertEngine;
use App\Services\Replenishment\DemandForecastService;
use App\Services\Replenishment\ReplenishmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Finalise l’histoire démo : alertes actionnables + suggestions réappro Casa/Rabat.
 */
class DemoStorySeeder extends Seeder
{
    public function run(): void
    {
        $this->stampPurchaseOrderCompanies();
        $this->ensureSupplierScoreHistory();

        $warehouses = ['wh-casa', 'wh-rabat'];

        app(DemandForecastService::class)->recompute($warehouses);
        foreach ($warehouses as $warehouseId) {
            app(ReplenishmentService::class)->compute($warehouseId);
        }

        $result = app(AlertEngine::class)->run();
        $this->command?->info(
            "Demo story — réappro Casa/Rabat · alertes créées {$result['created']} · ignorées {$result['skipped']}"
        );
    }

    /** Backfill company_id manquant sur les OA importés du mock. */
    private function stampPurchaseOrderCompanies(): void
    {
        foreach (PurchaseOrder::query()->get() as $order) {
            if ($order->company_id) {
                continue;
            }
            $payload = is_array($order->payload) ? $order->payload : [];
            $companyId = (string) ($payload['companyId'] ?? 'co-atlas');
            $order->company_id = $companyId;
            $payload['companyId'] = $companyId;
            $order->payload = $payload;
            $order->save();
        }
    }

    /**
     * Historique OA visible pour le score sourcing (reçus / retards),
     * sans toucher aux OA déjà narrés (ex. OA-1042 retard Atlas).
     */
    private function ensureSupplierScoreHistory(): void
    {
        if (DB::table('purchase_orders')->where('id', 'po-score-abc')->exists()) {
            return;
        }

        $doc = [
            'id' => 'po-score-abc',
            'code' => 'OA-2026-01035',
            'supplierId' => 'sup-abc',
            'warehouseId' => 'wh-casa',
            'status' => 'received',
            'expectedDate' => '2026-08-22',
            'createdAt' => '2026-08-15T10:00:00.000Z',
            'amount' => 4200,
            'items' => [['productId' => 'prd-riz', 'quantity' => 60]],
            'companyId' => 'co-atlas',
        ];
        (new PurchaseOrder)->fillDocument($doc)->save();
    }
}
