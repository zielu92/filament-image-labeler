---
name: filament-image-labeler-development
description: Build and configure Filament Image Labeler — the ImageLabel form field for annotating images with rectangles/polygons, persisting them via HasAnnotations + syncAnnotations, linking shapes to app records (entity links), and opt-in automatic annotation. Use when writing or editing any ImageLabel, HasAnnotations, Annotation, LinkableType, or autoAnnotate code in an app using zielu92/filament-image-labeler.
---

# Filament Image Labeler

A Filament v5 form field (`Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel`) for drawing rectangles and polygons on an image, each labeled with free text. Built on Annotorious. Ships a polymorphic persistence layer so any Eloquent model can own annotations. Opt-in extras: **entity links** (a shape points at one record in your app) and **automatic annotation** (the model labels its own images).

PHP `^8.2`, Filament `filament/forms` `^5.0`, Livewire `^4.0`. Namespace root `Zielu92\FilamentImageLabeler\`.

## Vocabulary (use these terms in code, UI copy and comments)

- **Annotation** — one labeled shape. **Label** — the free text on a shape (shapes sharing a label share a Labels-panel row). Not "tag"/"name".
- **Entity link** — the association of a shape to one application record, *beside* its label; survives label renames. Verb is *link*/*unlink*. **Linked entity** = the record pointed at. Not "subject"/"referenced model".
- **Entity picker** — the inline search box in Label Details (not a modal). **Entity chip** — the marker showing a link (not "pill"/"badge").
- **Annotation suggestion** — one finding returned by automatic annotation. **Apply** — a suggestion becoming ordinary editor shapes (no separate proposal state).

## Installation

```bash
composer require zielu92/filament-image-labeler
php artisan migrate
```

- The service provider is auto-discovered via `composer.json`. **Do not** add it to a Filament panel `->plugins()` — it is not a `Plugin`; it is a form field.
- Migrations are auto-loaded (`loadMigrationsFrom`): `create_annotations_table` + `update_annotations_add_entity_links`. Run `php artisan migrate` only. **Never publish these migrations.**
- Assets (`resources/dist/*.js|css`) ship pre-built and are auto-registered as Filament assets. No npm, no asset publish step.
- Optional config publish (SSRF opt-out for automatic-annotation remote fetches): `php artisan vendor:publish --tag=filament-image-labeler-config` → key `allow_private_image_hosts` (env `IMAGE_LABELER_ALLOW_PRIVATE_IMAGE_HOSTS`, default `false`). Only needed when your images live on an internal/private host.
- Translations bundled (en/de/pl), namespace `filament-image-labeler::image-labeler`.

## 1. Model trait: `HasAnnotations`

```php
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;

class Photo extends Model
{
    use HasAnnotations;
}
```

Provides `annotations()` (MorphMany to `Annotation`, morph name `annotatable`), `syncAnnotations(array $data)`, and cascade delete of annotations when the model is deleted. The `Annotation` model has fillable `annotation_id`, `geometry`, `metadata`, `entity_type`, `entity_id`; casts `geometry`/`metadata` to array; relations `annotatable()` (MorphTo) and `entity()` (MorphTo, null when unlinked).

## 2. Form field: `ImageLabel`

`->image(string|Closure $url)` is required (the editor collapses while no image is set). The closure re-evaluates each render, so make the source image field `->live()` and resolve the URL from state — this also covers a freshly uploaded, not-yet-saved file:

```php
ImageLabel::make('annotations')
    ->image(fn ($get, $record) => $record?->exists
        ? $record->getFirstMedia()?->getTemporaryUrl(now()->addHour())
        : ($get('photo') instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile
            ? $get('photo')->temporaryUrl()
            : null))
    ->live()
    ->columnSpanFull();
```

Config methods (all accept `bool|Closure` where a toggle):

| Method | Default |
|--------|---------|
| `->image(string\|Closure $url)` | required |
| `->multiple(bool\|Closure)` | `true` (`false`: new shape replaces old) |
| `->enableSquare(bool\|Closure)` | `true` (Rectangle toolbar button) |
| `->enablePolygon(bool\|Closure)` | `true` (Polygon toolbar button) |
| `->enableClear(bool\|Closure)` | `true` (delete/clear button) |
| `->readOnly(bool\|Closure)` | `false` (shapes + hover tooltip only; no toolbar/panels) |
| `->coloredAnnotations(array\|Closure\|null $palette)` | `ImageLabel::DEFAULT_PALETTE` (12 hex) |

For read-only display, add `->readOnly()->dehydrated(false)` and hydrate state as in §3. Escape hatch to swap the URL from elsewhere: `Livewire::dispatch('image-labeler-update-url', $url)` (pass the field's state path as a second arg when multiple ImageLabel fields share a page).

## 3. Persistence contract (read carefully — the #1 mistake)

The field **never persists itself**. Its Livewire state is an array of shapes; the app maps it to `syncAnnotations()` on save and back on fill. **This is the highest-risk area — copy this exact shape, do not invent a geometry format.**

Field state (per shape): `{ id: string(uuid), target: <geometry>, label: string, color: string, entity?: {type,id}|null }`.

The `target` / stored `geometry` is **Annotorious' internal selector format** — pixel coordinates in the image's natural size. Store it verbatim; it round-trips:

```json
{ "selector": { "type": "RECTANGLE",
  "geometry": { "x": 693, "y": 88, "w": 256, "h": 160,
    "bounds": { "minX": 693, "minY": 88, "maxX": 949, "maxY": 248 } } } }

{ "selector": { "type": "POLYGON",
  "geometry": { "points": [[x, y], ...], "bounds": { "minX", "minY", "maxX", "maxY" } } } }
```

`metadata` is app-owned; the editor round-trips `{ "label": string, "color": "#rrggbb" }`. The server reads none of it — but **the entity link lives in `entity_type`/`entity_id` columns, never in `metadata`.**

Map state → rows, then sync (create):

```php
/** @param array<array{id:string,target:array,label:string,color:string,entity?:array{type:string,id:int|string}|null}> $shapes */
function annotationRows(array $shapes): array
{
    return collect($shapes)->map(fn ($s) => [
        'annotation_id' => $s['id'],
        'geometry'      => $s['target'],
        'metadata'      => ['label' => $s['label'] ?? '', 'color' => $s['color'] ?? null],
        'entity_type'   => $s['entity']['type'] ?? null,
        'entity_id'     => $s['entity']['id'] ?? null,
    ])->all();
}
// CreateRecord: stash rows in mutateFormDataBeforeCreate (unset $data['annotations']), then afterCreate(): $this->record->syncAnnotations($rows);
// EditRecord:   rebuild state in mutateFormDataBeforeFill, stash rows in mutateFormDataBeforeSave, sync in afterSave().
```

Hydrate on edit:

```php
use Zielu92\FilamentImageLabeler\Support\AnnotationColor;

