<?php

namespace App\Repositories\Eloquent;

use App\Models\TrackingEvent;
use App\Support\Uid;
use Illuminate\Support\Collection;

class TrackingEventRepository
{
    public function create(array $data): array
    {
        $model = new TrackingEvent([
            'id' => $data['id'] ?? Uid::make('trk'),
            ...$data,
        ]);
        $model->save();

        return $model->document();
    }

    /** @return Collection<int, array> */
    public function forSubject(string $type, string $id, int $limit = 100): Collection
    {
        return TrackingEvent::query()
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            ->map(fn (TrackingEvent $row) => $row->document())
            ->values();
    }

    /** @return Collection<int, array> */
    public function forRoute(string $routeId, int $limit = 100): Collection
    {
        return TrackingEvent::query()
            ->where('route_id', $routeId)
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            ->map(fn (TrackingEvent $row) => $row->document())
            ->values();
    }

    public function latestForRoute(string $routeId): ?array
    {
        return TrackingEvent::query()
            ->where('route_id', $routeId)
            ->orderByDesc('recorded_at')
            ->first()
            ?->document();
    }
}
