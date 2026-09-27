# Changelog

All notable changes to `filament-image-labeler` will be documented in this file.

## Unreleased

- **Automatic annotation (opt-in).** `HasAnnotations::autoAnnotate(url, path)` hook — override it per model to return `AnnotationSuggestion`s (label + normalized 0..1 box/polygon); the detection backend is entirely yours, the package adds no dependencies. `ImageLabel::enableAutoAnnotation()` adds a toolbar Annotate button, `->autoAnnotateOnLoad()` also runs it when the image appears; synchronous execution with spinner, suggestions applied as normal editor shapes (labels panel, colors, undo), errors surfaced under the toolbar. Remote images are auto-fetched to a temp file (http/https, 20 MB cap) so pixel conversion always works. Translations (en/de/pl).

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
