# AGENTS.md

This file provides guidance to AI coding agents (Claude Code, Codex, etc.) working
in this repository. `CLAUDE.md` is a pointer to this file — edit here, not there.

## What this is

Standalone XSD-to-PHP generator library, published as `mario-fehr/xsd-object-mapper`.
Candidate for eventual Packagist publication.

## Commands

- Install: `composer install`
- Requires PHP `>=8.4`.
- `composer test` — PHPUnit.
- `composer phpstan` — static analysis, level max, `src`+`bin`+`tests`, runs clean with no baseline (`[OK] No errors`).
- `composer cs-check` / `composer cs-fix` — PHP-CS-Fixer (`@PHP8x4Migration` + `@Symfony` + `@Symfony:risky`), dry-run vs. apply.
- `composer rector` / `composer rector-fix` — Rector (`deadCode`/`codeQuality`/`typeDeclarations`/`earlyReturn`/`privatization`/`instanceOf`/`phpunitCodeQuality`/`phpunitNarrowAsserts` sets — deliberately not `naming`, which renames by type rather than role, e.g. `$xpathCache` → `$weakMap`), dry-run vs. apply.
- `composer deps-check` — `composer-dependency-analyser`, catches unused/missing/shadow Composer dependencies.
- `npm install` (once) — Prettier; the only JS in this repo, kept out of Composer.
- `npm run format` / `npm run format:check` — Prettier on `**/*.{md,yml,yaml,json}` (`proseWrap: preserve` doesn't rewrap this repo's long unwrapped prose lines; `CLAUDE.md` excluded via `.prettierignore`, it's a 1-line `@AGENTS.md` pointer not a doc).

## Planning

Design rationale for this package is worth keeping (unlike ephemeral ticket-driven work), so any
non-trivial change gets two committed documents before implementation starts:

- `docs/specs/YYYY-MM-DD-slug-design.md` — WHY: problem, goals/non-goals, API, edge cases, testing.
- `docs/plans/YYYY-MM-DD-slug.md` — WHAT/HOW: numbered tasks, each a failing-test → implement →
  passing-test → commit loop.
- A durable architectural decision that isn't tied to one feature gets its own
  `docs/adr/NNNN-slug.md` instead of a plan.

No ticket IDs (date+slug instead), no story/feature-branch hierarchy or MR checklist — solo lib,
this two-document workflow replaces that. GitHub Actions CI and a PR template exist for whenever a
PR does happen, but there's no required-review process (yet).

If you're Claude Code with the `superpowers` plugin installed, its `brainstorming` →
`writing-plans` → `executing-plans`/`subagent-driven-development` skills produce exactly this
shape — use them (`.superpowers/` is that plugin's own ephemeral scratch, gitignored, never
committed). Without it, write the two files by hand following the structure above.

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
strategy, runtime-vs-generated-code split) while writing a spec or plan.

Clone one into the git-ignored `.references/` dir — the `add-reference-repository` skill
automates this if you have the `superpowers` plugin, otherwise `git clone --depth 1 <url>
.references/<name>` works just as well — bring back the pattern, not the code.

## Conventions

- PHP `>=8.4`: readonly, constructor-promoted classes; native types; backed enums for
  `xs:enumeration`. No comments unless the WHY is non-obvious — never explain WHAT
  already-obvious code does.
- New public `Config`/`NamespaceMapping` constructor parameters get a PHPDoc `@param` explaining
  the contract (see `Config.php` for the existing style).
- Attribute strategies (`src/Attribute/`) stay trivial and composable via
  `CompositeAttributeStrategy` — don't grow one strategy to do everything a new use case needs.
- A newly supported XSD construct gets both a `docs/construct-coverage.md` entry and an isolated
  synthetic test — corpus coverage from `OfficialSchemaFixtureTest` is not a substitute for one.
- A new `PropertyAttributeStrategy` implementation gets a line in README's "Attribute strategies"
  list when it lands.
- Documentation prose (README, CONTRIBUTING, CHANGELOG entries): match the existing sections'
  voice; draft/polish with the `natural-writing-editor` agent if you have it configured.
- Commit messages: [Conventional Commits](https://www.conventionalcommits.org/) —
  `type: short description`, lowercase, imperative mood, no trailing period (`feat`, `fix`,
  `refactor`, `chore`, `docs` used so far). Not Symfony's `[Scope] Description` bracket style.
- Releases: manual for now — a `CHANGELOG.md` entry under `[Unreleased]` per user-facing change
  (see `CONTRIBUTING.md`), no automated release workflow yet.
