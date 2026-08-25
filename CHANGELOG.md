# Changelog

All notable changes to `elpandape/warden` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Pre-1.0, minor versions may break the API.

## v2.0.0 — Identity, and rules that mean what they say (2026-08-25)

The catalog tuple becomes a constraint instead of a convention, a narrowing chain edits its
rule instead of stacking one beside it, relation writes stop crossing tenants, and several
defaults that used to widen silently now refuse. **Read the breaking changes before
upgrading: an install carrying duplicate catalog rows cannot migrate until it resolves them.**

### Breaking

- **Relation writes target one exact scope.** `detach()`, `sync()`, `toggle()`,
  `syncWithoutDetaching()` and `updateExistingPivot()` on `roles()` and `permissions()` reached
  every scope at once, so a call under one tenant destroyed another tenant's rows — including
  the `forbidden` ones a check relied on. They now touch only what the write scope owns, and
  `attach()` stamps it. Warden builds these relations itself, so a model overriding
  `newMorphToMany()` no longer shapes them.
- **`permissions` gains a unique index** over the name and a key carrying the rest of the
  identifying tuple. An existing install publishes and runs the upgrade migration
  (`vendor:publish --tag=warden-migrations-v2`), which adds the column, computes the key for
  every row, and stops before the index if rows still identify the same permission — resolve
  those with `warden:clean --duplicates` and run it again. It fails rather than picking a
  winner silently. See [UPGRADE.md](UPGRADE.md#from-warden-1x-to-20).
- **A second `where()` on the same concession replaces the condition** instead of adding a
  second rule whose union authorises both.
- **A `where()` after a vetoed call throws.** The chain is forgotten when a call writes
  nothing, rather than staying aimed at whatever it named before.
- **`findPermission()` identifies by the tuple** and throws on an ambiguous name instead of
  returning whichever row came first. It takes the entity and ownership shape.
- **`disallow()` and `unforbid()` by name no longer remove constrained twins.** A twin is a
  different rule; name it with its model to reach it, which the ownership variant now accepts.
- **An unrecognised `warden.scope.null_behavior` throws** rather than reading as `all`.
- **`retract()->on()` rejects an unsaved or keyless context**, as assigning already did.
- **Generated titles split camel case**, so `viewAny` reads `View any`.
- **The fake no longer answers entity-scoped checks with entity-less rules.**

### Fixed

- A duplicate catalog row widened a grant: re-pointing one row left the other's unconstrained
  grant passing and the narrowing condition silently discarded.
- The sync sweep deleted grants it could never have declared, because a name resolves to the
  plain row while the sweep reached every shape.
- A failure midway through a narrowing chain left the concession deleted and unreplaced. It
  now runs in a transaction.
- An empty group turned an unconstrained grant into a constrained one no class-level check
  could match.
- Morph aliases are registered from an in-code default, so a published config that dropped the
  key stops orphaning every stored row.
- A scripted rule in the fake answered for every authority, so a fake could not say that the
  admin may publish and the guest may not. It still does when no authority is named, which is
  the documented default rather than an accident.

### Added

- **An upgrade migration for installs already on 1.x.** 2.0 writes an identity key on every
  catalog save and a 1.x database has no column to hold it. Publish it with
  `vendor:publish --tag=warden-migrations-v2`: it adds the column, computes the key for every
  existing row, and only then adds the unique index. It stops before the index when rows still
  name the same rule — the state `warden:clean --duplicates` needs — and finishes on the run
  that follows. `Testing\Schema` exposes it as `upgradeToV2()` for packages that build the
  schema themselves.
- `warden:clean --duplicates` collapses catalog rows that identify the same permission.
- **The fake can express what a real rule expresses.** `for()` binds a rule to one authority,
  `owned()` to what that authority owns, `where()` / `whereColumn()` to a condition, and
  `inScope()` to a tenant. The `*` permission and the `*` entity now answer the way the
  catalog's wildcard rows do. Ownership, conditions and tenancy are decided by the same pieces
  the database engine uses — `Context::isOwnedBy()`, the constraint group,
  `Tenancy::readFilter()` — and a parity suite asserts the fake and the engine return the same
  verdict across the shapes a rule can take.
- UPGRADE.md now covers warden 1.x → 2.0 beside the silber/bouncer path.

## v1.3.0 — Conditions that cannot fire (2026-08-25)

### Fixed

- An entity-less constrained forbid now blocks instead of abstaining, matching the branch
  beside it. The README's "a constrained grant never matches instance-less checks" speaks
  only of grants, so the forbid side was undocumented fail-open behaviour.

### Changed

- **Constraining a permission with no entity now throws.** It used to succeed and write a
  row that could never match, leaving the holder with strictly less than omitting the
  condition would have. Code that did this was already getting nothing; it now finds out
  at the call site.

### Documentation

- The query trait is required: without it, `Model::whereCan()` never reaches Warden and
  Laravel reads it as a dynamic `where` against a column named `can`.
- Both pivot relations return granted and forbidden rows mixed, with the filter to read
  one side.
- Titles are generated once, on creation, and only when none was given.
- Role assignments are one hop: warden has no role hierarchy.

## v1.2.0 — One reading doctrine (2026-08-25)

### Fixed

- The uncached resolver now honours a swapped grant model's global scopes. It built the
  grants subquery on the raw query builder and re-implemented warden's tenant read filter
  by hand, so a custom model's scopes were silently dropped and the copy could drift from
  the scope it copied. Both engines now read grants the same way.

### Changed

- **`whereCan()` no longer hydrates the whole candidate catalog.** The candidate query is
  narrowed to the rows the authority could actually hold. With a catalog of 201 rows for
  one name and a single grant held, it hydrates 1 row instead of 201.
- For a consumer who has swapped `warden.models.grant` **and** given it a global scope,
  `can()` and `explain()` now exclude what that scope excludes, where they previously
  ignored it. The narrowing fails closed and makes the two engines agree.

## v1.1.0 — Invalidation, events and diagnosis (2026-08-25)

### Added

- `Contracts\ActorResolver` names who performs a write. Every write event now carries
  `?Model $actor`, filled in automatically, defaulting to the authenticated user and
  overridable through `warden.actor_resolver` for queues, console and impersonation.
- Cancellable pre-events for the removals: `RevokingPermission`, `UnforbiddingPermission`
  and `RetractingRole`, matching the veto the write side already had.
- `Cause::ConditionsNotMet` tells a rule whose conditions did not hold apart from no rule
  at all, and names the row that decided.
- `ConstraintSerializer::sameRule()` answers whether two option blobs describe the same
  rule — the twin-identity comparison, previously private.
- `Context::restrictionResolverFor()` mirrors the ownership accessor, and
  `Context::resolvesOwnershipFor()` finally makes "this model has no owner" expressible.
  An explicit `warden.ownership.default_attribute => null` is now honoured.
- `Testing\Schema::up()/down()` gives dependent packages a loadable entry point to the
  schema, which previously existed only as a publishable stub.
- `Grant` and `AssignedRole` declare their relations: `permission()`, `role()`, `entity()`
  and `restrictedTo()`.
- `retract()->from()` reports `retractedCount()`.
- `warden:clean --stranded` deletes grants whose authority row is gone.

### Fixed

- **Writes that never reach a fluent action now invalidate cached checks.** A model write,
  a relation `attach()`/`detach()`, or a foreign-key cascade used to leave every cached
  check answering the old value until the TTL expired. The cascade is predicted rather
  than hooked — it runs inside the engine, where no model event fires — and its scopes are
  read unscoped, because the tenant filter hides rows the cascade destroys anyway.
- A permission delete now announces the grants it took with it, per authority and scope.
- Deleting a role sweeps the grants it held: they hang off polymorphic columns no foreign
  key reaches, so they outlived the role.
- The cached engine no longer denies everything when the grant model is swapped for a bare
  `MorphPivot`. Tuple booleans are normalised where the tuple is built rather than trusted
  from a cast the override contract does not require, and an `int` zero never matched the
  strict comparison.
- `whereCan()` fails closed instead of emitting SQL for an ownership column the table does
  not have, where `can()` merely denied — the two engines disagreed by a `QueryException`.
- A narrowing chain announces its re-point instead of leaving the unconstrained grant as
  the last word.
- `explain()` no longer re-reads the row the resolver just decided on.

### Changed

- **A narrowing chain now emits `PermissionGranted`, `PermissionRevoked`,
  `PermissionGranted`** where it emitted one grant event. A listener that appends to a
  ledger is unaffected; one treating `PermissionGranted` as "state settled" must become
  idempotent.
- **Invalidation costs more per write.** A relation `attach()` of N rows now advances the
  counter, where it previously advanced nothing and left a stale answer for the full TTL.
  An action's write and the row hooks beneath it coalesce to one bump per scope.
- Pre-action events cover the whole call: a listener returning `false` aborts every item in
  it. Documented rather than changed.

## v1.0.2 — Constraint and query engine fixes (2026-08-24)

### Fixed

- An unreadable `permissions.options` blob no longer reads as "no conditions". The
  three engines now read the column rather than the array cast, which turned
  undecodable JSON into `null` and widened a constrained grant to every row. The
  serializer's `json_validate()` branch fails closed in each pass's safe direction.
- A forbid that cannot be expressed in SQL keeps the row conditions already collected,
  so a forbid pinned to one record blocks that record instead of excluding the whole
  table from `whereCan()`.
- A non-boolean value compared against a `bool`-cast column now compiles to the same
  impossible predicate as the reverse mismatch. Only one of the two directions was
  guarded, so `can()` and `whereCan()` could disagree.

### Changed

- Cache payload version raised to 3: entries hold the raw options blob rather than a
  decoded array. Existing entries are orphaned rather than misread, as the versioning
  headroom promises.
- The constraints section of the README now states that a boolean value matches only a
  `bool`-cast column, and such a column only a boolean, next to the example that needs it.

## v1.0.1 — Authorization correctness fixes (2026-08-24)

### Fixed

- `isAll()` no longer miscounts a role held twice. The eager-loaded path intersected a
  collection that could contain duplicates and compared counts, so an authority holding
  one role twice was **confirmed for a role it did not hold**, and could also be denied
  a role it did hold. The query path already counted distinct names.
- Re-running a constrained grant with a leading `or` no longer mints a second catalog
  row. Nothing sits to the left of a group's first item and neither engine reads its
  operator, so it no longer distinguishes one twin from another. Rows already stored
  keep their bytes.
- `assign()->to()` and `allow()->to()` no longer bump the cache version when
  `firstOrCreate()` wrote nothing.

### Changed

- A repeated write that changes no row no longer dispatches `RoleAssigned`,
  `PermissionGranted` or `PermissionForbidden`. Listeners that relied on receiving these
  for a no-op will stop seeing them. This matches `retract()` and `revoke()`, which
  already guarded on their delete count.

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
