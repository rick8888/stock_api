<?php

namespace App\Models;

class AuditLog extends RelationalDocumentModel
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'id', 'payload', 'user_id', 'action', 'entity', 'entity_id', 'audited_at', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'audited_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'userId' => 'user_id',
            'action' => 'action',
            'entity' => 'entity',
            'entityId' => 'entity_id',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->audited_at) {
            $doc['createdAt'] = $this->audited_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['createdAt'])) {
            $doc['createdAt'] = $this->payload['createdAt'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('createdAt', $data)) {
            $this->audited_at = $data['createdAt'] ? \Carbon\Carbon::parse((string) $data['createdAt']) : null;
        }

        return parent::fillDocument($data);
    }
}
