# Filament Image Labeler

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zielu92/filament-image-labeler.svg?style=flat-square)](https://packagist.org/packages/zielu92/filament-image-labeler)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/zielu92/filament-image-labeler/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/zielu92/filament-image-labeler/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/zielu92/filament-image-labeler/fix-php-code-style-issues.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/zielu92/filament-image-labeler/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/zielu92/filament-image-labeler.svg?style=flat-square)](https://packagist.org/packages/zielu92/filament-image-labeler)
[![Scanned by Plumb](https://plumbphp.dev/badges/zielu92/filament-image-labeler/scanned.svg)](https://plumbphp.dev/zielu92/filament-image-labeler)

A Filament plugin for labeling images — draw rectangles and polygons, name them, color them, all inside one form field. Built on [Annotorious](https://annotorious.dev/), with a polymorphic persistence layer so any Eloquent model can keep its annotations.

<img src=".github/assets/screenshot.png" alt="Filament Image Labeler — label editor with toolbar, Labels panel and Label Details panel" width="700" />

## Features

- Annotation canvas with a toolbar under the image: **Select / Rectangle / Polygon / Label**, plus **undo / redo / delete**
- Built-in **Labels panel** — every distinct label gets a row with a color dot, edit and delete
- Built-in **Label Details panel** — rename a label (renames every shape using it) and pick its color
- Label pills rendered over the shapes on the canvas
- Two-way selection: click a shape to load its label, click a row to select the shape
- Polymorphic `annotations` table — attach annotations to any model
- `HasAnnotations` trait with `syncAnnotations()` for easy CRUD
- **Optional entity links** — link a shape to a record in your own app (a `Person`, a `Building`, anything): an inline entity picker in the Label Details panel searches across the types you configure, the linked entity shows as a chip (optionally clickable — a template Action opens its URL or modal), and the first link seeds an empty label with the record's display string
- **Optional automatic annotation** — a model overrides `autoAnnotate()` to turn an image into suggestions (any backend you like: local model, detection API, LLM); the editor gets an **Annotate** button, optionally auto-running when the image loads
- Works with private/public file storage, Filament v5 compatible, translations (en/de/pl)
- [Laravel Boost](https://laravel.com/docs/boost) aware — ships an AI guideline + agent skill so coding agents write correct `ImageLabel` usage (see [AI coding agents](#ai-coding-agents-laravel-boost))

## Installation

```bash
composer require zielu92/filament-image-labeler
php artisan migrate
```

## AI coding agents (Laravel Boost)

This package ships first-class [Laravel Boost](https://laravel.com/docs/boost) resources so AI coding agents (Claude Code, Cursor, etc.) generate correct code for it out of the box:

- `resources/boost/guidelines/core.blade.php` — a short always-on guideline (what the package is, install, and a pointer to the skill).
- `resources/boost/skills/filament-image-labeler-development/` — an on-demand skill with the full field API, the persistence mapping, entity links and automatic annotation.

Boost discovers these automatically. To load them into your app's agent files, run `php artisan boost:install` and tick **zielu92/filament-image-labeler** in the third-party packages prompt (the choice is remembered in `boost.json`; use `php artisan boost:update --discover` on an existing Boost install). Nothing is injected without that opt-in. The skill requires **Laravel Boost 2.x**; the guideline also works on 1.x.

## How It Works

`ImageLabel` is a self-contained form field. Its Livewire state is an array of shapes:

```json
[
    {
        "id": "uuid",
        "target": {
            "selector": {
                "type": "RECTANGLE",
                "geometry": { "x": 693, "y": 88, "w": 256, "h": 160, "bounds": { "minX": 693, "minY": 88, "maxX": 949, "maxY": 248 } }
            }
        },
        "label": "Microcontroller",
        "color": "#ef4444",
        "entity": { "type": "App\\Models\\Part", "id": 12 }
    }
]
```

- `target.selector` is Annotorious' internal geometry — pixel coordinates in the image's natural size; polygons use `{ "type": "POLYGON", "geometry": { "points": [[x, y], ...], "bounds": {...} } }`. Store it as-is, it round-trips.
- `label` is free text; shapes sharing a label share a row in the Labels panel.
- `color` is assigned deterministically from a hash of the label name and can be overridden per label in the UI. Colors are denormalized onto each shape, so the state array is all you need to persist.
- `entity` is the [entity link](#entity-links) — a reference to one record — and only appears on fields configured with `->linkableTo()`.

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
/** @param array<array{id: string, target: array, label: string, color: string, entity?: array{type: string, id: int|string}|null}> $shapes */
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
        'geometry' => ['selector' => ['type' => 'RECTANGLE', 'geometry' => ['x' => 693, 'y' => 88, 'w' => 256, 'h' => 160, 'bounds' => ['minX' => 693, 'minY' => 88, 'maxX' => 949, 'maxY' => 248]]]],
        'metadata' => ['label' => 'Microcontroller', 'color' => '#ef4444'],
    ],
]);
```

**Behavior:** creates missing rows (matched by `annotation_id`), updates existing ones, deletes rows whose `annotation_id` is absent, `[]` clears all. `geometry` accepts an array or JSON string; `metadata` is nullable and yours to shape. Entity links: pass `entity_type` + `entity_id` as a pair; an explicit `null` clears the link, omitting the keys leaves the stored link untouched, and a half pair (one set, one null) is coerced to a full clear.

## Entity links

A shape can point at one record from your own app — a `Person`, a `Building`, a `Place` — beside its free-text label. The link survives label renames and lives in its own `entity_type` / `entity_id` columns, never in `metadata`. Everything below is opt-in: no `linkableTo()` means no picker, no chip, no links.

### 1. Enable the feature

Declare which record types a field allows. Each entry is a `LinkableType`:

```php
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;
use Zielu92\FilamentImageLabeler\Support\LinkableType;

ImageLabel::make('annotations')
    ->image(/* ... */)
    ->linkableTo([
        // Display convention: name column, else title, else "#<id>".
        LinkableType::make(Building::class),

        // Custom display: you must also tell the picker which columns to search.
        LinkableType::make(Person::class)
            ->display(fn (Person $person): string => "{$person->first_name} {$person->last_name}")
            ->searchBy(['first_name', 'last_name']),

        // Override the type heading shown above grouped picker results, and
        // allow creating a record from the picker with a form schema
        // (pass your Filament resource's form here if you have one).
        LinkableType::make(Vendor::class)
            ->typeLabel('Supplier')
            ->creatable(fn () => [TextInput::make('name')->required()]),
    ])
    ->columnSpanFull()
```

| `LinkableType` method | What it does |
|-----------------------|--------------|
| `->display(fn ($record): string)` | How a linked record is shown on the chip and in results. If you set a custom display you **must** also set `searchBy()`, or the picker can't find anything. |
| `->searchBy(array $columns)` | The columns the picker runs indexed `LIKE` matches against. Defaults to `name` / `title` when present. |
| `->typeLabel(string)` | The heading above that type's group in the results list. Defaults to the humanized model name. |
| `->creatable(array\|Closure)` | Lets the picker offer "Create <Type>", opening a modal with your schema and binding the new record to the selected shape. |

`linkableTo()` also takes a `Closure` returning the array, so the allow-list can depend on the record or the user.

### 2. Link shapes in the editor

With the feature on, the Label Details panel grows an **entity picker** — an inline search box (not a modal):

- Type to search every linkable type at once; results are grouped under their type heading. Selecting a row links it to the **currently selected shape**; there is no link without an active shape.
- Linking seeds the shape's label with the record's display string **only when the label is empty**, and colors it like any typed label.
- A linked shape shows an **entity chip** (type + display string) with an unlink `×` on the right; relinking a shape replaces its link.
- `creatable()` types add a "Create <Type>" row in the picker; the new record binds automatically. Which types offer creation is controlled field-wide with `->allowEntityCreation()`: `true` (default) enables every type that has a schema, `false` disables all, and an array of model classes enables exactly those.

### 3. Persist and hydrate the link

The field state carries `entity: ['type' => ..., 'id' => ...]` per shape. Map it onto the `syncAnnotations` keys on save:

```php
/** @param array<array{id: string, target: array, label: string, color: string, entity?: array{type: string, id: int|string}|null}> $shapes */
function annotationRows(array $shapes): array
{
    return collect($shapes)->map(fn ($s) => [
        'annotation_id' => $s['id'],
        'geometry' => $s['target'],
        'metadata' => ['label' => $s['label'] ?? '', 'color' => $s['color'] ?? null],
        'entity_type' => $s['entity']['type'] ?? null,
        'entity_id' => $s['entity']['id'] ?? null,
    ])->all();
}
```

And put it back into the shape on edit so the chip reappears:

```php
$data['annotations'] = $this->record->annotations()->orderBy('id')->get()
    ->map(fn ($ann) => [
        'id' => $ann->annotation_id,
        'target' => $ann->geometry,
        'label' => $ann->metadata['label'] ?? '',
        'color' => $ann->metadata['color'] ?? AnnotationColor::forLabel($ann->metadata['label'] ?? ''),
        'entity' => $ann->entity_type ? ['type' => $ann->entity_type, 'id' => $ann->entity_id] : null,
    ])
    ->values()
    ->all();
```

`syncAnnotations()` treats the pair like any other key: `null` clears a link, omitting the keys leaves the stored link untouched, and a half pair (one set, one null) coerces to a full clear.

### 4. Clean up dangling links

Add the `LinkableEntity` trait to every linkable model. Deleting a record then nulls all links pointing at it (the shape and its label survive):

```php
use Zielu92\FilamentImageLabeler\Concerns\LinkableEntity;

class Person extends Model
{
    use LinkableEntity;
}
```

Without the trait links dangle — the editor still renders them (with a class-name fallback) but nothing cleans them up. Stale links to types removed from `linkableTo()` keep rendering too; only the picker stops offering them.

### 5. Run an action from the link (entity link action)

Give linked entities a click action — open a record's show page, or run a modal right inside the editor — with **one** template `Filament\Actions\Action` whose closures receive the linked record:

```php
use Filament\Actions\Action;

ImageLabel::make('annotations')
    ->image(/* ... */)
    ->linkableTo([LinkableType::make(Place::class)])
    ->entityAction(
        Action::make('viewPlace')
            ->url(fn (Place $record): ?string => route('places.show', $record))
    )
```

How it behaves:

- **The chip is the action.** In edit mode, when the action is visible for the record the whole entity chip becomes clickable (mouse + `Enter`/`Space`); the unlink `×` keeps its own slot and stops propagation. Return `false` from `->visible(fn ($record) => ...)` for a record and the chip is plain — no affordance to run.
- **URL or modal.** A non-null `->url()` for the record navigates in the same tab; without a URL the Action's own modal config (`->schema()`, `->modalHeading()`, `->modalContent()`, …) opens over the editor. A single template covers every type — branch on the record inside the closures. `Action::make()` needs a name for identification; the editor registers it internally as `openLinkedEntity` (one action per field).
- **Auto-created and auto-annotated links** pick up the same affordance as soon as their display strings resolve.

**Read-only mode.** On a `readOnly()` field there is no Details panel or chip, so the affordance moves to the canvas: a single click on a linked, actionable shape runs its action (navigate, or open the modal); other shapes keep the hover-tooltip-only behavior. Link the field up exactly as in edit mode — the click handler activates on shapes whose link resolves to a visible action:

```php
ImageLabel::make('annotations')
    ->image(fn ($record) => $record->getFirstMedia()?->getUrl())
    ->readOnly()
    ->dehydrated(false)
    ->linkableTo([LinkableType::make(Place::class)])
    ->entityAction(
        Action::make('viewPlace')
            ->url(fn (Place $place): ?string => route('places.show', $place))
            // Or an in-editor modal instead of a URL:
            // ->schema(fn (Place $place) => [ /* read-only display components */ ])
            // ->modalHeading(fn (Place $place) => $place->name)
    )
    ->columnSpanFull()
```

**Guards.** Clicks are re-validated server-side every mount: the type must be in `linkableTo()` and the record must still exist — a violation throws, and stale links simply render with no affordance. The resolved `url()` is cached per session mount, so a mid-session rename shows the old href until reload; the modal path is always fresh (the record is re-resolved on open).

## Automatic annotation

Let a model label its own images. The package defines only *that* it happens and *what shape the answer has* — how you find things in the image is entirely yours (local model, hosted detector, vision LLM, hardcoded test data).

**1. Override the hook on your model.** The contract: return a list of `AnnotationSuggestion` **DTOs** (`label` + normalized `box`/`polygon`). The trait default returns `null`, which keeps the feature off:

```php
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;

class Photo extends Model
{
    use HasAnnotations;

    /**
     * @return list<AnnotationSuggestion>|null  one DTO per finding
     */
    public function autoAnnotate(string $url, ?string $path): ?array
    {
        // $url  - the image URL the editor currently displays
        // $path - a local temp file for that URL, when the package could fetch it (null otherwise)
        // Coordinates are normalized: fractions of the image's width/height.

        return [
            AnnotationSuggestion::box('USB Port', 0.42, 0.11, 0.18, 0.09),
            AnnotationSuggestion::polygon('Heatsink', [[0.1, 0.1], [0.3, 0.12], [0.28, 0.4]]),
        ];
    }
}
```

`AnnotationSuggestion` is the DTO the package defines (`src/Support/AnnotationSuggestion.php`): immutable, validates its geometry, exposes `label` + normalized `points`. Build it with `::box($label, $x, $y, $w, $h)` / `::polygon($label, [[x, y], ...])`. A plain array `['label' => ..., 'box' => ...]` is accepted too — the package converts it with `AnnotationSuggestion::fromArray()` — so JSON straight from a detection API works without ceremony.

**2. Choose what does the thinking — it's your method, per model.** The package never calls anything itself, so every model can annotate completely differently: a YOLO endpoint here, a face-detection service there, an LLM somewhere else, an ONNX runtime in-process, a python sidecar, hardcoded fixtures in tests. Models share a strategy via a trait/base class, or branch inside the hook by whatever you know about the record:

```php
use Illuminate\Support\Facades\Http;

class Photo extends Model
{
    use HasAnnotations;

    public function autoAnnotate(string $url, ?string $path): ?array
    {
        return match (true) {
            $this->isPortrait() => $this->detectFaces($url),
            $this->isHardware() => $this->detectParts($url),
            default => null,                       // this photo opts out
        };
    }

    protected function detectParts(string $url): ?array
    {
        // Any HTTP detector will do - map its response into suggestions.
        $detections = Http::timeout(20)->post('https://detector.test/v1/detect', [
            'image' => $url,
            'classes' => $this->source?->part_labels ?? ['*'],
        ])->json('detections', []);

        return array_map(
            fn (array $d): AnnotationSuggestion => AnnotationSuggestion::box(
                label: $d['class'],
                x: $d['bbox']['x1'],
                y: $d['bbox']['y1'],
                w: $d['bbox']['x2'] - $d['bbox']['x1'],
                h: $d['bbox']['y2'] - $d['bbox']['y1'],
            ),
            $detections,
        );
    }
}
```

Return `null` (or an empty array) when there is nothing to report — the field simply gets no shapes.

**3. Enable the field:**

```php
ImageLabel::make('annotations')
    ->image(/* ... */)
    ->enableAutoAnnotation()   // arms the feature for this field
    ->autoAnnotateOnLoad()     // optional: run when the image appears
    ->autoAnnotateButton(false) // optional: hide the toolbar button (load-only)
    ->columnSpanFull()
```

**What happens:** clicking Annotate (or image load, with `autoAnnotateOnLoad()`) calls your `autoAnnotate()`, then the editor turns every suggestion into a normal shape right there on the canvas — the normalized points are scaled against the image the browser displays, so placement works for any URL, including protected/private ones. From there it *is* manual work: keep editing, undo, save through `syncAnnotations()` as usual. Results are never silently replaced on re-run; new shapes are appended (a `multiple(false)` field replaces).

Notes:

- The button renders only when the field is enabled **and** the record actually overrides the hook; read-only fields never annotate.
- Execution is synchronous with a spinner; a slow backend can hit request timeouts — that's your method's contract to keep snappy (queued execution may come later).
- If your method throws, the editor shows the error under the toolbar and leaves your shapes untouched.
- `$path`: the package hands your method a local file when it can get one *without* requesting your own server — plain paths, public `/storage/...` URLs, remote http(s) downloads (max 20 MB). Same-host URLs (e.g. Livewire's local upload preview route) yield `null`; write your method so the URL alone is enough when that matters.
- Security: remote `$path` downloads refuse destinations that resolve to private, loopback, link-local (e.g. the cloud metadata address `169.254.169.254`) or reserved IP ranges, and never follow redirects — so an app that builds image URLs from user-controlled state can't turn the fetch into an SSRF probe. If your images genuinely live on an internal host, opt in via `config('filament-image-labeler.allow_private_image_hosts')` (publishable config file, env `IMAGE_LABELER_ALLOW_PRIVATE_IMAGE_HOSTS`). Results are also discarded if the image is swapped or cleared mid-request.

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
| `->linkableTo(array\|Closure $types)` | Enable entity links for these `LinkableType`s | off |
| `->allowEntityCreation(array\|bool\|Closure)` | Which linkable types offer in-picker record creation (needs `creatable()`) | `true` |
| `->entityAction(Action\|Closure\|null)` | Entity link action run from the chip / read-only canvas click | off |
| `->enableAutoAnnotation(bool\|Closure)` | Show the Annotate button (needs an `autoAnnotate()` override on the model) | `false` |
| `->autoAnnotateOnLoad(bool\|Closure)` | Also run automatic annotation when the image (re)loads | `false` |
| `->autoAnnotateButton(bool\|Closure)` | Show the toolbar button at all (turn off for hands-off, load-only annotation) | `true` |
| `->autoAnnotateButtonLabel(string\|Closure\|null)` | Button text | `'Annotate'` (translated) |
| `->autoAnnotateButtonIcon(string\|Closure\|null)` | Button icon; `null` for none | `'heroicon-m-sparkles'` |

### Read-only display

To show a saved photo's annotations without editing — e.g. on a view page or anywhere a form field fits — hydrate the same state and mark the field read-only:

```php
ImageLabel::make('annotations')
    ->image(fn ($record) => $record->getFirstMedia()?->getUrl())
    ->readOnly()
    ->dehydrated(false)
    ->columnSpanFull()
```

Shapes are drawn in their label colors; hovering a shape shows a tooltip with its label and color. No drawing, selection, or panels — except that an [entity link action](#5-run-an-action-from-the-link-entity-link-action) turns a single click on a linked shape into a navigate-or-modal.

In edit mode the canvas, toolbar and the Labels / Label Details panels appear only while an image is set — the field owns label state internally, no repeater wiring needed.

## Testing

```php
public function test_sync_creates_annotations(): void
{
    $photo = Photo::factory()->create();

    $photo->syncAnnotations([
        [
            'annotation_id' => 'ann-1',
            'geometry' => ['selector' => ['type' => 'RECTANGLE', 'geometry' => ['x' => 10, 'y' => 20, 'w' => 100, 'h' => 50, 'bounds' => ['minX' => 10, 'minY' => 20, 'maxX' => 110, 'maxY' => 70]]]],
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
