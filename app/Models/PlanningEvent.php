<?php

namespace App\Models;

class PlanningEvent extends RelationalDocumentModel
{
    protected $table = 'planning_events';

    protected $fillable = [
        'id', 'payload', 'title', 'event_at', 'type', 'related_id', 'warehouse_id', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'event_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'title' => 'title',
            'type' => 'type',
            'relatedId' => 'related_id',
            'warehouseId' => 'warehouse_id',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->event_at) {
            $doc['date'] = $this->event_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['date'])) {
            $doc['date'] = $this->payload['date'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('date', $data)) {
            $this->event_at = $data['date'] ? \Carbon\Carbon::parse((string) $data['date']) : null;
        }

        return parent::fillDocument($data);
    }
}
