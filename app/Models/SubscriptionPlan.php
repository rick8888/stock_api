<?php

namespace App\Models;

class SubscriptionPlan extends RelationalDocumentModel
{
    protected $table = 'subscription_plans';

    protected $fillable = [
        'id', 'payload', 'name', 'code', 'description', 'monthly_price', 'yearly_price',
        'max_users', 'max_warehouses', 'modules', 'active', 'stripe_price_monthly', 'stripe_price_yearly',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
            'max_users' => 'integer',
            'max_warehouses' => 'integer',
            'modules' => 'array',
            'active' => 'boolean',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'code' => 'code',
            'description' => 'description',
            'monthlyPrice' => 'monthly_price',
            'yearlyPrice' => 'yearly_price',
            'maxUsers' => 'max_users',
            'maxWarehouses' => 'max_warehouses',
            'active' => 'active',
            'stripePriceMonthly' => 'stripe_price_monthly',
            'stripePriceYearly' => 'stripe_price_yearly',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if (is_array($this->modules)) {
            $doc['modules'] = $this->modules;
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('modules', $data)) {
            $this->modules = is_array($data['modules']) ? array_values($data['modules']) : [];
        }

        return parent::fillDocument($data);
    }
}
