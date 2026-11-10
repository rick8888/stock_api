<?php

namespace App\Billing;

interface StripeGateway
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{id: string}
     */
    public function createCustomer(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array{url: string}
     */
    public function createBillingPortalSession(array $params): array;

    /**
     * @return array{type: string, data: array{object: array<string, mixed>}}
     */
    public function constructEvent(string $payload, string $signature, string $secret): array;
}
