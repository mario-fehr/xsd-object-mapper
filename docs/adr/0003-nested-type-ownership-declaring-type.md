# 3. Nested-type ownership follows the declaring type, not an extending subclass

## Status

Accepted (2026-08-27).

## Context

An inline anonymous nested type (an element with an inline `complexType`/`simpleType` child and no `type="..."` attribute) is generated under a synthesized nested PHP namespace built from an "owner" class name. When a base `complexType` declares such a nested type and is extended via `complexContent`/`xs:extension`, its owner must be well-defined: the base that lexically declares the nested type, or each subclass that inherits it? The two answers generate different code: one shared nested class versus one per subclass.

This surfaced as a bug: `Generator::collectProperties()` originally threaded the extending subclass's owner into the base-type recursion, so a base type with an inline nested type, extended by N subclasses, generated N structurally identical nested classes for the same field.

## Decision

The nested-type owner is always the type that lexically declares the inline nested type, never a type
that merely extends it via `complexContent`/`xs:extension`. `resolveBaseProperties()` computes the
base type's own class/namespace identity independently, using the same resolution logic as top-level
complex-type generation, instead of threading a caller-supplied owner through the recursion.

## Consequences

Subclasses extending the same base no longer each generate their own duplicate nested class for a
field declared on the base: all reference one class, owned by the base. This also makes per-base-type
property resolution safely memoizable: a given base type now has one deterministic owner regardless of
caller, so results can be cached by base-type key. Cycle detection is keyed by base-type identity
rather than a caller-accumulated path, which also catches indirect/mutual cycles a path-threaded set
could miss one step too late.

## Considered and rejected

- **Caching by threading the original (subclass) owner unchanged**: rejected, under the old logic the
  owner is caller-dependent, so a naive `$baseKey => result` cache would freeze the first caller's
  owner for every subsequent, semantically different caller.
