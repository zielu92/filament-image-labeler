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
- [Search UX across heterogeneous entity types](tickets/wf-002.md) — inline searchable picker in Details panel (no new combobox lib), single box with type-grouped results, bind needs active shape, seeds empty label only, entity gets its own marker, unbind explicit.
- [Per-type entity configuration API](tickets/wf-003.md) — descriptor objects on `linkableTo()`: display convention (name→title→#id, closure override), `searchBy()` required with custom display, type label humanized+overridable, per-type opt-in `creatable(schema)` reusing CreateAction modal; field-local defs, no global registry v1. Picker internals: exposed renderless `searchLinkables` fan-out over LIKE+limits, `Class:id` values (embedded native Select ruled out — see ticket Facts).
- [Domain vocabulary for the entity binding](tickets/wf-004.md) — CONTEXT.md gains: **entity link** (verb: link/unlink; API `linkableTo`), **linked entity** (thing depicted, ≠ annotatable), **entity picker** (inline, name pins no-modal), **entity chip**, plus **label** as disambiguator. Rejected: tag/bind/reference/subject.
- [Enforcement and optionality of entity binding](tickets/wf-005.md) — no required-entity mode; label seeding stands (no entity-only state — display concern only); Filament validation semantics untouched; stale allow-list links render anyway, never pruned.
- [Schema + sync contract for linkable columns](tickets/wf-006.md) — `entity_type`/`entity_id` + index + `Annotation::entity()`; marker trait `LinkableEntity` nulls links on record delete and exposes `linkedAnnotations()`; sync items take flat entity keys, null clears / absent keeps / half-link coerces to null; chip labels resolved by package via batched renderless endpoint, not consumer state.
- [AnnotationSuggestion carries optional entity link](tickets/wf-007.md) — `entity: EntityRef | array | null` on the suggestion (no live-model union); allow-list miss or dead record drops only the link, shape survives silently; label seeding applies at Apply like manual binds.
- [Per-type entity configuration API](tickets/wf-003.md) — per-type descriptor objects on `linkableTo()`: display via name/title/#id convention + closure override, `searchBy` required when display is custom, humanized type label + i18n override, per-type `creatable(schema)` reusing resource-free CreateAction (auto-binds new record), field-local definitions (no global registry v1). Facts killed embedded Select (A): exposed-method combobox (C) is the cheap inline route.

## Not yet specified

- Visual styling of the entity chip/pill in the Details panel (icon, color, layout) — placement settled by wf-002, chrome graduate to a prototype ticket once the Details-panel structure is known.
- Read-only/view-page rendering of linked entities outside the editor.
- Translation strings + a11y for all new UI.

## Out of scope

- Multiple entities on one shape (one link per shape for this effort; revisit only if destination is redrawn).
- Searching/filtering images *by* linked entity as a feature (the columns enable it; building the query UI is beyond this destination).
- Reverse relation manager on the linked model (e.g. "annotations on this Person").
