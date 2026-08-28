# 7. Custom `Decimal` constraint for `totalDigits`/`fractionDigits`

## Status

Accepted (2026-08-27).

## Context

`xs:totalDigits`/`xs:fractionDigits` (XML Schema Part 2 §4.3) have no `symfony/validator` built-in
equivalent, unlike `pattern`, `minLength`/`maxLength`, or `min/maxInclusive`, which map onto
`Assert\Regex`/`Assert\Length`/`Assert\Range`.

## Decision

Two decisions here: one deferred, one shipped.

First, the underlying `xs:decimal` → PHP `float` precision problem stays unfixed at the root for now. Mapping `xs:decimal` to a PHP string or a dedicated Decimal value object would be the correct fix, but it's a breaking change to the generator's type mapping, out of scope for a validator-level change. Deferred, tracked in `docs/backlog.md`.

Second, the stopgap: add a custom `XsdObjectMapper\Validator\Decimal` constraint plus `DecimalValidator`, counting significant digits against the value's string representation. This makes `totalDigits`/`fractionDigits` enforceable at runtime like every other facet, through the same `SymfonyValidatorAttributeStrategy::facetConstraints()` mechanism as the built-in `Assert\*` mappings.

## Consequences

The stopgap inherits the precision limit it deliberately leaves unfixed: digit counting works off the string form of a `float`, so it carries IEEE 754 double's ~15-17 significant-digit precision and can't heal rounding artifacts; PHP also renders floats outside roughly `1e-4..1e15` in scientific notation, which the validator must normalize back to plain decimal form before counting, or the digit count is meaningless. Lifting this limit is the deferred decision above, not a change to this constraint.
