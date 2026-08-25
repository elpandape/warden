# Upgrading warden

Version-to-version upgrades of this package, newest first. Coming from silber/bouncer
instead? See [MIGRATING-FROM-BOUNCER.md](MIGRATING-FROM-BOUNCER.md).

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
