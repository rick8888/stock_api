<?php

namespace App\Models\Concerns;

use App\Support\Uid;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait SyncsDocumentLines
{
    /** @var list<array<string, mixed>>|null */
    public ?array $pendingLines = null;

    abstract public function lines(): HasMany;

    abstract protected function documentLineForeignKey(): string;

    abstract protected function documentLineIdPrefix(): string;

    /** @param list<array<string, mixed>> $lines */
    protected function queueDocumentLines(array $lines): void
    {
        $this->pendingLines = array_values($lines);
    }

    public function flushPendingLines(): void
    {
        if ($this->pendingLines === null || ! $this->id) {
            return;
        }

        $this->lines()->delete();
        foreach ($this->pendingLines as $index => $line) {
            if (empty($line['productId'])) {
                continue;
            }
            $this->lines()->create([
                'id' => Uid::make($this->documentLineIdPrefix()),
                'product_id' => $line['productId'],
                'product_name' => $line['productName'] ?? null,
                'sku' => $line['sku'] ?? null,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit' => $line['unit'] ?? null,
                'sort_order' => $index,
            ]);
        }
        $this->pendingLines = null;
        $this->unsetRelation('lines');
    }

    protected function documentLinesArray(): array
    {
        $lineRows = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        if ($lineRows->isEmpty()) {
            return [];
        }

        return $lineRows->map(fn ($line) => [
            'productId' => $line->product_id,
            'productName' => $line->product_name,
            'sku' => $line->sku,
            'quantity' => (int) $line->quantity,
            'unit' => $line->unit,
        ])->values()->all();
    }
}
