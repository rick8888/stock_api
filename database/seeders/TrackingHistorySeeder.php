<?php

namespace Database\Seeders;

use App\Models\Delivery;
use App\Models\Route;
use App\Services\Logistics\TrackingService;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Rejoue une trajectoire GPS réaliste sur les tournées en cours pour que le
 * suivi soit démontrable sans téléphone chauffeur.
 */
class TrackingHistorySeeder extends Seeder
{
    public function run(): void
    {
        // SUPER_ADMIN contourne le cloisonnement entrepôt : le seed doit pouvoir
        // rejouer les trajectoires Casablanca et Rabat dans la même passe.
        $actor = \App\Models\User::query()->find('usr-super')
            ?? \App\Models\User::query()->where('role', 'SUPER_ADMIN')->first()
            ?? \App\Models\User::query()->where('role', 'ADMIN')->first();
        if ($actor) {
            Auth::guard('api')->setUser($actor);
        }

        DB::table('tracking_events')->delete();

        $tracking = app(TrackingService::class);

        foreach (Route::query()->whereIn('status', ['in_progress', 'planned'])->get() as $routeModel) {
            $route = $routeModel->document();
            $path = $route['path'] ?? [];
            if (count($path) < 2) {
                continue;
            }

            // Fraction déjà parcourue selon le progress seedé, sinon 35 %.
            $target = max(0.15, min(0.85, (($route['progress'] ?? 35) / 100)));
            $steps = 8;
            $started = CarbonImmutable::now()->subMinutes(25);

            for ($i = 1; $i <= $steps; $i++) {
                $t = ($target * $i) / $steps;
                $point = Geo::pointAlong($path, $t);
                // Petite dérive pour simuler le GPS réel (pas exactement sur l'axe).
                $jitterLat = (($i % 3) - 1) * 0.00015;
                $jitterLng = (($i % 2) ? 1 : -1) * 0.00012;

                $tracking->ping([
                    'routeId' => $route['id'],
                    'lat' => $point['lat'] + $jitterLat,
                    'lng' => $point['lng'] + $jitterLng,
                    'speedKmh' => 22 + ($i % 5) * 3,
                    'heading' => 180 + $i * 8,
                    'accuracyM' => 8 + ($i % 4),
                    'eventType' => $i === 1 ? 'departed' : 'ping',
                    'source' => 'driver_app',
                    'recordedAt' => $started->addMinutes($i * 3)->toIso8601String(),
                ]);
            }
        }

        // S'assurer que les stops livrés restent à 100 %.
        foreach (Delivery::query()->where('status', 'delivered')->get() as $delivery) {
            $doc = $delivery->document();
            if (($doc['progress'] ?? 0) < 100) {
                $delivery->mergeDocument(['progress' => 100])->save();
            }
        }
    }
}
