<?php

namespace Zielu92\FilamentImageLabeler\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A reference to one linked entity: the record an entity link points at.
 * Type is the model class, id its key - resolved for display only by
 * convention, never by a live model instance.
 */
final class EntityRef
{
    public function __construct(
        public readonly string $type,
        public readonly int | string $id,
    ) {}

    public static function fromModel(Model $model): self
    {
        return new self($model::class, $model->getKey());
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;

        if (! is_string($type) || $type === '' || $id === null || $id === '') {
            return null;
        }

        return new self($type, is_numeric($id) ? (int) $id : (string) $id);
    }

    /**
     * @return array{type: string, id: int|string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }

    public function toString(): string
    {
        return $this->type . ':' . $this->id;
    }
}
