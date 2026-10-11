# AGENTS

- Default branch is `master` — CI triggers, badge branch refs and PR bases must say `master`.
- Wayfinder maps index binding build decisions; ticket detail in `.wayfinder/tickets/` (`wf-*` → `map.md` entity links, `wf2-*` → `map-entity-actions.md` click actions). Read the relevant map before touching entity code.
- Frontend assets: the served bundle is `resources/dist/`, rebuilt by `npm run build` from `resources/css/labeler.css` + `resources/js/`. Edit the source, never dist; run the build after any CSS/JS change.
- `CONTEXT.md` is the domain glossary — use its terms exactly: entity link, linked entity, entity picker, entity chip, label, entity link action.
