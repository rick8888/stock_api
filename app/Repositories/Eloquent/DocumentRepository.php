<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\DocumentModel;
use App\Support\Uid;
use Illuminate\Support\Collection;

abstract class DocumentRepository
{
    /** @var class-string<DocumentModel> */
    protected string $modelClass;

    protected string $idPrefix = 'row';

    public function all(): Collection
    {
        return ($this->modelClass)::query()
            ->get()
            ->map(fn (DocumentModel $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        $model = ($this->modelClass)::query()->find($id);

        return $model?->document();
    }

    public function findOrFail(string $id, string $message = 'Introuvable'): array
    {
        $doc = $this->find($id);
        if (! $doc) {
            throw new DomainException($message);
        }

        return $doc;
    }

    public function put(array $doc): array
    {
        $id = $doc['id'] ?? Uid::make($this->idPrefix);
        $doc['id'] = $id;
        /** @var DocumentModel $model */
        $model = ($this->modelClass)::query()->find($id) ?? new ($this->modelClass)(['id' => $id]);
        $model->fillDocument($doc)->save();

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var DocumentModel|null $model */
        $model = ($this->modelClass)::query()->find($id);
        if (! $model) {
            throw new DomainException($message);
        }
        $model->mergeDocument($patch)->save();

        return $model->document();
    }

    public function delete(string $id): void
    {
        ($this->modelClass)::query()->where('id', $id)->delete();
    }
}
