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
}
