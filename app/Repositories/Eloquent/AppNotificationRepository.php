<?php

namespace App\Repositories\Eloquent;

use App\Models\AppNotification;
use Illuminate\Support\Collection;

class AppNotificationRepository extends DocumentRepository
{
    protected string $modelClass = AppNotification::class;

    protected string $idPrefix = 'ntf';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return AppNotification::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderByDesc('notified_at')
            ->get()
            ->map(fn (AppNotification $model) => $model->document())
            ->values();
    }

    public function forUser(?string $userId): Collection
    {
        if (! $userId) {
            return collect();
        }

        return AppNotification::query()
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->orderByDesc('notified_at')
            ->get()
            ->map(fn (AppNotification $model) => $model->document())
            ->values();
    }
}
