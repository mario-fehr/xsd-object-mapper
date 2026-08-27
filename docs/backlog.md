# Known limitations / backlog

Design decisions and known gaps for `xsd2php` itself — independent of any specific consumer's XSD
files. See `construct-coverage.md` for the construct-by-construct support/test matrix this backlog
explains the reasoning for.

## Structural XSD constructs

- **`substitutionGroup` / abstract complexTypes (polymorphism)** — no clean PHP equivalent, its own
  design problem. Not solved by goetas-webservices/xsd2php either.
- **`xs:any` (wildcard content)** — fundamentally untypeable; any static generator can only fall
  back to a string/raw-XML representation.
- **`xs:anyAttribute`** — same reasoning as `xs:any`, no static PHP equivalent.
- **`mixed="true"` (text + element mixed content)** — not recognized/handled; would likely silently
  process only the child elements and drop the interleaved text (not verified against a real case).
- **`xs:redefine` / `xs:override`** — schema redefinition/override mechanism, not handled.
- **`use="prohibited"` on `xs:attribute`** — mishandled: `$use !== 'required'` treats `prohibited`
  the same as `optional` (nullable/allowed) instead of forbidding the attribute entirely. A real
  logic bug, independent of how often any particular schema happens to use `use="prohibited"`.
- **`xs:all` has no dedicated test** — `collectParticleElements()` queries
  `xs:sequence | xs:choice | xs:all` together everywhere, so the code path is shared with
  sequence/choice, but that assumption itself has never been falsified by an isolated test.
- **`resolveNamedRef()`'s `$seenGroups` cycle detection is too coarse** — threaded by reference
  through the _entire_ particle tree of one `collectParticleElements()` call, including across
  sibling branches, not just along one reference path. A group referenced twice from independent
  places within the same complexType (legitimate reuse, not a real cycle) gets misreported as
  "circular ref" on the second occurrence and its elements are silently dropped. Fix would need
  `$seenGroups` scoped per-path instead of tree-wide (e.g. a copy per sequence/choice branch instead
  of threading the same reference, "seen" only along one ref path).
- **Visitor/event hooks for the generator** — no extension point for custom logic during code
  generation beyond `PropertyAttributeStrategy`.
- **Multiple target attribute strategies beyond `symfony/serializer`** — e.g. JMS Serializer or
  native `json_encode` attributes as an alternative.
- **Publish to Packagist** — currently lives only as a local path-repository.
- **AST-based code generation via `nikic/php-parser`'s `BuilderFactory`** instead of the current
  string concatenation (hand-rolled indentation, `use`-collision logic). Bigger quality win, bigger
  rewrite, not a quick win. Not a Symfony component. Prior art:
  `open-code-modeling/php-code-ast` in `reference-repos.md`.
- **Own CLI via `symfony/console`** (`vendor/bin/xsd2php convert ...`, similar to
  goetas-webservices/xsd2php) — only worthwhile if the package is ever consumed standalone, outside
  a project that already wraps it with its own generation script.

## Type derivation

- **`xs:union`** — `resolveSimpleTypeRef()` falls back to `string`, no real alternative-type
  handling. A 2-member union of compatible date-ish types (`xs:date`/`xs:dateTime`) would be a
  cheap, safe upgrade to `\DateTimeImmutable` (both lexical forms parse fine); a general n-member
  union of arbitrary, potentially incompatible member types is the fundamentally harder case and
  stays out of scope.
- **`xs:list`** — falls back to `string` (with a diagnostic note), loses the actual array-of-`Type`
  semantics. `xs:list` → `Type[]` with a whitespace split/join would be a direct, lossless upgrade
  for the common case (a list of an enum or a simple scalar type).
- **`whiteSpace` facet** — not handled (no `Assert`-equivalent generated). XSD's own default is
  `collapse` for most base types, which limits how often this actually matters in practice.

## symfony/validator constraints

- **`Assert\Choice` for enumerations** — deliberately not a backlog item: already structurally
  covered by PHP `enum` typing.
- **`xs:assertion`** (XSD 1.1 only, arbitrary XPath expression) — no generic mapping onto a Symfony
  constraint exists.
- **Fidelity guarantee for XSD pattern → PCRE conversion** — best-effort (`^(?:...)$` wrapping), not
  a complete translation of the XSD regex dialect (e.g. `\p{...}` Unicode categories). Sufficient
  for the patterns a consumer's schema actually uses, not a guarantee for every conceivable pattern.
