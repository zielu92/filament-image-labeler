<?php

namespace Zielu92\FilamentImageLabeler\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Renderless;
use Throwable;
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;
use Zielu92\FilamentImageLabeler\Support\FetchesImage;
use Zielu92\FilamentImageLabeler\Support\LinkableType;

class ImageLabel extends Field
{
    public const LINKED_ENTITY_ACTION = 'openLinkedEntity';

    public const DEFAULT_PALETTE = [
        '#ef4444', '#3b82f6', '#10b981', '#f59e0b',
        '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
        '#f97316', '#6366f1', '#14b8a6', '#e11d48',
    ];

    protected string $view = 'filament-image-labeler::image-labeler';

    protected string | Closure | null $imageUrl = null;

    protected bool | Closure $isMultiple = true;

    protected bool | Closure $isSquareEnabled = true;

    protected bool | Closure $isPolygonEnabled = true;

    protected bool | Closure $isClearEnabled = true;

    protected bool | Closure $isReadOnly = false;

    protected array | Closure | null $colorPalette = null;

    protected bool | Closure $isAutoAnnotateEnabled = false;

    protected bool | Closure $autoAnnotatesOnLoad = false;

    protected bool | Closure $autoAnnotateButton = true;

    protected string | Htmlable | Closure | null $autoAnnotateButtonLabel = null;

    protected string | Closure | null $autoAnnotateButtonIcon = 'heroicon-m-sparkles';

    /** @var array<array-key, mixed> | Closure | null */
    protected array | Closure | null $linkableTypes = null;

    /** @var list<LinkableType>|null */
    protected ?array $resolvedLinkableTypes = null;

    /** @var array<array-key, class-string>|bool | Closure */
    protected array | bool | Closure $entityCreation = true;

    protected Action | Closure | null $entityAction = null;

    protected ?Action $entityActionInstance = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->default([]);

