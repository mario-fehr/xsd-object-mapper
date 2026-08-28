# Fix: `use="prohibited"` attributes are excluded, not treated as optional

Tracks [#3](https://github.com/mario-fehr/xsd-object-mapper/issues/3).

## Why

An `xs:attribute` with `use="prohibited"` must not appear on the generated class at all — `prohibited` means the attribute is forbidden. Today it is generated as a normal nullable/optional property, so the generated model permits a value the schema forbids.

## Root cause

In the attribute loop (`src/Generator.php:690-704`), `$use` is read at `:699` and passed straight into `makeProperty()` at `:703` as `'required' !== $use`, the optional/nullable flag. For `prohibited`, `'required' !== 'prohibited'` is `true`, so the attribute is generated exactly like an optional one. Only two of the three XSD `use` values are actually distinguished; `prohibited` falls through into the `optional` branch.

## Goals

- An attribute declared `use="prohibited"` is not generated on the class.
- `required` and `optional` attributes are unchanged (`required` non-nullable, `optional` nullable).
- No change to any schema that does not use `use="prohibited"`.

## Non-goals

- Removing an inherited attribute via `use="prohibited"` on an `xs:restriction` base. The generator's `complexContent`/`xs:restriction` handling is already limited (restriction is treated as extension, with a `warn()` at `:604`); modeling restriction-based attribute removal is a broader, separate concern. This fix covers a `prohibited` attribute declared directly on the type being generated.
- Any config surface. Internal correctness fix, no new options.

## Design

Skip the attribute before it becomes a property. In the loop at `:690`, after `$use` is known, when `'prohibited' === $use`, `continue` to the next attribute — before `resolveParticleType()` / `makeProperty()`.

`prohibited` is a deliberate, correctly-handled schema construct, not an unsupported or degraded one, so the skip is silent — no `warn()`. (The existing `warn()` calls in this loop mark genuinely unsupported or malformed input: an unnamed attribute, an unsupported `ref=`. Prohibited is neither.)

## Implementation scope

- `src/Generator.php` only: one guard (`if ('prohibited' === $use) { continue; }`) in the attribute loop.
- No new types, no config, no change to generated output for schemas without `use="prohibited"`.
- `composer phpstan` (level max) stays clean.

## Testing

TDD: write the failing test first, then apply the guard.

- New synthetic test: a `complexType` with three attributes — one `use="required"`, one `use="optional"`, one `use="prohibited"` — asserts the required one is a non-nullable property, the optional one nullable, and the prohibited one absent from the generated class entirely.
- Byte-identical regression against `tests/fixtures/w3c-purchase-order.xsd`.
- Update the `xs:attribute` row in `docs/construct-coverage.md` to record `use="prohibited"` as supported (excluded), and drop the corresponding backlog line if it still frames this as a bug rather than a tracked issue.

## Edge cases

- `use="prohibited"` combined with `default`/`fixed` or a type: all irrelevant once the attribute is skipped; no property is emitted, so no default hint, type resolution, or nullability applies.
- A type that both inherits an attribute and declares it `prohibited`: out of scope per Non-goals — the direct-declaration skip does not reach into inherited-attribute removal, which the generator does not model today.

## Rollback

Single self-contained change to one file; revert the commit. No schema, data, or config migration.
