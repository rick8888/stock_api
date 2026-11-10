<?php

namespace App\Models;

class PlatformInvoice extends RelationalDocumentModel
{
    protected $table = 'platform_invoices';

    protected $fillable = [
        'id', 'payload', 'company_id', 'subscription_id', 'number', 'amount', 'status',
        'issued_at', 'due_date', 'paid_at', 'payment_provider', 'payment_ref', 'stripe_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount' => 'float',
            'issued_at' => 'date',
            'due_date' => 'date',
            'paid_at' => 'date',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'companyId' => 'company_id',
            'subscriptionId' => 'subscription_id',
            'number' => 'number',
            'amount' => 'amount',
            'status' => 'status',
            'paymentProvider' => 'payment_provider',
            'paymentRef' => 'payment_ref',
            'stripeInvoiceId' => 'stripe_invoice_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        foreach ([
            'issuedAt' => 'issued_at',
            'dueDate' => 'due_date',
            'paidAt' => 'paid_at',
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
            'issuedAt' => 'issued_at',
            'dueDate' => 'due_date',
            'paidAt' => 'paid_at',
        ] as $camel => $column) {
            if (array_key_exists($camel, $data)) {
                $this->setAttribute($column, $data[$camel] ? \Carbon\Carbon::parse((string) $data[$camel])->toDateString() : null);
            }
        }

        return parent::fillDocument($data);
    }
}
