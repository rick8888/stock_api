<?php

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;

class AuditLogRepository extends DocumentRepository
{
    protected string $modelClass = AuditLog::class;

    protected string $idPrefix = 'aud';
}
