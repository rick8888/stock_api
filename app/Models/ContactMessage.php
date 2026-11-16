<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = [
        'name',
        'email',
        'company',
        'phone',
        'message',
        'source',
        'ip',
        'handled_at',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    /** Format renvoyé au front (camelCase, comme le reste de l'API). */
    public function toApi(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'company' => $this->company,
            'phone' => $this->phone,
            'message' => $this->message,
            'source' => $this->source,
            'createdAt' => $this->created_at?->toIso8601String(),
            'handledAt' => $this->handled_at?->toIso8601String(),
        ];
    }
}
