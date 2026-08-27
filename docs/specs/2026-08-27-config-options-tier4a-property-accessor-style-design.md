# Config options — Tier 4a (property accessor style as a config axis)

## Why

`docs/backlog.md`'s "Config options" section, Tier 4 ("speculative/large, defer"): the generated
property style (constructor-promoted, `public readonly`, no getter/setter) is currently fixed in
`Generator::buildComplexClass()` — this spec makes it a config axis, matching
`makinacorpus/php-xsd-gen`'s `property_promotion`/`property_public`/`property_readonly`/
`property_getter`/`property_setter` (`.references/makinacorpus-php-xsd-gen/src/GeneratorConfig.php`).
Explicitly deferred/speculative per the backlog — no known consumer need today — proceeding anyway per
direct instruction; scope decision confirmed: the full 5-boolean matrix, not a reduced set of named
styles, matching the reference repo's own flexibility rather than a narrower subset.

`makinacorpus/php-xsd-gen` is AST-based (`nikic/php-parser`'s `BuilderFactory`) — its own "many
combinable flags" design is easy to keep correct because each flag toggles one AST-builder call. This
generator's `buildComplexClass()` is hand-rolled string concatenation. That difference doesn't change
*what* this spec generates, but it does change *how big* the implementation is — see Implementation
scope's closing note.

## Goals

- `Config` gains 5 new fields, defaulting to today's exact fixed behavior (promoted, public, readonly,
  no getter, no setter) — a `Config` that doesn't set any of them generates byte-identical output to
  today:
  ```php
  public bool $propertyPromotion = true,
  public bool $propertyPublic = true,
  public bool $propertyReadonly = true,
  public bool $propertyGetter = false,
  public bool $propertySetter = false,
  ```
- `propertyPromotion`: `true` — constructor-promoted properties (today's only mode). `false` —
  separate property declarations + non-promoted constructor parameters + manual `$this->prop = $prop;`
  assignment statements in the constructor body.
- `propertyPublic`: visibility of the property (promoted param's own visibility modifier, or the
  separate property declaration's visibility when not promoted). `true` → `public`, `false` →
  `private`.
- `propertyReadonly`: `true` → the class keeps today's `final readonly class` declaration (which makes
  every property in the class readonly, promoted or not — a PHP 8.2 readonly class's guarantee, no
  per-property `readonly` modifier needed anywhere). `false` → `final class` (no `readonly` keyword at
  all, on the class or on any property/param).
- `propertyGetter`/`propertySetter`: independent of promotion/public/readonly — `true` adds a
  `get{Name}(): Type { return $this->{name}; }` / `set{Name}(Type $value): void { $this->{name} =
  $value; }` public method for every property, regardless of the property's own visibility.
- Invalid combination rejected at `Config` construction: `propertyReadonly: true` with
  `propertySetter: true` throws `\InvalidArgumentException` immediately — a setter on a `readonly`-
  class property compiles but throws `Error: Cannot modify readonly property` at *every* call, a
  broken-output combination, not just an ergonomics footgun. (`makinacorpus/php-xsd-gen` silently
  force-disables the setter with an `E_USER_WARNING` for the same combination — this spec throws
  instead, matching this package's own precedent of failing loud on a caller-configuration error rather
  than silently degrading; see [Tier 1](2026-08-27-config-options-tier1-design.md)'s
  `typeMissingError`/`typeOverrideError` reasoning for the same posture, and contrast with
  `Generator`'s `warn()`/`note()` convention, which is reserved for *schema-content* issues, not
  caller-config mistakes.)

## Non-goals

- `class_constructor` (public/protected constructor visibility toggle) — already in `docs/backlog.md`'s
  "Deliberately not pursued" list (SOAP-tooling-specific rationale, doesn't apply here). Constructor
  stays `public` unconditionally.
- `property_camel_case` — already handled unconditionally by the existing `Naming::toPropName()`, not
  a new axis.
- `property_defaults` — already in "Deliberately not pursued" (unimplemented even in
  `makinacorpus/php-xsd-gen` itself, no mature prior art).
- No `isFoo()`-style getter naming for `bool`-typed properties — plain `get{Name}()` uniformly, matching
  `makinacorpus/php-xsd-gen`'s own getter naming (no special-casing by type).
- No fluent (`return static`/`return $this`) setter — `void` return, matching
  `makinacorpus/php-xsd-gen`'s own setter signature.
- No validation of the "no external access" combination (`propertyPublic: false` with
  `propertyGetter: false` — a class with genuinely no way to read a property from outside). Permissive,
  matches `makinacorpus/php-xsd-gen`'s own posture (it doesn't validate this either) — only the
  compile-succeeds-but-always-throws `readonly`+`setter` combination is rejected, not every possible
  ergonomic footgun.
- `class_factory_method` (`static create()`, originally the other Tier 4 item) was dropped after
  review — its own source repo states it's "part of generated SOAP tooling" (the identical rationale
  `docs/backlog.md` already excludes `class_constructor` for), and it duplicates the hydration path
  this generator's `#[SerializedName]`/`#[Context]` attributes already give `symfony/serializer`. See
  `docs/backlog.md`'s "Deliberately not pursued" list.
