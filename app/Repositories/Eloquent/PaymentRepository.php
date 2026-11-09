<?php

namespace App\Repositories\Eloquent;

use App\Models\Payment;

class PaymentRepository extends DocumentRepository
{
    protected string $modelClass = Payment::class;

    protected string $idPrefix = 'pay';
}