$data['annotations'] = $this->record->annotations()->orderBy('id')->get()
    ->map(fn ($ann) => [
        'id'     => $ann->annotation_id,
        'target' => $ann->geometry,
        'label'  => $ann->metadata['label'] ?? '',
        'color'  => $ann->metadata['color'] ?? AnnotationColor::forLabel($ann->metadata['label'] ?? ''),
        'entity' => $ann->entity_type ? ['type' => $ann->entity_type, 'id' => $ann->entity_id] : null,
    ])->values()->all();
```

`AnnotationColor::forLabel('USB Port')` returns the same color the canvas assigns by default — use it when a row was saved without an explicit color.

`syncAnnotations()` semantics: creates rows missing by `annotation_id`, updates existing, deletes rows whose `annotation_id` is absent; `[]` clears all. `geometry` accepts array or JSON string. Entity pair: passing `entity_type`+`entity_id` sets the link; omitting both keys leaves the stored link untouched; an explicit `null` (or a half pair) clears it.

**Never persist automatic-annotation pending items** — see §5: their normalized 0..1 wire format is not the stored pixel format.

## 4. Entity links (opt-in)

A shape can link to one app record, beside its label. No `->linkableTo()` = no picker/chip/links. Minimal wiring = (a) `LinkableEntity` on each linkable model, (b) `->linkableTo([...])` on the field, (c) map the `entity` key yourself (already shown in §3).

```php
use Zielu92\FilamentImageLabeler\Support\LinkableType;

ImageLabel::make('annotations')
    ->image(/* ... */)
    ->linkableTo([
        LinkableType::make(Building::class),                       // display: name, else title, else "#<id>"
        LinkableType::make(Person::class)
            ->display(fn (Person $p): string => "{$p->first_name} {$p->last_name}")
            ->searchBy(['first_name', 'last_name']),               // REQUIRED whenever ->display() is set
        LinkableType::make(Vendor::class)
            ->typeLabel('Supplier')
            ->creatable(fn () => [TextInput::make('name')->required()]),
    ])
    ->allowEntityCreation(true)          // true=all creatable(), false=none, array=exactly those model classes
    ->entityAction(
        \Filament\Actions\Action::make('viewPlace')
            ->url(fn (Place $record): ?string => route('places.show', $record))
    );
