<?php

namespace Zielu92\FilamentImageLabeler\Support;

class AnnotationColor
{
    /**
     * Get the color for an annotation ID from a palette.
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
}
