# Config options — Tier 2 (date-prefer-interface)

## Why

Continuation of `docs/backlog.md`'s "Config options" section, following
[Tier 1](2026-08-27-config-options-tier1-design.md). Tier 2 originally covered three items — class
prefix/suffix, `add_comments`-style header tags, and `date-prefer-interface`. After a scope review
against this generator's actual XSD use case (not just against the reference repos' own rationale),
`classPrefix`/`classSuffix` and `headerComments` were dropped:

- **`classPrefix`/`classSuffix`** (`WsdlToPhp/PackageGenerator`'s `GeneratorOptions::PREFIX`/`SUFFIX`)
  — that tool's rationale is avoiding collisions when many WSDL-generated classes land in a SOAP-client
  codebase alongside hand-written ones. This generator already solves the same problem at the namespace
  level: `Config::$namespaceMap` lets a consumer route every XSD `targetNamespace` to its own PHP
  namespace/directory, so a consumer avoiding collisions with their own classes can already do so by
  namespace choice, without touching the schema — the exact goal the backlog entry stated for
  prefix/suffix. Adding a second, redundant collision-avoidance knob isn't justified without a concrete
  case namespace routing doesn't cover.
- **`headerComments`** (`WsdlToPhp/PackageGenerator`'s `ADD_COMMENTS`) — generic docblock-header vanity
  (author/license lines), no XSD- or generator-specific rationale; the same feature would have the same
  value bolted onto any unrelated code-generation tool. Not worth the added surface without a concrete
  need.

`date-prefer-interface` (`janephp`'s `\DateTimeInterface`-vs-`\DateTimeImmutable` type-hint toggle)
stays — XSD `date`/`dateTime` maps directly onto this generator's own hardcoded `\DateTimeImmutable`
type today, a real, narrowly-scoped axis. One caveat found during this review, stated explicitly since
it bears on whether the flag is actually useful, not just implementable: `composer.json` lists
`symfony/validator` as a dependency but **not** `symfony/serializer` — this package's own test suite
has no way to verify that `symfony/serializer`'s `DateTimeNormalizer` actually denormalizes a
`\DateTimeInterface`-typed constructor parameter into a concrete instance at runtime (it's a consumer-
side runtime behavior, untestable from inside this repo). The flag still ships — it's a straightforward,
low-risk type-hint swap regardless of that caveat — but a consumer turning it on should verify their own
`symfony/serializer` version handles `\DateTimeInterface`-typed denormalization before relying on it.

## Goals

- `Config::$datePreferInterface: bool = false` — `xs:date`/`xs:dateTime` properties get the PHP type
  `\DateTimeInterface` instead of `\DateTimeImmutable` when `true`.
- Default `false` preserves today's exact output (`\DateTimeImmutable`) — no behavior change for a
  `Config` that doesn't set it.

## Non-goals

- `datePreferInterface` doesn't touch Symfony Serializer context (format string, etc.) — no such
  context-attribute generation exists yet for full `dateTime` properties (only `dateOnly` gets one
  today — see [Tier 3a](2026-08-27-config-options-tier3a-serializer-context-design.md), which
  generalizes that mechanism). This flag only changes the PHP property type hint.
- No verification of `symfony/serializer`'s own `\DateTimeInterface` denormalization behavior — out of
  this package's reach, per the Why section's caveat. Documented as a consumer-facing note (in
  `Config`'s docblock at implementation time), not something this spec's tests can cover.
- No revisit of `classPrefix`/`classSuffix`/`headerComments` — dropped, not deferred; if a concrete need
  for either surfaces later (a collision `NamespaceMapping` genuinely can't route around, or a real
  compliance requirement for header tags), it goes back through `docs/backlog.md` as a fresh entry with
  its own justification, not silently reinstated here.

## API

`src/Config.php` — one more constructor parameter (additive to Tier 1's four):

```php
public bool $datePreferInterface = false,
```

## Implementation scope

`Naming::xsPrimitiveToPhp()` stays a pure static function (no `Config` access) — the swap happens at
its one call site instead, in `resolvePrimitiveOrNamedSimpleType()`. Currently:

```php
return ['kind' => 'scalar', 'phpType' => Naming::xsPrimitiveToPhp($local), 'dateOnly' => 'date' === $local];
```

becomes:

```php
$phpType = Naming::xsPrimitiveToPhp($local);
if ($this->config->datePreferInterface && '\DateTimeImmutable' === $phpType) {
    $phpType = '\DateTimeInterface';
}

return ['kind' => 'scalar', 'phpType' => $phpType, 'dateOnly' => 'date' === $local];
```

## Testing

- `GeneratorTest.php`: `datePreferInterface: true` — a fixture with an `xs:date`/`xs:dateTime` element
  generates a `\DateTimeInterface`-typed property instead of `\DateTimeImmutable`; a `false`/default-
  path test confirms no regression.
- A byte-identical-output regression check against `tests/fixtures/w3c-purchase-order.xsd` with a
  default `Config`, same bar every prior tier spec used.

## Edge cases

- The swap only fires for `'\DateTimeImmutable' === $phpType` — an already-`dateOnly` property still
  gets `\DateTimeImmutable`/`\DateTimeInterface` swapped identically to a full `dateTime` property (the
  `dateOnly` flag only affects the `symfony/serializer` `Context` format string emitted separately, not
  the base type resolved here) — no special-casing needed between the two.
- If a future `scalarTypeMapping` ([Tier 3b](2026-08-27-config-options-tier3b-scalar-type-mapping-design.md))
  entry substitutes a different class for a date-shaped named `simpleType`, that substitution runs in
  `resolveSimpleTypeRef()`, a different method than this flag's hook in
  `resolvePrimitiveOrNamedSimpleType()`'s XS-primitive fallback branch — the two don't interact, a
  mapped type's `phpType` is never `'\DateTimeImmutable'` in the first place, so the `datePreferInterface`
  check's `===` guard already excludes it correctly with no additional guard needed.
