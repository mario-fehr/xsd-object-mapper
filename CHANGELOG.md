# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `xs:attribute` with `use="prohibited"` is now excluded from the generated class instead of being generated as an optional property. [#3](https://github.com/mario-fehr/xsd-object-mapper/issues/3)
