<?php

namespace App\Repositories\Eloquent;

use App\Models\InventorySession;

class InventorySessionRepository extends DocumentRepository
{
    protected string $modelClass = InventorySession::class;

    protected string $idPrefix = 'inv';
}
