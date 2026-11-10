<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\SupplierProduct;
use App\Models\SupplierWarehouseTerm;
use Illuminate\Database\Seeder;

/**
 * Conditions d'achat de démonstration : prix, minimum de commande, colisage
 * et délai par fournisseur, plus quelques surcharges par point de livraison.
 *
 * Sans ces données le moteur de réapprovisionnement ne peut ni arrondir au
 * conditionnement ni différencier les délais entre entrepôts.
 */
class SupplierCatalogSeeder extends Seeder
{
    /** Conditionnement et minimum de commande par unité de vente. */
    private const TERMS_BY_UNIT = [
        'sac' => ['moq' => 50, 'multiple' => 25],
        'brique' => ['moq' => 240, 'multiple' => 120],
        'bidon' => ['moq' => 60, 'multiple' => 12],
        'pack' => ['moq' => 100, 'multiple' => 50],
        'paquet' => ['moq' => 120, 'multiple' => 24],
        'boîte' => ['moq' => 48, 'multiple' => 12],
        'flacon' => ['moq' => 72, 'multiple' => 24],
    ];

    /** Écart de prix d'un fournisseur par rapport au tarif de référence. */
    private const PRICE_FACTOR = [
        'sup-atlas' => 1.0,
        'sup-abc' => 1.06,
        'sup-maghreb' => 0.97,
        'sup-global' => 1.03,
        'sup-nord' => 0.91,
    ];

    /** Fournisseurs de repli proposés en plus du fournisseur habituel. */
    private const ALTERNATES = ['sup-global', 'sup-maghreb'];

    /**
     * Délais réels constatés par point de livraison : les sites du nord sont
     * livrés plus vite depuis Tanger, Marrakech paie l'éloignement.
     */
    private const WAREHOUSE_TERMS = [
        ['supplier' => 'sup-nord', 'warehouse' => 'wh-tanger', 'lead' => 3, 'stdDev' => 0.5],
        ['supplier' => 'sup-nord', 'warehouse' => 'wh-casa', 'lead' => 6, 'stdDev' => 1.5],
        ['supplier' => 'sup-nord', 'warehouse' => 'wh-marrakech', 'lead' => 8, 'stdDev' => 2.0],
        ['supplier' => 'sup-atlas', 'warehouse' => 'wh-casa', 'lead' => 4, 'stdDev' => 0.8],
        ['supplier' => 'sup-atlas', 'warehouse' => 'wh-marrakech', 'lead' => 6, 'stdDev' => 1.2],
        ['supplier' => 'sup-abc', 'warehouse' => 'wh-rabat', 'lead' => 2, 'stdDev' => 0.4],
    ];

    public function run(): void
    {
        foreach (Product::all() as $product) {
            $terms = self::TERMS_BY_UNIT[$product->unit] ?? ['moq' => 50, 'multiple' => 10];
            $suppliers = array_values(array_unique(array_filter(
                [$product->supplier_id, ...self::ALTERNATES],
            )));

            foreach ($suppliers as $supplierId) {
                $factor = self::PRICE_FACTOR[$supplierId] ?? 1.0;
                $isPreferred = $supplierId === $product->supplier_id;

                $this->upsert(SupplierProduct::class, [
                    'supplier_id' => $supplierId,
                    'product_id' => $product->id,
                ], [
                    'id' => 'spp-'.$supplierId.'-'.$product->id,
                    'supplier_sku' => strtoupper(substr($supplierId, 4, 3)).'-'.strtoupper(substr($product->id, 4)),
                    'unit_price' => round($product->price * $factor, 2),
                    'currency' => 'MAD',
                    // Un fournisseur de repli impose un lot plus gros et un
                    // conditionnement plus large qu'un référencement habituel.
                    'min_order_qty' => $terms['moq'] * ($isPreferred ? 1 : 2),
                    'order_multiple' => $terms['multiple'] * ($isPreferred ? 1 : 2),
                    'lead_time_days' => null,
                    'is_preferred' => $isPreferred,
                    'active' => true,
                    'company_id' => $product->company_id,
                ]);
            }
        }

        foreach (self::WAREHOUSE_TERMS as $row) {
            $this->upsert(SupplierWarehouseTerm::class, [
                'supplier_id' => $row['supplier'],
                'warehouse_id' => $row['warehouse'],
            ], [
                'id' => 'swt-'.$row['supplier'].'-'.$row['warehouse'],
                'lead_time_days' => $row['lead'],
                'lead_time_std_dev' => $row['stdDev'],
                'min_order_value' => null,
                'active' => true,
            ]);
        }
    }

    /** @param  class-string<\Illuminate\Database\Eloquent\Model>  $model */
    private function upsert(string $model, array $keys, array $attributes): void
    {
        $existing = $model::query();
        foreach ($keys as $column => $value) {
            $existing->where($column, $value);
        }

        ($existing->first() ?? new $model())->fill([...$keys, ...$attributes])->save();
    }
}
