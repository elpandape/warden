# Changelog

All notable changes to `elpandape/bouncer` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Pre-1.0, minor versions may break the API.

## v0.6.0 — Cache v2 + Octane (2026-08-17)

### Breaking
- **Folder restructure by domain**: every `ElPandaPe\Bouncer\Database\*` class moved.
  Models live in `Models\*` (internal concerns in `Models\Concerns\*`), the public
  authority traits in `Concerns\*`, tenancy in `Tenancy\*`, titles in `Support\Titles\*`;
  `GateRegistrar`, `Verdict` and the resolvers now group under `Checks\`. Factories keep
  their `Database\Factories` namespace (files now under `database/factories/`).
  Stored data is unaffected: the default morph aliases never referenced class names.

### Added
- **`CachedResolver`, enabled by default**: one minimal versioned payload per authority,
  matched in memory with the database engine's exact semantics — the entire suite runs
  against both resolvers in CI, so they cannot drift.
- **O(1) automatic invalidation**: every write action bumps a version counter for the
  exact tenant scope it touched (global, per-tenant, plus an all-rows counter for
  unscoped checks); stale entries are orphaned, never scanned. Counters reseed randomly
  after eviction so old keys cannot resurrect.
- Anti-stampede locking on cold rebuilds (when the store provides locks), configurable
  TTL, per-request memoization, and payloads that already carry the v0.8 fields
  (constraints, role restrictions) so ABAC will not break the format.
- `Bouncer::refresh()` and `Bouncer::refreshFor($authority)`.
- Octane: the resolver and tenancy are container-scoped — state resets between
  requests and queue jobs (opt out via `bouncer.octane.register_reset_listener`).

### Upgrade notes
- Update imports for the restructure; the public API is otherwise unchanged:
  - `ElPandaPe\Bouncer\Database\{Permission,Role,Grant,AssignedRole}` → `ElPandaPe\Bouncer\Models\*`
  - `ElPandaPe\Bouncer\Database\Concerns\{HasPermissions,HasRolesAndPermissions}` → `ElPandaPe\Bouncer\Concerns\*`
  - `ElPandaPe\Bouncer\Database\Concerns\{IsPermission,IsRole}` → `ElPandaPe\Bouncer\Models\Concerns\*`
  - `ElPandaPe\Bouncer\Database\Tenancy\*` and the tenant traits → `ElPandaPe\Bouncer\Tenancy\*`
  - `ElPandaPe\Bouncer\{GateRegistrar,Verdict}` and `Resolvers\*` → `ElPandaPe\Bouncer\Checks\*`
- With the cache now active by default, raw database edits need `Bouncer::refresh()`
  afterwards; writes made through the API invalidate on their own.

## v0.5.0 — Ownership & multi-tenancy: full parity (2026-08-17)

### Added
- **Ownership resolution**: `toOwn()` grants authorize owned entities only, resolved by
  attribute — configurable globally, per entity class, or via closure — with a
  configurable strict-mode safety valve; ownership forbids now apply to owners only.
- **Multi-tenancy**: instance-based `Tenancy` (`Bouncer::tenant()`, `scope()` alias) with
  exception-safe `onceTo()/removeOnce()`, injectable `TenantResolver`, configurable
  null-tenant semantics (`all`/`strict`), and the catalog/role-grant splits
  (`onlyRelations()`, `dontScopeRoleGrants()`); scoping applies to catalog models,
  pivot joins and the resolver's grant branches consistently.
- **Exact-scope writes**: reads fall back to global rows, but every write targets one
  exact scope — tenant-scoped revokes, retracts and syncs never destroy global rules,
  and a global write is never absorbed by a same-named row inside some tenant.
- Authority query scopes: `whereIs()`, `whereIsAll()`, `whereIsNot()` — tenant-aware
  at query execution time.
- `Tenancy` is a container-scoped binding: tenant state resets between Octane requests
  and queue jobs (opt out via `bouncer.octane.register_reset_listener`).
- The eager-loaded role fast path (`isA()`/`isAll()`) filters by pivot scope, so roles
  loaded under one tenant never leak into another (fail-closed).

### Milestone
- Feature parity with silber/bouncer is complete — its whole niche (instance grants,
  explicit forbids, ownership, tenancy) now runs without the original's defects.

## v0.4.0 — The check engine (2026-08-17)

### Added
- `DatabaseResolver`: the read engine — two queries per check (forbidden first),
  wildcard matching in three dimensions, role/direct/everyone grant branches, and
  qualified column references throughout (no MySQL 9 breakage).
- `GateRegistrar`: wires Bouncer into Laravel's Gate lazily, honoring the configured
  before/after slot; explicit forbids cut the gate, everything else abstains politely
  (guests, extra arguments, non-model strings).
- `Bouncer::can()/cannot()/canAny()/authorize()` passthroughs for the authenticated user.
- The `Resolver` contract, ready for the cached implementation coming in v0.6.0.

### Notes
- Ownership-scoped grants (`toOwn`) do not authorize yet — resolution lands in v0.5.0.

## v0.3.0 — Actions & the PHP 8.4 platform (2026-08-17)

### Breaking
- Minimum PHP is now **8.4** and minimum Laravel is now **12** (Laravel 11 is EOL and
  blocked by Composer security advisories; the Pest 5 toolchain requires PHPUnit 13).

### Added
- The fluent write API, executing immediately (no destructor magic): `allow()/allowEveryone()`,
  `forbid()/forbidEveryone()`, `disallow()`, `unforbid()`, `assign()`, `retract()`,
  `sync()->roles()/permissions()/forbiddenPermissions()` and the `is()` role checks —
  with wildcards (`everything()`, `toManage()`), ownership flags (`toOwn()`) and
  everyone-grants (null entity), all safe under concurrent writers.
- `Bouncer` orchestrator singleton and the `Bouncer` facade.
- Official Pest plugins wired into the gates: `pest-plugin-phpstan` (auto-registered)
  and `pest-plugin-rector` (`PestSetList::CODING_STYLE`); Rector now runs the PHP 8.4 sets.

### Upgrade notes
- Require PHP >= 8.4 and Laravel >= 12 before updating.
- No schema changes: the 0.x migration stays frozen.

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
