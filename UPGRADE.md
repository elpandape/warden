# Upgrading warden

Version-to-version upgrades of this package, newest first. Coming from silber/bouncer
instead? See [MIGRATING-FROM-BOUNCER.md](MIGRATING-FROM-BOUNCER.md).

## From 3.0 to 3.1

3.1 changes no schema, no configuration and no signature, so
`composer update elpandape/warden` is the whole code change, and a `^3.0` constraint
already allows it. What changes is what your event listeners receive and, in two cases,
when access ends: with `SoftDeletes` on your role model, and for `until()` moments in
another timezone — see *What else changes* below. If any listener is queued, the next
section comes first.

Coming straight from 3.0.0, still run the one-time `php artisan warden:clean --stranded`
that 3.0.1's CHANGELOG entry asks for, unless each tenant has its own users database: see
[Landlord vs tenant databases](README.md#landlord-vs-tenant-databases).

### Before deploying: queued listeners

- **Drain the queued listeners of `RoleDeleted` and `PermissionDeleted`,** or clear them.
  3.1 queues both events in a new shape, and a job either one queued under 3.0 fails under
  3.1 with a `TypeError`, even when the row still exists. A listener that sets
  `deleteWhenMissingModels` no longer drops such a job quietly: it lands in `failed_jobs`.
- **A job queued under 3.0 for any other warden event still restores,** without the
  properties 3.1 adds: reading its `$grants`, its `$assignments` or a catalog event's
  `$actor` throws an `Error`. Ship a listener that reads them once those queues have
  drained, or read them with `??` until then.
- **A model that travels by value keeps every column, `$hidden` included** — `$hidden` only
  shapes arrays and JSON. The roles and permissions in `$roles` and `$permissions` already
  travelled that way; 3.1 adds the deleted row of `RoleDeleted` and `PermissionDeleted` and
  every model in a `$grants` or `$assignments` entry, the context an `AssignmentRemoval`
  names included — that context as a copy without the relations your model had loaded, a
  role or permission you handed to a verb with them, as in 3.0. If your models
  (`warden.models.*`, or a context model) hold a sensitive column, make the queued
  listeners of any warden event that carries them implement `ShouldBeEncrypted`.

### What listeners see differently

- **Fewer, narrower write events.** An authority a call changed nothing for receives no
  `RoleAssigned`, `RoleRetracted`, `PermissionGranted`, `PermissionForbidden`,
  `PermissionRevoked` or `PermissionUnforbidden`, and `$roles` / `$permissions` hold only
  what changed for it. A test that counts events over recipients who already held the role
  will count fewer.
- **Each write event lists its rows** in `$grants` or `$assignments`. `created`,
  `expiresAt` and `previousExpiresAt` tell a new row from a moved date, and a removal's
  `expiresAt` tells a live revocation from the removal of an expired row. If you build warden
  events yourself — in tests, say — pass their arguments by name from `actor` on.
- **`retract()` without `on()`** still dispatches `RoleRetracted` with `restrictedTo: null`;
  the contexts it removed are in `$assignments`.
- **A narrowing chain** repeated identically dispatches four events instead of five, with no
  `PermissionGranted` for a twin that did not change. The plain row's `PermissionDeleted`
  now comes after the re-point's `PermissionRevoked` and `PermissionGranted` instead of
  before them, and changing the condition now also announces the removal of the previous
  twin's grant.
- **Deleting a role** dispatches a `RoleRetracted` per holder and scope after `RoleDeleted`,
  and `RoleDeleted` carries `$heldGrants` and `$heldRoles`. If a `deleting` listener of yours
  read the role's grants in order to log them, `$heldGrants` has them.
- **Editing a role or a permission through its model** dispatches `RoleUpdated` or
  `PermissionUpdated`, a title edit included: `$event->changed === ['title']` singles those
  out.
- **Catalog events carry `$actor`.** The default resolver returns `null` in the console; one
  that names a system account there attributes what `warden:clean` deletes too.
- **A listener's `can()` sees the write it hears about.** A re-check you added after the call
  to get around stale answers is no longer needed.
- **Queued listeners of `RoleDeleted` and `PermissionDeleted` now run**, with the deleted row
  by value, without the relations it had loaded. The actor is read again when the job runs;
  if its row is gone by then, it arrives as an unsaved stand-in carrying only its key, on
  the connection the actor came from (`exists` is `false`), instead of failing the job.

### What else changes

- A soft-deleted role — `SoftDeletes` on your role model — now keeps its holders' access
  until `forceDelete()`. 3.0 swept its grants on `delete()`; 3.1 leaves its grants, its
  holders and its nested edges in place, so the trashed role stops answering `isA()`,
  `isAll()` and `whereIs()` while `can()` still grants what it lends and blocks what it
  forbids, and `restore()` brings it back whole. To end the access, force-delete it, or
  `retract()` it or `disallow()` what it grants **before** the soft delete: by name, the
  verbs no longer reach a trashed role — `retract('editor')` takes nothing,
  `disallow('editor')` throws `RoleDoesNotExist`, and `assign('editor')` creates a second,
  empty `editor`, or fails on the `(name, scope)` index under the tenant that holds the
  trashed one — so pass the trashed model (`Role::withTrashed()`) once it is there.
  `RoleDeleted` goes out with empty `$heldGrants` and `$heldRoles`, and no `RoleRetracted`
  follows; a later `forceDelete()` from the trash dispatches a second `RoleDeleted`, with
  the lists, then the `RoleRetracted`s, and sweeps as usual.
- A soft-deleted permission keeps its grant rows, but its own scope hides it from every
  check at once, before its `PermissionDeleted` goes out: while trashed it neither grants
  nor forbids — a prohibition it carried lifts — and only `PermissionDeleted` says so,
  where 3.0 announced a `PermissionRevoked` or `PermissionUnforbidden` per row. `restore()`
  brings it back, invalidating the permission's own scope only: a tenant's permission
  granted under another tenant can answer from that tenant's cache until its next write or
  the TTL. A later `forceDelete()` from the trash dispatches a second `PermissionDeleted`,
  then the cascade.
- `until()` over a row that already has an end date stores the wall time it names, as a new
  row always did. A moment in another zone that names the row's instant but a different
  wall time is now written and announced where 3.0 kept the old date, and access ends at
  the wall time named, read in your application's zone. Hand `until()` moments in that zone.
- `disallow()`, `unforbid()` and `retract()` read the rows they remove before deleting them
  one by one, and deleting a role, with events on, reads every holder's row and what the
  role held first. A call pays at least a SELECT per authority and a DELETE per row where it
  paid one DELETE per authority.
- A new grant or assignment with `until()` is inserted with its date when your grant or
  assignment model accepts `expires_at` by mass assignment, as warden's own do: an observer
  of that model sees its `created` with the date, and no `updated` after it. A model that
  does not still gets the date in an `updated` right after.
- `assign()->to()` over several authorities asks your actor resolver once for its write
  events, not once per authority; each role or permission it creates by name asks once
  more, for its catalog event.
- Saving a role or a permission read with a partial `select()` reads the snapshot columns it
  is missing: one more query, for partial rows only, and none with events off.
- The README's [Events for auditing](README.md#events-for-auditing) section describes all of
  this in one place.

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
