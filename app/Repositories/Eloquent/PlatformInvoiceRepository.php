<?php

namespace App\Repositories\Eloquent;

use App\Models\PlatformInvoice;

class PlatformInvoiceRepository extends DocumentRepository
{
    protected string $modelClass = PlatformInvoice::class;

    protected string $idPrefix = 'pinv';
}
