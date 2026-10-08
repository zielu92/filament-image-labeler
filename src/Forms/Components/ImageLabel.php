<?php

namespace Zielu92\FilamentImageLabeler\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Contracts\Support\Htmlable;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->default([]);

        $this->registerActions([
            Action::make('autoAnnotate')
                ->action(fn (Model | array | null $record): array => $this->performAutoAnnotate($record)),
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
     * grouped-by-type matches.
     *
     * @return list<array{type: string, id: int|string, display: string, label: string}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function searchEntities(string $search): array
    {
        if (blank($search)) {
            return [];
        }

        $results = [];

        foreach ($this->getLinkableTypes() as $type) {
            $results = array_merge($results, $type->search(trim($search)));

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
     *
     * @param  list<array<array-key, mixed>>  $refs
     * @return array<string, array{display: string, label: string}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function resolveEntities(array $refs): array
    {
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
                $record = $byKey->get((string) $id) ?? $byKey->get($id);
                $resolved[$type . ':' . $id] = [
                    'display' => $record instanceof Model
                        ? $linkable->displayFor($record)
                        : $linkable->typeName() . ' #' . $id,
                    'label' => $linkable->typeName(),
                ];
            }
        }

        return $resolved;
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
