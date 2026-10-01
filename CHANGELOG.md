# Changelog

All notable changes to `filament-image-labeler` will be documented in this file.

## v0.3.1 - code clean up and security upgrade  - 2026-10-01

### Fixed

- Auto-annotation failures are now logged instead of silently swallowed

### Changed

- `FetchesImage::localize()` split into smaller methods (no behavior change)

### Build

- Patched nanoid 5.1.11 → 5.1.16 (npm audit)

## v0.3.1 - 2026-10-01

### Fixed

- Auto-annotation failures are no longer swallowed silently — `ImageLabel::resolveAutoAnnotateRecord()` now logs the Throwable as a warning instead of returning null.

### Changed

- `FetchesImage::localize()` split into `localizeRemoteUrl()`, `localizeSameHostUrl()` and `download()` — behavior unchanged, readability refactor.

### Build

- Patched `nanoid` 5.1.11 → 5.1.16 via `npm audit fix`.

## v0.3.0 - auto annotation  - 2026-09-29

### What's new

Automatic annotation — opt-in, and the package never decides *how* you detect. You override one method on your model and return labeled shapes; the editor drops them onto the canvas as ordinary, fully editable annotations.

```php
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;

class Photo extends Model
{
    use HasAnnotations;

    public function autoAnnotate(string $url, ?string $path): ?array
    {
        return [
            AnnotationSuggestion::box('USB Port', 0.42, 0.11, 0.18, 0.09),
            AnnotationSuggestion::polygon('Heatsink', [[0.1, 0.1], [0.3, 0.12], [0.28, 0.4]]),
        ];
    }
}

ImageLabel::make('annotations')
    ->image(/* ... */)
    ->enableAutoAnnotation()         // toolbar "Annotate" button
    ->autoAnnotateOnLoad()           // optional: run when the image appears
    ->autoAnnotateButton(false)      // optional: hide the button (load-only)
    ->autoAnnotateButtonLabel('Detect')
    ->autoAnnotateButtonIcon('heroicon-m-magnifying-glass');
What you get:
- Your engine, per model. YOLO / a hosted detection API / a vision LLM / an ONNX sidecar / test fixtures — anything. Different models may annotate completely differently, branch per record, or opt out by returning null.
- Normalized DTO contract. AnnotationSuggestion::box() / ::polygon() (raw ['label' => …, 'box' => …] arrays also accepted); coordinates are 0..1 fractions, so your code never needs image dimensions.
- Shapes, not second-class proposals. Suggestions arrive as regular editor shapes: rendered, selectable, movable/resizable with native handles, undo, labels panel, saved through the existing syncAnnotations().
- Editor collapses when there's no image — canvas, toolbar and panels only appear while an image is set.
- Security guardrails. The optional local-file hand-off ($path) never requests your own host, refuses private / loopback / link-local (cloud-metadata!) destinations and never follows redirects; opt out via filament-image-labeler.allow_private_image_hosts. In-flight results are discarded if the image changes.
- No new composer dependencies. Translations en/de/pl.
Fixed / other
- Persistence docs corrected: target.selector uses Annotorious' internal geometry (RECTANGLE / POLYGON + bounds) — the old SvgSelector sample never matched what was actually stored.
Upgrade notes
- Fully backward compatible; the feature is dormant until you override autoAnnotate() and enable the field.
- If you auto-annotated during the v0.3.0-beta period with the pre-release code, shapes saved in that window may be invisible — clear and re-annotate.
- Publishable config (optional): php artisan vendor:publish --tag=filament-image-labeler-config
Full changelog: v0.2.0 → v0.3.0 · PR #7 · docs (https://github.com/zielu92/filament-image-labeler#automatic-annotation)


```
## v0.3.0 - 2026-09-29

- **Automatic annotation (opt-in).** `HasAnnotations::autoAnnotate(url, path)` hook — override it per model to return `AnnotationSuggestion`s (builders: `::box()` / `::polygon()`, normalized 0..1 coordinates; raw arrays accepted); the detection backend is entirely yours, the package adds no dependencies. `ImageLabel::enableAutoAnnotation()` adds a toolbar Annotate button, `->autoAnnotateOnLoad()` also runs it when the image appears, `->autoAnnotateButton(false)` hides the button for hands-off load-only annotation; synchronous execution with spinner, suggestions applied as normal editor shapes (rendered, selectable, movable/resizable via native handles, labels panel, colors, undo), errors surfaced under the toolbar. Pixel placement happens client-side against the displayed image, so no server read of the image is needed; `$path` is best-effort (plain paths, public `/storage` URLs, remote http(s) ≤ 20 MB — same-host URLs are never fetched from ourselves). Translations (en/de/pl).
- **Editor collapses when no image is set:** canvas, toolbar and Labels/Details panels are hidden until an image appears (and hide again if it is removed, clearing stale state).
- **Security (auto-annotation `$path` fetch):** remote downloads refuse hosts resolving to private/loopback/link-local/reserved ranges and do not follow redirects (SSRF guardrails); opt-in via publishable config `filament-image-labeler.allow_private_image_hosts`. In-flight annotation results are discarded if the image is swapped/cleared before they arrive.
- Docs: corrected the persistence example to Annotorious' actual internal geometry format (`target.selector.type: RECTANGLE|POLYGON` + pixel geometry/bounds) — the old `SvgSelector` sample never matched what is stored.

## v0.2.0 - 2026-09-27

**Breaking:** the app-side `Repeater` wiring is gone — the field now owns labels internally. Field state is `[{id, target, label, color}]` and metadata is stored as `{label, color}`.

- Self-contained editor matching the plugin promo: toolbar **below** the canvas (Select / Rectangle / Polygon / Label + undo / redo / delete), Labels list panel, Label Details panel (rename + color picker), label pills on the canvas, two-way canvas↔list selection sync.
- Per-label colors: deterministic default from the label name (`AnnotationColor::forLabel()`), overridable per label; `ImageLabel::DEFAULT_PALETTE`.
- `->readOnly()` display mode: renders shapes, hover shows the label; no toolbar or panels.
- Keyboard: Delete/Backspace removes the selected shapes, Esc deselects / cancels drawing; Ctrl/Cmd+Z / Ctrl+Y handled natively.
- `image-labeler-update-url` accepts an optional second payload argument (the field's state path) so pages with multiple fields stay in sync individually.
- Images now track the form: server-rendered URL changes reach the canvas without event wiring (photo field just needs `->live()`).
- All view styling ships in the package's own CSS (no reliance on the host app's Tailwind build).

## v0.1.0

- Initial release: Annotorious canvas with rectangles/polygons and polymorphic persistence.
