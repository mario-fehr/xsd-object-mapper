# 7. Custom `Decimal` constraint for `totalDigits`/`fractionDigits`

## Status

Accepted (2026-08-27).

## Context

`xs:totalDigits`/`xs:fractionDigits` (XML Schema Part 2 §4.3) have no `symfony/validator` built-in
equivalent, unlike `pattern`, `minLength`/`maxLength`, or `min/maxInclusive`, which map onto
`Assert\Regex`/`Assert\Length`/`Assert\Range`.

## Decision

Add a custom `XsdObjectMapper\Validator\Decimal` constraint plus `DecimalValidator`, counting significant
digits against the value's string representation.

## Consequences

`totalDigits`/`fractionDigits` become enforceable at runtime like every other supported facet, via the
same `SymfonyValidatorAttributeStrategy::facetConstraints()` mechanism as the built-in `Assert\*`
mappings. Known limitation, inherited from the generator's `xs:decimal` → PHP `float` mapping (not
decided by this constraint): digit counting works off the string form of a `float`, so it inherits
IEEE754 double's ~15–17 significant-digit precision and can't heal rounding artifacts; PHP also casts
floats outside roughly `1e-4..1e15` to scientific notation, which the validator must normalize back to
plain decimal form before counting, or the digit count is meaningless.

## Considered and rejected

- **Fixing precision at the root**: mapping `xs:decimal` to a PHP string or a dedicated Decimal value
  object instead of `float` would be the correct fix for the underlying precision problem, but is a
  breaking change to the generator's type mapping, out of scope for a validator-level constraint. See
  `docs/backlog.md`.
