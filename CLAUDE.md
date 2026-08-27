# xsd2php

Standalone XSD-to-PHP generator library. Candidate for eventual Packagist publication.

## Commands

- Install: `composer install`
- Test: `vendor/bin/phpunit`
- Requires PHP `^8.4`.

## Planning

Committed spec/plan/adr workflow (overrides the global local/uncommitted `_plans/` convention — design rationale for this package is worth keeping, unlike ephemeral ticket-driven work). Driven by the `superpowers` plugin, not written by hand:

- New feature/change: `brainstorming` skill first, then `writing-plans` — writes `docs/specs/YYYY-MM-DD-slug-design.md` (WHY: problem, goals/non-goals, API, edge cases, testing) and `docs/plans/YYYY-MM-DD-slug.md` (WHAT/HOW: numbered tasks, each a failing-test → implement → passing-test → commit loop).
- Implementation: `subagent-driven-development` or `executing-plans` to run the plan.
- Durable design decision worth keeping forever (not a feature-specific plan): `docs/adr/NNNN-slug.md`, one per decision.
- `.superpowers/` is the plugin's own ephemeral scratch (briefs/reports/ledger/diffs) — gitignored, never committed.
- Manual fallback templates (if the plugin's own shape ever needs a nudge): `.claude/templates/{adr,spec,plan}_template.md`.

No ticket IDs (date+slug instead), no branch/MR sections — solo lib, no GitLab remote workflow (yet).

## Architecture

- `src/Generator.php` — entry point, XSD → PHP class generation.
- `src/Config.php` — input config (`$xsdPaths`, namespace mapping).
- `src/NamespaceMapping.php`, `src/Naming.php`, `src/TypeRenderContext.php` — supporting resolution/naming logic.
- `src/Attribute/` — pluggable `PropertyAttributeStrategy` implementations (Symfony Serializer, Symfony Validator, semantic-type, composite).
- `src/Validator/` — custom constraints generated code depends on (`Decimal`, `ExactlyOneOf`).
- `bin/xsd-construct-report.php`, `bin/check-fixture-drift.php` — schema-agnostic CLI tools, take any XSD dir/file as input.
- `docs/construct-coverage.md` — per-construct support/test matrix. `docs/backlog.md` — reasoning behind every gap in that matrix.

## Independence

Must stay completely independent of any consuming project:

- No mention of any customer/consumer name anywhere in `src/`, `bin/`, `tests/`, `docs/` (or here in `CLAUDE.md` — this file ships with the package too).
- Never read/depend on a consumer's schema files or planning docs (e.g. no reference to a consumer's `schema/xsd/` or `_plans/` dirs).
- Docs describing the generator's own capabilities/limitations (coverage matrix, backlog) go in `docs/` here, written generically — no real-world occurrence counts tied to one consumer's schema.
- Real-world test corpus needs: use an official/public schema (e.g. `tests/fixtures/w3c-purchase-order.xsd`, from the W3C XML Schema Primer), never a consumer's schema.
- Schema-agnostic tooling (e.g. `bin/xsd-construct-report.php`) is fine — takes any XSD dir as input, no built-in knowledge of a specific consumer.

**Why:** coupling to one consumer's schema or planning docs makes the package unshippable/unreusable.

## Code comments

Source files must never reference `_plans/...` paths (from this or any consuming repo). If a comment needs the reasoning behind a decision, inline the reasoning itself.
