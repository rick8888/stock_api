<?php

namespace App\Models;

class Subscription extends RelationalDocumentModel
{
    protected $table = 'subscriptions';

    protected $fillable = [
        'id', 'payload', 'company_id', 'plan_id', 'status', 'interval', 'started_at',
        'current_period_end', 'trial_ends_at', 'seats', 'stripe_customer_id', 'stripe_subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'started_at' => 'date',
            'current_period_end' => 'date',
            'trial_ends_at' => 'date',
            'seats' => 'integer',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'companyId' => 'company_id',
            'planId' => 'plan_id',
            'status' => 'status',
            'interval' => 'interval',
            'seats' => 'seats',
            'stripeCustomerId' => 'stripe_customer_id',
            'stripeSubscriptionId' => 'stripe_subscription_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        foreach ([
            'startedAt' => 'started_at',
            'currentPeriodEnd' => 'current_period_end',
            'trialEndsAt' => 'trial_ends_at',
        ] as $camel => $column) {
            $value = $this->getAttribute($column);
            if ($value) {
                $doc[$camel] = $value instanceof \DateTimeInterface
                    ? \Carbon\Carbon::instance($value)->toDateString()
                    : (string) $value;
            }
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        foreach ([
            'startedAt' => 'started_at',
            'currentPeriodEnd' => 'current_period_end',
            'trialEndsAt' => 'trial_ends_at',
        ] as $camel => $column) {
            if (array_key_exists($camel, $data)) {
                $this->setAttribute($column, $data[$camel] ? \Carbon\Carbon::parse((string) $data[$camel])->toDateString() : null);
            }
        }

        return parent::fillDocument($data);
    }
}
