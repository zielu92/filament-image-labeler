# Filament Image Labeler

A Filament plugin for annotating images with rectangles and polygons. Built on [Annotorious](https://annotorious.dev/), it provides a canvas-based drawing tool as a Filament form field, plus a polymorphic persistence layer so any Eloquent model can have annotations.

## Features

- Draw rectangles and polygons on images
- Stable color assignment per annotation (hash-based, not index-based)
- Polymorphic `annotations` table — attach annotations to any model
- `HasAnnotations` trait with `syncAnnotations()` for easy CRUD
- Flexible `metadata` JSON column — store whatever your app needs
- Works with private/public file storage
- Filament v5 compatible

## Installation

```bash
composer require zielu92/filament-image-labeler
```

Run the migration (auto-loaded from the package, no publishing needed):

```bash
php artisan migrate
```

## How It Works

The package has two parts:

1. **`ImageLabel` form field** — Renders an image with an Annotorious overlay. Users draw shapes on the image. The component emits its state as a JSON array of `[{id, target}]` objects where `id` is a unique annotation identifier and `target` contains the W3C Web Annotation geometry data.

2. **Persistence layer** — An `Annotation` Eloquent model and `HasAnnotations` trait that store annotations in a polymorphic `annotations` table. Each annotation has an `annotation_id` (the Annotorious UUID), `geometry` (JSON), and an optional `metadata` (JSON) column for any app-specific data.

### Database Schema

```
annotations
├── id (bigint, PK)
├── annotatable_type (string)
├── annotatable_id (unsigned bigint)
├── annotation_id (string, unique per parent)
├── geometry (JSON) — Annotorious target/selector data
├── metadata (JSON, nullable) — your app's custom data
└── timestamps
```

## Usage

### 1. Add the trait to your model

```php
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;

class Photo extends Model
{
    use HasAnnotations;
}
```

This gives your model:
- `$photo->annotations()` — morphMany relationship
- `$photo->syncAnnotations(array $data)` — create/update/delete in one call
- Automatic cascade delete when the parent model is deleted

### 2. Add the ImageLabel field to your Filament form

```php
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;

ImageLabel::make('annotations')
    ->image(fn ($record) => $record?->getFirstMediaUrl())
    ->enableSquare()      // Enable rectangle drawing
    ->enablePolygon()     // Enable polygon drawing
    ->enableClear()       // Show "Clear All" button
    ->multiple()          // Allow multiple annotations (default: true)
    ->live()
    ->columnSpanFull()
```

### 3. Sync annotations on save

The `ImageLabel` component emits raw geometry data. Your app decides what metadata to attach. Use Filament's page lifecycle hooks to persist:

```php
// In your CreateRecord page:
class CreatePhoto extends CreateRecord
{
    private array $annotationData = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Extract annotation data before Eloquent save
        $this->annotationData = collect($data['annotation_repeater'] ?? [])
            ->map(fn ($item) => [
                'annotation_id' => $item['annotation_id'],
                'geometry' => $item['geometry'],
                'metadata' => [
                    'title' => $item['title'] ?? null,
                    'category' => $item['category'] ?? null,
                ],
            ])->toArray();

        unset($data['annotations'], $data['annotation_repeater']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncAnnotations($this->annotationData);
    }
}
```

### 4. Hydrate annotations on edit

```php
// In your EditRecord page:
class EditPhoto extends EditRecord
{
    private array $annotationData = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $annotations = $this->record->annotations()->orderBy('id')->get();

        if ($annotations->isNotEmpty()) {
            // Populate the repeater with your app's metadata fields
            $data['annotation_repeater'] = $annotations->map(fn ($ann) => [
                'annotation_id' => $ann->annotation_id,
                'title' => $ann->metadata['title'] ?? '',
                'category' => $ann->metadata['category'] ?? null,
                'geometry' => json_encode($ann->geometry),
            ])->toArray();

            // Populate the canvas with geometry
            $data['annotations'] = $annotations->map(fn ($ann) => [
                'id' => $ann->annotation_id,
                'target' => $ann->geometry,
            ])->toArray();
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->annotationData = collect($data['annotation_repeater'] ?? [])
            ->map(fn ($item) => [
                'annotation_id' => $item['annotation_id'],
                'geometry' => $item['geometry'],
                'metadata' => [
                    'title' => $item['title'] ?? null,
                    'category' => $item['category'] ?? null,
                ],
            ])->toArray();

        unset($data['annotations'], $data['annotation_repeater']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncAnnotations($this->annotationData);
    }
}
```

## Full Example: Repeater with color swatch

A common pattern is to pair the `ImageLabel` with a Filament Repeater that shows editable metadata for each annotation. When `->coloredAnnotations()` is enabled, you can display a matching color swatch in the repeater:

```php
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;
use Zielu92\FilamentImageLabeler\Support\AnnotationColor;

// Define your palette once — pass the same array to both the component and AnnotationColor
$palette = [
    '#ef4444', '#3b82f6', '#10b981', '#f59e0b',
    '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
    '#f97316', '#6366f1', '#14b8a6', '#e11d48',
];

// The annotation canvas with colored annotations
ImageLabel::make('annotations')
    ->image(fn ($record) => $record?->getFirstMediaUrl())
    ->coloredAnnotations($palette)
    ->enableSquare()
    ->enablePolygon()
    ->enableClear()
    ->multiple()
    ->live()
    ->columnSpanFull()
    ->afterStateUpdated(function (?array $state, Set $set, Get $get) {
        $currentRepeater = $get('annotation_repeater') ?? [];
        $existingById = collect($currentRepeater)->keyBy('annotation_id');

        $newRepeater = collect($state ?? [])->map(function ($annotation) use ($existingById) {
            $id = $annotation['id'];
            $existing = $existingById->get($id);

            return [
                'annotation_id' => $id,
                'title' => $existing['title'] ?? '',
                'category' => $existing['category'] ?? null,
                'geometry' => json_encode($annotation['target'] ?? []),
            ];
        })->toArray();

        $set('annotation_repeater', $newRepeater);
    }),

// The metadata repeater with color swatch
Repeater::make('annotation_repeater')
    ->schema([
        Placeholder::make('color_swatch')
            ->hiddenLabel()
            ->content(function (Get $get) use ($palette): HtmlString {
                $id = $get('annotation_id') ?? '';
                $color = AnnotationColor::forId($id, $palette);

                return new HtmlString(
                    '<div style="width: 24px; height: 24px; border-radius: 4px; '
                    . 'background-color: ' . $color . '; '
                    . 'border: 1px solid rgba(0,0,0,0.2);"></div>'
                );
            })
            ->columnSpan(1),
        TextInput::make('title')
            ->label('Title')
            ->columnSpan(3),
        Select::make('category')
            ->options([
                'person' => 'Person',
                'object' => 'Object',
                'location' => 'Location',
            ])
            ->columnSpan(3),
        Hidden::make('annotation_id'),
        Hidden::make('geometry'),
    ])
    ->addable(false)
    ->deletable(true)
    ->reorderable(false)
    ->columns(7)
    ->columnSpanFull()
    ->live()
    ->afterStateUpdated(function (?array $state, Set $set) {
        $canvasState = collect($state ?? [])->map(fn ($item) => [
            'id' => $item['annotation_id'],
            'target' => json_decode($item['geometry'] ?? '[]', true),
        ])->toArray();

        $set('annotations', $canvasState);
    }),
```

The `hashColor` helper (same djb2 algorithm used internally by the package) is available as `AnnotationColor::forId($id, $palette)`.

Each repeater row displays a colored square that matches the annotation's color on the canvas. The color is deterministic — same annotation ID always produces the same color, regardless of order.

When a user deletes a repeater item, the `afterStateUpdated` callback rebuilds the canvas state from the remaining items, effectively removing the annotation from the image as well.

> **Tip:** If you want annotations to only be removable from the canvas (not the repeater), set `->deletable(false)` and rely solely on the "Clear All" button or Annotorious's built-in delete (select + backspace).

## The `syncAnnotations` Method

```php
$model->syncAnnotations([
    [
        'annotation_id' => 'uuid-from-annotorious',
        'geometry' => ['selector' => ['type' => 'SvgSelector', 'value' => '<svg>...</svg>']],
        'metadata' => ['title' => 'My Label', 'score' => 0.95],  // optional
    ],
]);
```

**Behavior:**
- Creates annotations that don't exist yet (matched by `annotation_id`)
- Updates annotations that already exist
- Deletes annotations whose `annotation_id` is no longer in the array
- Passing `[]` deletes all annotations for the model

The `geometry` field accepts either an array or a JSON string (auto-decoded).
The `metadata` field is nullable — pass `null` or omit it if you don't need custom data.

## ImageLabel Configuration

| Method | Description | Default |
|--------|-------------|---------|
| `->image(string\|Closure $url)` | Image URL to annotate | required |
| `->enableSquare(bool $condition)` | Enable rectangle drawing tool | `true` |
| `->enablePolygon(bool $condition)` | Enable polygon drawing tool | `true` |
| `->enableClear(bool $condition)` | Show "Clear All" button | `true` |
| `->multiple(bool $condition)` | Allow multiple annotations | `true` |
| `->coloredAnnotations(array\|null $palette)` | Enable colored annotations with custom palette | `null` (disabled) |

### Colored Annotations

By default, annotations use Annotorious's default styling (white/light blue outlines). To enable distinct colors per annotation, pass a color palette:

```php
// With colors — each annotation gets a unique color from the palette
ImageLabel::make('annotations')
    ->image(fn ($record) => $record?->getFirstMediaUrl())
    ->coloredAnnotations([
        '#ef4444', '#3b82f6', '#10b981', '#f59e0b',
        '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
        '#f97316', '#6366f1', '#14b8a6', '#e11d48',
    ])
    ->enableSquare()
    ->enablePolygon()
    ->live()

// Without colors — uses Annotorious default white/light styling
ImageLabel::make('annotations')
    ->image(fn ($record) => $record?->getFirstMediaUrl())
    ->enableSquare()
    ->enablePolygon()
    ->live()
```

Each annotation gets a deterministic color based on a hash of its ID. The same annotation always gets the same color regardless of order. Colors cycle through the palette when there are more annotations than colors.

To use the color in your repeater (matching the canvas), use the package's `AnnotationColor` helper:

```php
use Zielu92\FilamentImageLabeler\Support\AnnotationColor;

Placeholder::make('color_swatch')
    ->hiddenLabel()
    ->content(function (Get $get) use ($palette): HtmlString {
        $id = $get('annotation_id') ?? '';
        $color = AnnotationColor::forId($id, $palette);

        return new HtmlString(
            '<div style="width: 24px; height: 24px; border-radius: 4px; '
            . 'background-color: ' . $color . ';"></div>'
        );
    })
```

## Working with Private Files

If your images are stored on a private disk, use temporary signed URLs:

```php
ImageLabel::make('annotations')
    ->image(function ($record, Get $get) {
        if ($record && $record->getFirstMedia()) {
            return $record->getFirstTemporaryUrl(now()->addMinutes(30));
        }

        // Handle temporary upload during create...
        return null;
    })
```

## Testing

The package provides the `HasAnnotations` trait which is easily testable:

```php
public function test_sync_creates_annotations(): void
{
    $photo = Photo::factory()->create();

    $photo->syncAnnotations([
        [
            'annotation_id' => 'ann-1',
            'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
            'metadata' => ['label' => 'Person'],
        ],
    ]);

    $this->assertCount(1, $photo->annotations);
    $this->assertEquals('Person', $photo->annotations->first()->metadata['label']);
}
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

This package uses [Annotorious](https://annotorious.dev/) for the image annotation canvas, licensed under the [BSD 3-Clause License](https://github.com/annotorious/annotorious/blob/main/LICENSE).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
