<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class DocumentModel extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function document(): array
    {
        $payload = $this->payload ?? [];
        $payload['id'] = $this->id;

        return $payload;
    }

    public function fillDocument(array $data): static
    {
        $id = $data['id'] ?? $this->id;
        $data['id'] = $id;
        $this->id = $id;
        $this->payload = $data;

        return $this;
    }

    public function mergeDocument(array $patch): static
    {
        return $this->fillDocument(array_merge($this->document(), $patch, ['id' => $this->id]));
    }
}
