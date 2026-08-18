# Upgrading from silber/bouncer

Three steps: swap the package, upgrade the database in place, rename the code.
The fluent API (`allow()->to()`, `forbid()`, `assign()`, `is()`, wildcards,
ownership, scopes) is intentionally compatible — most call sites survive as-is.

## 1. Swap the package

```bash
composer remove silber/bouncer
composer require elpandape/bouncer
```

The packages cannot coexist (`conflict`), by design.

## 2. Upgrade the database — `bouncer:upgrade`

Do **not** run this package's migration on a legacy database. Run the upgrade
command instead; it transforms the silber/bouncer schema in place:

```bash
php artisan bouncer:upgrade --dry-run   # report only
php artisan bouncer:upgrade
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

## 3. Rename the code — Rector set

```bash
vendor/bin/rector process app --config vendor/elpandape/bouncer/stubs/rector-silber-upgrade.php
```

It rewrites imports and renamed calls. The main equivalences:

| silber/bouncer | elpandape/bouncer |
|---|---|
| `Silber\Bouncer\BouncerFacade` | `ElPandaPe\Bouncer\Facades\Bouncer` |
| `Silber\Bouncer\Database\Ability` | `ElPandaPe\Bouncer\Models\Permission` |
| `Silber\Bouncer\Database\Role` | `ElPandaPe\Bouncer\Models\Role` |
| `...\Concerns\HasRolesAndAbilities` | `...\Concerns\HasRolesAndPermissions` |
| `Bouncer::ability([...])` | `Bouncer::permission([...])` |
| `$user->getAbilities()` | `$user->getPermissions()` (unrestricted-role channel; restricted contexts excluded) |
| `Bouncer::scope()` | unchanged (alias of `tenant()`) |
| `Bouncer::cache()` / `dontCache()` | config `bouncer.cache.enabled` |
| `Bouncer::refresh()` / `refreshFor()` | unchanged, now O(1) |

## What changed underneath (worth knowing)

- Checks run through the Gate exactly as before, but a versioned cache with
  automatic invalidation answers them by default — raw DB edits need
  `Bouncer::refresh()` (writes through the API do not).
- `sync()` manages **unrestricted** role assignments only.
- Null-tenant visibility is configurable (`bouncer.scope.null_behavior`), and
  writes always target one exact tenant scope.
- Everything new (ABAC, scoped roles, `whereCan()`, `explain()`, events,
  typed exceptions, testing tools) is additive: adopt at your own pace.
