<?php

namespace App\Models;

class AppNotification extends RelationalDocumentModel
{
    protected $table = 'notifications';

    protected $fillable = [
        'id', 'payload', 'title', 'message', 'notified_at', 'read', 'type', 'user_id', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'notified_at' => 'datetime',
            'read' => 'boolean',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'title' => 'title',
            'message' => 'message',
            'read' => 'read',
            'type' => 'type',
            'userId' => 'user_id',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->notified_at) {
            $doc['createdAt'] = $this->notified_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['createdAt'])) {
            $doc['createdAt'] = $this->payload['createdAt'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('createdAt', $data)) {
            $this->notified_at = $data['createdAt'] ? \Carbon\Carbon::parse((string) $data['createdAt']) : null;
        }

        return parent::fillDocument($data);
    }
}
