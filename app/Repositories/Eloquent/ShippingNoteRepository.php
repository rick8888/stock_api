<?php

namespace App\Repositories\Eloquent;

use App\Models\ShippingNote;

class ShippingNoteRepository extends DocumentRepository
{
    protected string $modelClass = ShippingNote::class;

    protected string $idPrefix = 'bl';
}
