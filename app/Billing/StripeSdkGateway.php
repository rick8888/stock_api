<?php

namespace App\Billing;

use App\Exceptions\DomainException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeSdkGateway implements StripeGateway
{
    private ?StripeClient $client = null;

    public function createCustomer(array $params): array
    {
        $customer = $this->client()->customers->create($params);

        return ['id' => (string) $customer->id];
    }

    public function createCheckoutSession(array $params): array
    {
        $session = $this->client()->checkout->sessions->create($params);

        return [
            'id' => (string) $session->id,
            'url' => (string) ($session->url ?? ''),
        ];
    }

    public function createBillingPortalSession(array $params): array
    {
        $session = $this->client()->billingPortal->sessions->create($params);

        return ['url' => (string) ($session->url ?? '')];
    }

    public function constructEvent(string $payload, string $signature, string $secret): array
    {
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            throw new DomainException('Signature Stripe invalide.', 400);
        }

        $object = $event->data->object;

        return [
            'type' => (string) $event->type,
            'data' => [
                'object' => is_object($object) ? $object->toArray() : (array) $object,
            ],
        ];
    }

    private function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }
        $secret = (string) config('services.billing.stripe.secret', '');
        if ($secret === '') {
            throw new DomainException('STRIPE_SECRET manquant.');
        }
        $this->client = new StripeClient($secret);

        return $this->client;
    }
}
