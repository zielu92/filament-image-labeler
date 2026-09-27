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
     * Automatic annotation hook. The feature is off while this stays as-is:
     * override it in your model to report what you find in an image - use any
     * technique you like (local model, cloud API, hardcoded demo...).
     *
     * @param  string  $url  the image URL the field currently displays
     * @param  string|null  $path  a locally readable file for that URL, when the package could fetch it
     * @return array<int, AnnotationSuggestion|array{label: string, box?: array, polygon?: array}>|null
     *                                                                                                  suggestions with normalized (0..1) geometry, or null for "nothing to report"
     */
    public function autoAnnotate(string $url, ?string $path): ?array
    {
        return null;
    }

    /**
     * Whether the model actually overrides {@see autoAnnotate()} - the package
     * treats the untouched trait default as "automatic annotation is off".
     */
    public function hasCustomAutoAnnotation(): bool
    {
        return (new ReflectionMethod($this, 'autoAnnotate'))->getFileName() !== __DIR__ . '/HasAnnotations.php';
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
