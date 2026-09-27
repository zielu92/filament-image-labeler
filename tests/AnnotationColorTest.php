<?php

namespace Zielu92\FilamentImageLabeler\Tests;

use PHPUnit\Framework\TestCase;
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;
use Zielu92\FilamentImageLabeler\Support\AnnotationColor;

class AnnotationColorTest extends TestCase
{
    public function test_for_label_is_deterministic_and_within_palette(): void
    {
        $first = AnnotationColor::forLabel('Microcontroller');
        $second = AnnotationColor::forLabel('Microcontroller');

        $this->assertSame($first, $second);
        $this->assertContains($first, ImageLabel::DEFAULT_PALETTE);
    }

    public function test_unlabeled_uses_none_color(): void
    {
        $this->assertSame(AnnotationColor::NONE_COLOR, AnnotationColor::forLabel(''));
    }

    public function test_custom_palette_is_respected(): void
    {
        $palette = ['#111111', '#222222'];

        $this->assertContains(AnnotationColor::forLabel('Connector', $palette), $palette);
    }

    public function test_empty_palette_falls_back_safely(): void
    {
        $this->assertSame(AnnotationColor::NONE_COLOR, AnnotationColor::forLabel('Connector', []));
    }
}
