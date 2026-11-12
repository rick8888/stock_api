<?php

namespace App\Services;

use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\DriverRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\ShippingNoteRepository;
use App\Repositories\Eloquent\VehicleRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Geo;
use App\Support\Tenant;

/**
 * Aide au classement d’une tournée (règles ops) — pas d’optimisation TSP/ML.
 *
 * Ordre proposé : urgence (at_risk / créneau) + regroupement ville + plus proche voisin.
 * Affectation : chauffeur / véhicule disponibles sur l’entrepôt, avec motifs.
 */
class RouteAssistService
{
    public function __construct(
        private readonly WarehouseRepository $warehouses,
        private readonly ShippingNoteRepository $notes,
        private readonly CustomerRepository $customers,
        private readonly DriverRepository $drivers,
        private readonly VehicleRepository $vehicles,
        private readonly RouteRepository $routes,
        private readonly UserDirectory $users,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @param  array{
     *   warehouseId?: string,
     *   date?: string,
     *   stops?: list<array<string, mixed>>,
     *   driverId?: string|null,
     *   vehicleId?: string|null
     * }  $input
     * @return array{
     *   warehouseId: string,
     *   stops: list<array<string, mixed>>,
     *   distanceKm: float,
     *   previousDistanceKm: float|null,
     *   drivers: list<array<string, mixed>>,
     *   vehicles: list<array<string, mixed>>,
     *   reasons: list<string>
     * }
     */
    public function suggest(array $input): array
    {
        $warehouseId = (string) ($input['warehouseId'] ?? '');
        if ($warehouseId !== '' && $this->tenant->user()) {
            $this->tenant->assertWarehouseAccess($warehouseId);
        }

        $warehouse = $warehouseId !== '' ? $this->warehouses->find($warehouseId) : null;
        $origin = [
            'lat' => (float) ($warehouse['lat'] ?? 33.5731),
            'lng' => (float) ($warehouse['lng'] ?? -7.5898),
            'label' => (string) ($warehouse['name'] ?? 'Entrepôt'),
        ];

        $rawStops = is_array($input['stops'] ?? null) ? $input['stops'] : [];
        $enriched = [];
        foreach ($rawStops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }
            $enriched[] = $this->enrichStop($stop, $index, $warehouse);
        }

        $previousPath = array_merge([$origin], array_map(
            fn (array $s) => ['lat' => $s['lat'], 'lng' => $s['lng']],
            $enriched,
        ));
        $previousDistanceKm = count($enriched) ? round(Geo::pathLengthKm($previousPath), 1) : null;

        $ordered = $this->orderStops($enriched, $origin);
        $orderedPath = array_merge([$origin], array_map(
            fn (array $s) => ['lat' => $s['lat'], 'lng' => $s['lng']],
            $ordered,
        ));
        $distanceKm = count($ordered) ? round(Geo::pathLengthKm($orderedPath), 1) : 0.0;

        $date = (string) ($input['date'] ?? now()->toDateString());
        $drivers = $this->rankDrivers($warehouseId, $date, (string) ($input['driverId'] ?? ''));
        $vehicles = $this->rankVehicles($warehouseId, $drivers, (string) ($input['vehicleId'] ?? ''));

        $reasons = [];
        if ($ordered) {
            $cities = array_values(array_unique(array_filter(array_map(
                fn (array $s) => (string) ($s['city'] ?? ''),
                $ordered,
            ))));
            if (count($cities) > 1) {
                $reasons[] = 'Arrêts regroupés par ville ('.implode(' → ', $cities).')';
            } elseif ($cities) {
                $reasons[] = 'Tous les arrêts sur '.$cities[0];
            }
            $urgent = count(array_filter($ordered, fn (array $s) => ($s['status'] ?? '') === 'at_risk'));
            if ($urgent) {
                $reasons[] = $urgent.' arrêt(s) à risque placés en priorité';
            }
            if ($previousDistanceKm !== null && $distanceKm < $previousDistanceKm) {
                $reasons[] = 'Distance indicative '.$distanceKm.' km (avant '.$previousDistanceKm.' km)';
            } else {
                $reasons[] = 'Distance indicative '.$distanceKm.' km (ordre proposé)';
            }
        } else {
            $reasons[] = 'Aucun arrêt à classer — suggestion flotte uniquement';
        }

        return [
            'warehouseId' => $warehouseId,
            'stops' => $ordered,
            'distanceKm' => $distanceKm,
            'previousDistanceKm' => $previousDistanceKm,
            'drivers' => $drivers,
            'vehicles' => $vehicles,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $stop
     * @param  array<string, mixed>|null  $warehouse
     * @return array<string, mixed>
     */
    private function enrichStop(array $stop, int $index, ?array $warehouse): array
    {
        $noteId = (string) ($stop['shippingNoteId'] ?? '');
        $note = $noteId !== '' ? $this->notes->find($noteId) : null;
        $city = (string) ($stop['city'] ?? ($note['city'] ?? ''));
        $client = (string) ($stop['client'] ?? ($note['client'] ?? ''));
        $status = (string) ($stop['status'] ?? 'prepared');
        $scheduledAt = (string) ($stop['scheduledAt'] ?? '');
        $point = $this->resolvePoint($stop, $note, $warehouse, $client !== '' ? $client : 'Stop');

        return [
            'key' => (string) ($stop['key'] ?? $noteId ?: 'stop-'.$index),
            'shippingNoteId' => $noteId,
            'client' => $client,
            'city' => $city,
            'address' => (string) ($stop['address'] ?? ($note['address'] ?? '')),
            'scheduledAt' => $scheduledAt,
            'status' => $status,
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'reasons' => [],
            'score' => 0,
            'sequence' => $index + 1,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @param  array{lat: float, lng: float}  $origin
     * @return list<array<string, mixed>>
     */
    private function orderStops(array $stops, array $origin): array
    {
        if (! $stops) {
            return [];
        }

        $remaining = $stops;
        $ordered = [];
        $cursor = $origin;
        $prevCity = null;

        while ($remaining) {
            $bestIndex = 0;
            $bestScore = -INF;
            foreach ($remaining as $i => $candidate) {
                $dist = Geo::haversineKm($cursor, [
                    'lat' => (float) $candidate['lat'],
                    'lng' => (float) $candidate['lng'],
                ]);
                $urgency = ($candidate['status'] ?? '') === 'at_risk' ? 40.0 : 0.0;
                $scheduled = (string) ($candidate['scheduledAt'] ?? '');
                if ($scheduled !== '') {
                    // Créneaux plus tôt = léger bonus (minutes depuis minuit, inversé).
                    $minutes = $this->minutesOfDay($scheduled);
                    $urgency += max(0.0, (18 * 60 - $minutes) / 30.0);
                }
                $sameCity = $prevCity !== null && strcasecmp((string) ($candidate['city'] ?? ''), $prevCity) === 0;
                $cityBonus = $sameCity ? 25.0 : 0.0;
                // Score : urgence + cluster ville − distance (km).
                $score = $urgency + $cityBonus - ($dist * 2.5);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $i;
                }
            }

            $picked = $remaining[$bestIndex];
            array_splice($remaining, $bestIndex, 1);
            $distFromPrev = Geo::haversineKm($cursor, [
                'lat' => (float) $picked['lat'],
                'lng' => (float) $picked['lng'],
            ]);
            $reasons = [];
            if (($picked['status'] ?? '') === 'at_risk') {
                $reasons[] = 'Priorité risque';
            }
            if ($prevCity !== null && strcasecmp((string) ($picked['city'] ?? ''), $prevCity) === 0) {
                $reasons[] = 'Même ville que l’arrêt précédent';
            } elseif (($picked['city'] ?? '') !== '') {
                $reasons[] = 'Secteur '.(string) $picked['city'];
            }
            $reasons[] = 'À '.round($distFromPrev, 1).' km du point précédent';

            $picked['score'] = (int) round(max(0, $bestScore));
            $picked['reasons'] = $reasons;
            $picked['distanceFromPrevKm'] = round($distFromPrev, 1);
            $picked['sequence'] = count($ordered) + 1;
            $ordered[] = $picked;
            $cursor = ['lat' => (float) $picked['lat'], 'lng' => (float) $picked['lng']];
            $prevCity = (string) ($picked['city'] ?? '') ?: $prevCity;
        }

        return $ordered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rankDrivers(string $warehouseId, string $date, string $currentId): array
    {
        $busy = [];
        foreach ($this->scopedRoutes() as $route) {
            if (($route['date'] ?? '') !== $date) {
                continue;
            }
            if (in_array($route['status'] ?? '', ['done', 'cancelled'], true)) {
                continue;
            }
            $busy[(string) ($route['driverId'] ?? '')] = true;
        }

        $ranked = [];
        foreach ($this->scopedDrivers() as $driver) {
            $id = (string) ($driver['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $user = $this->users->find((string) ($driver['userId'] ?? ''));
            $warehouses = is_array($user) ? ($user['warehouseIds'] ?? []) : [];
            $sameWh = $warehouseId === '' || in_array($warehouseId, $warehouses, true) || ! $warehouses;
            $status = (string) ($driver['status'] ?? 'available');
            $reasons = [];
            $score = 50;
            if ($status === 'available') {
                $score += 30;
                $reasons[] = 'Disponible';
            } elseif ($status === 'on_route') {
                $score -= 25;
                $reasons[] = 'Déjà en tournée';
            } else {
                $score -= 40;
                $reasons[] = 'Indisponible';
            }
            if ($sameWh) {
                $score += 20;
                $reasons[] = 'Rattaché à l’entrepôt';
            } else {
                $score -= 15;
                $reasons[] = 'Autre entrepôt';
            }
            if (isset($busy[$id])) {
                $score -= 35;
                $reasons[] = 'Déjà planifié ce jour';
            }
            if (! empty($driver['vehicleId'])) {
                $score += 10;
                $reasons[] = 'Véhicule habituel lié';
            }
            if ($currentId !== '' && $id === $currentId) {
                $score += 5;
                $reasons[] = 'Sélection actuelle';
            }
            $name = is_array($user)
                ? trim(((string) ($user['firstName'] ?? '')).' '.((string) ($user['lastName'] ?? '')))
                : $id;
            $ranked[] = [
                'driverId' => $id,
                'name' => $name !== '' ? $name : $id,
                'status' => $status,
                'vehicleId' => $driver['vehicleId'] ?? null,
                'score' => $score,
                'recommended' => false,
                'reasons' => $reasons,
            ];
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        if ($ranked) {
            $ranked[0]['recommended'] = true;
            $ranked[0]['reasons'][] = 'Meilleure dispo pour cette tournée';
        }

        return array_slice($ranked, 0, 5);
    }

    /**
     * @param  list<array<string, mixed>>  $drivers
     * @return list<array<string, mixed>>
     */
    private function rankVehicles(string $warehouseId, array $drivers, string $currentId): array
    {
        $preferredVehicle = null;
        foreach ($drivers as $driver) {
            if (! empty($driver['recommended']) && ! empty($driver['vehicleId'])) {
                $preferredVehicle = (string) $driver['vehicleId'];
                break;
            }
        }

        $ranked = [];
        foreach ($this->scopedVehicles() as $vehicle) {
            $id = (string) ($vehicle['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $status = (string) ($vehicle['status'] ?? 'available');
            $sameWh = $warehouseId === '' || (string) ($vehicle['warehouseId'] ?? '') === $warehouseId;
            $reasons = [];
            $score = 50;
            if ($status === 'available') {
                $score += 30;
                $reasons[] = 'Disponible';
            } else {
                $score -= 30;
                $reasons[] = 'Statut '.$status;
            }
            if ($sameWh) {
                $score += 20;
                $reasons[] = 'Basé sur l’entrepôt';
            } else {
                $score -= 15;
                $reasons[] = 'Autre entrepôt';
            }
            if ($preferredVehicle && $id === $preferredVehicle) {
                $score += 25;
                $reasons[] = 'Lié au chauffeur recommandé';
            }
            if ($currentId !== '' && $id === $currentId) {
                $score += 5;
                $reasons[] = 'Sélection actuelle';
            }
            $ranked[] = [
                'vehicleId' => $id,
                'name' => (string) ($vehicle['name'] ?? $id),
                'plate' => (string) ($vehicle['plate'] ?? ''),
                'status' => $status,
                'warehouseId' => (string) ($vehicle['warehouseId'] ?? ''),
                'score' => $score,
                'recommended' => false,
                'reasons' => $reasons,
            ];
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        if ($ranked) {
            $ranked[0]['recommended'] = true;
            $ranked[0]['reasons'][] = 'Meilleure affectation flotte';
        }

        return array_slice($ranked, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $note
     * @param  array<string, mixed>|null  $warehouse
     * @return array{lat: float, lng: float}
     */
    private function resolvePoint(array $input, ?array $note, ?array $warehouse, string $label): array
    {
        if (isset($input['lat'], $input['lng'])) {
            return ['lat' => (float) $input['lat'], 'lng' => (float) $input['lng']];
        }
        if (isset($input['location']['lat'], $input['location']['lng'])) {
            return [
                'lat' => (float) $input['location']['lat'],
                'lng' => (float) $input['location']['lng'],
            ];
        }
        if ($note) {
            $customerId = $note['customerId'] ?? null;
            $addressId = $note['addressId'] ?? null;
            if ($customerId) {
                $customer = $this->customers->find((string) $customerId);
                foreach ($customer['addresses'] ?? [] as $address) {
                    if ($addressId && ($address['id'] ?? null) !== $addressId) {
                        continue;
                    }
                    if (isset($address['lat'], $address['lng'])) {
                        return [
                            'lat' => (float) $address['lat'],
                            'lng' => (float) $address['lng'],
                        ];
                    }
                    if ($addressId) {
                        break;
                    }
                }
            }
        }

        return [
            'lat' => (float) ($warehouse['lat'] ?? 33.5731),
            'lng' => (float) ($warehouse['lng'] ?? -7.5898),
        ];
    }

    private function minutesOfDay(string $iso): float
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return 12 * 60;
        }

        return ((int) date('H', $ts)) * 60 + (int) date('i', $ts);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function scopedDrivers()
    {
        if ($this->tenant->bypassTenantFilter() || ! $this->tenant->user()) {
            return $this->drivers->all()->values();
        }

        return $this->drivers->forWarehouses($this->tenant->companyWarehouseIds())->values();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function scopedVehicles()
    {
        if ($this->tenant->bypassTenantFilter() || ! $this->tenant->user()) {
            return $this->vehicles->all()->values();
        }

        return $this->vehicles->forWarehouses($this->tenant->companyWarehouseIds())->values();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function scopedRoutes()
    {
        if ($this->tenant->bypassTenantFilter() || ! $this->tenant->user()) {
            return $this->routes->all()->values();
        }

        return $this->routes->forWarehouses($this->tenant->companyWarehouseIds())->values();
    }
}
