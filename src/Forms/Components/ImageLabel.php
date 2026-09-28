<?php

namespace Zielu92\FilamentImageLabeler\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;
use Zielu92\FilamentImageLabeler\Support\FetchesImage;

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
     * Show the Annotate toolbar button. Does nothing unless the record's
     * HasAnnotations::autoAnnotate() is overridden as well.
     */
    public function enableAutoAnnotation(bool | Closure $condition = true): static
    {
        $this->isAutoAnnotateEnabled = $condition;

        return $this;
    }

    public function isAutoAnnotateEnabled(): bool
    {
        return (bool) $this->evaluate($this->isAutoAnnotateEnabled);
    }

    /**
     * Also run the model's autoAnnotate() as soon as the image appears
     * (requires enableAutoAnnotation()).
     */
    public function autoAnnotateOnLoad(bool | Closure $condition = true): static
    {
        $this->autoAnnotatesOnLoad = $condition;

        return $this;
    }

    public function isAutoAnnotateOnLoad(): bool
    {
        return $this->isAutoAnnotateEnabled() && (bool) $this->evaluate($this->autoAnnotatesOnLoad);
    }

    /**
     * Show the Annotate toolbar button (default: yes, whenever
     * enableAutoAnnotation() is on). Hide it for hands-off setups where
     * only autoAnnotateOnLoad() should drive annotation.
     */
    public function autoAnnotateButton(bool | Closure $condition = true): static
    {
        $this->autoAnnotateButton = $condition;

        return $this;
    }

    public function showsAutoAnnotateButton(): bool
    {
        return $this->isAutoAnnotateEnabled() && ! $this->isReadOnly() && (bool) $this->evaluate($this->autoAnnotateButton);
    }

    /**
     * Whether the annotate UI is fully wired: field enabled, not read-only,
     * and the record overrides autoAnnotate().
     */
    public function recordSupportsAutoAnnotation(Model | array | null $record = null): bool
    {
        if (! $this->isAutoAnnotateEnabled() || $this->isReadOnly()) {
            return false;
        }

        $model = $record instanceof Model ? $record : $this->resolveAutoAnnotateRecord();

        return $this->autoAnnotateModel($model) !== null;
    }

    /**
     * Runs the record's autoAnnotate() and turns its suggestions into pending
     * items (id + label + normalized rect/polygon); the view scales them
     * against the image it displays. Called by the field action.
     *
     * @return list<array{id: string, label: string, rect: array<int, float>|null, polygon: list<array<int, float>>|null}>
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

        // $path is a best-effort convenience for the model's method (never
        // same-host URLs, which would mean fetching from ourselves).
        $path = FetchesImage::localize($url);

        if (! method_exists($model, 'autoAnnotate')) {
            return [];
        }

        $suggestions = $model->autoAnnotate($url, $path);

        if ($suggestions === null) {
            return [];
        }

        return AnnotationSuggestion::toPending($suggestions);
    }

    /**
     * The record when it uses HasAnnotations and overrides autoAnnotate().
     * hasCustomAutoAnnotation() exists only on the trait, so method_exists
     * doubles as the "uses HasAnnotations" check.
     */
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
        } catch (Throwable) {
            return null;
        }

        return $record instanceof Model ? $record : null;
    }
}
