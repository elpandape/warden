# Bouncer

> Roles & permissions for Laravel — instance-level grants, explicit forbids, ownership,
> multi-tenancy, ABAC. PHP 8.4+ · Laravel 12+.
>
> Based on [Bouncer](https://github.com/JosephSilber/bouncer) by Joseph Silber — this package
> is a modernized evolution of his original work (MIT).

![Version](https://img.shields.io/badge/version-0.3.0-blue) ![PHP](https://img.shields.io/badge/php-%5E8.4-777bb3) ![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20) ![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen) ![PHPStan](https://img.shields.io/badge/phpstan-max-4b5563)

> **Status: alpha (v0.3.0).** Checks land in v0.4.0; the full API completes by v0.9.0 —
> each tagged release documents what shipped. Do not use in production before v1.0.0.

## What's available (v0.3.0)

- **The fluent write API**: `Bouncer::allow($user)->to(...)`, `forbid()`, `disallow()`,
  `unforbid()`, `assign()`, `retract()`, `sync()`, `is()` — immediate execution, no
  destructor magic, safe under concurrency.
- Models: `Permission`, `Role`, and real pivot models (`Grant`, `AssignedRole`) you can
  swap or extend via config. Friendly titles are generated on creation (configurable).
- `HasRolesAndPermissions` for any authority model (not just users): `roles()`,
  `permissions()`, `isA()` / `isAn()` / `isNotA()` / `isAll()`.
- Stable morph aliases (`bouncer.role`, `bouncer.permission`), safe with
  `Relation::enforceMorphMap()`. UUID/ULID-ready: no hardcoded integer cast on entity
  ids, and the published migration ships commented column variants to switch to string keys.
- Schema v2 migration (frozen for 0.x), full config file, en/es translations, `Context`.

Checks through the Gate (`can()`, `@can`, policies) ship in v0.4.0; ownership,
multi-tenancy, caching, events, ABAC, `whereCan()` and `explain()` complete by v0.9.0.

## Granting & forbidding

```php
use ElPandaPe\Bouncer\Facades\Bouncer;

Bouncer::allow($user)->to('ban-users');            // simple permission
Bouncer::allow($user)->to('edit', Post::class);    // every post
Bouncer::allow($user)->to('edit', $post);          // one post
Bouncer::allow($user)->everything();               // wildcard
Bouncer::allow($user)->toOwn(Post::class);         // only what they own
Bouncer::allowEveryone()->to('browse');            // everyone

Bouncer::assign('admin')->to($user);               // roles, created on the fly
Bouncer::allow('admin')->to('audit');              // grant to a role by name
Bouncer::sync($user)->roles(['editor', 'writer']); // declarative sync
```

✅ Do — forbid beats everything, use it for exceptions:

```php
Bouncer::allow($user)->to('view', Document::class);
Bouncer::forbid($user)->to('view', $classifiedDocument);
```

❌ Don't — don't model exceptions by scattering conditionals around your
codebase; an explicit `forbid()` row is queryable, auditable and revocable
(`Bouncer::unforbid($user)->to('view', $classifiedDocument)`).

## Schema & models

Four tables: `permissions` (the catalog), `roles`, `assigned_roles` (role ↔ authority)
and `grants` (permission ↔ authority, with a `forbidden` flag — a grant row is exactly
one concession or one prohibition). Any model can hold roles and permissions:

```php
use ElPandaPe\Bouncer\Database\Concerns\HasRolesAndPermissions;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

Swap models through config — never by extending package internals:

```php
// ✅ Do — point the config at your model, keep the concern:
// config/bouncer.php
'models' => ['role' => App\Models\Role::class],

// app/Models/Role.php
class Role extends Model
{
    use ElPandaPe\Bouncer\Database\Concerns\IsRole;
}
```

```php
// ❌ Don't — don't hardcode package classes in relations or checks;
// resolve them via config so swapped models keep working everywhere.
$user->roles()->attach(ElPandaPe\Bouncer\Database\Role::first());
```

## Installation

```bash
composer require elpandape/bouncer
php artisan vendor:publish --tag=bouncer-config
php artisan vendor:publish --tag=bouncer-migrations
php artisan migrate
```

This package **conflicts with `silber/bouncer`** by design: same facade alias, same default
tables. Migrating from it will be a one-command upgrade (`bouncer:upgrade`, ships in v0.10.0).

## Configuration

Everything lives in `config/bouncer.php`: models, table names, database connection,
morph aliases, gate behavior, ownership, multi-tenancy scope semantics, cache, events
and i18n-safe exception messages. Every key is documented in the file itself.

## Development

No local PHP or Composer needed — everything runs through Docker:

```bash
make build      # build the dev image
make install    # composer install
make ci         # pint + phpstan + rector + tests (100% coverage) + type coverage
make test-dbs   # run the suite against MySQL 9 and Postgres 16
make shell      # a shell inside the container
```

## Credits & license

Original concept and API design: [Joseph Silber](https://github.com/JosephSilber) —
this project started as an evolution of his Bouncer and keeps his copyright notice.
Licensed under the [MIT license](LICENSE.md).
