<?php

namespace App\Services;

use App\Repositories\Eloquent\AppNotificationRepository;
use App\Support\Tenant;
use Illuminate\Support\Collection;

class NotificationService
{
    public function __construct(
        private readonly AppNotificationRepository $notifications,
        private readonly Tenant $tenant,
    ) {}

    public function list(): Collection
    {
        $userId = $this->tenant->user()?->id;
        $companyId = $this->tenant->companyId();

        if ($this->tenant->bypassTenantFilter()) {
            return $this->notifications->all()
                ->filter(fn (array $row) => empty($row['userId']) || ($row['userId'] ?? '') === $userId)
                ->sortByDesc(fn (array $row) => (string) ($row['createdAt'] ?? ''))
                ->values();
        }

        $rows = $this->notifications->forCompany($companyId);

        return $rows
            ->filter(function (array $row) use ($userId) {
                $target = $row['userId'] ?? null;

                return empty($target) || $target === $userId;
            })
            ->sortByDesc(fn (array $row) => (string) ($row['createdAt'] ?? ''))
            ->values();
    }

    public function markRead(string $id): void
    {
        $row = $this->notifications->findOrFail($id, 'Notification introuvable');
        $this->assertVisible($row);
        $this->notifications->merge($id, ['read' => true], 'Notification introuvable');
    }

    public function markAllRead(): int
    {
        $count = 0;
        foreach ($this->list() as $row) {
            if (! empty($row['read'])) {
                continue;
            }
            $this->notifications->merge((string) $row['id'], ['read' => true], 'Notification introuvable');
            $count++;
        }

        return $count;
    }

    /** @param  array<string, mixed>  $row */
    private function assertVisible(array $row): void
    {
        $userId = $this->tenant->user()?->id;
        $target = $row['userId'] ?? null;
        if ($target && $target !== $userId && ! $this->tenant->bypassTenantFilter()) {
            abort(403, 'Permission refusée.');
        }
        if (! $this->tenant->bypassTenantFilter()) {
            $this->tenant->assertCompanyAccess($row['companyId'] ?? $this->tenant->companyId());
        }
    }
}
