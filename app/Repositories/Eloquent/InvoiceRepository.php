<?php

namespace App\Repositories\Eloquent;

use App\Models\Invoice;

class InvoiceRepository extends DocumentRepository
{
    protected string $modelClass = Invoice::class;

    protected string $idPrefix = 'invc';
}
