<?php

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;
use Illuminate\Support\Collection;

class AuditLogRepository extends DocumentRepository
{
    protected string $modelClass = AuditLog::class;

    protected string $idPrefix = 'aud';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return AuditLog::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderByDesc('audited_at')
            ->get()
            ->map(fn (AuditLog $model) => $model->document())
            ->values();
    }

    public function has(string $action, string $entityId): bool
    {
        return AuditLog::query()
            ->where('action', $action)
            ->where('entity_id', $entityId)
            ->exists();
    }
}
