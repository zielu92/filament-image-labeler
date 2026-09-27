<?php

namespace Zielu92\FilamentImageLabeler\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One finding reported by a model's autoAnnotate() method.
 *
 * Geometry is normalized: every coordinate is a 0..1 fraction of the image
 * width/height, so the model code never needs the image's pixel size.
 * A suggestion carries either a box `[x, y, w, h]` or a polygon
 * `[[x, y], [x, y], ...]` (polygon wins when both are present); either way
 * it is stored as closed `points`.
 */
final class AnnotationSuggestion
{
    /**
     * @var list<array{0: float, 1: float}>
     */
    public readonly array $points;

    /**
     * @param  array<array-key, mixed>|null  $box  [x, y, w, h], normalized
     * @param  array<array-key, mixed>|null  $polygon  [[x, y], ...], normalized
     */
    public function __construct(
        public readonly string $label,
        ?array $box = null,
        ?array $polygon = null,
    ) {
        if ($polygon !== null) {
            $this->points = self::polygonPoints($polygon);
        } elseif ($box !== null) {
            $this->points = self::boxPoints($box);
        } else {
            throw new InvalidArgumentException('An annotation suggestion needs a box or a polygon.');
        }
    }

    /**
     * @param  array{label?: string, box?: array<array-key, mixed>|null, polygon?: array<array-key, mixed>|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) ($data['label'] ?? ''),
            box: $data['box'] ?? null,
            polygon: $data['polygon'] ?? null,
        );
    }

    /**
     * A rectangle from normalized [x, y, w, h] (fractions of image size).
     */
    public static function box(string $label, float $x, float $y, float $w, float $h): self
    {
        return new self(label: $label, box: [$x, $y, $w, $h]);
    }

    /**
     * A closed shape from normalized points: [[x, y], [x, y], ...].
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function polygon(string $label, array $points): self
    {
        return new self(label: $label, polygon: $points);
    }

    /**
     * Convert suggestions into ready ImageLabel field-state shapes.
     *
     * @param  iterable<int, self|array<array-key, mixed>>  $suggestions
     * @return list<array{id: string, target: array<string, mixed>, label: string, color: string}>
     */
    public static function toShapes(iterable $suggestions, int $width, int $height, ?array $palette = null): array
    {
        $shapes = [];

        foreach ($suggestions as $suggestion) {
            $suggestion = $suggestion instanceof self ? $suggestion : self::fromArray($suggestion);

            $points = array_map(
                fn (array $point): array => [
                    round(min(max($point[0], 0.0), 1.0) * $width, 2),
                    round(min(max($point[1], 0.0), 1.0) * $height, 2),
                ],
                $suggestion->points
            );

            $shapes[] = [
                'id' => (string) Str::uuid(),
                'target' => [
                    'selector' => [
                        'type' => 'SvgSelector',
                        'value' => '<svg xmlns="http://www.w3.org/2000/svg"><path d="' . self::pointsToPath($points) . '"/></svg>',
                    ],
                ],
                'label' => $suggestion->label,
                'color' => AnnotationColor::forLabel($suggestion->label, $palette),
            ];
        }

        return $shapes;
    }

    /**
     * @param  array<array-key, mixed>  $box
     * @return list<array{0: float, 1: float}>
     */
    protected static function boxPoints(array $box): array
    {
        if (count($box) !== 4) {
            throw new InvalidArgumentException('A suggestion box must be [x, y, w, h] with numeric, normalized values.');
        }

        $values = [];

        foreach ($box as $value) {
            if (! is_numeric($value)) {
                throw new InvalidArgumentException('A suggestion box must be [x, y, w, h] with numeric, normalized values.');
            }

            $values[] = (float) $value;
        }

        [$x, $y, $w, $h] = $values;

        return [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
    }

    /**
     * @param  array<array-key, mixed>  $polygon
     * @return list<array{0: float, 1: float}>
     */
    protected static function polygonPoints(array $polygon): array
    {
        if (count($polygon) < 3) {
            throw new InvalidArgumentException('A suggestion polygon needs at least 3 points.');
        }

        $points = [];

        foreach ($polygon as $point) {
            if (! is_array($point)) {
                throw new InvalidArgumentException('Each suggestion polygon point must be [x, y] with numeric, normalized values.');
            }

            if (count($point) !== 2) {
                throw new InvalidArgumentException('Each suggestion polygon point must be [x, y] with numeric, normalized values.');
            }

            $values = array_values($point);

            if (! is_numeric($values[0]) || ! is_numeric($values[1])) {
                throw new InvalidArgumentException('Each suggestion polygon point must be [x, y] with numeric, normalized values.');
            }

            $points[] = [(float) $values[0], (float) $values[1]];
        }

        return $points;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     */
    protected static function pointsToPath(array $points): string
    {
        $first = array_shift($points);

        if ($first === null) {
            return '';
        }

        $path = "M {$first[0]},{$first[1]}";

        foreach ($points as $point) {
            $path .= " L {$point[0]},{$point[1]}";
        }

        return $path . ' Z';
    }
}
