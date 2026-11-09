<?php

namespace App\Repositories\Eloquent;

use App\Models\AppNotification;

class AppNotificationRepository extends DocumentRepository
{
    protected string $modelClass = AppNotification::class;

    protected string $idPrefix = 'ntf';
}
