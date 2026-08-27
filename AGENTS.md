# AGENTS.md

This file provides guidance to AI coding agents (Claude Code, Codex, etc.) working
in this repository. `CLAUDE.md` is a pointer to this file — edit here, not there.

## What this is

Standalone XSD-to-PHP generator library. Candidate for eventual Packagist publication.

## Commands

- Install: `composer install`
- Requires PHP `^8.4`.
- `composer test` — PHPUnit.
- `composer phpstan` — static analysis, level max (`phpstan-baseline.neon` freezes pre-existing findings; new code must stay clean).
- `composer cs-check` / `composer cs-fix` — PHP-CS-Fixer (`@PHP8x4Migration` + `@Symfony`), dry-run vs. apply.
- `composer rector` / `composer rector-fix` — Rector (`deadCode` + `codeQuality` sets), dry-run vs. apply.
- `composer deps-check` — `composer-dependency-analyser`, catches unused/missing/shadow Composer dependencies.

## Planning

Committed spec/plan/adr workflow (overrides the global local/uncommitted `_plans/` convention — design rationale for this package is worth keeping, unlike ephemeral ticket-driven work). Driven by the `superpowers` plugin, not written by hand:

- New feature/change: `brainstorming` skill first, then `writing-plans` — writes `docs/specs/YYYY-MM-DD-slug-design.md` (WHY: problem, goals/non-goals, API, edge cases, testing) and `docs/plans/YYYY-MM-DD-slug.md` (WHAT/HOW: numbered tasks, each a failing-test → implement → passing-test → commit loop).
- Implementation: `subagent-driven-development` or `executing-plans` to run the plan.
- Durable design decision worth keeping forever (not a feature-specific plan): `docs/adr/NNNN-slug.md`, one per decision.
- `.superpowers/` is the plugin's own ephemeral scratch (briefs/reports/ledger/diffs) — gitignored, never committed.

No ticket IDs (date+slug instead), no branch/MR sections — solo lib, no GitLab remote workflow (yet).

## Architecture

- `src/Generator.php` — entry point, XSD → PHP class generation.
- `src/Config.php` — input config (`$xsdPaths`, namespace mapping).
- `src/NamespaceMapping.php`, `src/Naming.php`, `src/TypeRenderContext.php` — supporting resolution/naming logic.
- `src/Attribute/` — pluggable `PropertyAttributeStrategy` implementations (Symfony Serializer, Symfony Validator, semantic-type, composite).
- `src/Validator/` — custom constraints generated code depends on (`Decimal`, `ExactlyOneOf`).
- `bin/xsd-construct-report.php`, `bin/check-fixture-drift.php` — schema-agnostic CLI tools, take any XSD dir/file as input.
- `docs/construct-coverage.md` — per-construct support/test matrix. `docs/backlog.md` — reasoning behind every gap in that matrix.
- `docs/reference-repos.md` — prior-art XSD→PHP (and adjacent) generators.

## Reference: prior-art generators

`docs/reference-repos.md` lists open-source XSD→PHP (and adjacent schema→PHP)
generators worth consulting before inventing a solution from scratch. Consult it
when: a construct isn't in `docs/construct-coverage.md` yet and you're about to
design how to generate PHP for it; writing/updating a `docs/backlog.md` entry
(check whether prior art solved the gap, punted on it, or hit the same wall);
or facing a generator-architecture question (config shape, naming/collision
strategy, runtime-vs-generated-code split) during `brainstorming`/`writing-plans`.

Use the `add-reference-repository` skill to clone one into the git-ignored
`.references/` dir — bring back the pattern, not the code (see Independence,
below).

## Independence

Must stay completely independent of any consuming project:

- No mention of any customer/consumer name anywhere in `src/`, `bin/`, `tests/`, `docs/` (or here in `AGENTS.md`/`CLAUDE.md` — these ship with the package too).
- Never read/depend on a consumer's schema files or planning docs (e.g. no reference to a consumer's `schema/xsd/` or `_plans/` dirs).
- Docs describing the generator's own capabilities/limitations (coverage matrix, backlog) go in `docs/` here, written generically — no real-world occurrence counts tied to one consumer's schema.
- Real-world test corpus needs: use an official/public schema (e.g. `tests/fixtures/w3c-purchase-order.xsd`, from the W3C XML Schema Primer), never a consumer's schema.
- Schema-agnostic tooling (e.g. `bin/xsd-construct-report.php`) is fine — takes any XSD dir as input, no built-in knowledge of a specific consumer.
- Prior-art research via `docs/reference-repos.md`/`.references/` studies design patterns only — never copy code verbatim, even from MIT-licensed repos (see that file).

**Why:** coupling to one consumer's schema or planning docs makes the package unshippable/unreusable.

## Code comments

Source files must never reference `_plans/...` paths (from this or any consuming repo). If a comment needs the reasoning behind a decision, inline the reasoning itself.
