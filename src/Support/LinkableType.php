<?php

namespace Zielu92\FilamentImageLabeler\Support;

use Closure;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Developer-facing configuration for one linkable entity type on an
 * ImageLabel field: how its records display, which columns the entity
 * picker searches, how the type is named, and (opt-in) its creation form.
 */
final class LinkableType
{
    private ?Closure $display = null;

    /** @var list<string>|null */
    private ?array $searchBy = null;

    private ?string $typeLabel = null;

    private array | Closure | null $creationSchema = null;

    /** @var array<class-string, array{name: bool, title: bool}> */
    private static array $columnCache = [];

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(public readonly string $model) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function make(string $model): self
    {
        return new self($model);
    }

    public function display(Closure $callback): self
    {
        $clone = clone $this;
        $clone->display = $callback;

        return $clone;
    }

    /** @param list<string> $columns */
    public function searchBy(array $columns): self
    {
        $clone = clone $this;
        $clone->searchBy = $columns;

        return $clone;
    }

    public function typeLabel(string $label): self
    {
        $clone = clone $this;
        $clone->typeLabel = $label;

        return $clone;
    }

    /** @param array<array-key, mixed>|Closure $schema */
    public function creatable(array | Closure $schema): self
    {
        $clone = clone $this;
        $clone->creationSchema = $schema;

        return $clone;
    }

    public function isCreatable(): bool
    {
        return $this->creationSchema !== null;
    }

    /**
     * @return array<array-key, mixed>|Schema
     */
    public function getCreationSchema(): mixed
    {
        return $this->creationSchema instanceof Closure
            ? ($this->creationSchema)()
            : ($this->creationSchema ?? []);
    }

    public function hasCustomDisplay(): bool
    {
        return $this->display !== null;
    }

    public function hasSearchBy(): bool
    {
        return $this->searchBy !== null;
    }

    public function typeName(): string
    {
        return $this->typeLabel ?? Str::of(class_basename($this->model))->headline()->title()->toString();
    }

    /**
     * Display string for a record: custom closure, else first present of
     * the name / title columns, else "#<key>".
     */
    public function displayFor(Model $record): string
    {
        if ($this->display !== null) {
            return (string) ($this->display)($record);
        }

        $columns = $this->conventionColumns();

        foreach (['name', 'title'] as $column) {
            if ($columns[$column] && filled($record->getAttribute($column))) {
                return (string) $record->getAttribute($column);
            }
        }

        return '#' . $record->getKey();
    }

    /**
     * Columns the entity picker LIKEs against.
     *
     * @return list<string>
     */
    public function searchableColumns(): array
    {
        if ($this->searchBy !== null) {
            return $this->searchBy;
        }

        $columns = $this->conventionColumns();

        return array_values(array_filter(
            ['name', 'title'],
            fn (string $column): bool => $columns[$column],
        ));
    }

    /**
     * Search records for one term: every whitespace-separated word must
     * match at least one searchable column (OR across columns, AND across
     * words), mirroring Filament's own picker baseline.
     *
     * @return list<array{type: string, id: int|string, display: string, label: string}>
     */
    public function search(string $term, int $limit = 8): array
    {
        $columns = $this->searchableColumns();

        if ($columns === []) {
            return [];
        }

        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return [];
        }

        /** @var class-string<Model> $model */
        $model = $this->model;

        $query = $model::query();

        foreach ($words as $word) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';

            $query->where(function ($q) use ($columns, $like): void {
                foreach ($columns as $column) {
                    $q->orWhereRaw("{$column} like ? escape '\\'", [$like]);
                }
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (Model $record): array => [
                'type' => $this->model,
                'id' => $record->getKey(),
                'display' => $this->displayFor($record),
                'label' => $this->typeName(),
            ])
            ->all();
    }

    /**
     * @return array{name: bool, title: bool}
     */
    private function conventionColumns(): array
    {
        if (isset(self::$columnCache[$this->model])) {
            return self::$columnCache[$this->model];
        }

        /** @var Model $instance */
        $instance = new ($this->model);
        $schema = $instance->getConnection()->getSchemaBuilder();
        $table = $instance->getTable();

        return self::$columnCache[$this->model] = [
            'name' => $schema->hasColumn($table, 'name'),
            'title' => $schema->hasColumn($table, 'title'),
        ];
    }

    public static function resetColumnCache(): void
    {
        self::$columnCache = [];
    }
}
