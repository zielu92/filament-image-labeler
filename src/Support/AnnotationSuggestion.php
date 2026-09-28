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
     * @var array{0: float, 1: float, 2: float, 3: float}|null
     */
    public readonly ?array $box;

    /**
     * @param  array<array-key, mixed>|null  $box  [x, y, w, h], normalized
     * @param  array<array-key, mixed>|null  $polygon  [[x, y], ...], normalized
     */
    public function __construct(
        public readonly string $label,
        ?array $box = null,
        ?array $polygon = null,
    ) {
        $normBox = null;

        if ($polygon !== null) {
            $points = self::polygonPoints($polygon);
        } elseif ($box !== null) {
            $normBox = self::numericBox($box);
            [$x, $y, $w, $h] = $normBox;
            $points = [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
        } else {
            throw new InvalidArgumentException('An annotation suggestion needs a box or a polygon.');
        }

        $this->box = $normBox;
        $this->points = $points;
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
     * Convert suggestions into "pending" items for the editor: identity,
     * label and normalized geometry. The client scales them against the
     * image size the browser actually has and serializes them in Annotorious'
     * native shape grammar - the server never needs to read the image to
     * place shapes.
     *
     * @param  iterable<int, self|array<array-key, mixed>>  $suggestions
     * @return list<array{id: string, label: string, rect: array<int, float>|null, polygon: list<array<int, float>>|null}>
     */
    public static function toPending(iterable $suggestions): array
    {
        $clamp = fn (float $v): float => round(min(max($v, 0.0), 1.0), 6);

        $pending = [];

        foreach ($suggestions as $suggestion) {
            $suggestion = $suggestion instanceof self ? $suggestion : self::fromArray($suggestion);

            $pending[] = [
                'id' => (string) Str::uuid(),
                'label' => $suggestion->label,
                'rect' => $suggestion->box === null ? null : array_map($clamp, $suggestion->box),
                'polygon' => $suggestion->box === null
                    ? array_map(fn (array $p): array => [$clamp($p[0]), $clamp($p[1])], $suggestion->points)
                    : null,
            ];
        }

        return $pending;
    }

    /**
     * @param  array<array-key, mixed>  $box
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    protected static function numericBox(array $box): array
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

        return $values;
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
}
