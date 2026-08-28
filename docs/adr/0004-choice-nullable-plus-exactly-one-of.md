# 4. `xs:choice` elements become nullable plus a class-level `ExactlyOneOf` constraint

## Context

`Generator::collectParticleElements()` historically flattened `xs:sequence`, `xs:choice`, and
`xs:all` identically. Elements enclosed by an `xs:choice` were generated as required properties driven
only by their own `minOccurs`, ignoring that a choice means "exactly one of these", not "all of
these": without an explicit `minOccurs="0"` on every branch element, the generator produced classes
that demanded every alternative simultaneously.

## Decision

Elements enclosed by an `xs:choice` are generated as nullable properties regardless of their own
`minOccurs`. Each choice group additionally gets a class-level `#[ExactlyOneOf(fields: [...])]`
constraint (`XsdObjectMapper\Validator\ExactlyOneOf`), with `required: false` when the enclosing `xs:choice`
itself has `minOccurs="0"` (relaxing the semantics to "at most one").

## Consequences

Constructing an object with only one alternative set becomes possible (previously blocked by
`NotNull` on every alternative), and the "exactly one" invariant is enforced at runtime via
`symfony/validator` instead of being left undocumented. Deliberately not supported: a repeatable
choice (`maxOccurs > 1` on the choice particle itself, elements stay nullable but no constraint is
emitted), and a choice branch with more than one direct child element (a nested `sequence`/`group`/
`choice` directly under the choice is one atomic alternative, not N independent members; the
generator warns and skips the constraint rather than mis-modeling it as N independent choices).

## Considered and rejected

- **Modeling `xs:choice` as a PHP union type or a dedicated wrapper value object**: bigger structural
  change to every generated class touching a choice; not pursued. Nullable properties plus a runtime
  constraint reuse the existing generation model with a much smaller footprint.
