# Wayfinder map: Entity click actions

Second map (first: `map.md`, complete). Tracker: local markdown, same conventions — tickets in `.wayfinder/tickets/` (`wf2-*`), `blocked-by` frontmatter, claim = `assignee`, answers in `## Answer`.

## Destination

Locked spec for developer-defined actions on linked entities in the editor: a shape with an entity link gains a click affordance (entity chip; canvas click in read-only mode) that runs whatever the developer configured — redirect to a show page, in-place modal, or nothing for that record.

## Notes

- Settled at charting (grilling 2026-10-08, all recommendations): trigger = chip + read-only canvas (never edit-mode single click); return contract = union (`Action | string | null`); links open same tab; per-record null hides the affordance.
- Vocabulary per CONTEXT.md: entity link, linked entity, entity chip. A new term for the click affordance may graduate from wf2-003.
- Build on wf-006 machinery: `resolveEntities` batch payload is the natural carrier for per-ref action data.

## Decisions so far

- [Research: per-record Action mount protocol](tickets/wf2-001.md) — `$arguments`/`$record` ARE injectable into registered action closures (Action.php:567-583); `mountAction` context recordKey is table-only (InteractsWithActions.php:715); no config-copy API on Action → mounting a developer-returned instance = per-property delegation hack.

## Not yet specified

- Whether modal-returning actions need nested actions (confirm/second modal) and how `creatable()` modal coexists — once the return contract lands.
- Deep-link behavior when entity was deleted mid-session (link already nulls — action disappears? click races).
- Accessibility of the chip-as-button (keyboard focus, Enter to activate).

## Out of scope

- Rendering entity actions outside the editor (old map's deferral stands — view pages, infolists).
- Per-action target config (`_blank`) — settled same-tab at charting; revisit when someone asks.
