<?php

namespace Zielu92\FilamentImageLabeler\Forms\Components\Concerns;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use Zielu92\FilamentImageLabeler\Support\AutoAnnotationRunner;
use Zielu92\FilamentImageLabeler\Support\LinkableType;

/**
 * Automatic annotation on an ImageLabel field: the fluent configuration
 * (enableAutoAnnotation, autoAnnotateOnLoad, button label/icon) and the
 * record gating. Detection itself lives in Support\AutoAnnotationRunner;
 * this trait is the field-side binding.
 *
 * Requires ImageLabel (Filament Field): evaluate(), getImageUrl(),
 * isReadOnly(), getRecord(), getModelInstance(), getName(),
 * hasLinkableTypes(), getLinkableTypes().
 */
trait ConfiguresAutoAnnotation
{
    protected bool | Closure $isAutoAnnotateEnabled = false;

    protected bool | Closure $autoAnnotatesOnLoad = false;

    protected bool | Closure $autoAnnotateButton = true;

    protected string | Htmlable | Closure | null $autoAnnotateButtonLabel = null;

    protected string | Closure | null $autoAnnotateButtonIcon = 'heroicon-m-sparkles';

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

        return $model instanceof Model && AutoAnnotationRunner::supports($model);
    }

    /**
     * The field action: run the record's hook via the runner, return
     * pending items for the view. Entity refs outside the field's
     * linkableTo() allow-list are dropped, their shapes kept.
     *
     * @return list<array{id: string, label: string, entity: array{type: string, id: int|string}|null, rect: array<int, float>|null, polygon: list<array<int, float>>|null}>
     */
    public function performAutoAnnotate(Model | array | null $record = null): array
    {
        $model = $record instanceof Model ? $record : $this->resolveAutoAnnotateRecord();

        if (! $model instanceof Model || ! $this->recordSupportsAutoAnnotation($model)) {
            return [];
        }

        $url = $this->getImageUrl();

        if (blank($url)) {
            return [];
        }

        $allowed = array_map(
            fn (LinkableType $type): string => $type->model,
            $this->hasLinkableTypes() ? $this->getLinkableTypes() : [],
        );

        return AutoAnnotationRunner::annotate($model, $url, $allowed);
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
