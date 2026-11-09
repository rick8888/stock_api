<?php

namespace App\Services;

use App\Repositories\Eloquent\AlertRepository;
use Illuminate\Support\Collection;

class AlertService
{
    public function __construct(
        private readonly AlertRepository $alerts,
    ) {}

    public function list(): Collection
    {
        return $this->alerts->all();
    }

    public function markRead(string $id): void
    {
        $this->alerts->merge($id, ['read' => true], 'Alerte introuvable');
    }
}