- No AST-based rewrite (`nikic/php-parser`) — that's `docs/backlog.md`'s own, separate "Generator/
  package infrastructure" item. This spec extends the existing string-concatenation approach in
  `buildComplexClass()`, however much that grows the method (see closing note below).

## API

`src/Config.php` — five more constructor parameters (additive to every prior tier):

```php
public bool $propertyPromotion = true,
public bool $propertyPublic = true,
public bool $propertyReadonly = true,
public bool $propertyGetter = false,
public bool $propertySetter = false,
```

Constructor body gains one validation check:

```php
if ($this->propertyReadonly && $this->propertySetter) {
    throw new \InvalidArgumentException('propertySetter cannot be used with propertyReadonly - a setter on a readonly-class property always throws at call time.');
}
```

(`Config` is `final readonly class` with promoted properties and no existing constructor body — this
is the first validation logic `Config` itself needs; the constructor gains a `{ ... }` body instead of
`{}`.)

## Implementation scope

`Generator::buildComplexClass()` (`Generator.php:799-884`) currently does five things in one method:
resolve properties, collect imports, render the `use` block, render the constructor (params + always-
empty body, since promoted params self-assign), render class-level attributes. Property-accessor-style
support touches the constructor-rendering and (new) accessor-rendering steps; the natural refactor is
splitting those out of the single method rather than growing it in place — sized in the implementation
plan, not here, but the shape is:

- **Class declaration line**: `final readonly class` when `propertyReadonly`, else `final class` —
  one conditional replacing the current hardcoded string.
