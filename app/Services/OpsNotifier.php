<?php

namespace App\Services;

use App\Repositories\Eloquent\AlertRepository;
use App\Repositories\Eloquent\AppNotificationRepository;
use App\Repositories\Eloquent\AuditLogRepository;
use App\Support\Uid;

class OpsNotifier
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly AppNotificationRepository $notifications,
        private readonly AuditLogRepository $audit,
    ) {}

    public function alert(array $data): void
    {
        $this->alerts->put([
            'id' => Uid::make('al'),
            'read' => false,
            'createdAt' => now()->toIso8601String(),
            ...$data,
        ]);
    }

    public function notify(string $title, string $message, string $type = 'info', ?string $userId = null): void
    {
        $this->notifications->put([
            'id' => Uid::make('ntf'),
            'title' => $title,
            'message' => $message,
            'createdAt' => now()->toIso8601String(),
            'read' => false,
            'type' => $type,
            'userId' => $userId,
        ]);
    }

    public function audit(string $action, string $entity, string $entityId, string $userId = ''): void
    {
        $this->audit->put([
            'id' => Uid::make('aud'),
            'userId' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entityId' => $entityId,
            'createdAt' => now()->toIso8601String(),
        ]);
    }
}
