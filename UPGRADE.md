# Upgrading

- **Already on warden 1.x?** See [From warden 1.x to 2.0](#from-warden-1x-to-20) at the end.
- **Coming from silber/bouncer?** Read on.

## From silber/bouncer

Three steps: swap the package, upgrade the database in place, rename the code.
The fluent API (`allow()->to()`, `forbid()`, `assign()`, `is()`, wildcards,
ownership, scopes) is intentionally compatible — most call sites survive as-is.

## 1. Swap the package

```bash
composer remove silber/bouncer
composer require elpandape/warden
```

The packages cannot coexist (`conflict`), by design.

## 2. Upgrade the database — `warden:upgrade`

Do **not** run this package's migration on a legacy database. Run the upgrade
command instead; it transforms the silber/bouncer schema in place:

```bash
php artisan warden:upgrade --dry-run   # report only
php artisan warden:upgrade
```

What it does, in the one order that survives the table-name crossing:

| Legacy (silber/bouncer) | Becomes | Notes |
|---|---|---|
| `permissions` (the pivot!) | `grants` | `ability_id` → `permission_id`; FK preserved |
| `abilities` | `permissions` | identical shape: title, entity, `only_owned`, `options`, `scope` |
| `roles` | `roles` | unchanged |
| `assigned_roles` | `assigned_roles` | unchanged (`restricted_to_*` finally does something) |

Plus two data fixes:

- **Role morphs**: rows granting **to** roles, and abilities **targeting** the
  role model (`manage roles`-style), carry the legacy role morph
  (`Silber\Bouncer\Database\Role` or `roles`); both are rewritten to this
  package's role morph so they keep working. Custom role classes:
  pass `--role-morph="App\Models\Role"` (repeatable).
- **Tenant scopes, fail-closed**: your legacy install hid every tenant-scoped
  row from scope-less checks. This package's default (`null_behavior: 'all'`)
  would surface them globally, so the command **refuses to run** when
  tenant-scoped rows exist under that default — set
  `'null_behavior' => 'strict'` to preserve legacy semantics, or pass
  `--allow-open-scopes` if the widening is intentional.
- **Legacy constraint blobs** in `options` are cleared to `NULL`. The original
  serialized them but **never evaluated them**, so `NULL` preserves the
  behavior your app actually had; this package's fail-closed deserialization
  would have silenced those grants instead. Re-declare conditions with the
  real ABAC API afterwards: `allow()->to()->where(...)`.

On Postgres and SQLite the upgrade is atomic; MySQL commits DDL implicitly, so
snapshot first if you need a rollback path. Indexes keep their legacy names.

Scope: the command upgrades the **stable silber/bouncer schema (>= 1.0)**. If you
are on one of the ancient 1.0.0-rc releases with a pre-rename schema, migrate to
upstream 1.0 first, then run `warden:upgrade`.

## 3. Rename the code — Rector set

```bash
vendor/bin/rector process app --config vendor/elpandape/warden/stubs/rector-silber-upgrade.php
```

It rewrites imports and renamed calls. The main equivalences:

> **The facade alias changed.** `silber/bouncer` registered the root alias
> `Bouncer`; this package registers `Warden`. The set rewrites those call sites
> too — `use Bouncer;`, `\Bouncer::` and un-namespaced files like `routes/web.php`
> — so `Bouncer::allow(...)` becomes `Warden::allow(...)` on its own. If your app
> has its own global-namespace class called `Bouncer`, drop that one mapping from
> the set first.

| silber/bouncer | elpandape/warden |
|---|---|
| `Silber\Bouncer\BouncerFacade` | `ElPandaPe\Warden\Facades\Warden` |
| `Silber\Bouncer\Database\Ability` | `ElPandaPe\Warden\Models\Permission` |
| `Silber\Bouncer\Database\Role` | `ElPandaPe\Warden\Models\Role` |
| `...\Concerns\HasRolesAndAbilities` | `...\Concerns\HasRolesAndPermissions` |
| `Bouncer::ability([...])` | `Warden::permission([...])` |
| `$user->getAbilities()` | `$user->getPermissions()` (unrestricted-role channel; restricted contexts excluded) |
| `Bouncer::scope()` | `Warden::scope()`, kept as an alias of `tenant()` |
| `Bouncer::cache()` / `dontCache()` | config `warden.cache.enabled` |
| `Bouncer::refresh()` / `refreshFor()` | `Warden::refresh()` / `refreshFor()`, now O(1) |

## What changed underneath (worth knowing)

- Checks run through the Gate exactly as before, but a versioned cache with
  automatic invalidation answers them by default — raw DB edits need
  `Warden::refresh()` (writes through the API do not).
- `sync()` manages **unrestricted** role assignments only.
- Null-tenant visibility is configurable (`warden.scope.null_behavior`), and
  writes always target one exact tenant scope.
- Everything new (ABAC, scoped roles, `whereCan()`, `explain()`, events,
  typed exceptions, testing tools) is additive: adopt at your own pace.

---

## A note on the name

This package shipped its 1.0.0 as `elpandape/bouncer` for a day before being
renamed to `elpandape/warden` — same schema, same API, only identifiers moved.
If you installed it in that window: `ElPandaPe\Bouncer\*` is now
`ElPandaPe\Warden\*`, the `Bouncer` facade and its root alias are `Warden`,
`bouncer:*` commands are `warden:*`, `config/bouncer.php` is `config/warden.php`,
and the morph aliases `bouncer.role` / `bouncer.permission` are `warden.role` /
`warden.permission` — that last pair is written into `entity_type` and
`restricted_to_type`, so rewrite those columns before trusting your grants.
Full list in the [changelog](CHANGELOG.md).

---

## From warden 1.x to 2.0

2.0 gives the permission catalog an identity: a `identity_key` column carrying the rest of
the tuple that names a rule, and a unique index over `(name, identity_key)`. A 1.x database
has neither, and warden writes the key on every save — so the first write after the upgrade
fails with *no column named identity_key* until you run the migration.

```bash
composer update elpandape/warden
php artisan vendor:publish --tag=warden-migrations-v2
php artisan migrate
```

The migration adds the column, computes the key for every existing row, and only then adds
the unique index.

### If it stops on duplicates

Two rows can already name the same rule — 1.x had nothing preventing it, and one of them was
silently unreachable. The migration refuses to pick a winner for you:

```
Found 3 catalog row(s) that identify the same permission as another.
Resolve them with `php artisan warden:clean --duplicates`, then run this migration again.
```

At that point the column exists and the index does not, which is exactly the state
`warden:clean --duplicates` needs: it collapses each set into one row, re-points the grants
the losers held, and drops any grant that would collide with one the winner already has.

```bash
php artisan warden:clean --duplicates
php artisan migrate
```

The second run is safe: the migration skips what it already did. Take a backup first — the
cleanup deletes rows, and which row wins is decided by the lowest id, not by you.

### What else changes behaviour

No other table changes shape. The rest of 2.0 is behaviour, and the
[CHANGELOG](CHANGELOG.md#v200--identity-and-rules-that-mean-what-they-say-2026-08-25)
lists it — read the breaking section before deploying. The two most likely to reach your
code: a second `where()` on the same concession now **replaces** the condition instead of
adding a rule beside it, and `detach()` / `sync()` on `roles()` and `permissions()` now touch
only the rows at the active tenant scope.
