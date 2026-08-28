# Fix: per-path cycle detection for group / attributeGroup refs

Tracks [#4](https://github.com/mario-fehr/xsd-object-mapper/issues/4).

## Why

A named `xs:group` (or `xs:attributeGroup`) referenced more than once from independent places within the same `complexType` — legitimate reuse, not a cycle — is misreported as a circular reference on the second occurrence, and its elements/attributes are silently dropped from the generated class. Only the first reference survives; the schema is under-generated with no error, just a `warn()` line.

## Root cause

`resolveNamedRef()` (`src/Generator.php:253`) takes the visited set `array &$seen` by reference and sets `$seen[$key] = true` (`:276`) on resolution. That set is threaded by reference through the entire walk of one complexType — `collectParticleElements()` (`:215`), `collectGroupRefElements()` (`:286`), `collectAttributes()` (`:308`) all carry `&$seenGroups`. The key is added on first resolution and never removed, so it stays visible across sibling branches, not just along the current reference path. On a second, independent reference to the same group, `isset($seen[$key])` is true, so `:265-268` emits `circular xs:group ref ... stopping` and returns nothing.

The memo cache can't rescue it: the cycle check in `resolveNamedRef()` runs before the `groupElementsCache` lookup in `collectGroupRefElements()` (`:288` before `:292`), so the second reference short-circuits to "circular" and never reaches the already-populated cache.

`$seen` currently conflates two different questions: "have we passed through this ref on the path we are currently descending?" (the real cycle question) and "have we seen this ref anywhere in this type?" (what the code actually asks). Only the first is a cycle.

## Goals

- A group / attributeGroup referenced N times in one type expands N times, correct in every position.
- Genuine cycles (direct `G -> G`, mutual `A -> B -> A`) are still caught and stopped, exactly as today (`warn()` + skip, no infinite recursion).
- No change to generated output for any schema without duplicate group/attributeGroup references.

## Non-goals

- Strict-mode `typeMissingError` / `typeOverrideError` toggles — separate, explicitly deferred in `docs/specs/2026-08-27-config-options-tier1-design.md`.
- The `xs:all` dedicated-test gap — separate backlog item.
- Any config surface. This is an internal correctness fix, no new options.

## Design

Scope the visited set per descent path instead of tree-wide: pass it by value, and add a key to the copy only when descending into that ref's body.

1. Change `$seen` / `$seenGroups` from by-reference to by-value in `resolveNamedRef()`, `collectParticleElements()`, `collectGroupRefElements()`, `collectAttributes()` (drop the `&`).
2. `resolveNamedRef()` stops mutating: it still checks `isset($seen[$key])` for an on-path cycle and returns `[$key, $node]`, but no longer sets `$seen[$key]`.
3. The two recursive-descent call sites add the key to a copy as they descend into the referenced body:
   - `collectGroupRefElements()`: `collectParticleElements($groupParticle, [...$seenGroups, $key => true])`
   - `collectAttributes()`: `collectAttributes($attributeGroup, [...$seenGroups, $key => true])`

Effect: sibling / repeat references at the same level each receive the parent's set without the key, so each resolves independently. A ref that reappears along its own descent path finds the key in the path-local set and is reported circular. Real cycle detection preserved; false positives on reuse gone.

The memo cache (`groupElementsCache`, `attributeGroupCache`) keeps its current semantics and now actually serves reuse: a legitimate second reference passes the cycle check (its path-local set has no key) and hits the cache instead of recomputing.

### Why both group and attributeGroup

`resolveNamedRef()` is shared by both ref kinds, and `collectAttributes()` (`:333`) descends with the same shared set, so the attributeGroup path carries the identical bug. Fixing only groups would leave attributeGroups broken through the same mechanism. The fix is one change to the shared function plus the two descent call sites, covering both.

## Implementation scope

- `src/Generator.php` only: four signature changes (`&` removal), move one assignment out of `resolveNamedRef()` into the two call sites as a spread.
- No new types, no config, no generated-output change for schemas without duplicate refs.
- `composer phpstan` (level max) stays clean; the `&`-to-value change plus array spread is within existing idiom.

## Testing

TDD: write the failing reuse tests first, then apply the fix.

- New synthetic test: a `complexType` referencing the same `xs:group` twice in two independent sequence positions -> assert the elements from both references appear in the generated class (today the second set is dropped).
- New synthetic test: the same for a twice-referenced `xs:attributeGroup`.
- Regression, must stay green: `testCircularGroupRefStopsInsteadOfInfiniteRecursion` (`A -> B -> A`) still warns and stops; `testResolvesGroupRef` and `testGroupRefInsideChoiceIsTreatedAsChoiceMember` unchanged.
- Add a direct self-cycle case (`G` refs `G`) asserting the circular warning, since the new tests move the responsibility for that guard into the call sites.
- Byte-identical regression against `tests/fixtures/w3c-purchase-order.xsd` (the bar every prior spec used).
- Update the group / attributeGroup rows in `docs/construct-coverage.md` if the matrix distinguishes single vs. repeated reference.

## Edge cases

- One reference inside an `xs:choice`, another under `xs:sequence`: the cache stores `[element, intrinsicChoice]` pairs context-free, and each call site applies `$intrinsicChoice ?? $ownChoice` after retrieval (`:231`), so each reference gets correct, independent choice tagging. The fix does not touch this.
- Nested legitimate reuse (a group reused inside another reused group): the path-local copy grows only along the descent path; sibling nested groups are unaffected.
- Performance: `[...$seen, $key => true]` copies a small associative array (sized to path depth, not schema size) per group descent — negligible.

## Rollback

Single self-contained change to one file; revert the commit. No schema, data, or config migration.