- **Array-item-wise validation** (`Assert\All` wrapping) for facets/aliases on array properties —
  currently skipped entirely when `isArray === true` (only the array itself, not each item, is
  validated).
- **`xs:decimal` → PHP `float` mapping, no exact decimal arithmetic** — a known precondition of the
  generator, not newly introduced. `DecimalValidator` only checks the digit count of the string
  representation, it can't heal float-imprecision rounding artifacts. A real fix would need
  `xs:decimal` → a PHP string or a dedicated Decimal value-object instead of `float` — a bigger,
  breaking change to the generator.

## Static analysis

- **`phpstan-baseline.neon` still carries ~175 findings** (down from 219, see the `Property` value
  object entry below) — the two remaining dominant sources:
  - **`$typeInfo`'s untyped array shape** — `resolveParticleType()` and
    `resolvePrimitiveOrNamedSimpleType()` both return a `kind`/`phpType`/`dateOnly`/`facets`/
    `namedType` array bag, threaded through `makeProperty(array $typeInfo, ...)` and every call
    site. Same shape of problem the `Property` value object already solved for the property side;
    this is the type-resolution side, still untyped. A real fix needs its own value object (or a
    PHPStan-level `array{...}` shape declaration, which only silences the noise instead of making
    the impossible states unrepresentable) — deliberately deferred rather than folded into the
    `Property` migration, since `$typeInfo` is a distinct concern (type resolution vs. property
    identity) and widening that change would have made the `Property` migration itself harder to
    review.
  - **`ext-dom`'s own typing** — PHPStan sees `\DOMNodeList<\DOMNameSpaceNode|\DOMNode>|false` from
    `getElementsByTagNameNS()`, untyped `getAttribute()`/`hasAttribute()` on `\DOMNode`, etc. This
    is inherent to the standard library's stubs, not something this codebase can fix short of
    wrapping every DOM call in a typed adapter — not planned, the wrapping overhead isn't justified
    for a code-generation tool that touches the DOM API in exactly the ways its stubs are weakest
    at (attribute/text access, node-list iteration).

## Resolved

- **`makeProperty()`'s untyped array property bag** — replaced with a `Property` value object +
  `PropertyRole` enum (`Element`/`Attribute`/`Text` instead of 2 independent `isAttribute`/
  `isText` booleans, so the 4th impossible combination is now structurally unrepresentable).
  Shrunk `phpstan-baseline.neon` from 219 to 180 findings (see
  `docs/specs/2026-08-27-property-value-object-design.md`).
- **`xs:choice` treated like `xs:sequence`** — fixed: `collectParticleElements()` now tracks the
  enclosing `xs:choice` particle per element; choice-branch elements become nullable, plus a
  class-level `#[ExactlyOneOf(fields: [...])]` constraint (`required: false` when the choice itself
  has `minOccurs="0"`). Known, deliberately warn-and-skip-guarded limits: a repeatable choice
  (`maxOccurs > 1` on the choice itself), a choice branch with more than one element (a nested
  `xs:sequence`/`xs:group`/`xs:choice` directly under the choice — one atomic alternative, not N
  independent "one of" members, not representable), name collisions with a later non-choice
  property.
- **Facet inheritance across a chain of nested named-simpleType restrictions was overwritten
  instead of merged** — `resolveSimpleTypeRef()`/`resolveParticleType()` now merge facets across
  the whole restriction chain (own facet wins on a key collision), not just the immediate
  restriction.
- **`DecimalValidator`'s digit counting had 2 bugs** — scientific/exponential notation
  (`(string) $value` switching to `"1.234E-5"` outside PHP's `precision` range) was misparsed as
  fraction digits; leading zeros in the fraction part weren't excluded from `totalDigits` when the
  integer part is zero (`'0.05'` counted as 2 significant digits instead of 1). Both fixed.
- **`xs:attribute ref="..."` silently dropped, no warning** (unlike the equivalent element-ref
  path) — now warns and skips, consistent with the element-ref behavior.
- **`Generator::pathFor()` picked the first matching namespace mapping, not the most specific one**
  — for two mappings where one's PHP namespace is a prefix of the other's, it now picks the longest
  (most specific) match.
- **`SymfonyValidatorAttributeStrategy::toPcrePattern()`'s delimiter fallback could still collide**
  with the pattern content — now tries a small candidate list instead of a single fallback
  character.
- **`xs:default`/`xs:fixed` were completely ignored** — now surfaced as a `(XSD-Default: ...)` /
  `(XSD-Fixed: ...)` doc-comment hint on the generated property, purely informational (doesn't
  change nullability, defaults, or serialization behavior).
