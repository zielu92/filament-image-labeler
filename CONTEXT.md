# CONTEXT

Glossary for filament-image-labeler. Vocabulary only — how things are built lives elsewhere.

## Terms

- **Annotation** — one labeled shape (rectangle or polygon) tied to an image; persisted polymorphically.
- **Automatic annotation** — the opt-in feature where a model's shapes are produced by the model's own code instead of a human. Absent by default: a model is untouched by it until it overrides its annotation method.
- **Auto-annotation method** — the per-model, overridable routine that looks at an image and reports what it found. The package defines *that* it happens and *what shape the answer has*, never how it thinks; different models may each do it completely differently.
- **Annotation suggestion** — one finding reported by the auto-annotation method: a label plus geometry in normalized (0..1) image space.
- **Apply** — a suggestion becoming ordinary editor shapes. Manual editing and undo continue from there; there is no separate proposal state.
- **Annotate button** — the editor control that runs the model's auto-annotation method on demand. Distinct from **on-load annotation**, which runs it as soon as the image appears.
