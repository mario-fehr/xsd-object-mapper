# XSD construct coverage

Tracks, per XSD construct, whether the generator supports it and whether that support has an
isolated synthetic test — independent of any specific customer schema. There is no "how often does
this occur in a real-world schema" column here on purpose: this package doesn't know about, and
must not depend on, any particular consumer's XSD files. Anyone using this package against their
own schema can point `bin/xsd-construct-report.php` (a schema-agnostic construct counter, takes any
XSD directory or file) at it to get that kind of real-world-occurrence answer for their own case.

The single bundled official reference schema, `tests/fixtures/w3c-purchase-order.xsd` (W3C XML
Schema Primer's purchase-order example — see the file header for source/license), is exercised
end-to-end by `OfficialSchemaFixtureTest`. It's not meant to cover every construct below by itself;
it's there so the generator is proven against a real, independently-authored, publicly documented
schema, not only against this test suite's own hand-written fixtures.

## Legend

- **Generator:** ✅ supported · ⚠️ fallback/partial support · ❌ not supported (deliberately, see `backlog.md`) · — not generator-relevant
- **Synthetic test:** ✅ an isolated test exists · ❌ none yet

## Particle structure

| Construct                                   | Generator                                                     | Synthetic test |
| ------------------------------------------- | ------------------------------------------------------------- | -------------- |
| `xs:sequence`                               | ✅                                                            | ✅             |
| `xs:choice`                                 | ✅ (nullable properties + an `ExactlyOneOf` class constraint) | ✅             |
| `xs:all`                                    | ✅ (same code path as sequence/choice)                        | ❌             |
| `xs:group` (definition + `ref=`)            | ✅ (resolved recursively, per-path cycle detection)           | ✅             |
| `xs:attributeGroup` (`ref=`)                | ✅ (resolved recursively, cached)                             | ✅             |
| `minOccurs`/`maxOccurs` (incl. `unbounded`) | ✅                                                            | ✅             |
| `xs:any` (wildcard content)                 | ⚠️ falls back to `string`                                     | ❌             |
| `xs:anyAttribute`                           | ❌                                                            | ❌             |

## Type derivation

| Construct                                                                                                                   | Generator                                                             | Synthetic test |
| --------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- | -------------- |
| `xs:simpleContent` (extension, text + attributes)                                                                           | ✅                                                                    | ✅             |
| `xs:complexContent` extension (inheritance)                                                                                 | ✅ (facets merge across a chain of named-simpleType restrictions too) | ✅             |
| `xs:complexContent` restriction                                                                                             | ⚠️ treated like extension, with a warning                             | ❌             |
| Facets: `pattern`, `minLength`/`maxLength`/`length`, `min/maxInclusive`, `min/maxExclusive`, `totalDigits`/`fractionDigits` | ✅                                                                    | ✅             |
| `xs:enumeration` → PHP backed enum                                                                                          | ✅                                                                    | ✅             |
| `whiteSpace` facet                                                                                                          | ❌                                                                    | ❌             |
| `xs:union`                                                                                                                  | ⚠️ falls back to `string`                                             | ❌             |
| `xs:list`                                                                                                                   | ⚠️ falls back to `string`, with a diagnostic note                     | ❌             |
| `mixed="true"` (text + element mixed content)                                                                               | ❌                                                                    | ❌             |
| `abstract="true"` (complexType)                                                                                             | ❌                                                                    | ❌             |
| `substitutionGroup`                                                                                                         | ❌                                                                    | ❌             |
| `xs:redefine` / `xs:override`                                                                                               | ❌                                                                    | ❌             |

## Element/attribute declarations

| Construct                                           | Generator                                                                                                           | Synthetic test                  |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| `default=` / `fixed=`                               | ✅ (doc-comment hint only, doesn't change nullability or serialization)                                             | ✅                              |
| `nillable="true"`                                   | — (not a generator concern; handled at the serializer-integration layer by whatever consumes the generated classes) | —                               |
| `use="required"`/`"optional"`                       | ✅                                                                                                                  | ✅                              |
| `use="prohibited"`                                  | ✅ (excluded from the generated class)                                                                              | ✅                              |
| `ref=` (element)                                    | ✅                                                                                                                  | ✅                              |
| `ref=` (attribute)                                  | ⚠️ warns and skips                                                                                                  | ✅                              |
| Global vs. anonymous (inline) type                  | ✅                                                                                                                  | ✅                              |
| `elementFormDefault`/`attributeFormDefault`/`form=` | ✅                                                                                                                  | ✅ (only `qualified` exercised) |

## Identity constraints

| Construct                            | Generator | Bewertung                                                                                                                                                                          |
| ------------------------------------ | --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `xs:key` / `xs:keyref` / `xs:unique` | —         | Deliberately not a generator concern: identity constraints validate cross-references _within_ one XML instance document, they don't affect the shape of the generated PHP classes. |

## Namespace handling

| Construct                              | Generator                                                                                     |
| -------------------------------------- | --------------------------------------------------------------------------------------------- |
| `xs:import` (cross-namespace)          | ❌ not followed                                                                               |
| `xs:include` (same-namespace)          | ❌ not followed — callers must list every contributing file explicitly in `Config::$xsdPaths` |
| Multiple `targetNamespace`s in one run | ✅                                                                                            |

See `backlog.md` for the reasoning and status behind every ❌/⚠️ row above.
