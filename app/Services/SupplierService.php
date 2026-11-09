<?php

namespace App\Services;

use App\Repositories\Eloquent\SupplierRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;

class SupplierService
{
    public function __construct(
        private readonly SupplierRepository $suppliers,
    ) {}

    public function list(): Collection
    {
        return $this->suppliers->all();
    }

    public function get(string $id): ?array
    {
        return $this->suppliers->find($id);
    }

    public function create(array $input): array
    {
        return $this->suppliers->put([
            'id' => Uid::make('sup'),
            'reliability' => 80,
            'status' => 'active',
            ...$input,
        ]);
    }

    public function update(string $id, array $input): array
    {
        $this->suppliers->findOrFail($id, 'Fournisseur introuvable');

        return $this->suppliers->merge($id, $input, 'Fournisseur introuvable');
    }
}
