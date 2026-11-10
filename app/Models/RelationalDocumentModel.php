<?php

namespace App\Models;

/**
 * Document métier avec colonnes SQL indexables + dual-write payload (compat).
 * Lecture : colonnes non-null écrasent le payload ; legacy payload-only reste lisible.
 */
abstract class RelationalDocumentModel extends DocumentModel
{
    /**
     * Map camelCase document → colonne SQL snake_case.
     *
     * @return array<string, string>
     */
    abstract public static function documentMap(): array;

    public function document(): array
    {
        $doc = is_array($this->payload) ? $this->payload : [];
        $attrs = $this->getAttributes();

        foreach (static::documentMap() as $camel => $column) {
            if (! array_key_exists($column, $attrs) || $attrs[$column] === null) {
                continue;
            }
            $doc[$camel] = $this->getAttribute($column);
        }

        $doc['id'] = $this->id;

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        $id = $data['id'] ?? $this->id;
        $data['id'] = $id;
        $this->id = $id;

        foreach (static::documentMap() as $camel => $column) {
            if (! array_key_exists($camel, $data)) {
                continue;
            }
            $this->setAttribute($column, $data[$camel]);
        }

        // Dual-write : le payload reste la source de vérité pour champs hors map.
        $this->payload = $data;

        return $this;
    }
}
