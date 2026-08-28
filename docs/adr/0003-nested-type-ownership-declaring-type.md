# 3. Nested-type ownership follows the declaring type, not an extending subclass

## Status

Accepted (2026-08-27).

## Context

`Generator::collectProperties()` recurses into a base `complexType` on `complexContent`/`xs:extension`.
An inline anonymous nested type (e.g. an element with an inline `complexType`/`simpleType` child, no
`type="..."` attribute) is generated under a synthesized nested PHP namespace built from an "owner"
class name. The recursion originally passed the _extending subclass's_ owner down into the base-type
recursion, not the base type's own identity, so a base type with an inline nested type, extended by
N subclasses, generated N structurally identical nested classes for the same field instead of one.

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
