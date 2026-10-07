# Wayfinder map: Entity-linked annotations

Labelled by wayfinder. Tracker: local markdown (this folder). Tickets are files in `.wayfinder/tickets/`; `blocked-by` frontmatter holds the edges. Claim = set `assignee`. Answer goes in the ticket's `## Answer` on resolution; one line appended here under Decisions so far.

## Destination

Locked spec for **entity-linked annotations**: a shape optionally bound to an Eloquent record (`linkable_type`/`linkable_id` on `annotations`), coexisting with the free-text label. Decisions cover search UX, per-type display/attributes, entity-multiplicity, optional enforcement, persistence/migration, and auto-annotation payload — nothing left to decide before implementation.

## Notes

- Domain vocabulary lives in `CONTEXT.md`; new terms (e.g. the entity binding) must land there before implementation. Use `/grilling` + `/domain-modeling` on every grilling ticket.
- Decided at charting (round 1 grilling, user): allow-list per field (`linkableTo([...])`); autocomplete search in Details panel; entity coexists with free-text label; first-class morph columns, `metadata` untouched; dangling links nulled via trait/observer; `AnnotationSuggestion` entity link nullable; create-record-from-editor is optional capability reusing the record's own form.
- Search display must handle heterogeneous types (Person → "First Last", Building → name column, …).

## Decisions so far

<!-- one line per closed ticket -->

- [Research: cheapest search plumbing for canvas-embedded entity picker](tickets/wf-001.md) — B recommended: searchable Select in a field Action modal (`mountAction`+`schemaComponent`, already used at blade:388), zero new JS/deps; C = ExposedLivewireMethod + Alpine combobox if modal rejected. A strictly dominated; full-text E rejected.

## Not yet specified

- Editor chrome for entity pills in the Label Details panel (icon, color, layout) — needs concrete UI; graduate as a prototype ticket once search behavior is settled.
- Read-only/view-page rendering of linked entities outside the editor.
- Translation strings + a11y for all new UI.

## Out of scope

- Multiple entities on one shape (one link per shape for this effort; revisit only if destination is redrawn).
- Searching/filtering images *by* linked entity as a feature (the columns enable it; building the query UI is beyond this destination).
- Reverse relation manager on the linked model (e.g. "annotations on this Person").
