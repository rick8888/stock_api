<?php

namespace App\Services\Logistics;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\DriverRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\TrackingEventRepository;
use App\Repositories\Eloquent\VehicleRepository;
use App\Support\Geo;
use App\Support\Tenant;
use App\Support\Uid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suivi temps réel par tournée : ingestion GPS chauffeur → projection sur le
 * tracé → progression et ETA recalculées.
 */
class TrackingService
{
    /** Vitesse urbaine par défaut (km/h) quand le ping n'en fournit pas. */
    public const DEFAULT_SPEED_KMH = 30.0;

    /** Distance sous laquelle on considère le véhicule arrivé au stop. */
    public const ARRIVAL_RADIUS_KM = 0.12;

    public function __construct(
        private readonly TrackingEventRepository $events,
        private readonly RouteRepository $routes,
        private readonly DeliveryRepository $deliveries,
        private readonly VehicleRepository $vehicles,
        private readonly DriverRepository $drivers,
        private readonly Tenant $tenant,
    ) {}

    /**
     * Enregistre un ping GPS sur une tournée et met à jour progression / ETA.
     *
     * @param  array{
     *   routeId: string,
     *   lat: float,
     *   lng: float,
     *   accuracyM?: float|null,
     *   speedKmh?: float|null,
     *   heading?: float|null,
     *   eventType?: string,
     *   source?: string,
     *   recordedAt?: string|null
     * }  $input
     */
    public function ping(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $route = $this->routes->findOrFail($input['routeId'], 'Tournée introuvable');
            $this->tenant->assertWarehouseAccess($route['warehouseId'] ?? '');
            $this->assertDriverMayTrack($route);

            $lat = (float) $input['lat'];
            $lng = (float) $input['lng'];
            $recordedAt = ! empty($input['recordedAt'])
                ? CarbonImmutable::parse((string) $input['recordedAt'])
                : CarbonImmutable::now();

            $path = $this->normalizePath($route['path'] ?? []);
            if (count($path) < 2) {
                $path = $this->rebuildPathFromStops($route);
            }

            $projection = Geo::projectOntoPath($path, $lat, $lng);
            $speed = isset($input['speedKmh']) && $input['speedKmh'] !== null
                ? max(0.0, (float) $input['speedKmh'])
                : null;
            $etaSpeed = ($speed !== null && $speed >= 5.0) ? $speed : self::DEFAULT_SPEED_KMH;
            $etaAt = $projection['remainingKm'] > 0
                ? $recordedAt->addSeconds((int) round(($projection['remainingKm'] / $etaSpeed) * 3600))
                : $recordedAt;

            $progress = (int) min(100, max(0, round($projection['progress'])));
            $position = [
                'lat' => $lat,
                'lng' => $lng,
                'label' => 'Position véhicule',
                'at' => $recordedAt->toIso8601String(),
            ];

            $status = $route['status'] ?? 'planned';
            if ($status === 'planned' && $progress > 0) {
                $status = 'in_progress';
            }
            if ($progress >= 100) {
                $status = 'done';
            }

            $route = $this->routes->merge($route['id'], [
                'status' => $status,
                'progress' => $progress,
                'currentPosition' => $position,
                'lastLat' => $lat,
                'lastLng' => $lng,
                'lastLocatedAt' => $recordedAt->toIso8601String(),
                'eta' => $etaAt->toIso8601String(),
                'etaAt' => $etaAt->toIso8601String(),
            ], 'Tournée introuvable');

            if (! empty($route['vehicleId'])) {
                $this->vehicles->merge($route['vehicleId'], [
                    'lastLat' => $lat,
                    'lastLng' => $lng,
                    'lastLocatedAt' => $recordedAt->toIso8601String(),
                    'status' => $status === 'done' ? 'available' : 'on_mission',
                ], 'Véhicule introuvable');
            }

            $this->syncStopProgress($route, $path, $projection, $position, $recordedAt);

            $event = $this->events->create([
                'id' => Uid::make('trk'),
                'company_id' => $route['companyId'] ?? $this->tenant->companyId(),
                'subject_type' => 'route',
                'subject_id' => $route['id'],
                'route_id' => $route['id'],
                'vehicle_id' => $route['vehicleId'] ?? null,
                'driver_id' => $route['driverId'] ?? null,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy_m' => $input['accuracyM'] ?? null,
                'speed_kmh' => $speed,
                'heading' => $input['heading'] ?? null,
                'progress' => $progress,
                'event_type' => $input['eventType'] ?? 'ping',
                'source' => $input['source'] ?? 'driver_app',
                'recorded_at' => $recordedAt,
            ]);

            return [
                'event' => $event,
                'route' => $route,
                'projection' => $projection,
                'etaAt' => $etaAt->toIso8601String(),
            ];
        });
    }

    /**
     * Instantané de suivi d'une tournée pour l'UI.
     */
    public function routeSnapshot(string $routeId, int $eventLimit = 50): array
    {
        $route = $this->routes->findOrFail($routeId, 'Tournée introuvable');
        $this->tenant->assertWarehouseAccess($route['warehouseId'] ?? '');

        $events = $this->events->forRoute($routeId, $eventLimit);
        $stops = $this->deliveries->forWarehouses([$route['warehouseId']])
            ->filter(fn (array $item) => ($item['routeId'] ?? null) === $routeId)
            ->sortBy(fn (array $item) => $item['sequence'] ?? 0)
            ->values();

        return [
            'routeId' => $routeId,
            'route' => $route,
            'lastPosition' => $route['currentPosition'] ?? (
                isset($route['lastLat'], $route['lastLng'])
                    ? [
                        'lat' => (float) $route['lastLat'],
                        'lng' => (float) $route['lastLng'],
                        'at' => $route['lastLocatedAt'] ?? null,
                        'label' => 'Position véhicule',
                    ]
                    : null
            ),
            'progress' => (int) ($route['progress'] ?? 0),
            'eta' => $route['eta'] ?? $route['etaAt'] ?? null,
            'events' => $events->values()->all(),
            'stops' => $stops->map(fn (array $stop) => [
                'id' => $stop['id'],
                'client' => $stop['client'] ?? null,
                'status' => $stop['status'] ?? null,
                'sequence' => $stop['sequence'] ?? null,
                'progress' => $stop['progress'] ?? 0,
                'location' => $stop['location'] ?? null,
                'currentPosition' => $stop['currentPosition'] ?? null,
                'eta' => $stop['eta'] ?? null,
            ])->all(),
        ];
    }

    /** Dernières positions des tournées actives de l'entreprise. */
    public function liveRoutes(): Collection
    {
        $routes = $this->tenant->bypassTenantFilter()
            ? $this->routes->all()
            : $this->routes->forWarehouses($this->tenant->companyWarehouseIds());

        return $routes
            ->filter(fn (array $route) => in_array($route['status'] ?? '', ['in_progress', 'planned'], true))
            ->map(function (array $route) {
                return [
                    'routeId' => $route['id'],
                    'name' => $route['name'] ?? $route['id'],
                    'status' => $route['status'] ?? null,
                    'progress' => (int) ($route['progress'] ?? 0),
                    'eta' => $route['eta'] ?? $route['etaAt'] ?? null,
                    'lastPosition' => $route['currentPosition'] ?? (
                        isset($route['lastLat'], $route['lastLng'])
                            ? [
                                'lat' => (float) $route['lastLat'],
                                'lng' => (float) $route['lastLng'],
                                'at' => $route['lastLocatedAt'] ?? null,
                            ]
                            : null
                    ),
                    'vehicleId' => $route['vehicleId'] ?? null,
                    'driverId' => $route['driverId'] ?? null,
                    'warehouseId' => $route['warehouseId'] ?? null,
                ];
            })
            ->values();
    }

    private function assertDriverMayTrack(array $route): void
    {
        $role = $this->tenant->role();
        if (in_array($role, ['SUPER_ADMIN', 'ADMIN', 'MANAGER', 'DISPATCHER', 'TENANT_OWNER'], true)) {
            return;
        }

        if ($role !== 'DRIVER') {
            throw new DomainException('Seul le chauffeur de la tournée peut envoyer sa position.', 403);
        }

        $userId = $this->tenant->actorId();
        $driver = $this->drivers->all()->first(
            fn (array $item) => ($item['userId'] ?? null) === $userId,
        );
        if (! $driver || ($driver['id'] ?? null) !== ($route['driverId'] ?? null)) {
            throw new DomainException('Cette tournée n’est pas affectée à votre compte.', 403);
        }
    }

    /**
     * @param  list<array{lat: float|int|string, lng: float|int|string, label?: string}>  $path
     * @return list<array{lat: float, lng: float, label?: string}>
     */
    private function normalizePath(array $path): array
    {
        $normalized = [];
        foreach ($path as $point) {
            if (! isset($point['lat'], $point['lng'])) {
                continue;
            }
            $normalized[] = [
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
                'label' => $point['label'] ?? null,
            ];
        }

        return $normalized;
    }

    /** @return list<array{lat: float, lng: float, label?: string}> */
    private function rebuildPathFromStops(array $route): array
    {
        $warehouseId = $route['warehouseId'] ?? '';
        $stops = $this->deliveries->forWarehouses([$warehouseId])
            ->filter(fn (array $item) => ($item['routeId'] ?? null) === $route['id'])
            ->sortBy(fn (array $item) => $item['sequence'] ?? 0)
            ->values();

        $path = [];
        foreach ($stops as $stop) {
            if (! empty($stop['location']['lat']) && ! empty($stop['location']['lng'])) {
                $path[] = [
                    'lat' => (float) $stop['location']['lat'],
                    'lng' => (float) $stop['location']['lng'],
                    'label' => $stop['client'] ?? null,
                ];
            }
        }

        return $path;
    }

    /**
     * @param  list<array{lat: float, lng: float, label?: string}>  $path
     * @param  array{lat: float, lng: float, progress: float, remainingKm: float, distanceAlongKm: float, totalKm: float, segmentIndex: int}  $projection
     * @param  array{lat: float, lng: float, label?: string, at?: string}  $position
     */
    private function syncStopProgress(
        array $route,
        array $path,
        array $projection,
        array $position,
        CarbonImmutable $recordedAt,
    ): void {
        $stops = $this->deliveries->forWarehouses([$route['warehouseId'] ?? ''])
            ->filter(fn (array $item) => ($item['routeId'] ?? null) === $route['id'])
            ->sortBy(fn (array $item) => $item['sequence'] ?? 0)
            ->values();

        if ($stops->isEmpty()) {
            return;
        }

        $activeFound = false;
        foreach ($stops as $stop) {
            $status = $stop['status'] ?? 'prepared';
            if ($status === 'delivered' || $status === 'failed') {
                continue;
            }

            $destination = $stop['location'] ?? null;
            if (! $destination || ! isset($destination['lat'], $destination['lng'])) {
                continue;
            }

            $distanceToStop = Geo::haversineKm(
                ['lat' => $position['lat'], 'lng' => $position['lng']],
                ['lat' => (float) $destination['lat'], 'lng' => (float) $destination['lng']],
            );

            if (! $activeFound) {
                $activeFound = true;
                $legProgress = $this->legProgressToward($path, $projection, $destination);
                $arrived = $distanceToStop <= self::ARRIVAL_RADIUS_KM;
                $stopProgress = $arrived ? 98 : max(5, min(95, $legProgress));
                $etaSpeed = self::DEFAULT_SPEED_KMH;
                $stopEta = $distanceToStop > 0
                    ? $recordedAt->addSeconds((int) round(($distanceToStop / $etaSpeed) * 3600))
                    : $recordedAt;

                $this->deliveries->merge($stop['id'], [
                    'status' => $arrived
                        ? (($status === 'at_risk') ? 'at_risk' : 'in_transit')
                        : (($status === 'prepared') ? 'in_transit' : $status),
                    'progress' => $stopProgress,
                    'currentPosition' => $position,
                    'eta' => $stopEta->toIso8601String(),
                ], 'Livraison introuvable');
            } else {
                // Stops suivants : pas encore actifs.
                if (($stop['status'] ?? '') === 'in_transit') {
                    $this->deliveries->merge($stop['id'], [
                        'status' => 'prepared',
                        'progress' => 0,
                    ], 'Livraison introuvable');
                }
            }
        }
    }

    /**
     * Progression relative vers le prochain stop le long du tracé.
     *
     * @param  list<array{lat: float, lng: float}>  $path
     * @param  array{distanceAlongKm: float, totalKm: float}  $projection
     * @param  array{lat: float, lng: float}  $destination
     */
    private function legProgressToward(array $path, array $projection, array $destination): int
    {
        if (count($path) < 2) {
            return 0;
        }
        $destProjection = Geo::projectOntoPath($path, (float) $destination['lat'], (float) $destination['lng']);
        $targetAlong = $destProjection['distanceAlongKm'];
        $currentAlong = $projection['distanceAlongKm'];
        if ($targetAlong <= 0.01) {
            return 100;
        }
        $ratio = $currentAlong / $targetAlong;

        return (int) min(100, max(0, round($ratio * 100)));
    }
}
