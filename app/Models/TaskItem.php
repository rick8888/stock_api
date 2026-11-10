<?php

namespace App\Models;

class TaskItem extends RelationalDocumentModel
{
    protected $table = 'tasks';

    protected $fillable = [
        'id', 'payload', 'title', 'description', 'assignee_id', 'due_at', 'status', 'priority',
        'related_type', 'related_id', 'warehouse_id', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'due_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'title' => 'title',
            'description' => 'description',
            'assigneeId' => 'assignee_id',
            'status' => 'status',
            'priority' => 'priority',
            'relatedType' => 'related_type',
            'relatedId' => 'related_id',
            'warehouseId' => 'warehouse_id',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->due_at) {
            $doc['dueAt'] = $this->due_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['dueAt'])) {
            $doc['dueAt'] = $this->payload['dueAt'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('dueAt', $data)) {
            $this->due_at = $data['dueAt'] ? \Carbon\Carbon::parse((string) $data['dueAt']) : null;
        }

        return parent::fillDocument($data);
    }
}