- **Per-property rendering** now branches on `propertyPromotion`:
  - **Promoted** (today's path): visibility from `propertyPublic` (`public`/`private`) instead of the
    current hardcoded `public`; doc comment and `#[...]` attributes stay attached directly above the
    promoted param line (unchanged from today — PHP mirrors a promoted param's attributes onto its
    corresponding property for `symfony/serializer`'s/`symfony/validator`'s reflection-based readers,
    same as today).
  - **Non-promoted** (new): a separate property-declaration statement (`{public|private} Type
    $name;`, no `readonly` keyword — class-level `readonly` already covers it when `propertyReadonly`)
    carrying the doc comment and `#[...]` attributes (moved here from the constructor param — a plain,
    non-promoted constructor parameter has no property-reflection mirroring, so this move is required
    for `symfony/serializer`/`symfony/validator` to still find them, not optional/cosmetic); a plain
    (un-attributed, undocumented) constructor parameter of the same name/type; a `$this->name =
    $name;` assignment statement in the constructor body.
- **Constructor body**: today always empty (`{\n    }\n`, promoted params self-assign). When
  `!propertyPromotion`, gains one `$this->{name} = ${name};` line per property, in the same
  required-before-optional order the params themselves already use (`usort()`'s existing ordering,
  `Generator.php:804`, is unchanged and still governs both the param list and, now, the assignment
  order — no new ordering logic needed).
- **Accessors**: when `propertyGetter`/`propertySetter`, append `get{Ucfirst(name)}(): Type { return
  $this->{name}; }` / `set{Ucfirst(name)}(Type $value): void { $this->{name} = $value; }` public
  methods after the constructor, one pair of loops over `$properties` (independent of the
  promoted/non-promoted branch above — both read/write `$this->{name}` identically either way).
  `Naming::toClassName($p->phpName)`-style ucfirst (reuse `Naming`'s existing sanitize-free `ucfirst`,
  not the reserved-word-suffixing behavior `Naming::toClassName()` has — `$p->phpName` is already a
  valid, sanitized PHP identifier from `Naming::toPropName()`, so a bare `ucfirst()` suffices here,
  confirm no reserved-word collision risk at implementation time, e.g. `getList()`/`getPrint()` are
  fine since `list`/`print` are keywords only as bare identifiers, not as method names).

**Closing note on size**: this is the "biggest rewrite" `docs/backlog.md` itself flagged Tier 4a as.
The five flags are independent per the confirmed full-matrix scope, so the implementation plan should
budget for testing a representative combination set (every flag toggled independently against the
default, plus the two or three combinations most likely to interact unexpectedly — e.g.
`propertyPromotion: false` + `propertyGetter: true` + `propertyPublic: false`, the "classic private-
with-accessors" combo `makinacorpus/php-xsd-gen`'s own *default* actually is), not literally all 32
combinations minus the one rejected pair.

## Testing

- `ConfigTest.php` (or wherever `Config`'s own tests live — confirm at implementation time):
  `propertyReadonly: true, propertySetter: true` throws `\InvalidArgumentException`; every other
  combination constructs without error.
- `GeneratorTest.php`, one fixture-driven test per flag flipped from its default, each asserting the
  specific generated-code shape changes expected (promoted-vs-declared property, class keyword,
  visibility keyword, presence/absence/signature of `get*`/`set*` methods) — plus the "classic private-
  with-accessors" combination from the closing note above as one combined-flags regression case.
- A default-`Config` byte-identical-output regression check against
  `tests/fixtures/w3c-purchase-order.xsd`, same bar every prior tier spec used — this is the one most
  worth automating carefully here, since the refactor touches the single most heavily-exercised code
  path in the whole generator (every generated class goes through `buildComplexClass()`).
- A getter/setter fixture confirms `#[...]` attributes land on the property declaration (not the
  constructor parameter) when `propertyPromotion: false` — the specific correctness property called
  out in Implementation scope, worth its own explicit assertion rather than only checking the
  attributes exist somewhere in the file.

## Edge cases

- A `propertyPromotion: false` class still needs every property assigned before `symfony/serializer`'s
  denormalizer (or any other constructor caller) can rely on it — the manual `$this->name = $name;`
  assignment order matters only in that every property must be assigned exactly once; order among
  properties themselves has no behavioral consequence (unlike the *parameter* order, which PHP's
  required-before-optional rule does constrain).
- `propertyReadonly: false` also removes readonly-ness from any `readonly` value objects this generator
  emits elsewhere (there are none outside `buildComplexClass()`'s own output — `Property`/`Config`/
  `NamespaceMapping`/`TypeInfo` are this *package's own* internal value objects, not generated code;
  unaffected by a `Config` flag that only governs generated-class shape).
- Non-promoted mode's property statement and the plain constructor parameter share the same
  `Naming::toPropName()`-derived name by construction (both come from the same `Property::$phpName`) —
  no separate collision surface introduced.
- `propertyGetter`/`propertySetter` methods don't get their own `#[...]` attributes (no `symfony/
  serializer`/`symfony/validator` concern applies to a plain accessor method) — only the property/
  promoted-param carries them, confirmed already implicit in Goals but stated explicitly here since
  it's the kind of detail easy to accidentally duplicate onto the getter during implementation.
