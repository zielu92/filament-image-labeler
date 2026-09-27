# Filament Image Labeler

A Filament plugin for labeling images — draw rectangles and polygons, name them, color them, all inside one form field. Built on [Annotorious](https://annotorious.dev/), with a polymorphic persistence layer so any Eloquent model can keep its annotations.

## Features

- Annotation canvas with a toolbar under the image: **Select / Rectangle / Polygon / Label**, plus **undo / redo / delete**
- Built-in **Labels panel** — every distinct label gets a row with a color dot, edit and delete
- Built-in **Label Details panel** — rename a label (renames every shape using it) and pick its color
- Label pills rendered over the shapes on the canvas
- Two-way selection: click a shape to load its label, click a row to select the shape
- Polymorphic `annotations` table — attach annotations to any model
- `HasAnnotations` trait with `syncAnnotations()` for easy CRUD
- Works with private/public file storage, Filament v5 compatible, translations (en/de/pl)

## Installation

```bash
composer require zielu92/filament-image-labeler
php artisan migrate
```

## How It Works

`ImageLabel` is a self-contained form field. Its Livewire state is an array of shapes:

```json
[
    {
        "id": "uuid",
        "target": { "type": "SpecificTarget", "hasSource": "...", "selector": { "type": "SvgSelector", "value": "<svg>...</svg>" } },
        "label": "Microcontroller",
        "color": "#ef4444"
    }
]
```

- `target` is the W3C Web Annotation geometry from Annotorious (store it as-is, it round-trips).
- `label` is free text; shapes sharing a label share a row in the Labels panel.
- `color` is assigned deterministically from a hash of the label name and can be overridden per label in the UI. Colors are denormalized onto each shape, so the state array is all you need to persist.

## Usage

### 1. Add the trait to your model

```php
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;

class Photo extends Model
{
    use HasAnnotations;
}
```

This gives your model `$photo->annotations()` (morphMany), `$photo->syncAnnotations(array $data)` and automatic cascade delete.

### 2. Add the field to your Filament form

```php
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;

ImageLabel::make('annotations')
    ->image(fn ($get, $record) => /* resolve the current photo URL */)
    ->live()
    ->columnSpanFull()
```

The `image` closure is re-evaluated whenever the form renders, so the canvas follows the form: make the photo field `->live()` and resolve the URL from its state (this also covers freshly uploaded, not-yet-saved files):

```php
->image(fn ($get, $record) => $record?->exists
    ? $record->getFirstMedia()?->getTemporaryUrl(now()->addHour())
    : ($get('photo') instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile
        ? $get('photo')->temporaryUrl()
        : null))
```

As a fallback the field also reacts to a `Livewire::dispatch('image-labeler-update-url', $url)` event. Pass the field's state path as a second argument (`..., $url, 'annotations')`) when a page renders multiple `ImageLabel` fields, so only the matching one updates.

### Keyboard

In edit mode: **Delete** / **Backspace** removes the selected shape(s), **Esc** deselects or cancels an in-progress polygon, and **Ctrl/Cmd+Z** / **Ctrl/Cmd+Y** (or the toolbar arrows) undo/redo.

### 3. Persist on save, hydrate on edit

Map the field state onto `syncAnnotations()` — the app decides what goes into `metadata`:

```php
/** @param array<array{id: string, target: array, label: string, color: string}> $shapes */
function annotationRows(array $shapes): array
{
    return collect($shapes)->map(fn ($s) => [
        'annotation_id' => $s['id'],
        'geometry' => $s['target'],
        'metadata' => ['label' => $s['label'] ?? '', 'color' => $s['color'] ?? null],
    ])->all();
}
```

```php
// CreatePhoto.php
class CreatePhoto extends CreateRecord
{
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->annotationData = annotationRows($data['annotations'] ?? []);
        unset($data['annotations']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncAnnotations($this->annotationData);
    }
}
```

```php
// EditPhoto.php
use Zielu92\FilamentImageLabeler\Support\AnnotationColor;

class EditPhoto extends EditRecord
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['annotations'] = $this->record->annotations()->orderBy('id')->get()
            ->map(fn ($ann) => [
                'id' => $ann->annotation_id,
                'target' => $ann->geometry,
                'label' => $ann->metadata['label'] ?? '',
                'color' => $ann->metadata['color'] ?? AnnotationColor::forLabel($ann->metadata['label'] ?? ''),
            ])
            ->values()
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->annotationData = annotationRows($data['annotations'] ?? []);
        unset($data['annotations']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncAnnotations($this->annotationData);
    }
}
```

`AnnotationColor::forLabel('USB Port')` returns the same color the canvas picks for a label by default — use it when your metadata was saved without an explicit color.

## The `syncAnnotations` Method

```php
$model->syncAnnotations([
    [
        'annotation_id' => 'uuid-from-canvas',
        'geometry' => ['selector' => ['type' => 'SvgSelector', 'value' => '<svg>...</svg>']],
        'metadata' => ['label' => 'Microcontroller', 'color' => '#ef4444'],
    ],
]);
```

**Behavior:** creates missing rows (matched by `annotation_id`), updates existing ones, deletes rows whose `annotation_id` is absent, `[]` clears all. `geometry` accepts an array or JSON string; `metadata` is nullable and yours to shape.

## ImageLabel Configuration

| Method | Description | Default |
|--------|-------------|---------|
| `->image(string\|Closure $url)` | Image URL to annotate | required |
| `->enableSquare(bool\|Closure)` | Show the Rectangle tool button | `true` |
| `->enablePolygon(bool\|Closure)` | Show the Polygon tool button | `true` |
| `->enableClear(bool\|Closure)` | Show the delete/clear toolbar button | `true` |
| `->multiple(bool\|Closure)` | Allow multiple shapes (new shape replaces old when `false`) | `true` |
| `->coloredAnnotations(array\|null $palette)` | Palette used for default label colors | `ImageLabel::DEFAULT_PALETTE` |
| `->readOnly(bool\|Closure $condition)` | Display mode: shapes render, hovering shows the label; no toolbar or panels | `false` |

### Read-only display

To show a saved photo's annotations without editing — e.g. on a view page or anywhere a form field fits — hydrate the same state and mark the field read-only:

```php
ImageLabel::make('annotations')
    ->image(fn ($record) => $record->getFirstMedia()?->getUrl())
    ->readOnly()
    ->dehydrated(false)
    ->columnSpanFull()
```

Shapes are drawn in their label colors; hovering a shape shows a tooltip with its label and color. No drawing, selection, or panels.

In edit mode the Labels and Label Details panels are always rendered — the field owns label state internally, no repeater wiring needed.

## Upgrading from v0.1

The app-side `Repeater` pattern is gone: the field now manages labels/colors itself and its state entries carry `label` and `color`. Old saved data still works — `target` geometry is unchanged; rows without a label in `metadata` simply hydrate as *Unlabeled*.

## Testing

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
