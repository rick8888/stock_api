<?php

namespace App\Repositories\Eloquent;

use App\Models\Route;

class RouteRepository extends DocumentRepository
{
    protected string $modelClass = Route::class;

    protected string $idPrefix = 'rt';
}
