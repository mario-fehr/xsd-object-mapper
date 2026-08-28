# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Generator::getWarnings()` exposes the diagnostics collected during the most recent `generate()` run (the same messages written to STDERR).

### Fixed

- `xs:attribute` with `use="prohibited"` is now excluded from the generated class instead of being generated as an optional property. [#3](https://github.com/mario-fehr/xsd-object-mapper/issues/3)
- An `xs:group` or `xs:attributeGroup` referenced more than once within a single type is no longer misreported as a circular reference; cycle detection is now scoped per reference path. [#4](https://github.com/mario-fehr/xsd-object-mapper/issues/4)
