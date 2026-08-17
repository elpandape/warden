# Bouncer

> Roles & permissions for Laravel — instance-level grants, explicit forbids, ownership,
> multi-tenancy, ABAC. PHP 8.4+ · Laravel 12+.
>
> Based on [Bouncer](https://github.com/JosephSilber/bouncer) by Joseph Silber — this package
> is a modernized evolution of his original work (MIT).

![Version](https://img.shields.io/badge/version-0.5.0-blue) ![PHP](https://img.shields.io/badge/php-%5E8.4-777bb3) ![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20) ![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen) ![PHPStan](https://img.shields.io/badge/phpstan-max-4b5563)

> **Status: alpha (v0.5.0) — full feature parity with the original Bouncer.**
> Caching, events, ABAC, `whereCan()` and `explain()` complete by v0.9.0.
> Do not use in production before v1.0.0.

## What's available (v0.5.0)

- **Checks through Laravel's Gate**: `can()`, `@can`, `authorize()` and policies work
  out of the box — explicit forbids beat any grant, and Bouncer never overrides your
  policies unless you configure it to run first.
- **Ownership**: `toOwn(Post::class)` grants only what the user owns — resolved by
  attribute (configurable globally, per class, or with a closure), strict-mode safe.
- **Multi-tenancy**: `Bouncer::tenant()->to($id)` isolates the whole system per tenant,
  with global (unscoped) rows visible everywhere, an injectable tenant resolver,
  exception-safe `onceTo()`, and catalog/role-grant splits.
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

Caching, events, ABAC, `whereCan()` and `explain()` complete by v0.9.0.

## Ownership

```php
Bouncer::allow($user)->toOwn(Post::class);            // any action on owned posts
Bouncer::allow($user)->toOwn(Post::class, ['edit']);  // only these actions
Bouncer::allow($user)->toOwnEverything();

Bouncer::ownedVia('author_id');                       // global attribute
Bouncer::ownedVia(Post::class, 'writer_id');          // per class
Bouncer::ownedVia(fn ($post, $user) => $post->team_id === $user->team_id);
```

✅ Do — let ownership carry the common case and forbid the exceptions:

```php
Bouncer::allow($user)->toOwn(Post::class);
Bouncer::forbid($user)->toOwn(Post::class, 'delete'); // owners still can't delete
```

❌ Don't — don't reimplement ownership inside policies you'll have to keep in
sync with your grants; `ownedVia()` is declared once and works everywhere.

## Multi-tenancy

```php
Bouncer::tenant()->to($tenantId);       // everything now scoped to this tenant
Bouncer::tenant()->onceTo(9, fn () => ...); // temporary, exception-safe
Bouncer::tenant()->onlyRelations();     // keep the permission catalog global
Bouncer::tenant()->dontScopeRoleGrants();
```

Rows written without an active tenant are global: visible from every tenant. What a
check sees with **no** active tenant is configurable (`bouncer.scope.null_behavior`:
`'all'` sees everything, `'strict'` sees only global rows) — the semantic ambiguity
that plagued the original is now an explicit choice. Wire a
`Contracts\TenantResolver` in config to detect the tenant from session/JWT automatically.

**Writes always target one exact scope.** Reads fall back to global rows, but a write
under tenant 5 creates, matches, or deletes tenant-5 rows only — a tenant-scoped
`disallow()`, `unforbid()`, `retract()` or `sync()` never destroys a global rule, and a
global write never gets absorbed by a same-named row inside some tenant.

✅ Do — remove a global forbid where it lives: outside any tenant:

```php
Bouncer::tenant()->removeOnce(fn () => Bouncer::unforbid($user)->to('publish'));
```

❌ Don't — don't expect `Bouncer::unforbid($user)->to('publish')` under a tenant to
lift a **global** forbid; global rules are only writable globally, by design.

## Checking permissions

Nothing to learn: it is Laravel's Gate.

```php
$user->can('edit-site');            // simple permission
$user->can('edit', $post);          // one instance
$user->can('edit', Post::class);    // the whole class
Gate::authorize('edit', $post);     // throws on deny
@can('edit', $post) ... @endcan     // Blade, as always
```

How grants match checks:

| Grant ↓ / Check → | `can('edit')` | `can('edit', Post::class)` | `can('edit', $post)` |
|---|---|---|---|
| `to('edit')` | ✅ | — | — |
| `to('edit', Post::class)` | — | ✅ | ✅ |
| `to('edit', $post)` | — | — | ✅ that one |
| `to('edit', '*')` | — | ✅ | ✅ |
| `to('*')` | ✅ | — | — |
| `toManage(Post::class)` | — | ✅ | ✅ |
| `everything()` | ✅ | ✅ | ✅ |

Rules worth knowing: an explicit `forbid()` beats every **Bouncer** grant; by default
Bouncer answers **after** your policies and Gate definitions, so those always win over
both grants and forbids (set `bouncer.gate.run_before_policies` to flip it, making
forbids veto everything). `Gate::after` fallbacks registered after Bouncer are consulted
only when Bouncer abstains. Checks with more than one argument are left entirely to your
policies; guests and non-model arguments are never answered. Denials carry a translatable
message (`bouncer::bouncer.unauthorized`, shipped in English and Spanish).

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
use ElPandaPe\Bouncer\Concerns\HasRolesAndPermissions;

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
    use ElPandaPe\Bouncer\Models\Concerns\IsRole;
}
```

```php
// ❌ Don't — don't hardcode package classes in relations or checks;
// resolve them via config so swapped models keep working everywhere.
$user->roles()->attach(ElPandaPe\Bouncer\Models\Role::first());
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
