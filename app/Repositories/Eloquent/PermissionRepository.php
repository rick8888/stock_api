<?php

namespace App\Repositories\Eloquent;

use App\Models\Permission;

class PermissionRepository extends DocumentRepository
{
    protected string $modelClass = Permission::class;

    protected string $idPrefix = 'prm';
}
