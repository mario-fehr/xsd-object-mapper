# Contributing

## Setup

```bash
composer install
npm install   # only needed for Prettier (Markdown/YAML/JSON formatting), see AGENTS.md
```

Requires PHP `>=8.4`.

## Before opening a PR

All of these must pass:

```bash
composer test
composer phpstan
composer cs-check
composer rector
composer deps-check
npm run format:check
```

`cs-fix` / `rector-fix` apply the corresponding fixes automatically.

## Code style / design

- See [`AGENTS.md`](AGENTS.md) for architecture, conventions, and the planning workflow.
- Durable design decisions are recorded as ADRs in [`docs/adr/`](docs/adr) (see its [`README.md`](docs/adr/README.md) for the index and conventions) — check there before proposing a change to existing behavior, and add one for any new architectural decision.
- Write an ADR only for an architecturally significant choice: one that shapes the generator's structure, its input/output contract, its dependencies, or a runtime behavior a consumer relies on. A localized bug fix or a routine construct addition is not an ADR: it belongs in the commit message and, where relevant, [`docs/construct-coverage.md`](docs/construct-coverage.md).
- ADRs and [`docs/backlog.md`](docs/backlog.md) divide by lifespan, not by topic: an ADR is a stable decision that constrains future work and that other docs point back to (immutable once accepted, superseded rather than edited); the backlog is a live worklist of open or deferred gaps, and an item leaves it once resolved. Known defects belong in GitHub issues, not backlog entries; finished work belongs in [`CHANGELOG.md`](CHANGELOG.md) and git history, not a "Resolved" section.
- State each rationale in exactly one place and link to it from everywhere else, never restate it: point to an ADR through the index in [`docs/adr/README.md`](docs/adr/README.md) so the reference still resolves once that ADR is superseded, and check the backlog before assuming a missing construct is an oversight rather than a deliberate scope decision.

## Commits / PRs

Split by logical unit, not by file count. Add a [`CHANGELOG.md`](CHANGELOG.md) entry under
`[Unreleased]` for any user-facing change.
