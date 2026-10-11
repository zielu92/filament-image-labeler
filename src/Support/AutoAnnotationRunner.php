<?php

namespace Zielu92\FilamentImageLabeler\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The execution half of automatic annotation: run a model's own
 * autoAnnotate() hook over an image and turn the findings into pending
 * items, dropping entity links outside the field's allow-list. The field
 * keeps the enable/read-only configuration and record resolution; the
 * detection contract lives here.
 */
final class AutoAnnotationRunner
{
    /**
     * Whether this model actually overrides the auto-annotation hook.
     * hasCustomAutoAnnotation() exists only on the trait - method_exists
     * doubles as the trait check (PHP has no instanceof for traits).
     */
    public static function supports(Model $model): bool
    {
        return method_exists($model, 'hasCustomAutoAnnotation') && $model->hasCustomAutoAnnotation();
    }

    /**
     * Run the hook and emit pending items for the editor. Entity refs
     * outside $allowedTypes drop their link, the shape survives.
     *
     * @param  list<class-string>  $allowedTypes
     * @return list<array{id: string, label: string, entity: array{type: string, id: int|string}|null, rect: array<int, float>|null, polygon: list<array<int, float>>|null}>
     */
    public static function annotate(Model $model, string $url, array $allowedTypes): array
    {
        $path = FetchesImage::localize($url);

        if (! method_exists($model, 'autoAnnotate')) {
            return [];
        }

        $suggestions = $model->autoAnnotate($url, $path);

        if ($suggestions === null) {
            return [];
        }

        $pending = AnnotationSuggestion::toPending($suggestions);

        foreach ($pending as &$item) {
            if (! in_array($item['entity']['type'] ?? null, $allowedTypes, true)) {
                $item['entity'] = null;
            }
        }

        return $pending;
    }
}
