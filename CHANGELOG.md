# Changelog

All notable changes to `ttpryg/database-migrator` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-20

### Added

- Initial release: a framework-agnostic PDO schema migration runner with a
  tracker table, incremental `migrate()`, `rollback()`, `appliedMigrations()`,
  and `markAllAsMigrated()` for baseline installs.
- Configurable migrations directory and tracker table name, allowing multiple
  modules to share a database without migration-record collisions.
- Optional automatic tracker table creation (`autoCreateTrackerTable`) and
  public `ensureTrackerTable()` method for restricted database environments.
- Support for migrations recorded under legacy names via
  `Migration::previouslyRecordedAs()`.
- Dedicated exceptions (`InvalidMigrationException`,
  `MigrationNotFoundException`, and the `ExceptionInterface` marker) for
  invalid migration files, unsafe tracker names, and missing rollback files.
