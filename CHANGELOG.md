# Changelog

All notable changes to `elpandape/warden` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Pre-1.0, minor versions may break the API.

## v1.0.0 — Renamed to Warden (2026-08-18)

The package is now `elpandape/warden`. Nothing about the behaviour changed: this
release is `elpandape/bouncer` v1.0.0 under a new name, because "Bouncer" belonged
to the package this one succeeds. `elpandape/bouncer` is abandoned and declared in
`conflict`; the two cannot coexist.

`elpandape/bouncer` existed for a single day and shipped no migration tooling of
its own: the table below is the whole upgrade. The morph aliases are the only
entry that touches data already written — they live in `entity_type` and
`restricted_to_type`, and a stale value makes a grant stop matching instead of
raising.

### Breaking

| Was | Is |
|---|---|
| `elpandape/bouncer` | `elpandape/warden` |
| `ElPandaPe\Bouncer\*` | `ElPandaPe\Warden\*` |
| `ElPandaPe\Bouncer\Bouncer` | `ElPandaPe\Warden\Warden` |
| `BouncerServiceProvider` | `WardenServiceProvider` |
| `Bouncer` facade / root alias | `Warden` |
| `BouncerFake` | `WardenFake` |
| `php artisan bouncer:*` | `php artisan warden:*` |
| `config/bouncer.php`, `config('bouncer.*')` | `config/warden.php`, `config('warden.*')` |
| `--tag=bouncer-config` / `bouncer-migrations` | `warden-config` / `warden-migrations` |
| `*_create_bouncer_tables.php` | `*_create_warden_tables.php` |
| morph aliases `bouncer.role` / `bouncer.permission` | `warden.role` / `warden.permission` |
| middleware `bouncer.role` / `bouncer.permission` | `warden.role` / `warden.permission` |
| translations `bouncer::bouncer.*` | `warden::warden.*` |
| cache prefix `bouncer` | `warden` |

Migrating from `silber/bouncer` is unaffected: `warden:upgrade` and
`stubs/rector-silber-upgrade.php` target `elpandape/warden` directly — see
[UPGRADE.md](UPGRADE.md).


---

## Released as `elpandape/bouncer`

Everything below shipped under the old package name. The entries are left as
published — the commands, classes and config keys they mention are the ones that
existed at the time.

## v1.0.0 — The package, finished (2026-08-18)

The successor to silber/bouncer is done: its whole niche (instance-level grants,
explicit forbids, ownership, multi-tenancy) reimplemented without the original's
defects, plus the features its tracker asked for and never got — evaluated ABAC
constraints, model-scoped roles, `whereCan()`, `explain()`, typed events and
exceptions, a versioned cache with automatic invalidation, and a one-command
upgrade from the original.

**From here on, strict semver**: breaking changes wait for 2.0.

### Added

- `CONTRIBUTING.md`, and a README rebuilt for readers rather than for the order
  features happened to be written in: install and a quickstart first, everyday
  usage next, advanced capabilities after, reference last. Badges now report live
  CI, Packagist and license state instead of hardcoded claims.

### Notes

- Audited against all 45 open issues of silber/bouncer: 20 resolved, 19 partially
  addressed, 4 open by decision or deferred to 1.1, 2 not applicable.
- Verified on PHP 8.4 and 8.5, Laravel 13, SQLite, MySQL 9 and Postgres 16, against
  both the database and cached resolvers, at 100% coverage and type coverage.

## v1.0.0-rc.3 — Upstream issue audit (2026-08-18)

### Fixed

