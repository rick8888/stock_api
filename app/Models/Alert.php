<?php

namespace App\Models;

class Alert extends RelationalDocumentModel
{
    protected $table = 'alerts';

    protected $fillable = [
        'id', 'payload', 'title', 'message', 'severity', 'alerted_at', 'read', 'domain',
        'related_id', 'warehouse_id', 'user_id', 'roles', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'alerted_at' => 'datetime',
            'read' => 'boolean',
            'roles' => 'array',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'title' => 'title',
            'message' => 'message',
            'severity' => 'severity',
            'read' => 'read',
            'domain' => 'domain',
            'relatedId' => 'related_id',
            'warehouseId' => 'warehouse_id',
            'userId' => 'user_id',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->alerted_at) {
            $doc['createdAt'] = $this->alerted_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['createdAt'])) {
            $doc['createdAt'] = $this->payload['createdAt'];
        }
        if (is_array($this->roles)) {
            $doc['roles'] = $this->roles;
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('createdAt', $data)) {
            $this->alerted_at = $data['createdAt'] ? \Carbon\Carbon::parse((string) $data['createdAt']) : null;
        }
        if (array_key_exists('roles', $data)) {
            $this->roles = is_array($data['roles']) ? array_values($data['roles']) : [];
        }

        return parent::fillDocument($data);
    }
}
