<?php

namespace Zielu92\FilamentImageLabeler\Support;

use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;

class AnnotationColor
{
    public const NONE_COLOR = '#6b7280';

    /**
     * Get the color for an arbitrary value (annotation ID or label name) from a palette.
     * Uses the same djb2 hash algorithm as the JavaScript canvas.
     */
    public static function forId(string $id, array $palette): string
    {
        $hash = 0;
        for ($i = 0; $i < strlen($id); $i++) {
            $hash = (($hash << 5) - $hash) + ord($id[$i]);
            $hash = $hash & 0xFFFFFFFF;
            if ($hash >= 0x80000000) {
                $hash -= 0x100000000;
            }
        }

        return $palette[abs($hash) % count($palette)];
    }

    /**
     * Default color for a label name. The canvas assigns this when a label is
     * first used; users may override it per-label in the details panel.
     */
    public static function forLabel(string $label, ?array $palette = null): string
    {
        if ($label === '') {
            return static::NONE_COLOR;
        }

        return static::forId($label, $palette ?? ImageLabel::DEFAULT_PALETTE);
    }
}
