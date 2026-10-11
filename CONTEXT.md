# CONTEXT

Glossary for filament-image-labeler. Vocabulary only — how things are built lives elsewhere.

## Terms

- **Annotation** — one labeled shape (rectangle or polygon) tied to an image; persisted polymorphically.
- **Automatic annotation** — the opt-in feature where a model's shapes are produced by the model's own code instead of a human. Absent by default: a model is untouched by it until it overrides its annotation method.
- **Auto-annotation method** — the per-model, overridable routine that looks at an image and reports what it found. The package defines *that* it happens and *what shape the answer has*, never how it thinks; different models may each do it completely differently.
- **Annotation suggestion** — one finding reported by the auto-annotation method: a label plus geometry in normalized (0..1) image space.
- **Apply** — a suggestion becoming ordinary editor shapes. Manual editing and undo continue from there; there is no separate proposal state.
- **Annotate button** — the editor control that runs the model's auto-annotation method on demand. Distinct from **on-load annotation**, which runs it as soon as the image appears.
- **Label** — the free text attached to a shape; what shapes are grouped and colored by. Deliberately *not* what an entity link is. _Avoid_: tag, name, title.
- **Entity link** — the association of one shape to one application record, beside its label and surviving label renames. The verb is *link* (link, unlink); never tag, bind or reference.
- **Linked entity** — the record an entity link points at: the real-world thing the shape depicts. Not the image's owner — that is the annotatable model. _Avoid_: subject, referenced model.
- **Entity picker** — the inline search control in the editor used to choose a record to link. Always opens in place; not a modal. _Avoid_: entity modal, search box.
- **Entity chip** — the marker in the details panel showing a shape's entity link. _Avoid_: pill, badge.
- **Entity link action** — a developer-defined operation run from a shape's entity link: navigates to the linked entity or opens its details in the editor. It exists only while the link does, and the developer's code decides per record whether it exists at all. The verb is *run*. _Avoid_: link action, view button.