        $this->registerActions([
            Action::make('autoAnnotate')
                ->action(fn (Model | array | null $record): array => $this->performAutoAnnotate($record)),
            fn (): array => $this->creatableActions(),
            fn (): array => $this->linkedEntityAction() !== null ? [$this->linkedEntityAction()] : [],
        ]);
    }

    public function image(string | Closure $url): static
    {
        $this->imageUrl = $url;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->evaluate($this->imageUrl);
    }

    public function multiple(bool | Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function enableSquare(bool | Closure $condition = true): static
    {
        $this->isSquareEnabled = $condition;

        return $this;
    }

    public function isSquareEnabled(): bool
    {
        return (bool) $this->evaluate($this->isSquareEnabled);
    }

    public function enablePolygon(bool | Closure $condition = true): static
    {
        $this->isPolygonEnabled = $condition;

        return $this;
    }

    public function isPolygonEnabled(): bool
    {
        return (bool) $this->evaluate($this->isPolygonEnabled);
    }

    public function enableClear(bool | Closure $condition = true): static
    {
        $this->isClearEnabled = $condition;

        return $this;
    }

    public function isClearEnabled(): bool
    {
        return (bool) $this->evaluate($this->isClearEnabled);
    }

    public function readOnly(bool | Closure $condition = true): static
    {
        $this->isReadOnly = $condition;

        return $this;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->evaluate($this->isReadOnly);
    }

    public function coloredAnnotations(array | Closure | null $palette = null): static
    {
        $this->colorPalette = $palette;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getColorPalette(): array
    {
        $palette = $this->evaluate($this->colorPalette);

        return empty($palette) ? static::DEFAULT_PALETTE : array_values($palette);
    }

    /**
     * Enable entity links: shapes on this field may be linked to records of
     * the given types. Each entry is a configured LinkableType.
     *
     * @param  array<array-key, LinkableType>|Closure  $types
     */
    public function linkableTo(array | Closure $types): static
    {
        $this->linkableTypes = $types;
        $this->resolvedLinkableTypes = null;

        return $this;
    }

    public function hasLinkableTypes(): bool
    {
        return $this->linkableTypes !== null;
    }

    /**
     * @return list<LinkableType>
     */
    public function getLinkableTypes(): array
    {
        if ($this->resolvedLinkableTypes !== null) {
            return $this->resolvedLinkableTypes;
        }

        $types = $this->evaluate($this->linkableTypes) ?? [];

        if ($types === []) {
            throw new InvalidArgumentException('ImageLabel: linkableTo() needs at least one LinkableType.');
        }

        $seen = [];

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
        }

        return $this->resolvedLinkableTypes = array_values($types);
    }

    /**
     * Entity picker search: fan the term out over every linkable type
     * (indexed LIKE per searchable column, AND across words). Returns
     * grouped-by-type matches, each carrying the entity link action
     * affordance when one is configured.
     *
     * @return list<array{type: string, id: int|string, display: string, label: string, visible?: bool, url?: string|null}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function searchEntities(string $search): array
    {
        if (blank($search) || ! $this->hasLinkableTypes()) {
            return [];
        }

        $action = $this->linkedEntityAction();
        $affordance = $action === null
            ? null
            : fn (Model $record): array => $this->linkedEntityAffordance($action, $record);

        $results = [];

        foreach ($this->getLinkableTypes() as $type) {
            $results = array_merge($results, $type->search(trim($search), 8, $affordance));

            if (count($results) >= 50) {
                break;
            }
        }

        return array_slice($results, 0, 50);
    }

    /**
     * Resolve display strings for entity refs referenced by current shapes -
     * one query per type, refs outside the allow-list get a basename
     * fallback without touching the DB (stale links still render).
     * With an entityAction() configured, allowed+existing records also
     * carry the per-ref affordance: `visible` (bool) and `url` (?string),
     * server-evaluated from the template's closures.
     *
     * @param  list<array<array-key, mixed>>  $refs
     * @return array<string, array{display: string, label: string, visible?: bool, url?: string|null}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function resolveEntities(array $refs): array
    {
        if (! $this->hasLinkableTypes()) {
            return [];
        }

        $types = [];
        $ids = [];

        foreach ($this->getLinkableTypes() as $type) {
            $types[$type->model] = $type;
        }

        foreach ($refs as $ref) {
            $type = $ref['type'] ?? null;
            $id = $ref['id'] ?? null;

            if (! is_string($type) || $type === '' || $id === null || $id === '') {
                continue;
            }

            $ids[$type][] = is_numeric($id) ? (int) $id : (string) $id;
        }

        $action = $this->linkedEntityAction();
        $resolved = [];

        foreach ($ids as $type => $typeIds) {
            $typeIds = array_values(array_unique($typeIds));

            if (! isset($types[$type])) {
                if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
                    continue;
                }

                $label = Str::of(class_basename($type))->headline()->title()->toString();

                foreach ($typeIds as $id) {
                    $resolved[$type . ':' . $id] = ['display' => $label . ' #' . $id, 'label' => $label];
                }

                continue;
            }

            $linkable = $types[$type];
            $found = $linkable->model::query()->whereKey($typeIds)->get();
            $byKey = $found->keyBy(fn (Model $record): string => $record->getKey());

            foreach ($typeIds as $id) {
                $record = $this->findLinked($byKey, $id);
                $entry = [
                    'display' => $record instanceof Model
                        ? $linkable->displayFor($record)
                        : $linkable->typeName() . ' #' . $id,
                    'label' => $linkable->typeName(),
                ];

                if ($action !== null) {
                    [$entry['visible'], $entry['url']] = $this->linkedEntityAffordance($action, $record);
                }

                $resolved[$type . ':' . $id] = $entry;
            }
        }

        return $resolved;
    }

    /**
     * @param  EloquentCollection<array-key, Model>  $byKey
     */
    protected function findLinked(EloquentCollection $byKey, int | string $id): ?Model
    {
        return $byKey->get((string) $id) ?? $byKey->get($id);
    }

    /**
     * Evaluate the template's visibility and url for one record; no
     * affordance at all when the link is stale or off the allow-list.
     *
     * @return array{0: bool, 1: string|null}
     */
    protected function linkedEntityAffordance(Action $action, ?Model $record): array
    {
        if (! $record instanceof Model) {
            return [false, null];
        }

        $action->record($record);

        try {
            return [$action->isVisible(), $action->getUrl()];
        } finally {
            $action->record(null);
        }
    }

    /**
     * The entity link action: one template Action whose record-aware
     * closures run per linked entity. Registered once under
     * LINKED_ENTITY_ACTION; mounts with the clicked record injected from
     * the click's {type,id} args. A non-null url() for a record navigates
     * same tab; modal config opens the in-editor modal; visible() false
     * hides the affordance for that record.
     */
    public function entityAction(Action | Closure | null $action): static
    {
        $this->entityAction = $action;
        $this->entityActionInstance = null;

        return $this;
    }

    /**
     * The prepared wrapper: template re-named to the internal key, record
     * injected (and re-validated) at mount. Stable instance so per-record
     * closure evaluation in resolveEntities shares one visibility cache.
     */
    public function linkedEntityAction(): ?Action
    {
        if ($this->entityActionInstance !== null) {
            return $this->entityActionInstance;
        }

        if ($this->entityAction === null) {
            return null;
        }

        $template = $this->evaluate($this->entityAction);

        if (! $template instanceof Action) {
            throw new InvalidArgumentException('ImageLabel: entityAction() accepts a Filament Action.');
        }

        $inner = $template->getMountUsing();

        $this->entityActionInstance = $template
            ->name(static::LINKED_ENTITY_ACTION)
            ->mountUsing(function (Action $action, array $arguments) use ($inner): void {
                $action->record($this->resolveLinkedEntityRecord($arguments));

                $action->evaluate($inner, ['action' => $action, 'arguments' => $arguments]);
            });

        return $this->entityActionInstance;
    }

    /**
     * Trust boundary: the clicked {type,id} must be in the field's
     * linkableTo() allow-list and the record must exist.
     */
    protected function resolveLinkedEntityRecord(array $arguments): Model
    {
        $type = $arguments['type'] ?? null;
        $id = $arguments['id'] ?? null;

        $allowed = array_filter(
            $this->hasLinkableTypes() ? $this->getLinkableTypes() : [],
            fn (LinkableType $linkable): bool => $linkable->model === $type,
        );

        $linkable = reset($allowed);

        if (! $linkable instanceof LinkableType || $id === null || $id === '') {
            throw new InvalidArgumentException('ImageLabel: entity link action called for a type outside linkableTo().');
        }

        /** @var class-string<Model> $model */
        $model = $linkable->model;

        $record = $model::query()->find(is_numeric($id) ? (int) $id : $id);

        if (! $record instanceof Model) {
            throw new InvalidArgumentException("ImageLabel: entity link action record [{$model}:#{$id}] does not exist.");
        }

        return $record;
    }

    /**
     * Field-level control over in-editor record creation:
     *  - false: never offer creation,
     *  - true (default): offer it for every linkable type that defines a
     *    creatable() schema,
     *  - array of model classes: exactly these types (each must define a
     *    creatable() schema, or this throws at dev time).
     *
     * @param  array<array-key, class-string>|bool|Closure  $types
     */
    public function allowEntityCreation(array | bool | Closure $types = true): static
    {
        $this->entityCreation = $types;

        return $this;
    }

    /**
     * @return list<LinkableType>
     */
    protected function creatableTypes(): array
    {
        if (! $this->hasLinkableTypes()) {
            return [];
        }

        $policy = $this->evaluate($this->entityCreation);

        if ($policy === false) {
            return [];
        }

        $types = array_values(array_filter(
            $this->getLinkableTypes(),
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
     * Picker payload for the editor: which linkable types exist, how they
     * are named, which can be created from the editor (and under which
     * registered action name), and how to run the entity link action.
     *
     * @return list<array{type: string, label: string, creatable: bool, action: string}>
     */
    public function getEntityTypesPayload(): array
    {
        if (! $this->hasLinkableTypes()) {
            return [];
        }

        $creatable = array_map(
            fn (LinkableType $type): string => $type->model,
            $this->creatableTypes(),
        );

        return array_map(
            fn (LinkableType $type): array => [
                'type' => $type->model,
                'label' => $type->typeName(),
                'creatable' => in_array($type->model, $creatable, true),
                'action' => $this->creationActionName($type->model),
            ],
            $this->getLinkableTypes(),
        );
    }

    /**
     * Entity link action config for the editor: registered action name,
     * the template label as chip aria-label when string-resolvable, and
     * the translation fallback.
     *
     * @return array{name: string, label: string}|null
     */
    public function getEntityActionPayload(): ?array
    {
        $action = $this->linkedEntityAction();

        if ($action === null) {
            return null;
        }

        $label = $action->getLabel();

        return [
            'name' => static::LINKED_ENTITY_ACTION,
            'label' => is_string($label) ? $label : __('filament-image-labeler::image-labeler.entity.view'),
        ];
    }

    public function creationActionName(string $model): string
    {
        return 'createEntity' . Str::studly(class_basename($model));
    }

    /**
     * One CreateAction per type enabled by allowEntityCreation(); the action
     * returns the created ref so the editor can bind it to the active shape.
     *
     * @return list<Action>
     */
    protected function creatableActions(): array
    {
        $actions = [];

        foreach ($this->creatableTypes() as $type) {
            $actions[] = CreateAction::make($this->creationActionName($type->model))
                ->label(__('filament-image-labeler::image-labeler.entity.create') . ' ' . $type->typeName())
                ->model($type->model)
                ->schema($type->getCreationSchema())
                ->action(function (CreateAction $action, array $data): array {
                    /** @var class-string<Model> $model */
                    $model = $action->getModel();

                    $record = $model::create($data);

                    $action->success();

                    return [
                        'type' => $model,
                        'id' => $record->getKey(),
                    ];
                });
        }

        return $actions;
    }

    public function enableAutoAnnotation(bool | Closure $condition = true): static
    {
        $this->isAutoAnnotateEnabled = $condition;

        return $this;
    }

    public function isAutoAnnotateEnabled(): bool
    {
        return (bool) $this->evaluate($this->isAutoAnnotateEnabled);
    }

    public function autoAnnotateOnLoad(bool | Closure $condition = true): static
    {
        $this->autoAnnotatesOnLoad = $condition;

        return $this;
    }

    public function isAutoAnnotateOnLoad(): bool
    {
        return $this->isAutoAnnotateEnabled() && (bool) $this->evaluate($this->autoAnnotatesOnLoad);
    }

    public function autoAnnotateButton(bool | Closure $condition = true): static
    {
        $this->autoAnnotateButton = $condition;

        return $this;
    }

    public function showsAutoAnnotateButton(): bool
    {
        return $this->isAutoAnnotateEnabled() && ! $this->isReadOnly() && (bool) $this->evaluate($this->autoAnnotateButton);
    }

    public function autoAnnotateButtonLabel(string | Htmlable | Closure | null $label = null): static
    {
        $this->autoAnnotateButtonLabel = $label ?? __('filament-image-labeler::image-labeler.tools.annotate');

        return $this;
    }

    public function getAutoAnnotateButtonLabel(): string | Htmlable
    {
        return $this->evaluate($this->autoAnnotateButtonLabel)
            ?? __('filament-image-labeler::image-labeler.tools.annotate');
    }

    public function autoAnnotateButtonIcon(string | Closure | null $icon = null): static
    {
        $this->autoAnnotateButtonIcon = $icon;

        return $this;
    }

    public function getAutoAnnotateButtonIcon(): string | Htmlable | null
    {
        return $this->evaluate($this->autoAnnotateButtonIcon);
    }

    /** Field enabled, not read-only, and the record overrides autoAnnotate(). */
    public function recordSupportsAutoAnnotation(Model | array | null $record = null): bool
    {
        if (! $this->isAutoAnnotateEnabled() || $this->isReadOnly()) {
            return false;
        }

        $model = $record instanceof Model ? $record : $this->resolveAutoAnnotateRecord();

        return $this->autoAnnotateModel($model) !== null;
    }

    /**
     * The field action: run the record's hook, return pending items for the view.
     * Entity refs outside the field's linkableTo() allow-list are dropped,
     * their shapes kept.
     *
     * @return list<array{id: string, label: string, entity: array{type: string, id: int|string}|null, rect: array<int, float>|null, polygon: list<array<int, float>>|null}>
     */
    public function performAutoAnnotate(Model | array | null $record = null): array
    {
        $model = $record instanceof Model ? $record : $this->resolveAutoAnnotateRecord();

        if ($model === null || ! $this->recordSupportsAutoAnnotation($model)) {
            return [];
        }

        $url = $this->getImageUrl();

        if (blank($url)) {
            return [];
        }

        $path = FetchesImage::localize($url);

        if (! method_exists($model, 'autoAnnotate')) {
            return [];
        }

        $suggestions = $model->autoAnnotate($url, $path);

        if ($suggestions === null) {
            return [];
        }

        $pending = AnnotationSuggestion::toPending($suggestions);

        $allowed = array_map(
            fn (LinkableType $type): string => $type->model,
            $this->hasLinkableTypes() ? $this->getLinkableTypes() : [],
        );

        foreach ($pending as &$item) {
            if (! in_array($item['entity']['type'] ?? null, $allowed, true)) {
                $item['entity'] = null;
            }
        }

        return $pending;
    }

    /** hasCustomAutoAnnotation() exists only on the trait - method_exists doubles as the trait check (PHP has no instanceof for traits). */
    protected function autoAnnotateModel(?Model $model): ?Model
    {
        if ($model === null || ! method_exists($model, 'hasCustomAutoAnnotation')) {
            return null;
        }

        return $model->hasCustomAutoAnnotation() ? $model : null;
    }

    /**
     * Best-effort record lookup, usable with or without a bound form.
     */
    protected function resolveAutoAnnotateRecord(): ?Model
    {
        try {
            $record = $this->getRecord() ?? $this->getModelInstance();
        } catch (Throwable $e) {
            Log::warning('ImageLabel: could not resolve auto-annotate record.', [
                'field' => $this->getName(),
                'exception' => $e,
            ]);

            return null;
        }

        return $record instanceof Model ? $record : null;
    }
}
