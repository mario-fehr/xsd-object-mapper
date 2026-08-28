# Known limitations / backlog

Design decisions and known gaps for `xsd-object-mapper` itself: independent of any specific consumer's XSD
files. See `construct-coverage.md` for the construct-by-construct support/test matrix this backlog
explains the reasoning for.

## Structural XSD constructs

- **`substitutionGroup` / abstract complexTypes (polymorphism)**: no clean PHP equivalent, its own
  design problem. Not solved by goetas-webservices/xsd2php either.
- **`xs:any` (wildcard content)**: fundamentally untypeable; any static generator can only fall
  back to a string/raw-XML representation.
- **`xs:anyAttribute`**: same reasoning as `xs:any`, no static PHP equivalent.
- **`mixed="true"` (text + element mixed content)**: not recognized/handled; would likely silently
  process only the child elements and drop the interleaved text (not verified against a real case).
- **`xs:redefine` / `xs:override`**: schema redefinition/override mechanism, not handled.
- **`xs:all` has no dedicated test**: `collectParticleElements()` queries
  `xs:sequence | xs:choice | xs:all` together everywhere, so the code path is shared with
  sequence/choice, but that assumption itself has never been falsified by an isolated test.

## Generator/package infrastructure

- **Visitor/event hooks for the generator**: no extension point for custom logic during code
  generation beyond `PropertyAttributeStrategy`.
- **Multiple target attribute strategies beyond `symfony/serializer`**: e.g. JMS Serializer or
  native `json_encode` attributes as an alternative.
- **Publish to Packagist**: currently lives only as a local path-repository.
- **AST-based code generation via `nikic/php-parser`'s `BuilderFactory`** instead of the current
  string concatenation (hand-rolled indentation, `use`-collision logic). Bigger quality win, bigger
  rewrite, not a quick win. Not a Symfony component. Prior art:
  `open-code-modeling/php-code-ast` in `../reference-repos.md`.
- **Own CLI via `symfony/console`** (`vendor/bin/xsd2php convert ...`, similar to
  goetas-webservices/xsd2php): only worthwhile if the package is ever consumed standalone, outside
  a project that already wraps it with its own generation script.

## Config options

`Config`'s constructor only takes `xsdPaths`/`namespaceMap`/`attributeStrategy`. Comparing against
`goetas-webservices/xsd2php`, `WsdlToPhp/PackageGenerator`, `janephp/janephp`, and
`makinacorpus/php-xsd-gen`'s generator config surfaces (see `../reference-repos.md`) surfaces options
worth considering:

