<?php

namespace Zielu92\FilamentImageLabeler\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Zielu92\FilamentImageLabeler\Models\Annotation;

/**
 * Marks a model as a potential linked entity: shapes can carry an entity
 * link pointing at it. Deleting the model nulls every link pointing at it.
 */
trait LinkableEntity
{
    public static function bootLinkableEntity(): void
    {
        static::deleted(function ($model) {
            Annotation::query()
                ->where('entity_type', $model::class)
                ->where('entity_id', $model->getKey())
                ->update([
                    'entity_type' => null,
                    'entity_id' => null,
                ]);
        });
    }

    /**
     * Annotations (on any annotatable model) whose entity link points here.
     */
    public function linkedAnnotations(): MorphMany
    {
        return $this->morphMany(Annotation::class, 'entity');
    }
}
