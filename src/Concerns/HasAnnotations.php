<?php

namespace Zielu92\FilamentImageLabeler\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReflectionMethod;
use Zielu92\FilamentImageLabeler\Models\Annotation;
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;

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
     * @return array<int, AnnotationSuggestion|array{label: string, box?: array, polygon?: array}>|null
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
     *
     * @param  array<int, array{annotation_id: string, geometry: array|string, metadata?: array|null}>  $data
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

            $this->annotations()->updateOrCreate(
                ['annotation_id' => $item['annotation_id']],
                [
                    'geometry' => $geometry,
                    'metadata' => $metadata,
                ]
            );
        }
    }
}
