<?php

namespace Zielu92\FilamentImageLabeler\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The validated set of linkable entity types behind an ImageLabel field:
 * allow-list checks, picker search, batched ref resolution, the creation
 * policy and the payloads the editor consumes. The field only configures
 * this; the logic lives here.
 */
final readonly class LinkableTypes
{
    /** @var list<LinkableType> */
    public array $types;

    /**
     * @param  array<array-key, mixed>  $types
     */
    public function __construct(array $types)
    {
        if ($types === []) {
            throw new InvalidArgumentException('ImageLabel: linkableTo() needs at least one LinkableType.');
        }

        $seen = [];
        $validated = [];

        foreach ($types as $type) {
            if (! $type instanceof LinkableType) {
                throw new InvalidArgumentException('ImageLabel: linkableTo() accepts only LinkableType instances.');
            }

            if (! class_exists($type->model) || ! is_subclass_of($type->model, Model::class)) {
                throw new InvalidArgumentException("ImageLabel: linkable type [{$type->model}] is not an Eloquent model.");
            }

            if (isset($seen[$type->model])) {
                throw new InvalidArgumentException("ImageLabel: duplicate linkable type [{$type->model}].");
            }

            $seen[$type->model] = true;

            if ($type->hasCustomDisplay() && ! $type->hasSearchBy()) {
                throw new InvalidArgumentException("ImageLabel: [{$type->model}] uses a custom display() but no searchBy() - tell the picker which columns to LIKE.");
            }

            if (! $type->hasCustomDisplay() && $type->searchableColumns() === []) {
                throw new InvalidArgumentException("ImageLabel: [{$type->model}] has neither a name/title column nor searchBy() - the entity picker would find nothing.");
            }

            $validated[] = $type;
        }

        $this->types = $validated;
    }

    public function find(string $model): ?LinkableType
    {
        foreach ($this->types as $type) {
            if ($type->model === $model) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Fan a term out over every type (AND across words, OR across
     * searchable columns); matches carry the entity link action affordance
     * when one is configured.
     *
     * @param  (callable(Model): array{0: bool, 1: string|null})|null  $affordance
     * @return list<array{type: string, id: int|string, display: string, label: string, visible?: bool, url?: string|null}>
     */
    public function search(string $term, ?callable $affordance = null, int $limitPerType = 8, int $cap = 50): array
    {
        $results = [];

        foreach ($this->types as $type) {
            $results = array_merge($results, $type->search($term, $limitPerType, $affordance));

            if (count($results) >= $cap) {
                break;
            }
        }

        foreach ($results as &$row) {
            if (($row['visible'] ?? false) && ($row['url'] ?? null) !== null && ! EntityUrlGuard::isSafe($row['url'])) {
                $row['visible'] = false;
                $row['url'] = null;
            }
        }
        unset($row);

        return array_slice($results, 0, $cap);
    }

    /**
     * Resolve display strings for entity refs - one query per allowed type;
     * refs outside the allow-list get a basename fallback without touching
     * the DB (stale links still render). The $affordance callback, when
     * given, decides visible/url per allowed ref (record is null for a
     * dead one).
     *
     * @param  list<array<array-key, mixed>>  $refs
     * @param  (callable(Model|null): array{0: bool, 1: string|null})|null  $affordance
     * @return array<string, array{display: string, label: string, visible?: bool, url?: string|null}>
     */
    public function resolveRefs(array $refs, ?callable $affordance = null): array
    {
        $allowed = $this->byModel();
        $resolved = [];

        foreach ($this->groupRefIds($refs) as $type => $typeIds) {
            if (! isset($allowed[$type])) {
                foreach ($this->fallbackEntries($type, $typeIds) as $key => $entry) {
                    $resolved[$key] = $entry;
                }

                continue;
            }

            $linkable = $allowed[$type];
            $byKey = $linkable->model::query()->whereKey($typeIds)->get()
                ->keyBy(fn (Model $record): string => (string) $record->getKey());

            foreach ($typeIds as $id) {
                $record = $byKey->get((string) $id) ?? $byKey->get($id);

                $entry = [
                    'display' => $record instanceof Model
                        ? $linkable->displayFor($record)
                        : $linkable->typeName() . ' #' . $id,
                    'label' => $linkable->typeName(),
                ];

                if ($affordance !== null) {
                    [$visible, $url] = $affordance($record);

                    if ($visible && $url !== null && ! EntityUrlGuard::isSafe($url)) {
                        $visible = false;
                        $url = null;
                    }

                    $entry['visible'] = $visible;
                    $entry['url'] = $url;
                }

                $resolved[$type . ':' . $id] = $entry;
            }
        }

        return $resolved;
    }

    /**
     * Types allowed to be created from the picker, per the field's
     * allowEntityCreation policy: false = none, true = every creatable()
     * type, an array = exactly those (each must define a schema, or this
     * throws at dev time).
     *
     * @param  array<array-key, class-string>|bool  $policy
     * @return list<LinkableType>
     */
    public function creatable(array | bool $policy): array
    {
        if ($policy === false) {
            return [];
        }

        $types = array_values(array_filter(
            $this->types,
            fn (LinkableType $type): bool => $type->isCreatable(),
        ));

        if ($policy === true) {
            return $types;
        }

        $byModel = [];
        foreach ($types as $type) {
            $byModel[$type->model] = $type;
        }

        $selected = [];

        foreach ($policy as $model) {
            if (! isset($byModel[$model])) {
                throw new InvalidArgumentException("ImageLabel: [{$model}] is not a creatable linkable type - give it a creatable() schema or linkableTo() it first.");
            }

            $selected[] = $byModel[$model];
        }

        return $selected;
    }

    /**
     * Picker payload: which types exist, how they are named and which can
     * be created from the editor (and under which registered action name).
     *
     * @param  list<LinkableType>  $creatable
     * @return list<array{type: string, label: string, creatable: bool, action: string}>
     */
    public function typesPayload(array $creatable): array
    {
        $models = array_map(fn (LinkableType $type): string => $type->model, $creatable);

        return array_map(
            fn (LinkableType $type): array => [
                'type' => $type->model,
                'label' => $type->typeName(),
                'creatable' => in_array($type->model, $models, true),
                'action' => self::creationActionName($type->model),
            ],
            $this->types,
        );
    }

    public static function creationActionName(string $model): string
    {
        return 'createEntity' . Str::studly(class_basename($model));
    }

    /**
     * @return array<class-string, LinkableType>
     */
    private function byModel(): array
    {
        $out = [];

        foreach ($this->types as $type) {
            $out[$type->model] = $type;
        }

        return $out;
    }

    /**
     * @param  list<array<array-key, mixed>>  $refs
     * @return array<string, list<int|string>>
     */
    private function groupRefIds(array $refs): array
    {
        $ids = [];

        foreach ($refs as $ref) {
            $type = $ref['type'] ?? null;
            $id = $ref['id'] ?? null;

            if (! is_string($type) || $type === '' || $id === null || $id === '') {
                continue;
            }

            $ids[$type][] = is_numeric($id) ? (int) $id : (string) $id;
        }

        return array_map(
            fn (array $typeIds): array => array_values(array_unique($typeIds)),
            $ids,
        );
    }

    /**
     * Off-allow-list refs render with a humanized class-name fallback and
     * no DB touch; unresolvable classes are dropped.
     *
     * @param  list<int|string>  $typeIds
     * @return array<string, array{display: string, label: string}>
     */
    private function fallbackEntries(string $type, array $typeIds): array
    {
        if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
            return [];
        }

        $label = Str::of(class_basename($type))->headline()->title()->toString();

        return array_combine(
            array_map(fn (int | string $id): string => $type . ':' . $id, $typeIds),
            array_map(fn (int | string $id): array => ['display' => $label . ' #' . $id, 'label' => $label], $typeIds),
        );
    }
}