```

`LinkableType` methods: `->display(Closure)` (record→string), `->searchBy(array)` (columns for picker search), `->typeLabel(string)`, `->creatable(array|Closure)` (in-picker record creation schema). `linkableTo()` also takes a `Closure` for a per-record/user allow-list. Dev-time validation throws on: empty list, non-`LinkableType` entries, non-Eloquent class, duplicate model class, `display()` without `searchBy()`, or a type with no searchable columns.

Entity link action (`->entityAction()`): one template `Action` whose closures receive the linked record. A non-null `->url()` navigates the same tab; otherwise the Action's modal opens over the editor; `->visible(fn ($record) => false)` gives that record no affordance. On a `readOnly()` field the affordance moves to a canvas click. Unsafe resolved URLs (`javascript:`, `data:`, …) are stripped by an internal guard.

Add the trait so deleting a record nulls its inbound links (shape + label survive):

```php
use Zielu92\FilamentImageLabeler\Concerns\LinkableEntity;

class Person extends Model { use LinkableEntity; }
```

## 5. Automatic annotation (opt-in; the model's own code does the thinking)

The package defines only *that* it happens and *what the answer looks like* — the detection backend (YOLO, vision LLM, HTTP detector, hardcoded fixtures) is entirely the app's method. No AI/ML package dependency. Override one method on the model:

```php
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;

public function autoAnnotate(string $url, ?string $path): ?array
{
    // $url  = the image URL currently displayed; $path = a local temp file, or null.
    // Coordinates are NORMALIZED 0..1 fractions of width/height — NOT pixels.
    return [
        AnnotationSuggestion::box('USB Port', 0.42, 0.11, 0.18, 0.09),
        AnnotationSuggestion::polygon('Heatsink', [[0.1, 0.1], [0.3, 0.12], [0.28, 0.4]]),
    ];
    // return null (trait default) keeps the feature OFF for this model.
}
```

`AnnotationSuggestion` (`Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion`) is an immutable DTO: `::box($label, $x, $y, $w, $h)` / `::polygon($label, [[x,y], ...])`; a plain array `['label' => ..., 'box' => ...]` is accepted too (converted via `fromArray()`), so a detection API's JSON works directly. Polygon wins over box when both are given. Suggestions may carry an `EntityRef`/`['type','id']` to link the resulting shape.

Field side (all `bool|Closure` unless noted): `->enableAutoAnnotation()` arms the feature (default off); `->autoAnnotateOnLoad()` also runs it when the image appears (needs `enableAutoAnnotation()`); `->autoAnnotateButton(false)` hides the toolbar Annotate button for load-only; `->autoAnnotateButtonLabel(string|Htmlable|Closure|null)` and `->autoAnnotateButtonIcon(string|Closure|null)` customize it. The button shows only when the field is enabled **and** the record overrides `autoAnnotate()`; `readOnly()` fields never annotate.

Behavior: applying runs synchronously (spinner); a slow backend can hit request timeouts — keep `autoAnnotate()` snappy (queued/async is not built in). If the method throws, the editor surfaces the error and leaves existing shapes untouched; results are discarded if the image swaps mid-request. The `$path` local-file handoff never requests your own server (same-host URLs yield `null`; remote downloads cap at 20 MB and refuse private/loopback/link-local/reserved IPs unless you set `allow_private_image_hosts`) — write the method so the `$url` alone is enough when that matters.

## Internal — do NOT call these directly

`AutoAnnotationRunner`, `LinkableTypes` (registry), `LinkedEntityAction`, `EntityUrlGuard`, `FetchesImage`, `ImageLabel::performAutoAnnotate()` / `recordSupportsAutoAnnotation()`, and the Livewire-JS endpoints `searchEntities()` / `resolveEntities()` / `getEntityTypesPayload()` / `getEntityActionPayload()`. Consumer-facing `Support\` classes are only: `AnnotationSuggestion`, `EntityRef`, `LinkableType`, `AnnotationColor`.

## Gotchas

- The field is **not** a Filament `Plugin`; never `->plugins()`.
- Migrations auto-load; publishing them is wrong.
- The `geometry`/`target` shape is Annotorious' internal pixel format — store verbatim, don't reshape it. `bounds` must accompany rectangle/polygon geometry.
- Never persist normalized auto-annotation pending items; only persist the editor state (already pixel geometry) through `syncAnnotations()`.
- `->display()` on a `LinkableType` is invalid without `->searchBy()`.
- Use canonical vocabulary: Label ≠ Entity link; verbs *link*/*run* — never tag/name/subject/pill/badge.
- For tests: `syncAnnotations()` stores whatever `geometry` you pass; copy example payloads from the package README's "How It Works" / "syncAnnotations" sections, not from the plugin's own test suite (its legacy samples use a stale selector format).
