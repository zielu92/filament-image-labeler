# Filament Image Labeler

A Filament v5 form field (`ImageLabel`) for annotating images with rectangles and polygons: Annotorious canvas, polymorphic persistence (`HasAnnotations`), optional entity links and opt-in automatic annotation.

- Install: `composer require zielu92/filament-image-labeler && php artisan migrate`. Migrations auto-load — do **not** publish them. Assets ship pre-built (no npm). It is a plain form field, **not** a Filament `Plugin` — do not register it via `->plugins()`.
- IMPORTANT: activate the `filament-image-labeler-development` skill before writing any `ImageLabel`, `HasAnnotations`, `LinkableType`, or `autoAnnotate` code. The stored geometry format and the field-state → `syncAnnotations()` mapping are package-specific and easy to hallucinate.

@verbatim
<code-snippet name="Minimal field" lang="php">
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;

ImageLabel::make('annotations')
    ->image(fn ($record) => $record?->image_url)
    ->live()
    ->columnSpanFull();
</code-snippet>
@endverbatim
