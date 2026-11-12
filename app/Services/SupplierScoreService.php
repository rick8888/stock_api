<?php

namespace App\Services;

use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Support\DemoDate;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * Score fournisseur dynamique à partir de l’historique OA (ponctualité observée).
 *
 * Sans historique : on retombe sur la fiabilité déclarée.
 */
class SupplierScoreService
{
    /** @var Collection<string, array{received: int, late: int, open: int}>|null */
    private ?Collection $historyCache = null;

    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return array{
     *   reliability: int,
     *   declaredReliability: int,
     *   onTimeRate: int|null,
     *   orderSampleSize: int,
     *   lateCount: int,
     *   receivedCount: int,
     *   reasons: list<string>
     * }
     */
    public function score(array $supplier): array
    {
        $declared = max(0, min(100, (int) ($supplier['reliability'] ?? 80)));
        $supplierId = (string) ($supplier['id'] ?? '');
        $stats = $this->history()->get($supplierId, [
            'received' => 0,
            'late' => 0,
            'open' => 0,
        ]);

        $received = (int) $stats['received'];
        $late = (int) $stats['late'];
        $sample = $received + $late;

        if ($sample === 0) {
            return [
                'reliability' => $declared,
                'declaredReliability' => $declared,
                'onTimeRate' => null,
                'orderSampleSize' => 0,
                'lateCount' => 0,
                'receivedCount' => 0,
                'reasons' => ['Fiabilité déclarée '.$declared.'% (pas encore d’historique OA)'],
            ];
        }

        $onTimeRate = (int) round(($received / $sample) * 100);
        // 40 % déclaré + 60 % ponctualité observée.
        $reliability = (int) round($declared * 0.4 + $onTimeRate * 0.6);

        $reasons = [
            'Fiabilité dynamique '.$reliability.'% (déclarée '.$declared.'%)',
            'Ponctualité observée '.$onTimeRate.'% · '.$received.'/'.$sample.' OA à l’heure',
        ];
        if ($late > 0) {
            $reasons[] = $late.' commande(s) en retard dans l’historique';
        }

        return [
            'reliability' => max(0, min(100, $reliability)),
            'declaredReliability' => $declared,
            'onTimeRate' => $onTimeRate,
            'orderSampleSize' => $sample,
            'lateCount' => $late,
            'receivedCount' => $received,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $suppliers
     * @return Collection<string, array<string, mixed>>
     */
    public function scoreMany(Collection $suppliers): Collection
    {
        return $suppliers->mapWithKeys(function (array $supplier) {
            $id = (string) ($supplier['id'] ?? '');

            return [$id => $this->score($supplier)];
        });
    }

    /** @return Collection<string, array{received: int, late: int, open: int}> */
    private function history(): Collection
    {
        if ($this->historyCache !== null) {
            return $this->historyCache;
        }

        $today = now()->toDateString();
        $out = [];
        $companyId = $this->tenant->bypassTenantFilter() ? null : $this->tenant->companyId();

        foreach ($this->orders->all() as $order) {
            // Legacy seed : OA sans companyId restent visibles pour le tenant courant.
            $orderCompany = (string) ($order['companyId'] ?? '');
            if ($companyId !== null && $orderCompany !== '' && $orderCompany !== $companyId) {
                continue;
            }
            $supplierId = (string) ($order['supplierId'] ?? '');
            if ($supplierId === '') {
                continue;
            }
            if (! isset($out[$supplierId])) {
                $out[$supplierId] = ['received' => 0, 'late' => 0, 'open' => 0];
            }

            $status = (string) ($order['status'] ?? '');
            if (in_array($status, ['cancelled', 'canceled', 'draft'], true)) {
                continue;
            }

            if ($status === 'received') {
                $out[$supplierId]['received']++;
                continue;
            }

            if ($status === 'delayed') {
                $out[$supplierId]['late']++;
                continue;
            }

            $expected = $order['expectedDate'] ?? null;
            if ($expected) {
                $day = substr((string) DemoDate::rebase($expected), 0, 10);
                if ($day < $today) {
                    $out[$supplierId]['late']++;
                    continue;
                }
            }

            $out[$supplierId]['open']++;
        }

        return $this->historyCache = collect($out);
    }
}