- **Per-type alias mapping**: renaming one specific generated class by its XSD QName
  (`goetas-webservices/xsd2php`'s DI `aliases` config), finer-grained than `NamespaceMapping`, which
  only maps at the namespace level.
- **`clean-generated`-style output-dir purge before regeneration**: `janephp`'s `ConfigLoader`
  defaults this to `true`. Without it, a generated class for an XSD type later removed/renamed stays
  behind as an orphan; nothing in this generator currently purges the output directory first.
- **Configurable date format(s)**: `janephp`'s `date-format`/`full-date-format`/`date-input-format`.
  Currently hardcoded to `\DateTimeImmutable` with no format context passed to the Symfony Serializer
  attribute.
- **`date-prefer-interface`**: `janephp`. Generate `\DateTimeInterface` instead of
  `\DateTimeImmutable` on date/dateTime properties.
- **Custom type mapping per named `simpleType`**: `janephp`'s `custom-string-format-mapping`.
  Substitutes a consumer-supplied value-object class for a specific named XSD simple type instead of
  the generator's own scalar mapping. A generic version of the `xs:decimal` → Decimal-value-object
  fix (see [ADR 0007](adr/0007-custom-decimal-constraint-for-digit-facets.md)).
- **`skip-null-values`/`include-null-value`**: `janephp`. Serializer-context null-handling flag;
  would need the generator to also emit Symfony Serializer context attributes, which it doesn't yet.
- **Property accessor style as a config axis**: `makinacorpus/php-xsd-gen`'s
  `property_promotion`/`property_public`/`property_readonly`/`property_getter`/`property_setter`
  (individually togglable, `readonly` + `setter` together rejected as invalid). Currently the
  generated property style (readonly public vs. getter/setter vs. promoted constructor param) is
  fixed in the generator, not configurable (the most substantial gap of this batch).
- **Strict-mode toggles for missing/colliding types** (`makinacorpus/php-xsd-gen`'s
  `type_missing_error`/`type_override_error`): hard-fail instead of silently ignoring a missing
  referenced type or silently overwriting on a type-name collision. A distinct concern from the
  group/attributeGroup cycle detection, which is now scoped per reference path ([#4](https://github.com/mario-fehr/xsd-object-mapper/issues/4)).

Priority, if picked up (value vs. effort, not a commitment to build any of it):

1. **Cheap, real gap, do first**: `clean-generated` purge, per-type alias mapping, strict-mode
   missing/colliding-type toggles.
2. **Mechanical, small scope**: `date-prefer-interface`.
3. **Real value, more design work**: custom type mapping per named `simpleType` (also unblocks the
   `xs:decimal` item above), configurable date format(s) and `skip-null-values` (both need Symfony
   Serializer context-attribute generation, which doesn't exist yet, same underlying gap).
4. **Speculative/large, defer**: property-accessor-style axis (biggest rewrite, no known consumer
   need yet).

Deliberately not pursued (checked, ruled out): `naming_strategy`/`path_generator`/
`namespace_dictates_directories` (this generator's PSR-4 output layout is a fixed contract, not
configurable by design); `known_locations`/`known_namespace_locations` (conflicts with the deliberate
"no xs:include/xs:import-following" design, see `Config::$xsdPaths` docblock);
`makinacorpus/php-xsd-gen`'s `class_constructor` public/private toggle (its own stated rationale is
SOAP-tooling-specific, doesn't apply here); its `property_defaults` (unimplemented even in that
source repo, no mature prior art to compare against); a `validation` on/off
toggle (already solved via `PropertyAttributeStrategy` composition: omit the validator strategy);
`enums-as-objects` (already structurally covered by PHP `enum` typing); `allow-external-refs`/
`external-ref-allowed-hosts` (only relevant if import/include-following is ever introduced); class
prefix/suffix (`WsdlToPhp/PackageGenerator`'s `GeneratorOptions::PREFIX`/`SUFFIX`): its stated
collision-avoidance goal is already covered by `Config::$namespaceMap`, which lets a consumer route
each XSD `targetNamespace` to its own PHP namespace/directory; a second, redundant knob isn't
justified without a concrete case namespace routing doesn't cover (see
`docs/specs/2026-08-27-config-options-tier2-design.md`); `add_comments`-style docblock header tags
(`WsdlToPhp/PackageGenerator`'s `ADD_COMMENTS`): generic vanity (author/license lines) with no XSD- or
generator-specific rationale, same value on any unrelated codegen tool (see the same spec);
`makinacorpus/php-xsd-gen`'s `class_factory_method`: its own docblock states "This is part of
generated SOAP tooling", the identical rationale `class_constructor` above was already excluded for.
Also directly redundant with this generator's actual hydration path: it already emits
`#[SerializedName]`/`#[Context]` attributes specifically so `symfony/serializer` can build an instance
from XML/array data without any hand-written array-to-constructor mapping, a generated `create()`
would be a second, competing hydration mechanism solving the same problem this package already solves.

## Type derivation

- **`xs:union`**: `resolveSimpleTypeRef()` falls back to `string`, no real alternative-type
  handling. A 2-member union of compatible date-ish types (`xs:date`/`xs:dateTime`) would be a
  cheap, safe upgrade to `\DateTimeImmutable` (both lexical forms parse fine); a general n-member
  union of arbitrary, potentially incompatible member types is the fundamentally harder case and
  stays out of scope.
- **`xs:list`**: falls back to `string` (with a diagnostic note), loses the actual array-of-`Type`
  semantics. `xs:list` → `Type[]` with a whitespace split/join would be a direct, lossless upgrade
  for the common case (a list of an enum or a simple scalar type).
- **`whiteSpace` facet**: not handled (no `Assert`-equivalent generated). XSD's own default is
  `collapse` for most base types, which limits how often this actually matters in practice.

## symfony/validator constraints

- **`Assert\Choice` for enumerations**: deliberately not a backlog item, already structurally
  covered by PHP `enum` typing.
- **`xs:assertion`** (XSD 1.1 only, arbitrary XPath expression): no generic mapping onto a Symfony
  constraint exists.
- **Fidelity guarantee for XSD pattern → PCRE conversion**: best-effort (`^(?:...)$` wrapping), not
  a complete translation of the XSD regex dialect (e.g. `\p{...}` Unicode categories). Sufficient
  for the patterns a consumer's schema actually uses, not a guarantee for every conceivable pattern.
- **Array-item-wise validation** (`Assert\All` wrapping) for facets/aliases on array properties:
  currently skipped entirely when `isArray === true` (only the array itself, not each item, is
  validated).
- **`xs:decimal` → PHP `float` mapping, no exact decimal arithmetic**: root cause, and why the fix (map to a PHP string or a dedicated Decimal value-object) is deferred — see [ADR 0007](adr/0007-custom-decimal-constraint-for-digit-facets.md).
