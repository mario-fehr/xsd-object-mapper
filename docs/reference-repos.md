# Reference repositories

Prior art for XSD→PHP (and adjacent schema→PHP) code generation. Consulted when deciding
how to handle a construct, not copied from — see [Independence](../CLAUDE.md#independence)
and each repo's own license (all MIT below, but study the pattern, don't vendor the code).

Clone on demand into `.references/<name>/` (git-ignored, read-only) via the
`add-reference-repository` skill. Re-clone to refresh; never edit in place. Not kept
checked out permanently — this repo has no monorepo-scale need for a standing
`.references/` tree the way a bundler-plugin project does.

| Upstream                                                                                    | Why                                                                                                                                                                                                                                                                       |
| ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [goetas-webservices/xsd2php](https://github.com/goetas-webservices/xsd2php)                 | Closest direct prior art: XSD → PHP classes + JMS Serializer metadata. Compare construct coverage, config shape, naming/collision handling.                                                                                                                               |
| [goetas-webservices/xsd-reader](https://github.com/goetas-webservices/xsd-reader)           | Pure-PHP XSD schema reader xsd2php is built on. Compare against our own XSD parsing for edge cases (imports/includes, redefine, substitution groups).                                                                                                                     |
| [goetas-webservices/xsd2php-runtime](https://github.com/goetas-webservices/xsd2php-runtime) | Runtime support shipped alongside generated code (base collection types, validators). Compare against `src/Validator/` for what belongs in generated code vs. a runtime dependency.                                                                                       |
| [WsdlToPhp/PackageGenerator](https://github.com/WsdlToPhp/PackageGenerator)                 | Different architecture (WSDL+XSD → full PHP SDK), most actively maintained of the bunch. Useful second opinion on construct handling (`choice`, `restriction`/`extension`) from a codebase that made different design tradeoffs than goetas.                              |
| [janephp/janephp](https://github.com/janephp/janephp)                                       | Not XSD — JSON Schema/OpenAPI → PHP models + API clients. Same generator-architecture family (schema → typed PHP + serializer metadata) from a very active, mature multi-package project. Useful for generator/config/pluggable-strategy design, not construct semantics. |
| [makinacorpus/php-xsd-gen](https://github.com/makinacorpus/php-xsd-gen)                     | Smaller, modern-PHP-syntax (constructor property promotion) take on the same XSD → PHP problem. Useful as a "what would this look like designed today" comparison against goetas's older codebase.                                                                        |
| [open-code-modeling/php-code-ast](https://github.com/open-code-modeling/php-code-ast)       | Not XSD — `nikic/php-parser`-based AST builder with a high-level OO API for PHP code generation. Relevant to the `backlog.md` item on moving off string concatenation to AST-based generation, not to construct semantics.                                                |

See [AGENTS.md](../AGENTS.md#reference-prior-art-generators) for when to consult this list.
