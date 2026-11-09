<?php

namespace App\Repositories\Eloquent;

use App\Models\Consultation;

class ConsultationRepository extends DocumentRepository
{
    protected string $modelClass = Consultation::class;

    protected string $idPrefix = 'cs';
}
