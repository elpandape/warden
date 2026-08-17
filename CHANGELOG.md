# Changelog

All notable changes to `elpandape/bouncer` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Pre-1.0, minor versions may break the API.

## v0.2.0 — Models & schema (2026-08-17)

### Added
- `Permission` and `Role` models plus real pivot models (`Grant`, `AssignedRole`),
  all swappable via `config('bouncer.models')` and resolved through the `Context`.
- `HasRolesAndPermissions` concern for any authority model: `roles()`, `permissions()`
  relations and the `isA()` / `isAn()` / `isNotA()` / `isNotAn()` / `isAll()` role checks.
- Friendly titles generated on creation for roles and permissions (`match(true)`-based,
  never empty, disable with `bouncer.titles.autogenerate`).
- Stable morph aliases (`bouncer.role`, `bouncer.permission`) registered in the provider,
  compatible with `Relation::enforceMorphMap()`.
- Model factories for permissions and roles.
- Opt-in pivot timestamps (`bouncer.pivot_timestamps` + commented columns in the migration stub).

### Fixed (by design, vs the original package)
- UUID/ULID entity keys are never mangled by the models: no hardcoded integer cast on
  entity ids (the original's #626), and the editable migration stub documents the
  string-column variant required to store them on real databases.
- Duplicated role names count as one requirement in "has all roles" checks.
- Role checks answer from the eager-loaded relation when available (no N+1),
  and model overrides fail fast instead of silently falling back.

## v0.1.0 — Foundations (2026-08-17)

### Added
- Package skeleton: manual service provider, `config/bouncer.php` with every key documented.
- `Context` — instance-based registry (tables, connection, morph aliases, ownership attribute)
  replacing the original's global static `Models::` state.
- `Support\Config` typed accessors and the `LogicalOperator`, `ComparisonOperator`, `GateSlot` enums.
- Schema v2 migration stub (four tables: `permissions`, `roles`, `assigned_roles`, `grants`),
  frozen for the 0.x cycle; includes the columns that activate in v0.8.0 (`options`, `restricted_to_*`).
- Translations scaffolding (`en`, `es`).
- Docker + Make development environment (no local PHP/Composer required).
- Quality gates: Pint, PHPStan max (+ Larastan), Rector (PHP sets only, no auto `#[\Override]`),
  Pest with 100% code and type coverage, architecture tests, GitHub Actions.
