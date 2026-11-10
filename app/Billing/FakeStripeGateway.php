<?php

namespace App\Billing;

use App\Exceptions\DomainException;

class FakeStripeGateway implements StripeGateway
{
    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** @var array{type: string, data: array{object: array<string, mixed>}}|null */
    public ?array $nextEvent = null;

    public function createCustomer(array $params): array
    {
        $this->calls[] = ['method' => 'createCustomer', 'params' => $params];

        return ['id' => 'cus_fake_'.substr(md5(json_encode($params) ?: ''), 0, 8)];
    }

    public function createCheckoutSession(array $params): array
    {
        $this->calls[] = ['method' => 'createCheckoutSession', 'params' => $params];

        return [
            'id' => 'cs_test_fake',
            'url' => 'https://checkout.stripe.test/session/cs_test_fake',
        ];
    }

    public function createBillingPortalSession(array $params): array
    {
        $this->calls[] = ['method' => 'createBillingPortalSession', 'params' => $params];

        return ['url' => 'https://billing.stripe.test/session/bps_fake'];
    }

    public function constructEvent(string $payload, string $signature, string $secret): array
    {
        $this->calls[] = [
            'method' => 'constructEvent',
            'params' => ['payload' => $payload, 'signature' => $signature, 'secret' => $secret],
        ];
        if ($signature === 'bad') {
            throw new DomainException('Signature Stripe invalide.', 400);
        }
        if ($this->nextEvent) {
            return $this->nextEvent;
        }
        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || empty($decoded['type'])) {
            throw new DomainException('Payload webhook invalide.', 400);
        }

        return [
            'type' => (string) $decoded['type'],
            'data' => [
                'object' => is_array($decoded['data']['object'] ?? null)
                    ? $decoded['data']['object']
                    : [],
            ],
        ];
    }
}
