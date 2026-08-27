# 2. Explicit `Config::$xsdPaths`, no `xs:include`/`xs:import` following

## Context

Multi-file XSD schemas commonly pull related files together via `xs:include` (same namespace) or
`xs:import` (cross-namespace). The generator needs to know the full set of type/element/group
definitions before it can resolve references between them.

## Decision

`Config::$xsdPaths` requires the caller to pass an explicit, ordered list of every contributing XSD
file. The generator parses exactly those files and does not follow `xs:include`/`xs:import`.

## Consequences

Simpler generator: no recursive file resolution, no relative-path handling, no de-duplication of a
schema document reachable via more than one include path. In exchange, callers with multi-file
schemas must enumerate every contributing file themselves — documented as a known limitation (see
`docs/construct-coverage.md`, "Namespace handling").

## Considered and rejected

- **Following `xs:include`/`xs:import` automatically** — deferred rather than rejected outright; no
  current caller needs it, and it adds file-resolution complexity (relative-path resolution, cycle
  detection across includes) the current explicit-list model doesn't otherwise require.
