<?php

namespace Zielu92\FilamentImageLabeler\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReflectionMethod;
use Zielu92\FilamentImageLabeler\Models\Annotation;
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;
use Zielu92\FilamentImageLabeler\Support\EntityRef;

trait HasAnnotations
{
    public static function bootHasAnnotations(): void
    {
        static::deleting(function ($model) {
            $model->annotations()->delete();
        });
    }

    public function annotations(): MorphMany
    {
        return $this->morphMany(Annotation::class, 'annotatable');
    }

    /**
     * Automatic annotation hook - override to report what you find in an
     * image ($url, and a local $path when one was obtainable). Returning
     * null keeps the feature off for this model.
     *
     * @return array<int, AnnotationSuggestion|array{label: string, box?: array, polygon?: array, entity?: EntityRef|array}|null>|null
     */
    public function autoAnnotate(string $url, ?string $path): ?array
    {
        return null;
    }

    /** Whether the model overrides autoAnnotate() (the untouched trait default counts as off). */
    public function hasCustomAutoAnnotation(): bool
    {
        return realpath((new ReflectionMethod($this, 'autoAnnotate'))->getFileName())
            !== realpath(__DIR__ . '/HasAnnotations.php');
    }

    /**
     * Sync annotations: create new, update existing, delete removed.
     *
     * Each item must have 'annotation_id' and 'geometry'.
     * Optionally include 'metadata' (array) for any app-specific data.
     * Optionally include 'entity_type' + 'entity_id' as a pair: explicit
     * null clears the entity link, absent keys leave it untouched, and a
     * half pair (one null, one set) is coerced to a full clear.
     *
     * @param  array<int, array{annotation_id: string, geometry: array|string, metadata?: array|null, entity_type?: string|null, entity_id?: int|string|null}>  $data
     */
    public function syncAnnotations(array $data): void
    {
        $incomingIds = collect($data)->pluck('annotation_id')->filter()->all();

        // Delete annotations not in the incoming set
        $this->annotations()
            ->whereNotIn('annotation_id', $incomingIds)
            ->delete();

        // Create or update each incoming annotation
        foreach ($data as $item) {
            $geometry = $item['geometry'] ?? [];
            if (is_string($geometry)) {
                $geometry = json_decode($geometry, true) ?? [];
            }

            $metadata = $item['metadata'] ?? null;
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true);
            }

            $attributes = [
                'geometry' => $geometry,
                'metadata' => $metadata,
            ];

            $hasType = array_key_exists('entity_type', $item);
            $hasId = array_key_exists('entity_id', $item);

            if ($hasType || $hasId) {
                $type = $item['entity_type'] ?? null;
                $id = $item['entity_id'] ?? null;

                if (! $hasType || ! $hasId || $type === null || $id === null) {
                    // Half pair or explicit null: full clear.
                    $type = null;
                    $id = null;
                }

                $attributes['entity_type'] = $type;
                $attributes['entity_id'] = $id;
            }

            $this->annotations()->updateOrCreate(
                ['annotation_id' => $item['annotation_id']],
                $attributes
            );
        }
    }
}
