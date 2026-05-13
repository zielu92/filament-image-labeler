<?php

namespace Zielu92\FilamentImageLabeler\Forms\Components;

use Filament\Forms\Components\Field;
use Closure;

class ImageLabel extends Field
{
    protected string $view = 'filament-image-labeler::image-labeler';

    protected string|\Closure|null $imageUrl = null;
    protected bool | Closure $isMultiple = true;

    protected bool | Closure $isSquareEnabled = true;
    protected bool | Closure $isPolygonEnabled = true;
    protected bool | Closure $isClearEnabled = true;
    protected array | Closure | null $colorPalette = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->default([]);
    }

    public function image(string|\Closure $url): static
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

    public function coloredAnnotations(array | Closure | null $palette = null): static
    {
        $this->colorPalette = $palette;

        return $this;
    }

    public function getColorPalette(): ?array
    {
        return $this->evaluate($this->colorPalette);
    }

    public function hasColoredAnnotations(): bool
    {
        return $this->colorPalette !== null;
    }
}
