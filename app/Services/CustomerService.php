<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\CustomerRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;

class CustomerService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly Tenant $tenant,
    ) {}

    public function list(): Collection
    {
        if ($this->tenant->bypassTenantFilter()) {
            return $this->customers->all()->values();
        }

        return $this->customers->forCompany($this->tenant->companyId());
    }

    public function get(string $id): ?array
    {
        $customer = $this->customers->find($id);
        if (! $customer) {
            return null;
        }
        if (! $this->tenant->bypassTenantFilter()) {
            $companyId = $this->tenant->companyId();
            if (! empty($customer['companyId']) && $customer['companyId'] !== $companyId) {
                return null;
            }
        }

        return $customer;
    }

    public function create(array $input): array
    {
        $name = trim($input['name'] ?? '');
        if (strlen($name) < 2) {
            throw new DomainException('Le nom du client est requis.');
        }
        $addresses = $this->normalizeAddresses($input['addresses'] ?? []);
        if (! $addresses && ! empty($input['address'])) {
            $addresses = [[
                'id' => Uid::make('adr'),
                'label' => $input['addressLabel'] ?? 'Principale',
                'address' => trim($input['address']),
                'city' => trim($input['city'] ?? ''),
                'isDefault' => true,
            ]];
        }
        if (! $addresses) {
            throw new DomainException('Au moins une adresse est requise.');
        }

        return $this->customers->put($this->tenant->stamp([
            'id' => Uid::make('cus'),
            'name' => $name,
            'phone' => trim($input['phone'] ?? ''),
            'email' => strtolower(trim($input['email'] ?? '')),
            'ice' => trim($input['ice'] ?? ''),
            'notes' => trim($input['notes'] ?? ''),
            'active' => (bool) ($input['active'] ?? true),
            'addresses' => $addresses,
        ]));
    }

    public function update(string $id, array $input): array
    {
        $customer = $this->get($id);
        if (! $customer) {
            throw new DomainException('Client introuvable', 404);
        }
        if (array_key_exists('name', $input)) {
            $customer['name'] = trim($input['name']);
        }
        foreach (['phone', 'email', 'ice', 'notes'] as $key) {
            if (array_key_exists($key, $input)) {
                $customer[$key] = $key === 'email'
                    ? strtolower(trim((string) $input[$key]))
                    : trim((string) $input[$key]);
            }
        }
        if (array_key_exists('active', $input)) {
            $customer['active'] = (bool) $input['active'];
        }
        if (array_key_exists('addresses', $input)) {
            $customer['addresses'] = $this->normalizeAddresses($input['addresses']);
        }

        return $this->customers->put($customer);
    }

    /** @param list<array<string, mixed>> $addresses */
    private function normalizeAddresses(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $index => $row) {
            $address = trim($row['address'] ?? '');
            $city = trim($row['city'] ?? '');
            if ($address === '' || $city === '') {
                continue;
            }
            $out[] = [
                'id' => $row['id'] ?? Uid::make('adr'),
                'label' => trim($row['label'] ?? '') ?: ('Adresse '.($index + 1)),
                'address' => $address,
                'city' => $city,
                'isDefault' => (bool) ($row['isDefault'] ?? $index === 0),
            ];
        }
        if ($out && ! collect($out)->contains(fn (array $row) => ! empty($row['isDefault']))) {
            $out[0]['isDefault'] = true;
        }

        return $out;
    }
}
