<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\ShippingNote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Crée / enrichit les fiches clients à partir des stops et bons de livraison,
 * avec lat/lng sur chaque adresse. Lie aussi customerId / addressId sur les BL.
 */
class CustomerGeoSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = 'co-atlas';

        foreach (Delivery::query()->orderBy('id')->get() as $deliveryModel) {
            $delivery = $deliveryModel->document();
            $client = trim((string) ($delivery['client'] ?? ''));
            $address = trim((string) ($delivery['address'] ?? ''));
            $city = trim((string) ($delivery['city'] ?? ''));
            if ($client === '' || $address === '' || $city === '') {
                continue;
            }

            $location = $delivery['location'] ?? null;
            $point = $this->resolvePoint(
                isset($location['lat']) ? (float) $location['lat'] : null,
                isset($location['lng']) ? (float) $location['lng'] : null,
                $city,
                $address,
                $client,
            );

            $customer = $this->upsertCustomer($companyId, $client, $address, $city, $point['lat'], $point['lng'], true);
            $addressId = $this->defaultAddressId($customer, $address, $city);

            $noteId = $delivery['shippingNoteId'] ?? ('bl-'.Str::after($delivery['id'], 'dlv-'));
            $note = ShippingNote::query()->find($noteId);
            if ($note) {
                $note->mergeDocument([
                    'customerId' => $customer['id'],
                    'addressId' => $addressId,
                    'client' => $client,
                    'address' => $address,
                    'city' => $city,
                ])->save();
            }
        }

        foreach (ShippingNote::query()->orderBy('id')->get() as $noteModel) {
            $note = $noteModel->document();
            $client = trim((string) ($note['client'] ?? ''));
            $address = trim((string) ($note['address'] ?? ''));
            $city = trim((string) ($note['city'] ?? ''));
            if ($client === '' || $address === '' || $city === '') {
                continue;
            }

            $point = $this->resolvePoint(null, null, $city, $address, $client);
            // Fallback ville uniquement : ne pas écraser une position déjà connue.
            $customer = $this->upsertCustomer($companyId, $client, $address, $city, $point['lat'], $point['lng'], false);
            $addressId = $this->defaultAddressId($customer, $address, $city);

            if (($note['customerId'] ?? null) === $customer['id'] && ($note['addressId'] ?? null) === $addressId) {
                continue;
            }

            $noteModel->mergeDocument([
                'customerId' => $customer['id'],
                'addressId' => $addressId,
            ])->save();
        }
    }

    /** @return array{lat: float, lng: float} */
    private function resolvePoint(?float $lat, ?float $lng, string $city, string $address, string $label): array
    {
        if ($lat !== null && $lng !== null) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        $cities = [
            'casablanca' => ['lat' => 33.5731, 'lng' => -7.5898],
            'rabat' => ['lat' => 34.0209, 'lng' => -6.8416],
            'marrakech' => ['lat' => 31.6295, 'lng' => -7.9811],
            'tanger' => ['lat' => 35.7595, 'lng' => -5.8340],
            'sale' => ['lat' => 34.0531, 'lng' => -6.7980],
            'salé' => ['lat' => 34.0531, 'lng' => -6.7980],
        ];
        $key = Str::lower(Str::ascii($city));
        $base = $cities[$key] ?? $cities['casablanca'];
        $hash = crc32($address.'|'.$label.'|'.$city);
        $n = $hash % 6400;

        return [
            'lat' => $base['lat'] + ((($n % 80) - 40) * 0.0004),
            'lng' => $base['lng'] + ((((int) floor($n / 80) % 80) - 40) * 0.0005),
        ];
    }

    /** @param  array<string, mixed>  $customer */
    private function defaultAddressId(array $customer, string $address, string $city): ?string
    {
        foreach ($customer['addresses'] ?? [] as $row) {
            if (
                Str::lower(trim((string) ($row['address'] ?? ''))) === Str::lower($address)
                && Str::lower(trim((string) ($row['city'] ?? ''))) === Str::lower($city)
            ) {
                return $row['id'] ?? null;
            }
        }

        return $customer['addresses'][0]['id'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function upsertCustomer(
        string $companyId,
        string $name,
        string $address,
        string $city,
        ?float $lat,
        ?float $lng,
        bool $forceGeo = false,
    ): array {
        $slug = Str::slug($name);
        $preferredId = match ($name) {
            'Pharmacie des Habous' => 'cus-habous',
            'Superette Agdal' => 'cus-agdal',
            'Café Gueliz' => 'cus-gueliz',
            default => 'cus-'.$slug,
        };

        $existing = Customer::query()->find($preferredId)
            ?? Customer::query()->where('company_id', $companyId)->where('name', $name)->first();

        $addressId = match ($preferredId) {
            'cus-habous' => 'adr-habous-1',
            'cus-agdal' => 'adr-agdal-1',
            'cus-gueliz' => 'adr-gueliz-1',
            default => 'adr-'.$slug.'-1',
        };

        $addresses = [];
        if ($existing) {
            $doc = $existing->document();
            $addresses = array_values($doc['addresses'] ?? []);
        }

            $matched = false;
        foreach ($addresses as &$row) {
            $same =
                Str::lower(trim((string) ($row['address'] ?? ''))) === Str::lower($address)
                && Str::lower(trim((string) ($row['city'] ?? ''))) === Str::lower($city);
            if (! $same) {
                continue;
            }
            $matched = true;
            if ($lat !== null && $lng !== null && ($forceGeo || ! isset($row['lat'], $row['lng']))) {
                $row['lat'] = $lat;
                $row['lng'] = $lng;
            }
            $addressId = $row['id'] ?? $addressId;
            break;
        }
        unset($row);

        if (! $matched) {
            $addresses[] = [
                'id' => $addressId,
                'label' => 'Livraison',
                'address' => $address,
                'city' => $city,
                'isDefault' => count($addresses) === 0,
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        $payload = [
            'id' => $existing?->id ?? $preferredId,
            'name' => $name,
            'active' => true,
            'companyId' => $companyId,
            'addresses' => array_values($addresses),
            'phone' => $existing?->phone,
            'email' => $existing?->email,
            'ice' => $existing?->ice,
        ];

        if (! $existing && $preferredId === 'cus-habous') {
            $payload['phone'] = '+212522000111';
            $payload['email'] = 'contact@habous.ma';
            $payload['ice'] = '001234567000012';
        }

        $model = $existing ?? new Customer(['id' => $payload['id']]);
        $model->fillDocument($payload)->save();

        return $model->fresh()->document();
    }
}
