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
- Durable design decisions are recorded as ADRs in [`docs/adr/`](docs/adr) — check there before
  proposing a change to existing behavior, and add one for any new architectural decision.
- [`docs/backlog.md`](docs/backlog.md) explains known gaps; check it before assuming a missing
  construct is an oversight rather than a deliberate scope decision.

## Commits / PRs

Split by logical unit, not by file count. Add a [`CHANGELOG.md`](CHANGELOG.md) entry under
`[Unreleased]` for any user-facing change.
