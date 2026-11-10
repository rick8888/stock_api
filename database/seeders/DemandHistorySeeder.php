<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\StockItem;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Historique de sorties de démonstration, destiné à alimenter le moteur de prévision.
 *
 * Le jeu de démo ne contient que quelques mouvements ponctuels, insuffisants pour que
 * replenishment:compute-demand produise quoi que ce soit. Ce seeder fabrique une
 * consommation plausible sur les derniers mois, calibrée pour que la demande moyenne
 * recalculée retombe sur le dailyDemand annoncé de chaque article.
 *
 * Attention : ces mouvements sont de l'historique de lecture seule. Ils ne recalculent
 * pas stock_items, qui conserve les quantités du jeu de démo.
 */
class DemandHistorySeeder extends Seeder
{
    /** Profondeur d'historique générée, en jours. */
    public const DAYS = 120;

    /** Préfixe des pièces générées, qui rend le lot repérable et rejouable. */
    private const REFERENCE_PREFIX = 'BL-SIM-';

    /**
     * Les sorties se concentrent sur les jours ouvrés : samedi réduit, dimanche fermé.
     * Le poids hebdomadaire vaut donc 5 + 0,4 = 5,4 jours sur 7.
     */
    private const SATURDAY_FACTOR = 0.4;

    private const WEEKLY_WEIGHT = 5.4;

    /** En deçà de ce rythme, un article sort par lots espacés plutôt que tous les jours. */
    private const INTERMITTENT_THRESHOLD = 3.0;

    private const INTERMITTENT_FREQUENCY = 0.3;

    public function run(): void
    {
        // Graine fixe : le jeu de démonstration doit être reproductible d'une machine à l'autre.
        mt_srand(20260820);

        DB::table('stock_movements')->where('reference', 'like', self::REFERENCE_PREFIX.'%')->delete();

        $products = Product::query()->get()->keyBy('id')->map(fn (Product $p) => $p->document());
        $today = CarbonImmutable::today();
        $created = 0;

        foreach (StockItem::query()->get() as $line) {
            $item = $line->document();
            $product = $products->get($item['productId'] ?? '');
            if (! $product || ! empty($product['archived'])) {
                continue;
            }

            $base = (float) ($product['dailyDemand'] ?? 0);
            if ($base <= 0) {
                continue;
            }

            $created += $this->generateFor(
                (string) $item['productId'],
                (string) $item['warehouseId'],
                $base,
                $today,
            );
        }

        $this->command?->info("Historique de sorties généré : {$created} mouvements sur ".self::DAYS.' jours.');
    }

    private function generateFor(string $productId, string $warehouseId, float $base, CarbonImmutable $today): int
    {
        $intermittent = $base < self::INTERMITTENT_THRESHOLD;
        // Compense les jours fermés pour que la moyenne par jour calendaire vise $base.
        $weekdayRate = $intermittent
            ? $base / self::INTERMITTENT_FREQUENCY
            : $base * 7 / self::WEEKLY_WEIGHT;

        $rows = [];
        for ($offset = self::DAYS - 1; $offset >= 0; $offset--) {
            $day = $today->subDays($offset);
            $dayOfWeek = (int) $day->dayOfWeek;

            if ($dayOfWeek === CarbonImmutable::SUNDAY) {
                continue;
            }
            if ($intermittent && $this->random() > self::INTERMITTENT_FREQUENCY) {
                continue;
            }

            $factor = $dayOfWeek === CarbonImmutable::SATURDAY ? self::SATURDAY_FACTOR : 1.0;
            // ±40 % d'aléa : c'est cette dispersion que le moteur traduira en stock de sécurité.
            $quantity = (int) round($weekdayRate * $factor * (0.6 + 0.8 * $this->random()));
            if ($quantity <= 0) {
                continue;
            }

            $movedAt = $day->setTime(8 + (int) floor($this->random() * 9), (int) floor($this->random() * 60));
            $rows[] = [
                'productId' => $productId,
                'type' => 'issue',
                'quantity' => $quantity,
                'fromWarehouseId' => $warehouseId,
                'userId' => 'usr-magasinier',
                'note' => 'Sortie simulée pour historique de demande',
                'reference' => self::REFERENCE_PREFIX.$day->format('Ymd'),
                'date' => $movedAt->toIso8601String(),
            ];
        }

        foreach ($rows as $row) {
            (new StockMovement(['id' => 'mov-sim-'.bin2hex(random_bytes(5))]))
                ->fillDocument($row)
                ->save();
        }

        return count($rows);
    }

    /** Tirage uniforme dans [0, 1), dépendant de la graine fixée dans run(). */
    private function random(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