- `Context::table()` fails fast with a `ConfigurationException` when a configured
  table name is empty — previously the misconfiguration surfaced much later as
  broken SQL with an empty identifier, the same failure family as the upstream
  MySQL 9 report (#667).

### Docs

- **Recipes** section in the README: seven patterns for the questions the original
  package's tracker kept receiving — authorizing another user, pivot-table
  ownership, a default role per new user, landlord/tenant databases, roles grouped
  by tenant, role replacement, and long-lived processes (tinker/Octane/queues).
- UPGRADE.md states the scope of `bouncer:upgrade`: stable legacy schemas (>= 1.0);
  ancient pre-1.0 rc schemas must migrate to upstream 1.0 first.

### Audit

- All 45 open issues of silber/bouncer were audited against this codebase, each
  claim adversarially verified (several by execution): **20 resolved, 19 partially
  addressed, 4 not resolved, 2 not applicable**. The four open ones are recorded
  design decisions or v1.1 candidates (batch authorities, `whoCan()`, `forUser()`),
  not architectural gaps.

## v1.0.0-rc.2 — Supported versions (2026-08-18)

### Breaking

- **Minimum Laravel is now 13** (`illuminate/* ^13.0`). Laravel 12 could not be
  tested with this package's toolchain — `pestphp/pest ^5` requires
  `symfony/process ^8` while `orchestra/testbench 10` (Laravel 12) requires `^7`,
  an unsatisfiable pair. Rather than ship compatibility nobody verifies, Laravel 12
  is dropped, exactly as Laravel 11 was in v0.3.0. Stay on v1.0.0-rc.1 if you need
  Laravel 12 and accept untested support.

### Fixed

- CI runs assertions enabled (`zend.assertions=1`), matching the local Docker image,
  so `assert()` guards count as covered and the 100% gate is meaningful in both places.
- Workflows moved to `actions/checkout@v7`.

## v1.0.0-rc.1 — API freeze (2026-08-18)

### Frozen

- **The public API is frozen**: every class, method and config key documented in the
  README is a 1.0 contract. Only fixes land between this candidate and 1.0.0.

### Hardened

- Mutation testing over the core (resolvers, constraints, tenancy, actions): surviving
  mutants that pointed at real assertion gaps were killed with targeted tests.
- Release checklist: `composer validate --strict` clean, `composer audit` clean,
  translations (`en`/`es`) in sync.
- Developer loop: `make test`/`make test-cached` run in parallel (~5x faster),
  `make mutation` is a first-class per-path target mirrored by the nightly, and the
  install-command tests publish into private paths so nothing races the shared
  skeleton.

## v0.10.0 — Migration & polish (2026-08-18)

### Added

- **`bouncer:upgrade`**: transforms a silber/bouncer database in place — the pivot
  crossing in the only safe order (`permissions`→`grants` with `ability_id`→
  `permission_id`, then `abilities`→`permissions`), legacy role morphs rewritten
  (custom classes via `--role-morph`), never-evaluated legacy constraint blobs
  cleared to preserve real prior behavior, `--dry-run` report, atomic on
  Postgres/SQLite.
- **[UPGRADE.md](UPGRADE.md)**: the full migration guide, equivalence tables, and a
  ready-made Rector set (`stubs/rector-silber-upgrade.php`) that renames imports and
  calls in app code.
- **Optional route middleware** (`bouncer.role:admin,editor` any-of,
  `bouncer.permission:edit-site` all-of) throwing the typed `UnauthorizedException`
  with the required roles/permissions — off unless `register_middleware_aliases`.
- **`@forbidden` Blade directive**: renders only on an explicit denial — distinct
  from merely lacking a permission, the distinction only this data model can make.
  Off unless `register_blade_directives`.

### Decisions (recorded)

- Forbid precedence stays absolute, documented as the model's security contract,
  with `explain()` as the diagnostic; forbid-with-exceptions is a post-1.0 candidate.
- Multi-guard support is formally deferred to the post-1.0 backlog.

## v0.9.0 — Queries & diagnosis (2026-08-17)

### Added

- **`whereCan()`** (`QueriesByPermission` concern): `Post::whereCan($user, 'view')` —
  grant existence resolves once at build time; shapes, ownership (attribute-resolved),
  tenancy and **ABAC constraints compile into row conditions** with SQL precedence.
  Closure-resolved ownership and restricted-role assignments cannot become SQL and
  fail closed; undecidable forbids block their shape rows. Row-for-row parity with
  `can()` is pinned by tests.
- **`Bouncer::explain()`**: an `AuthorizationExplanation` with the verdict, the cause
  (granted/forbidden × directly/via-role/to-everyone, no-match, not-applicable), the
  decisive permission row and the carrying role — always from the database engine, so
  it diagnoses stale-cache gotchas; readable via `(string)`.
- **Testing tools**: `Bouncer::fake()` — a scriptable resolver that records checks
  (`allow`/`forbid` rules, forbidden-first) with `assertChecked/NotChecked/ NothingChecked/Granted/Forbidden`; plus the `WithPermissions` trait for terse
  arrange steps against real rows.
- **Artisan commands**: `bouncer:install [--migrate]`, `bouncer:show [Class:id]`,
  `bouncer:cache-reset`, `bouncer:clean [--dry-run]` (chunked, event-firing), and a
  `php artisan about` section.

## v0.8.0 — ABAC & scoped roles (2026-08-17)

### Breaking

- The `assigned_roles` unique index now includes `restricted_to_type/id`, so the same
  role can repeat across contexts. Re-publish and re-run the package migration if you
  are on an earlier 0.x (the one planned exception to the 0.x schema freeze).

### Added

- **ABAC constraints, actually evaluated** (the original shipped the infrastructure and
  never ran it): `allow()->to()->where()/orWhere()/whereColumn()` with Eloquent-shaped
  grammar, SQL precedence (AND over OR, closures group), strict comparisons, and
  evaluation in **both** engines — constrained rows fall through to the next candidate
  in specificity order. Constrained grants never match instance-less checks.
- Safe persistence: versioned JSON discriminated by enum — never a class name; corrupt
  or unknown shapes fail closed instead of widening a grant.
- Distinct constraints mean distinct catalog rows: refining one holder's grant re-points
  it to a twin permission and never mutates rows shared with other holders.
- **Model-scoped roles**: `assign(...)->on($context)->to(...)` fills the long-dead
  `restricted_to_*` columns. Membership resolves by convention (`{context}_id`), config
  (`bouncer.restrictions.default_attribute`), per-class attribute or closure via
  `Bouncer::restrictedVia()`. Restricted assignments contribute nothing to
  instance-less checks; `retract()->on()` removes one context only.
- Cache payload v2: role tuples carry their assignment's restriction; v1 entries are
  invalidated cleanly by the version bump.
- Role events now carry the real restriction context (`restrictedTo`).

## v0.7.0 — Events & exceptions (2026-08-17)

### Added

- **Typed events for every write**, with hydrated models and symmetric payloads:
  `PermissionGranted/Revoked/Forbidden/Unforbidden`, `RoleAssigned/Retracted`
  (with the `restrictedTo` slot v0.8 will fill), `RolesSynced`/`PermissionsSynced`
  carrying a full `SyncResult` diff (attached/detached/kept), and catalog lifecycle
  events (`RoleCreated/Deleted`, `PermissionCreated/Deleted`) fired at the model layer
  so every creation path counts. Post-action events always fire after cache
  invalidation. Disable with `bouncer.events_enabled`.
- **Cancellable pre-action events** (opt-in via `bouncer.cancellable_events`):
  `GrantingPermission`, `ForbiddingPermission`, `AssigningRole` — a listener returning
  `false` aborts the write. `sync()` is declarative and exempt by design.
- **Typed exceptions**: `UnauthorizedException` (extends `AuthorizationException`;
  `getRequiredPermissions()`/`getRequiredRoles()`, translatable en/es messages, naming
  the missing permission/role stays opt-in behind the anti-leak flags),
  `RoleDoesNotExist` and `PermissionDoesNotExist` (both `ModelNotFoundException`),
  and `ConfigurationException` for fail-fast misconfiguration.
- `Bouncer::findRole()` / `Bouncer::findPermission()`: strict, tenant-aware finders.
- **`BackedEnum` accepted in every public signature** that takes a permission or role
  name: grants, forbids, revokes, assignments, syncs, role checks, query scopes,
  `can()`/`canAny()`/`authorize()` and the finders.

### Changed

- `Bouncer::authorize()` now throws `UnauthorizedException` (policy denial messages
  are preserved; existing `AuthorizationException` catch blocks keep working).
- Revoking from a role name that does not exist now throws `RoleDoesNotExist`
  (previously a generic `ModelNotFoundException` — subclass, still catchable).

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
