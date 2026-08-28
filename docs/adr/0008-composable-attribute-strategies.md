# 8. Composable attribute strategies, not one monolithic strategy

## Context

The generator originally emitted only `symfony/serializer` attributes (`SerializedName`/`Context`) via
one strategy. A second concern, `symfony/validator` presence constraints (`NotBlank`/`NotNull`/
`Count`) derived from the same property model, needed to be added without overloading that strategy,
and further concerns (semantic type aliases, digit facets) followed the same shape.

## Decision

`PropertyAttributeStrategy` is a single-responsibility extension point
(`attributesFor(array $property): array`). Each concern gets its own implementation
(`SymfonySerializerAttributeStrategy`, `SymfonyValidatorAttributeStrategy`,
`SemanticTypeAttributeStrategy`), and `CompositeAttributeStrategy` merges multiple strategies' output
at the call site. The package does not hard-code which strategies are combined.

## Consequences

Adding a new attribute concern (e.g. a future JMS Serializer or plain `json_encode` strategy, see
`docs/backlog.md`) means writing one new class, not touching existing strategies. The composition
decision (which strategies run, and in what order; order matters for `use`-import collision
resolution: the first strategy's import wins) lives entirely with the caller building the `Config`.

## Considered and rejected

- **One strategy class handling every attribute concern via internal branching**: rejected, it would
  grow unboundedly with every new concern and couple unrelated attribute concerns' logic together.
