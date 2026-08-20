<p align="center">
  <img src="https://raw.githubusercontent.com/elpandape/warden/main/art/cover.jpg" alt="Warden" width="800">
</p>

<h1 align="center">Warden</h1>

<p align="center">
  <strong>Roles & permissions for Laravel</strong><br>
  Instance-level grants, explicit forbids, ownership, multi-tenancy, and ABAC.<br>
  <em>Authorization that explains itself.</em>
</p>

<p align="center">
  <a href="https://packagist.org/packages/elpandape/warden"><img src="https://img.shields.io/packagist/v/elpandape/warden?style=flat-square&color=blue" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/elpandape/warden"><img src="https://img.shields.io/packagist/dt/elpandape/warden?style=flat-square&color=green" alt="Total Downloads"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="License"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.4+-777BB4?style=flat-square&logo=php" alt="PHP 8.4+"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-13+-FF2D20?style=flat-square&logo=laravel" alt="Laravel 13+"></a>
</p>

---

## 📖 Table of Contents

- [✨ Features](#-features)
- [📋 Requirements](#-requirements)
- [🚀 Installation](#-installation)
- [⚡ Quick Start](#-quick-start)
- [🔐 Checking Permissions](#-checking-permissions)
- [🎁 Granting & Forbidding](#-granting--forbidding)
- [🏠 Ownership](#-ownership)
- [🎯 Scoped Roles](#-scoped-roles)
- [🏢 Multi-tenancy](#-multi-tenancy)
- [🔧 Conditional Permissions (ABAC)](#-conditional-permissions-abac)
- [📊 Querying by Permission](#-querying-by-permission)
- [🔍 Debugging with `explain()`](#-debugging-with-explain)
- [📡 Events](#-events)
- [⚠️ Exceptions](#-exceptions)
- [🔢 Enums](#-enums)
- [💾 Caching](#-caching)
- [🧪 Testing](#-testing)
- [🛡️ Middleware & Blade](#-middleware--blade)
- [🏗️ Schema & Models](#️-schema--models)
- [⚙️ Configuration](#-configuration)
- [📖 Recipes](#-recipes)
- [🔄 Migrating from silber/bouncer](#-migrating-from-silberbouncer)
- [🧪 Development](#-development)
- [👤 Credits & License](#-credits--license)

---

## ✨ Features

| Feature | Description |
|---|---|
| 🎯 **Laravel's Gate, zero learning curve** | `can()`, `@can`, `authorize()` — works out of the box. |
| 🔒 **Explicit forbids** | A `forbid()` beats every grant. Distinguishes "denied" from "not granted." |
| 📊 **`whereCan()` query scope** | The only package that can answer *"over which rows?"* as an Eloquent scope. |
| 🔍 **`explain()` debugging** | Know *why* a check resolved the way it did — including "explicitly forbidden." |
| 🏗️ **ABAC constraints** | `where('status', 'published')` on grants — evaluated on every check. |
| 🏠 **Ownership** | `toOwn(Post::class)` — grant only what the user owns, resolved by attribute or closure. |
| 🎯 **Scoped roles** | `assign('editor')->on($org)` — same role, different contexts. |
| 🏢 **Multi-tenancy** | Tenant-scoped rows with global fallback, injectable resolver, exception-safe `onceTo()`. |
| 💾 **Smart caching** | O(1) invalidation, versioned payloads, anti-stampede locking, Octane-safe. |
| 📡 **Typed events** | Every write dispatches a typed event with hydrated models — never raw IDs. |
| 🔢 **Enum support** | `BackedEnum` accepted everywhere a name string is. |
| 🧪 **Testing helpers** | `Warden::fake()`, `WithPermissions` trait, artisan commands. |
| 🔄 **Migration path** | `warden:upgrade` + Rector set for silber/bouncer users. |

---

## 📋 Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.4` |
| Laravel | `^13.0` |

---

## 🚀 Installation

```bash
composer require elpandape/warden
php artisan warden:install --migrate
```

`warden:install` publishes the config, the migration, and runs it. You can also publish individually:

```bash
php artisan vendor:publish --tag=warden-config
php artisan vendor:publish --tag=warden-migrations
```

Then add the concern to your authority model(s):

```php
use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

> 🔄 **Coming from silber/bouncer?** This package conflicts with it by design (same default tables). Run `php artisan warden:upgrade` to migrate the schema in place. See [UPGRADE.md](UPGRADE.md).

---

## ⚡ Quick Start

```php
use ElPandaPe\Warden\Facades\Warden;

// Grant
Warden::allow($user)->to('edit', Post::class);

// Forbid (always wins)
Warden::forbid($user)->to('edit', $secretPost);

// Scoped role
Warden::assign('editor')->on($org)->to($user);

// Check
$user->can('edit', $post);                          // Laravel's Gate
Post::whereCan($user, 'edit')->paginate();           // Which rows?
Warden::explain($user, 'edit', $post);               // Why?
```

---

## 🔐 Checking Permissions

Nothing to learn — it's Laravel's Gate.

```php
$user->can('edit-site');            // simple permission
$user->can('edit', $post);          // one instance
$user->can('edit', Post::class);    // the whole class
Gate::authorize('edit', $post);     // throws on deny
@can('edit', $post) ... @endcan     // Blade, as always
```

### Grant vs Check Matrix

| Grant ↓ / Check → | `can('edit')` | `can('edit', Post::class)` | `can('edit', $post)` |
|---|---|---|---|
| `to('edit')` | ✅ | — | — |
| `to('edit', Post::class)` | — | ✅ | ✅ |
| `to('edit', $post)` | — | — | ✅ that one |
| `to('edit', '*')` | — | ✅ | ✅ |
| `to('*')` | ✅ | — | — |
| `toManage(Post::class)` | — | ✅ | ✅ |
| `everything()` | ✅ | ✅ | ✅ |

> 📌 **Rules:**
> - `forbid()` beats every Warden grant.
> - By default, Warden answers **after** your policies — policies always win.
> - Set `warden.gate.run_before_policies` to make forbids veto everything.
> - Checks with more than one argument are left to your policies.
> - Guests and non-model arguments are never answered by Warden.

---

## 🎁 Granting & Forbidding

```php
use ElPandaPe\Warden\Facades\Warden;

// Simple permission
Warden::allow($user)->to('ban-users');

// Class-level
Warden::allow($user)->to('edit', Post::class);

// Instance-level
Warden::allow($user)->to('edit', $post);

// Wildcard
Warden::allow($user)->everything();

// Everyone
Warden::allowEveryone()->to('browse');

// Roles
Warden::assign('admin')->to($user);
Warden::allow('admin')->to('audit');

// Declarative sync
Warden::sync($user)->roles(['editor', 'writer']);
```

### Best Practices

✅ **Do** — use `forbid()` for exceptions:

```php
Warden::allow($user)->to('view', Document::class);
Warden::forbid($user)->to('view', $classifiedDocument);
```

❌ **Don't** — model exceptions with scattered conditionals; a `forbid()` row is queryable, auditable, and revocable:

```php
Warden::unforbid($user)->to('view', $classifiedDocument);
```

---

## 🏠 Ownership

```php
// All actions on owned posts
Warden::allow($user)->toOwn(Post::class);

// Only specific actions
Warden::allow($user)->toOwn(Post::class, ['edit']);

// Everything owned
Warden::allow($user)->toOwnEverything();
```

### Configure ownership resolution

```php
// Global attribute
Warden::ownedVia('author_id');

// Per class
Warden::ownedVia(Post::class, 'writer_id');

// Closure (evaluated live, never cached)
Warden::ownedVia(fn ($post, $user) => $post->team_id === $user->team_id);
```

### Best Practices

✅ **Do** — let ownership carry the common case, forbid the exceptions:

```php
Warden::allow($user)->toOwn(Post::class);
Warden::forbid($user)->toOwn(Post::class, 'delete'); // owners still can't delete
```

❌ **Don't** — reimplement ownership inside policies you'll have to keep in sync.

---

## 🎯 Scoped Roles

Restrict a role to any model — no global `team_id` required.

```php
Warden::assign('editor')->on($orgOne)->to($user);   // editor only inside orgOne
Warden::assign('editor')->on($orgTwo)->to($user);   // same role, second context
Warden::retract('editor')->on($orgOne)->from($user); // leave one; without on(), all
```

### Configure membership resolution

```php
Warden::restrictedVia(Post::class, 'organization_id');  // membership by FK
Warden::restrictedVia(fn ($entity, $context) => ...); // or a closure
```

> 📌 A restricted role's grants apply when the checked entity **belongs to the context**. Checks without an instance fail closed. Role membership checks (`isAn('editor')`) ignore restrictions by design.

### Best Practices

✅ **Do** — model teams with the models you already have:

```php
Warden::assign('admin')->on($project)->to($user);
$user->can('manage', $project);          // true: the entity IS the context
$user->can('edit', $taskInProject);      // true: task->project_id points at it
```

❌ **Don't** — fall back to one global role plus scattered `if ($user->org_id === …)` checks.

---

## 🏢 Multi-tenancy

```php
Warden::tenant()->to($tenantId);                    // scope everything to this tenant
Warden::tenant()->onceTo(9, fn () => ...);         // temporary, exception-safe
Warden::tenant()->onlyRelations();                  // keep permission catalog global
Warden::tenant()->dontScopeRoleGrants();
```

### Behavior with no active tenant

Configure `warden.scope.null_behavior`:
- `'all'` — sees everything (global + all tenants)
- `'strict'` — sees only global rows

> 📌 **Writes always target one exact scope.** A write under tenant 5 only affects tenant-5 rows. Global rules are only writable globally.

### Best Practices

✅ **Do** — remove a global forbid where it lives: outside any tenant:

```php
Warden::tenant()->removeOnce(fn () => Warden::unforbid($user)->to('publish'));
```

❌ **Don't** — expect a tenant-scoped `unforbid()` to lift a **global** forbid.

---

## 🔧 Conditional Permissions (ABAC)

Grants can carry conditions, written in the grammar your queries already use:

```php
Warden::allow($user)->to('view', Document::class)
    ->where('status', 'published')
    ->orWhere(fn ($group) => $group
        ->where('tier', '>=', 2)
        ->whereColumn('owner_id', 'id')
    );
```

### Available operators

| Method | Description |
|---|---|
| `where('col', 'value')` | Entity attribute equals value |
| `where('col', '>=', 5)` | With explicit operator |
| `whereColumn('owner_id', 'id')` | Compare against authority's attribute |
| `orWhere(...)` | OR grouping |
| `orWhere(fn)` | Nested closure grouping |

> 📌 **Important:**
> - Precedence is SQL's: `AND` binds tighter than `OR`.
> - Comparisons are strict — no PHP type juggling.
> - A constrained grant **never matches instance-less checks** (`can('view')`, `can('view', Document::class)`) — they fail closed.

### Best Practices

✅ **Do** — grant broadly, constrain the sensitive part:

```php
Warden::allow('viewer')->to('view', Document::class)->where('status', 'published');
Warden::forbid($user)->to('view', Document::class)->where('classified', true);
```

❌ **Don't** — encode workflow logic as constraints (e.g., "drafts visible on Tuesdays"). Complex rules belong in policies.

---

## 📊 Querying by Permission

Checks answer "can X do Y?"; Warden can also answer **"over which rows?"**

```php
use ElPandaPe\Warden\Concerns\QueriesByPermission;

class Post extends Model
{
    use QueriesByPermission;
}

// Usage
Post::whereCan($user, 'view')->latest()->paginate();
```

Instance grants, class grants, wildcards, everyone-grants, role grants, forbids, tenancy, ownership, and **ABAC constraints all compile into the query**.

> ⚠️ What cannot become SQL fails closed: closure-resolved ownership and restricted-role grants contribute no rows.

### Best Practices

✅ **Do** — drive index pages straight from authorization:

```php
Post::whereCan($user, 'view')->latest()->paginate();
```

❌ **Don't** — post-filter with `->get()->filter(fn ($p) => $user->can('view', $p))` — that's the N+1 this scope exists to delete.

---

## 🔍 Debugging with `explain()`

```php
$why = Warden::explain($user, 'edit', $post);

$why->allowed();      // bool
$why->cause;          // Cause::ForbiddenViaRole, Cause::GrantedDirectly, …
$why->permission;     // the decisive catalog row
$why->role;           // the role that carried it, when one did
(string) $why;        // "Explicitly forbidden by permission [edit] via role [banned]."
```

> 📌 Always answered by the database engine — never from cache — so it diagnoses stale-cache issues too.

---

## 📡 Events

Every write dispatches a typed, `readonly` event with **hydrated models** (never raw IDs). Disable globally with `warden.events_enabled`.

| Event | Fired By | Payload |
|---|---|---|
| `PermissionGranted` / `PermissionForbidden` | `allow()`, `forbid()` | `?Model $authority`, `Collection $permissions`, `$scope` |
| `PermissionRevoked` / `PermissionUnforbidden` | `disallow()`, `unforbid()` | Same shape |
| `RoleAssigned` / `RoleRetracted` | `assign()`, `retract()` | `Model $authority`, `Collection $roles`, `$scope`, `?Model $restrictedTo` |
| `RolesSynced` / `PermissionsSynced` | `sync()` | `SyncResult` diff: `attached` / `detached` / `kept` |
| `RoleCreated/Deleted`, `PermissionCreated/Deleted` | Model layer | The model |

```php
use ElPandaPe\Warden\Events\PermissionGranted;

Event::listen(PermissionGranted::class, function (PermissionGranted $event) {
    audit('granted', $event->authority, $event->permissions->pluck('name'));
});
```

### Pre-action events (opt-in)

Enable with `warden.cancellable_events`. A listener returning `false` aborts the write:

```php
// GrantingPermission, ForbiddingPermission, AssigningRole
```

> 📌 `sync()` never fires nor honors pre-action events — its declarative diff events tell the whole story.

---

## ⚠️ Exceptions

All typed, all catchable the Laravel way:

```php
Warden::findRole('ghost');            // RoleDoesNotExist (ModelNotFoundException)
Warden::findPermission('ghost');      // PermissionDoesNotExist
Warden::authorize('publish', $post);  // UnauthorizedException (AuthorizationException)
```

| Exception | Extends | Notes |
|---|---|---|
| `RoleDoesNotExist` | `ModelNotFoundException` | — |
| `PermissionDoesNotExist` | `ModelNotFoundException` | — |
| `UnauthorizedException` | `AuthorizationException` | `getRequiredPermissions()` / `getRequiredRoles()` |
| `ConfigurationException` | — | Fail-fast on bad config |

> 📌 `UnauthorizedException` messages are translatable (shipped in English and Spanish). Displaying the missing permission/role name in the message is **opt-in** via `warden.exceptions.display_*`.

---

## 🔢 Enums

Every public signature that takes a permission or role name also accepts a string-backed enum:

```php
enum Permission: string
{
    case EditSite = 'edit-site';
}

enum Role: string
{
    case Admin = 'admin';
}

Warden::allow($user)->to(Permission::EditSite);
Warden::assign(Role::Admin)->to($user);
$user->isAn(Role::Admin);
Warden::authorize(Permission::EditSite);
```

---

## 💾 Caching

Enabled by default. One minimal payload per authority, O(1) automatic invalidation, anti-stampede locking, Octane-safe.

```php
// config/warden.php
'cache' => [
    'enabled' => true,
    'store' => 'default',
    'prefix' => 'warden',
    'expiration_time' => DateInterval::createFromDateString('24 hours'),
],
```

### Manual invalidation

```php
Warden::refresh();          // O(1) version bump — invalidates everything
Warden::refreshFor($user);  // Drop one authority's payload
```

### Best Practices

✅ **Do** — write through Warden and let invalidation take care of itself:

```php
Warden::disallow($user)->to('publish');   // next check is already correct
```

❌ **Don't** — raw database edits (seeders, manual SQL) bypass invalidation. After hand-editing rows, call `Warden::refresh()` — or better, make the edit through the API.

> ⚠️ The in-memory matcher compares permission names **byte-exactly**, while a case-insensitive database collation may match `Edit` to `edit`. Use exact, consistent names.

---

## 🧪 Testing

### Fake mode

```php
$fake = Warden::fake();
$fake->allow('edit-site')->forbid('delete');

$fake->assertChecked('edit-site');
$fake->assertGranted('edit-site');
$fake->assertForbidden('delete');
$fake->assertNothingChecked();
```

### WithPermissions trait

```php
use ElPandaPe\Warden\Testing\WithPermissions;

$this->allowUser($user, 'view', Document::class);
$this->assignRoles($user, 'admin');
```

### Artisan commands

```bash
php artisan warden:show [Class:id]       # Show permissions for an authority
php artisan warden:cache-reset           # Reset cache
php artisan warden:clean --dry-run       # Clean orphaned permissions
```

---

## 🛡️ Middleware & Blade

Off by default. Enable via config:

```php
'warden.register_middleware_aliases' => true,
'warden.register_blade_directives' => true,
```

### Middleware

```php
Route::get('/admin', ...)->middleware('warden.role:admin,editor');      // any of
Route::put('/site', ...)->middleware('warden.permission:edit-site');    // all of
```

### Blade

```blade
@forbidden('publish')
    You are explicitly banned from publishing.
@endforbidden
```

---

## 🏗️ Schema & Models

Four tables:

| Table | Purpose |
|---|---|
| `permissions` | The catalog |
| `roles` | Role definitions |
| `assigned_roles` | Role ↔ authority pivot |
| `grants` | Permission ↔ authority (with `forbidden` flag) |

Any model can hold roles and permissions:

```php
use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

### Swap models via config

```php
// config/warden.php
'models' => [
    'role' => App\Models\Role::class,
],
```

```php
// app/Models/Role.php
class Role extends Model
{
    use ElPandaPe\Warden\Models\Concerns\IsRole;
}
```

> 📌 **Never** hardcode package classes in relations. Always resolve via config.

---

## ⚙️ Configuration

Everything lives in `config/warden.php`:

| Section | Controls |
|---|---|
| `models` | Swappable Role, Permission, Grant, AssignedRole models |
| `tables` | Table names and database connection |
| `morphs` | Morph aliases (`warden.role`, `warden.permission`) |
| `gate` | Gate behavior (`run_before_policies`) |
| `ownership` | Global/per-class ownership attribute |
| `scope` | Multi-tenancy semantics |
| `cache` | Store, prefix, TTL |
| `events` | Enable/disable events, cancellable pre-action events |
| `exceptions` | Display permission/role names in messages |

---

## 📖 Recipes

### Authorize someone other than the current user

```php
Gate::forUser($tenantUser)->allows('edit', $post);
Warden::explain($tenantUser, 'edit', $post);
```

### Ownership through a pivot table

```php
Warden::ownedVia(Business::class, fn ($business, $user) =>
    $business->owners()->whereKey($user->getKey())->exists()
);
Warden::allow($user)->toOwn(Business::class, ['manage']);
```

> ⚠️ Closure-resolved ownership cannot compile into `whereCan()`.

### Default role for new users

```php
// In your User model or observer:
protected static function booted(): void
{
    static::created(fn (User $user) => Warden::assign('member')->to($user));
}
```

> 💡 There is no "role for everyone" by design. Use `Warden::allowEveryone()->to(...)` for global grants.

### Landlord vs tenant databases

Point warden tables at their own connection with `warden.connection`. The published migration honors it (`Schema::connection(...)`), and the migration class is anonymous to avoid collisions.

### Replace a role instead of stacking

```php
Warden::sync($user)->roles(['editor']);     // declarative
Warden::retract('viewer')->from($user);       // or surgical
Warden::assign('editor')->to($user);
```

### Long-lived processes (Tinker, Octane, queues)

Writes through the API invalidate caches automatically. Only raw DB edits need `Warden::refresh()`. Tenant state lives in container-scoped bindings, so Octane requests and queue jobs reset themselves.

---

## 🔄 Migrating from silber/bouncer

```bash
composer require elpandape/warden        # replaces silber/bouncer (conflict enforced)
php artisan warden:upgrade --dry-run       # report
php artisan warden:upgrade                 # in-place schema transform
vendor/bin/rector process app --config vendor/elpandape/warden/stubs/rector-silber-upgrade.php
```

The fluent API is intentionally compatible. The schema upgrades in place (`abilities` → `permissions`, `permissions` pivot → `grants`). See **UPGRADE.md** for the full equivalence table.

---

## 🧪 Development

No local PHP or Composer needed — everything runs through Docker:

```bash
make build      # build the dev image
make install    # composer install
make ci         # pint + phpstan + rector + tests (100% coverage) + type coverage
make test-dbs   # run suite against MySQL 9 and Postgres 16
make mutation   # mutation testing over the core
make shell      # shell inside the container
```

---

## 👤 Credits & License

- **Original concept & API design:** [Joseph Silber](https://github.com/JosephSilber) — this project started as an evolution of his [Bouncer](https://github.com/JosephSilber/bouncer) and keeps his copyright notice.
- **Maintainer:** [Carlos Mayorga](https://github.com/elpandape)

Licensed under the [MIT License](LICENSE.md).

---

<p align="center">
  <sub>Authorization that explains itself.</sub>
</p>
