# Upgrading warden

Version-to-version upgrades of this package, newest first. Coming from silber/bouncer
instead? See [MIGRATING-FROM-BOUNCER.md](MIGRATING-FROM-BOUNCER.md).

## From 2.x to 3.0

3.0 gives grants and role assignments an end date: a nullable, indexed `expires_at` on both
pivot tables. Publish and run the incremental migration before upgrading the package in a
running application — every reader names the column, so a 2.x schema cannot answer a check
under 3.0.

```bash
php artisan vendor:publish --tag=warden-migrations-v3
php artisan migrate
```

In a package test suite, `ElPandaPe\Warden\Testing\Schema::upgradeToV3()` does the same
thing. Coming from silber/bouncer instead, `warden:upgrade` already leaves the column behind
and needs no extra step.

The migration is re-runnable and touches no rows: existing grants and assignments come out
with a null end date, which means exactly what it meant before — no end.

### What else changes

- **Cached payloads move to version 4.** Payloads written by 2.x are discarded on read
  rather than misread. Nothing to do; the first check after the upgrade rebuilds them.
- **`getPermissions()` now filters expiry**, like the resolver already did. It cannot hand
  back a permission that `can()` would deny.
- **A condition that can never be true is refused instead of logged.** Writing a boolean
  against a column the model does not cast to `bool` used to succeed with a warning; it now
  throws. Rows written before this release are **not** migrated — they keep evaluating to
  false, and as a `forbid()` they stay inert. Run `php artisan warden:doctor` to list them.
- **`LogicalOperator::Not` throws when evaluated**, where a hand-built group used to read it
  as an `and`. Storing one was already refused, so only in-memory groups are affected.
- **`LogicalOperator::combine()` is removed.** It had no caller inside the package.
- **`until()` on a `forbid()` throws.** A prohibition does not expire.
- **Roles can nest, and the switch is off.** `assign('auditor')->to($role)` has always been
  accepted and has always granted nothing; with `warden.roles.nested` on, holders of the
  outer role now inherit the inner one's grants. **Nothing changes until you turn it on** —
  but if your database already carries role-to-role assignments written under the old
  contract, know that flipping the switch is what makes them live. Audit them first:

  ```php
  ElPandaPe\Warden\Models\AssignedRole::query()
      ->where('entity_type', (new ElPandaPe\Warden\Models\Role)->getMorphClass())
      ->count();
  ```

  `warden:clean --stranded` now sweeps both pivots, so an edge left pointing at a deleted
  role is reachable by the cleanup for the first time.

`Contracts\Constraint::passes()` keeps its signature: nothing that implements it needs to
change.

## From 1.x to 2.0

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
