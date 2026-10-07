# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-07

### Added

- persistent, model-bound scheduled sequences for Laravel 10–13;
- irregular offsets, complete-sequence recurrence, and recurrence after the final offset;
- durable occurrence records with stable idempotency keys;
- concurrency-safe claiming and recoverable queue publication;
- coalesce, replay, and skip catch-up policies;
- stale-work validation after cancellation, restart, or sequenceable deletion;
- application-job guards, sequence memory, and permanent retention;
- automatic minute scheduler registration and Artisan generation and runner commands;
- SQLite package tests and real-process MySQL and PostgreSQL reliability coverage;
- migration, usage, implementation, and RFC documentation.

[Unreleased]: https://github.com/savgoran/laravel-scheduled-sequence/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/savgoran/laravel-scheduled-sequence/releases/tag/v0.1.0
