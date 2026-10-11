<?php

namespace Zielu92\FilamentImageLabeler\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Annotation extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'annotation_id',
        'geometry',
        'metadata',
        'entity_type',
        'entity_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'metadata' => 'array',
        ];
    }

    public function annotatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The linked entity: the application record this shape's annotation
     * points at, or null when the shape carries no entity link.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
