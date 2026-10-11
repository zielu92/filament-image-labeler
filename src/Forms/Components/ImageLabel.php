<?php

namespace Zielu92\FilamentImageLabeler\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Model;
use Zielu92\FilamentImageLabeler\Forms\Components\Concerns\ConfiguresAutoAnnotation;
use Zielu92\FilamentImageLabeler\Forms\Components\Concerns\ManagesEntityLinks;
use Zielu92\FilamentImageLabeler\Support\LinkedEntityAction;

class ImageLabel extends Field
{
    use ConfiguresAutoAnnotation;
    use ManagesEntityLinks;

    public const LINKED_ENTITY_ACTION = LinkedEntityAction::NAME;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->default([]);

        $this->registerActions([
            Action::make('autoAnnotate')
                ->action(fn (Model | array | null $record): array => $this->performAutoAnnotate($record)),
            $this->creatableActions(...),
            fn (): array => ($action = $this->linkedEntityAction()) instanceof LinkedEntityAction ? [$action->action()] : [],
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
}
