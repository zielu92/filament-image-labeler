<?php

namespace Zielu92\FilamentImageLabeler\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Zielu92\FilamentImageLabeler\Models\Annotation;

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
