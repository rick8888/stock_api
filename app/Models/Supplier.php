<?php

namespace App\Models;

class Supplier extends RelationalDocumentModel
{
    protected $table = 'suppliers';

    protected $fillable = [
        'id', 'payload', 'name', 'contact', 'email', 'phone', 'city', 'address', 'ice',
        'category', 'payment_terms', 'note', 'lead_time_days', 'reliability', 'status', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'lead_time_days' => 'integer',
            'reliability' => 'integer',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'contact' => 'contact',
            'email' => 'email',
            'phone' => 'phone',
            'city' => 'city',
            'address' => 'address',
            'ice' => 'ice',
            'category' => 'category',
            'paymentTerms' => 'payment_terms',
            'note' => 'note',
            'leadTimeDays' => 'lead_time_days',
            'reliability' => 'reliability',
            'status' => 'status',
            'companyId' => 'company_id',
        ];
    }
}
