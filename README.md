<p align="center">
  <img src="https://repository-images.githubusercontent.com/1362165005/fe6eacd8-d46e-4109-ab9b-a19ead739ce2" alt="Warden" width="800">
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
- [⏳ Temporary Access](#-temporary-access)
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
| ⏳ **Temporary access** | `until($moment)` on a grant or an assignment — it stops authorizing on its own. |
| 🪆 **Nested roles** | A role inside a role lends its grants, off by default and switchable live. |
| 🏢 **Multi-tenancy** | Tenant-scoped rows with global fallback, injectable resolver, exception-safe `onceTo()`. |
| 💾 **Smart caching** | O(1) invalidation, versioned payloads, anti-stampede locking, Octane-safe. |
| 📡 **Typed events** | Warden's verbs and catalog models announce exactly the rows they changed — hydrated models (snapshots for what a deleted role held), end dates and contexts — each stamped with the operation that wrote it. |
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

> 🔄 **Coming from silber/bouncer?** This package conflicts with it by design (same default tables). Run `php artisan warden:upgrade` to migrate the schema in place. See [MIGRATING-FROM-BOUNCER.md](MIGRATING-FROM-BOUNCER.md).

> ⬆️ **Already on warden 1.x?** 2.0 adds a column and a unique index to `permissions`. Publish and run the upgrade migration — `vendor:publish --tag=warden-migrations-v2` then `migrate` — before the first write. See [UPGRADE.md](UPGRADE.md#from-1x-to-20).

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
> - `Warden::can()` inside a policy **recurses through the Gate**. Ask the resolver directly — `app(Contracts\Resolver::class)` — when a policy needs Warden's own answer.
> - With `warden.gate.register` off, Warden abstains from every Gate answer, so a loose permission with no policy behind it reads as denied by **every** route through the Gate — `$user->can()`, `Warden::can()`, `cannot()`, `canAny()`, `authorize()` and the `warden.permission` middleware all go through the same Gate. What keeps answering is the resolver, `app(Contracts\Resolver::class)` — and your policies, wherever you have one.

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

> 📌 **An authority is a saved model with a usable key.** `allow()`, `forbid()`, `assign()->to()` and `sync()` refuse one that is not saved — a deleted one included — or whose key is not an int or a non-empty string, with a `ConfigurationException` thrown before any row is written: the row would name nobody while the event named the model. `disallow()`, `unforbid()` and `retract()->from()` only need the key, so they keep working from a model's own `deleted` hook. When everyone is what you mean, say so with `allowEveryone()`.

> 📌 **Only `allowEveryone()` grants to everyone.** Its rows carry neither an authority type nor a key, and those are the only rows `can()`, `whereCan()` and `getPermissions()` read as everyone's. A row with a type and no key — 3.0.0 wrote one for an unsaved authority — grants no saved model, and `php artisan warden:clean --stranded` deletes it.

> 📌 **Assignments are one hop unless you turn nesting on.** `assign('auditor')->to($role)` writes an edge between roles. By default holders of the outer role gain nothing from it — set `warden.roles.nested` to `true` and they inherit the inner role's grants, to `warden.roles.max_depth` levels deep.
>
> ```php
> // config/warden.php
> 'roles' => ['nested' => true, 'max_depth' => 10],
> ```
>
> **Off by default on purpose**, because turning it on widens what every existing assignment reaches. The switch is read on every check rather than baked into a cached payload, so turning it back off takes effect immediately — it is meant to work as an emergency lever. A cycle stops expanding at the depth ceiling instead of throwing.
>
> `can()`, `getPermissions()`, `getForbiddenPermissions()` and every role check nest together — `isA()` and its variants, `isAll()`, `Warden::is()`, `whereIs()`, `whereIsAll()`, `whereIsNot()` and the `warden.role` middleware: a split would let `can('publish')` say yes while `Warden::is($user)->an('editor')` says no, painting a menu wrong for precisely the users with the most access. The two listings count unrestricted assignments only, as they always have.
>
> `$role->nestedRoles()` only reads the edges; `Warden::assign($inner)->to($outer)` and `Warden::retract($inner)->from($outer)` write them, scoped to the tenant, with the cache invalidated and the event fired. Every writer the relation declares — `attach()`, `detach()`, `sync()`, `toggle()`, `updateExistingPivot()`, `save()`, `create()`, `firstOrCreate()` and the rest, `OrFail` and `Quietly` variants included — throws a `ConfigurationException` pointing to those two, because a write through the relation would reach every tenant and context at once, with no invalidation and no event. So does the pivot on a loaded edge: `$role->nestedRoles->first()->pivot` refuses `delete()`, a `save()` that would change it, and the `increment`/`decrement` family.
>
> `$role->nestedRoles()->delete()` deletes the inner roles themselves, as on any Eloquent relation — it is not an unnest. It runs through the query builder, so no model event fires and the cache is not invalidated, and the foreign key takes every assignment of those roles with them.

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

## ⏳ Temporary Access

Grants and role assignments can carry an end date. Past it they stop authorizing, stop appearing in `whereCan()`, and stop being listed by `getPermissions()` — no command has to run for that to happen.

```php
use ElPandaPe\Warden\Facades\Warden;

Warden::allow($user)->until(now()->addDays(7))->to('publish', Post::class);
Warden::assign('auditor')->until($audit->ends_at)->to($user);

// Lift an end date a previous write left; saying nothing leaves it alone.
Warden::allow($user)->until(null)->to('publish', Post::class);
```

> 📌 **The date lives on the assignment, not on the role.** A role is a shared definition, so an end date there would end it for everyone. On the assignment, the same role can end on different days for different holders — and when it does, the permissions that role lent go with it.

> 📌 **An expired assignment stops counting as held**, not only for `can()`. `isA()`, `isAll()`, `Warden::is()`, `whereIs()`, `whereIsAll()` and `whereIsNot()` read the date too, with nesting on or off, and so does the `warden.role` middleware, which answers an expired role with a 403. The relation is left alone: `$user->roles` still lists the row until something deletes it, with its date on the pivot's `expires_at`.

> 📌 **`until()` goes before `to()`**, like `on()`: writes execute immediately, so calling it afterwards throws rather than quietly doing nothing. Moving a date counts as a write — it invalidates the cache and fires the same event as any other, whose entry carries the date before and after.

> 📌 **A condition keeps the date.** `where()` re-points a grant at its constrained twin (see [Conditional Permissions](#-conditional-permissions-abac)), and the twin takes the date the same chain declared — `until(null)` included, which lifts it. Without `until()` it keeps the date the grant already had; if the authority held that permission both plain and under a condition, with different dates, the later one wins and no end date beats any.

> 📌 **Re-assigning does not revive an expired row.** Saying nothing about time leaves the date alone, and that date has passed: `assign('auditor')->to($user)` or `allow($user)->to('publish')` over an expired row writes nothing, and the access stays ended. Give it a new date, or `until(null)` to make it permanent.

> ⚠️ **A `forbid()` cannot expire**, and `until()` on one throws. A prohibition that lapsed by clock would turn *a forbid beats every grant* into *until Tuesday*, with the grant beneath it still live. Lift it deliberately with `unforbid()`.

> 📌 **A grant reached through a role outlives neither**: the earlier of the two dates ends it.

> 📌 **The boundary is exclusive.** A row stops counting at the instant it names, not a tick later.

`php artisan warden:clean --expired` deletes rows past their date. It is hygiene, not part of the mechanism: **an expired grant stops authorizing whether or not anyone runs it.**

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

// This class has no owner at all — overrides the global fallback
Warden::notOwned(Setting::class);
```

> 📌 **`ownedVia()` only registers; it never removes.** `ownedVia(Post::class, null)` sets the *global* attribute to `"App\Models\Post"`, which is never what you meant. Use `notOwned()` to take one class out, or `'default_attribute' => null` in the config to turn the fallback off everywhere.

> 📌 **A `toOwn()` grant against a class that resolves no ownership can never grant.** The row is written and looks healthy; warden logs a warning so it is greppable.

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

> 📌 A restricted role's grants apply when the checked entity **belongs to the context**. Checks without an instance fail closed. Role membership checks (`isAn('editor')`) ignore restrictions by design — but not end dates: an assignment past its `until()` stops counting, restricted or not.

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

Reads and deletes are therefore asymmetric: a check under tenant 5 answers *global **or** tenant 5*, while `retract()` and `disallow()` delete tenant-5 rows only. So a retract under a tenant can succeed and leave the authority still holding the role globally. `retract()->from()` exposes `retractedCount()` for callers that need to tell the cases apart:

```php
$removed = Warden::retract('editor')->from($user)->retractedCount();  // rows deleted at this scope
```


> ⚠️ **The scope rule is warden's, not Eloquent's.** `TenantScope` filters reads and stamps creates; it does not isolate writes. `$permission->delete()` and `$role->delete()` reach rows in every tenant, and the foreign keys cascade below Eloquent entirely. Remove rows through warden's own verbs, or through `warden:clean`.

> ⚠️ **Pivot tenancy is a plain predicate, not a registered scope**, so `withoutGlobalScopes()` does not lift it. Widen deliberately with `Warden::tenant()->removeOnce(...)`, which is the supported escape hatch.
>
> **Under `null_behavior => 'strict'` it narrows instead.** With no active tenant, strict reads only global rows, so `removeOnce()` turns `(scope is null or scope = $tenant)` into `scope is null` — strictly fewer rows than the read it was meant to widen. Under the default `'all'` it widens as described.

> 📌 **Relation writes obey the rule too.** `detach()`, `sync()`, `toggle()`, `syncWithoutDetaching()` and `updateExistingPivot()` on `roles()` and `permissions()` touch only rows at the active write scope, and `attach()` stamps it. A global row the tenant inherits stays out of reach in both directions: under tenant 5, `sync([$role])` adds the tenant-5 row beside the global one instead of adopting it, and `sync([])` leaves the global one standing. Relation writes invalidate the cache but announce nothing: see [Events for auditing](#events-for-auditing).

> ⚠️ **Scope, yes; restriction, no.** A relation write narrows to one scope and stops there: it does not filter `restricted_to_*`, so `$user->roles()->detach($editor)` removes the scoped-role assignments along with the plain one. That mirrors `Warden::retract('editor')->from($user)` without `->on()`, which deletes them all the same way — the relation is not narrower than the verb it reflects. To remove one context and leave the others, name it: `Warden::retract('editor')->on($org)->from($user)`.

> ⚠️ **A relation captures its write scope when it is built, not when it writes.** `$user->roles()` resolves the active tenant at construction time, so a relation held in a property across a tenant change still writes to the scope it was born in. Ask for it again after switching tenants, or write through `Warden::assign()` / `retract()`, which resolve the scope per call — and which also dispatch the typed events and report `retractedCount()`.

> 📌 **A role is global unless the write mints a tenant one.** Under an active tenant, `allow('editor')` attaches to a global `editor` if one exists, rather than creating a tenant-scoped twin. Roles are looked up by name and scope; a tenant twin only exists once something writes it.

> 📌 **The permission catalog behaves the same way**, and in both halves a row the tenant minted for itself wins the global one it shadows. Under a tenant, `allow($user)->to('publish')` reuses a global `publish` row when that is all there is, and picks the tenant's own the moment one exists. Set `Warden::tenant()->onlyRelations()` to keep the catalog global on purpose.

> 📌 `Tenancy::writeScope()` takes `forRoleGrant`, and it defaults to `false`. A bare call therefore reports the scope of an authority grant; ask with `forRoleGrant: true` when the holder is a role, or the answer describes a different write than the one you meant.

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
> - A **null** attribute satisfies no operator at all, `!=` included, and values whose types are not decidably comparable fail closed the same way. A **missing** attribute is different: under `Model::preventAccessingMissingAttributes()` it throws rather than failing closed.
> - Constrained grants share one catalog row per distinct rule, so **editing a permission's options changes the rule for every holder of that shape**. Write a new condition instead of editing a shared row.
> - A **boolean** value matches only a column the model casts to `bool`, and such a column matches only a boolean, so **writing either mismatch is refused**: `where('classified', true)` needs `'classified' => 'bool'` in the model's `$casts`. A row stored before 3.0 keeps failing closed in checks and in queries alike — `php artisan warden:doctor` lists those rows.
> - The refusal exists because of what the mismatch does to a `forbid()`: a condition that can never be true makes the prohibition inert, the grant underneath it stays live, and `explain()` reports that grant without ever mentioning the forbid — a missing cast read as "allowed".
> - A permission with **no entity** is only ever checked without an instance, so constraining one is refused: the shape that would make it match is the shape that rejects it.
> - A constrained grant **never matches instance-less checks** (`can('view')`, `can('view', Document::class)`) — they fail closed.
> - **`to()->where()` is two writes, not one.** `to()` lands an unconstrained grant that authorises every instance, and `where()` re-points it at the constrained twin. Only the second step runs in a transaction; between the two the live row is unconditional, and a throw in `where()` — an unknown operator, a permission with no entity, a twin in the trash (`TrashedCatalogRow`) — leaves it that way. Wrap the whole chain in your own transaction when that window matters.

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

> ⚠️ **No Gate, no policies.** `whereCan()` answers from warden's own rows only. A policy that would have granted or denied a row is not consulted, so a query and a check can disagree wherever a policy has the last word.

> ⚠️ **The trait is required.** Without it, `Post::whereCan($user, 'view')` never reaches Warden: Laravel reads it as a dynamic `where` against a column named `can`, and you get zero rows or a driver error instead of an answer.

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
$why->permission;     // the decisive catalog row, when one decided
$why->role;           // the role that carried it, when one did
(string) $why;        // "Explicitly forbidden by permission [edit] via role [banned]."
```

Which of `permission` and `role` are populated depends on the cause:

| Cause | `allowed()` | `permission` | `role` |
|---|---|---|---|
| `GrantedDirectly` | `true` | the row | — |
| `GrantedViaRole` | `true` | the row | the role |
| `GrantedToEveryone` | `true` | the row | — |
| `ForbiddenDirectly` | `false` | the row | — |
| `ForbiddenViaRole` | `false` | the row | the role |
| `ForbiddenToEveryone` | `false` | the row | — |
| `ConditionsNotMet` | `false` | the row whose conditions were not satisfied | — |
| `NoMatchingGrant` | `false` | — | — |
| `NotApplicable` | `false` | — | — |

> 📌 `ConditionsNotMet` and `NoMatchingGrant` are different answers: the first names a row that matched the shape but whose conditions were not satisfied — they failed against the instance, or the check named a class and there was no instance to satisfy them with — while the second means nothing matched at all. Both leave Warden abstaining so your policies decide.

> 📌 Always answered by the database engine — never from cache — so it diagnoses stale-cache issues too.

> 📌 **It blames the row that decided, and the role that holds it.** A row past its end date is never blamed, and neither is a role in the trash: `explain()` looks past both to the next source. With `warden.roles.nested` on, a row held by a role nested inside one the authority holds is `GrantedViaRole` or `ForbiddenViaRole`, and `role` names the nested role — or the authority's own role, when that one holds the row too. When several roles hold the row, `role` names the first one the check counted, the roles the authority holds before those nested inside them — so a role it holds under a restriction that does not apply, and also reaches through another role it holds, can be named ahead of that other role.

---

## 📡 Events

Warden announces its own writes. Every verb — `allow()`, `forbid()`, `disallow()`, `unforbid()`, `assign()`, `retract()`, `sync()`, and the `where()` that narrows a grant — dispatches a typed, `readonly` event, and so does creating, editing, deleting or restoring a role or permission through its model. Each event names what it touched with **hydrated models** (never raw IDs; what a deleted role held arrives as [snapshots](#snapshots)), lists exactly the rows that changed, and says which [operation](#one-call-one-operation) dispatched it. Writes made around warden are not announced: [Events for auditing](#events-for-auditing) says which, and when each event is dispatched. Disable globally with `warden.events_enabled`.

| Event | Fired By | Payload |
|---|---|---|
| `PermissionGranted` / `PermissionForbidden` | `allow()`, `forbid()`, and the `where()` that narrows them | `?Model $authority`, `Collection $permissions`, `$scope`, `?Model $actor`, `list<GrantChange> $grants` |
| `PermissionRevoked` / `PermissionUnforbidden` | `disallow()`, `unforbid()`, a narrowing `where()`, deleting a permission | `?Model $authority`, `Collection $permissions`, `$scope`, `?Model $actor`, `list<GrantRemoval> $grants` |
| `RoleAssigned` | `assign()` | `Model $authority`, `Collection $roles`, `$scope`, `?Model $restrictedTo`, `?Model $actor`, `list<AssignmentChange> $assignments` |
| `RoleRetracted` | `retract()`, deleting a role | `Model $authority`, `Collection $roles`, `$scope`, `?Model $restrictedTo`, `?Model $actor`, `list<AssignmentRemoval> $assignments` |
| `RolesSynced` / `PermissionsSynced` | `sync()` | `Model $authority`, `SyncResult $changes` (`attached` / `detached` / `kept`), `$scope`, `?Model $actor`; `PermissionsSynced` adds `bool $forbidden` |
| `RoleCreated` / `PermissionCreated` | Creating the row — also when a verb names one that does not exist yet | The model, `?Model $actor` |
| `RoleUpdated` / `PermissionUpdated` | A model save that changes the row's [snapshot](#snapshots) | The model, `array $before`, `array $after`, `list<string> $changed`, `?Model $actor` |
| `RoleDeleted` | Deleting the role through its model — to the trash too | `Model $role`, `?Model $actor`, `array $heldGrants`, `array $heldRoles`, `bool $softDeleted` |
| `PermissionDeleted` | Deleting the permission through its model — to the trash too | `Model $permission`, `?Model $actor`, `bool $softDeleted` |
| `RoleRestored` / `PermissionRestored` | `restore()` on a row in the trash, through its model | The model, `?Model $actor` |

> 📌 **Every event ends with `?string $operation`** — these sixteen and the six [pre-action events](#pre-action-events-opt-in) alike: the id of the [operation](#one-call-one-operation) that dispatched it. Warden stamps it on every event it builds; one you build yourself carries `null` unless you pass it. A job 3.1 queued restores without it, so read it as `$event->operation ?? null` until those queues drain. `bool $softDeleted` comes just before it on the two deletion events: `true` when the row went to the trash, `false` when it was destroyed — a `forceDelete()` from the trash included. Inside [`Event::defer()`](#when-an-event-is-dispatched) a `forceDelete()` says `true` too: trust the flag only for deletes made outside it.

The six write events carry one value per pivot row they wrote or deleted — `$grants` on the permission events, `$assignments` on the role events:

| Value | On | Properties |
|---|---|---|
| `GrantChange` | `PermissionGranted`, `PermissionForbidden` | `Model $permission`, `bool $created`, `?CarbonImmutable $expiresAt`, `?CarbonImmutable $previousExpiresAt` |
| `AssignmentChange` | `RoleAssigned` | `Model $role`, `bool $created`, `?CarbonImmutable $expiresAt`, `?CarbonImmutable $previousExpiresAt` |
| `GrantRemoval` | `PermissionRevoked`, `PermissionUnforbidden` | `Model $permission`, `?CarbonImmutable $expiresAt` — the date the row had when it went |
| `AssignmentRemoval` | `RoleRetracted` | `Model $role`, `?Model $restrictedTo` — the row's own context — and `?CarbonImmutable $expiresAt` |

A write reads like this:

| The row | `created` | `expiresAt` | `previousExpiresAt` |
|---|---|---|---|
| Was just created | `true` | the date it was created with, or `null` | `null` |
| Had its date moved | `false` | the new date | the old date |
| Got its first date | `false` | the new date | `null` |
| Had its date lifted with `until(null)` | `false` | `null` | the old date |

> 📌 **With `created: false` the two dates always differ** — a write that moves nothing is not announced. `expiresAt` is read back from the row, so it is the wall time the column stores, in your application's timezone, rather than the object you passed to `until()`.

> 📌 **`PermissionForbidden` carries `$grants` too.** A forbid cannot take `until()`, so in practice its entries are new rows with no end date.

```php
use ElPandaPe\Warden\Events\PermissionGranted;

Event::listen(PermissionGranted::class, function (PermissionGranted $event) {
    foreach ($event->grants as $grant) {
        // $authority received it; $actor granted it.
        audit(
            $grant->created ? 'granted' : 'date changed',
            $event->actor,
            $event->authority,
            $grant->permission->getAttribute('name'),
            $grant->expiresAt,
        );
    }
});
```

> 📌 **Building an event yourself** — in a test, say? Pass its arguments by name from `actor` on. Warden only ever appends optional parameters, so a name stays valid where a position would not.

`$actor` defaults to the authenticated user, on every post-write event — the catalog's included — and warden resolves it only for an event that goes out: never with events off, nor for the writes a `sync()` silences. Queues, console commands and impersonation are cases only your application can answer, so point `warden.actor_resolver` at a class implementing `Contracts\ActorResolver`:

```php
final class CurrentActor implements ActorResolver
{
    public function resolve(): ?Model
    {
        return Context::actingUser() ?? Auth::user();
    }
}
```

### Pre-action events (opt-in)

Enable with `warden.cancellable_events`. A listener returning `false` aborts the write:

```php
// GrantingPermission, ForbiddingPermission, AssigningRole
// RevokingPermission, UnforbiddingPermission, RetractingRole
```

> 📌 A pre-action event covers the **whole call**, not one item of it. `allow($user)->to(['a', 'b'])` announces both names in one event, and a listener returning `false` aborts **both**: there is no way to veto one and keep the other. Split the call if you need per-item decisions.

> 📌 `sync()` never fires nor honors pre-action events — its declarative diff events tell the whole story.

> 📌 **`where()` fires none.** It refines the grant `to()` has just made, and `to()` fired its own.

> 📌 **A cascade fires none either.** Deleting a role never fires `RetractingRole`, nor deleting a permission `RevokingPermission`: the foreign key deletes inside the engine, and the only veto over the delete is Eloquent's own — return `false` from a `deleting` listener on the model.

> 📌 **`Event::defer()` defeats the veto.** A pre-action event dispatched inside it reaches its listeners only after the write it announces, so a `false` stops nothing: see [When an event is dispatched](#when-an-event-is-dispatched).

### Events for auditing

What an audit log built on these events can rely on, and what it cannot.

#### What an event describes

An event describes **rows** warden wrote or deleted at `$scope` — not the access that results. Ask [`Warden::explain()`](#-debugging-with-explain) about access. So:

- a `PermissionGranted` under a tenant can leave access unchanged, when a global grant already gave it;
- deleting a row whose end date had passed is announced, with that date: the entry tells a revocation from the sweep of something already dead;
- `RoleCreated` and `PermissionCreated` never change access — a catalog row nobody holds authorizes nothing — so an access log can ignore them;
- a trip to the trash changes access though no holder's row moves: `RoleDeleted` or `PermissionDeleted` with `softDeleted: true` ends what the row granted and forbade, and `RoleRestored` or `PermissionRestored` gives it back — [Deleting a role or a permission](#deleting-a-role-or-a-permission) shows how that reads in a log.

#### What a write announces

- **Only what changed, once per authority.** `assign('editor')->to([$ana, $luis])` with Ana already an editor dispatches one `RoleAssigned`, for Luis. An authority the call changed nothing for receives nothing, and a call that changes nothing dispatches nothing — `sync()` excepted, below.
- **`$roles` and `$permissions` are the models of those rows**, each once, in the order you named them. The entries say the rest: which row was created, which had its date moved, which context a removal took.
- **Moving an end date is a write.** `until()` over an existing row dispatches the same event as a new row, told apart by its entry: `created: false`, with the dates after and before. Reaching the date dispatches nothing — expiry by clock is silent by design, and the date was announced when it was written.
- **Re-assigning an expired row without `until()` announces nothing**, because it writes nothing: [Temporary Access](#-temporary-access) explains why the row stays as it was. `sync()` lists that row under `kept`. Give it a new date, or `until(null)`, and the write is announced as a moved date.
- **`retract()` without `on()` removes every context of the role.** `RoleRetracted::$restrictedTo` is the context the call named — `null` when it named none — and each `AssignmentRemoval` carries the context its own row had. Losing `editor` with no context and in two organizations is one event with three entries, and `editor` once in `$roles`. A row whose context can no longer be named gets no entry, though `retractedCount()` still counts it.
- **A call writes its rows before it announces them.** Every grant and assignment row the call touches, for every authority it names, is written or deleted before its first write event goes out: a listener that throws on one cannot interrupt those writes, only the announcements still to come — and the exception leaves the call. Two kinds of write fall outside that. A role or permission the call creates by name dispatches its `RoleCreated` or `PermissionCreated` as it is created, before the rows that use it are written, so a listener that throws there stops the call before them — see [Implicit creation](#implicit-creation-and-the-narrowing-chain). And the unused plain row a `where()` deletes goes after the announcements, below.

#### One call, one operation

Every event carries `$operation`, the id of the operation that dispatched it, so a log can show what one act wrote as one entry. It is a ULID — 26 upper-case characters, ordered by the time the operation opened — and it only says which events belong together.

- **A call is an operation.** Every call that writes — `allow()` or `forbid()` with `to()`, `toOwn()` and what builds on them, `disallow()` and `unforbid()` the same way, `assign()->to()`, `retract()->from()`, and `sync()` with `roles()`, `permissions()` or `forbiddenPermissions()` — opens one before anything else, so its pre-action event, the roles and permissions it creates by name, and its write events share one id.
- **A narrowing chain is one operation.** `where()`, `orWhere()`, `whereColumn()` and `orWhereColumn()` resume the operation of the `to()` they refine, so every event of a chain shares one id — the `PermissionDeleted` of the plain row it retires included.
- **A catalog write through the model is an operation of its own** when no call is open around it — `Role::create()`, an admin form's `$permission->update()`, a `restore()` — and a delete shares one with its cascade: `RoleDeleted` and every `RoleRetracted` after it carry the same id. A `restore()` that also saves another changed column is two writes, though: its `RoleUpdated` or `PermissionUpdated` and its `RoleRestored` or `PermissionRestored` carry different ids, unless `Warden::operation()`, below, wraps the call.
- **`warden:clean` is one operation per run.**
- **Separate calls are separate operations**, chained on one builder too: `sync($user)->roles([...])->permissions([...])`, `allow($user)->to('a')->to('b')` and `retract('editor')->from($ana)->from($luis)` make one per call. Wrap them to make them one:

```php
use ElPandaPe\Warden\Facades\Warden;

// Saving a permission grid: 'delete' flips from granted to forbidden; 'view' and 'edit' are granted.
$operation = Warden::operation(function (string $operation) use ($role) {
    Warden::disallow($role)->to('delete', Document::class);
    Warden::forbid($role)->to('delete', Document::class);
    Warden::allow($role)->to(['view', 'edit'], Document::class);

    return $operation;
});
```

The `PermissionRevoked` and the `PermissionForbidden` of the flipped cell, and the `PermissionGranted` of the other two, carry the same `$operation`: a log can show the flip as one change instead of two. `operation()` returns what its callback returns. There is no accessor for the id of an open operation: take it from the callback's argument, or from an event.

- **An operation inside an operation joins it.** The inner callback receives the outer id, and so does every call made inside it: a helper that wraps its own writes in `Warden::operation()` still belongs to its caller's act.
- **A listener that writes through warden joins the operation of the event it hears**, because it runs while that operation is open — unless it waits for the commit (`ShouldHandleEventsAfterCommit`) and the transaction commits after the operation closed: its writes then open their own.
- **A queued listener receives `$operation` in the event.** What its own writes join depends on the driver: a queue worker starts every job with no operation open, so they open their own; the `sync` driver runs the listener inside the dispatch, where they join the open one.
- **It is not a transaction.** An operation opens no database transaction, holds back no event and no cache invalidation, and catches nothing: every event goes out as its write finishes, exactly as without it, and an exception passes through `operation()` unchanged. When the writes must stand or fall together, open a `DB::transaction()` as well — inside the callback or around it; either way every event carries the id.

#### Implicit creation and the narrowing chain

- **Naming something that does not exist creates it.** The first `allow()` or `forbid()` that names a permission dispatches `PermissionCreated` before its `PermissionGranted`, and a role that `assign()`, `allow()`, `forbid()` or `sync()` names for the first time dispatches `RoleCreated`. A catalog log gets one entry per first use. A name that matches only a row in the trash throws `TrashedCatalogRow` instead of creating a second one: see [Deleting a role or a permission](#deleting-a-role-or-a-permission).
- **`to()->where()` is two writes, and it announces both.** `to()` lands the unconstrained grant and announces it; `where()` re-points it at the constrained twin. It announces every grant row it replaced — the plain one, and any twin an earlier condition left — as `PermissionRevoked` (`PermissionUnforbidden` for a forbid), then the twin's `PermissionGranted` (`PermissionForbidden`) when its row was created or its date moved, and last `PermissionDeleted` for a plain row the chain itself created and left unused. With `SoftDeletes` on your permission model, that row — or a twin the chain created and a second `where()` leaves unused — is force-deleted, never sent to the trash, so no later write of the rule finds it there and throws `TrashedCatalogRow`: its `PermissionDeleted` says `softDeleted: false`. Over several permissions — `to(['view', 'edit'], Document::class)->where(...)` — each event's `$grants` follows the order you named them, and the rows replaced for one of them follow their keys.
- **The first chain on a permission nobody holds yet dispatches six events**, in this order: `PermissionCreated` and `PermissionGranted` for the plain row, `PermissionCreated` for the twin, `PermissionRevoked` for the plain grant, `PermissionGranted` for the twin, `PermissionDeleted` for the plain row. Running the identical chain again leaves the twin's grant row as it was — same row, no event about it — and dispatches the four about the plain row that `to()` creates and `where()` retires. All of a chain's events carry one `$operation`: `where()` resumes the operation of the `to()` it refines.
- **The twin is created before the re-point's transaction, and the unused plain row deleted after it** — after the re-point's announcements, too — so no event is dispatched from inside that transaction. A re-point that fails leaves the twin in the catalog with no grants; a listener that throws on the re-point's `PermissionRevoked` or `PermissionGranted` (`PermissionUnforbidden` or `PermissionForbidden` for a forbid) leaves the plain row there instead, with no grants either. Neither authorizes anything, and `warden:clean` reclaims both — unless your own transaction rolled them back first.
- **A listener that throws on the `PermissionGranted` of `to()` stops the chain before `where()` runs**: the unconstrained grant stays, as a throw inside `where()` would leave it (see [Conditional Permissions](#-conditional-permissions-abac)). Wrap the chain in your own transaction when that matters.

#### Sync

- **`sync()` dispatches one diffed event**, `RolesSynced` or `PermissionsSynced`, and silences the per-row events of the writes it delegates. Catalog events are not silenced: a role or permission `sync()` creates by name still dispatches `RoleCreated` or `PermissionCreated`, with the sync's `$operation`. Each of `roles()`, `permissions()` and `forbiddenPermissions()` is an operation of its own, chained on one `sync()` too.
- **It dispatches even when nothing moved**, with everything under `kept` — the one write event that does.
- **`detached` names the rows the sync read and then deleted.** A permissions sync names plain rules only — a name resolves to the row with no entity, no condition and no ownership — so it never deletes a class, instance, `toOwn()` or conditioned grant, nor reports one as `detached`. The read and the delete are two statements: a grant or an assignment another connection writes between them is deleted without appearing in `detached`.
- **`kept` names what the sync left in place**, a row whose end date has passed included: the sync neither revives nor removes it.
- **A sync leaves the trash alone.** It declares what is live, so it never deletes the assignments of a role in the trash or the grants of a permission in the trash, nor names them under `detached` or `kept`, and `restore()` still brings them back. A name that matches only a row in the trash throws `TrashedCatalogRow`, as it does for the other verbs.

#### Deleting a role or a permission

- **It settles before anyone hears of it.** Whatever no foreign key reaches is swept first — a role's own grants, and the nested edges it held as an authority — and the cache is invalidated, even when the sweep throws. Then `RoleDeleted` or `PermissionDeleted` goes out, then the cascade's events, all with one `$operation` — the call's, when the delete runs inside one, as the plain row a `where()` retires does. A listener that throws stops the announcements after it, and nothing else.
- **A `deleting` listener that halts the dispatch leaves less settled.** One of yours that returns anything but `null` or `false` — `false` cancels the delete — stops Eloquent's dispatch before warden reads what the delete reaches, and the delete goes ahead. A role's own grants and nested edges are still swept, a permission still invalidates its own scope, and a role going to the trash every scope it reaches; but a role's hard delete then invalidates nothing, its `RoleDeleted` carries empty held lists, and no cascade event goes out.
- **Deleting a permission** dispatches `PermissionDeleted`, then one `PermissionRevoked` — or `PermissionUnforbidden`, for a forbid — per grant its foreign key took, in grant-key order, with that row's `GrantRemoval`, expired rows included with their date. A grant `allowEveryone()` wrote arrives with a `null` authority: it was everyone's.
- **Deleting a role** dispatches `RoleDeleted`, carrying `$heldGrants` and `$heldRoles`: what the role itself held, as [snapshots](#snapshots) with their polarity, scope, context and end date, expired rows included — the record of rows swept rather than announced one by one. Then one `RoleRetracted` per holder and scope, in assignment-key order: `$roles` is the deleted role, `$restrictedTo` is `null` because no call named a context, and `$assignments` has an `AssignmentRemoval` per row, with its context and date. A role that held the deleted one through nesting arrives as the authority, nesting on or off.
- **What the cascade announces is read before the delete.** A foreign key removes the rows inside the engine, where no model event fires, so warden reads them first, and the read is the announcement: if the foreign key is not enforced, the events describe a deletion that did not happen. The read and the delete are two statements, too: a row another connection writes between them goes with no event, and one it deletes meanwhile can still be announced.
- **The cascade is blind to the active tenant.** Rows and holders are read with `withoutGlobalScopes()`, on purpose: the delete destroys every tenant's rows whichever one is active, so counting only the current tenant would promise a smaller loss than the real one. A holder under another tenant, or soft-deleted, arrives named — never as a `null` authority, which would read as *everyone*. A holder whose row is already gone is not announced — it authorized nobody — and neither is a row with a type and no key, or a key and no type; one whose morph alias maps to no class in this process is skipped with a warning in the log. A restriction context whose row is gone arrives as an unsaved model carrying only its key (`exists` is `false`), never as `null`, which would read as *no restriction*. A row whose restriction context maps to no class, or names only half of one, gets no entry, and a holder left with no nameable row gets no `RoleRetracted`.
- **A role in the trash neither grants nor forbids.** With `SoftDeletes` on your role model, `delete()` takes the role out of every check at once: `can()`, the Gate, `@can`, `authorize()`, the `warden.permission` middleware, `whereCan()`, `getPermissions()`, `explain()` and the cached checks, and the role checks — `isA()`, `isAll()`, `Warden::is()`, `whereIs()`, `whereIsAll()`, `whereIsNot()` and `warden.role` — stop seeing it and, with nesting on, what its holders reached through it. What it forbade lifts too, which can widen access where a broader grant stands. Nothing is swept: its grants, holders and nested edges stay, and `restore()` brings all of it back at once. The cached checks of every scope the role reaches are invalidated before `RoleDeleted` goes out, and again before `RoleRestored`. Only the trash counts: a global scope of your own on the role model does not hide it from checks.
- **In an audit log, the soft delete is where access ends.** `RoleDeleted` goes out with `softDeleted: true` and empty `$heldGrants` and `$heldRoles` — nothing was swept, which is not to say the role held nothing — and no `RoleRetracted` follows, because no holder's row was destroyed. `restore()` dispatches `RoleRestored`. A later `forceDelete()` from the trash dispatches a second `RoleDeleted`, with `softDeleted: false` and the held lists, then a `RoleRetracted` per holder and scope, and sweeps as usual: those events record the rows destroyed, for access that ended at the soft delete.
- **A name never reaches the trash.** `findRole('editor')` and the verbs that remove by name look past a role in the trash: `retract('editor')` takes nothing and `disallow('editor')` throws `RoleDoesNotExist`. A name that would create the role — `assign('editor')`, a `sync()` naming it, the `allow('editor')` or `forbid('editor')` that creates it — throws `TrashedCatalogRow` rather than create a second `editor` beside it: restore the trashed one, or force-delete it, first. Only warden's verbs check: your own `Role::create()` or `Warden::role()->firstOrCreate()` still writes a second `editor` wherever the unique index lets it. Under a tenant, a global `editor` in the trash does not stop `assign('editor')` from creating the tenant's own, since only the row the insert would stand in for counts — unless `onlyRelations()` keeps the catalog global. And `allow('editor')->to('publish')` resolves `publish` before it refuses, so a `publish` it had to create stays behind, unused, for `warden:clean`. The trashed model itself is accepted (`Role::withTrashed()`): `assign($trashed)` and `allow($trashed)` write rows that stay inert until `restore()`, and `retract($trashed)` and `disallow($trashed)` remove them.
- **A permission in the trash neither grants nor forbids.** It keeps its grant rows and announces no cascade — only `PermissionDeleted`, with `softDeleted: true` — but every check stops seeing it at once, and the cached checks of every scope its grants live in are invalidated before `PermissionDeleted` goes out: a prohibition it carried lifts, which can widen access where a broader grant stands. `restore()` brings it back everywhere at once, invalidating the same scopes before `PermissionRestored` goes out. A later `forceDelete()` from the trash dispatches a second `PermissionDeleted`, with `softDeleted: false`, then the cascade. A name that matches only a permission in the trash — `allow($user)->to('publish')`, or the twin of a `where()` — throws `TrashedCatalogRow`; the twin's refusal comes after `to()` wrote the unconstrained grant, which stays: see [Available operators](#available-operators). With `SoftDeletes` on your permission model, `warden:clean` sends unused permissions to the trash rather than away, and the next write of such a name throws `TrashedCatalogRow` until you restore the row or force-delete it: see [Maintenance commands](#maintenance-commands).
- **With events off, a delete still settles.** Turning `warden.events_enabled` off stops the announcements, not the cache invalidation or the sweep — nor the invalidation that moving a row in or out of the trash causes. A role's or a permission's delete then skips the reads that only feed its events.
- **A bulk delete announces nothing.** `Role::query()->where(...)->delete()`, `DB::table()` and raw statements fire no model event: no `RoleDeleted`, no cascade events, no sweep and no cache invalidation. Delete model by model to keep all four — `Role::query()->where(...)->lazyById()->each->delete()` — or follow a bulk delete with `Warden::refresh()` and `warden:clean --stranded` — not with one users database per tenant: see [Landlord vs tenant databases](#landlord-vs-tenant-databases). With `SoftDeletes`, the same query sends the rows to the trash instead, and `Role::onlyTrashed()->restore()` brings them back — both as silently, cache included: see [Writes that announce nothing](#writes-that-announce-nothing).

#### Maintenance commands

- **`warden:clean` deletes unused permissions one by one through the model**: each dispatches `PermissionDeleted`, with the actor your resolver returns — `null` in the console with the default one. A run is one operation: every event it dispatches, `--duplicates` included, carries the same `$operation`. With `SoftDeletes` on your permission model, each goes to the trash rather than away, and the next write that names one throws `TrashedCatalogRow` until you restore it or force-delete it.
- **`--duplicates` re-points each duplicate's grants to the surviving row by query**, without events — when one collides with a grant the survivor already holds, the survivor keeps the later end date — no end date beats any — and the duplicate's grant is dropped — then deletes the duplicate through the model: one `PermissionDeleted` each, and no cascade events, because by then it points at nothing. While a rule has a live row, its rows in the trash stay out of the collapse: none is kept, none is deleted, and their grants are never moved onto the live row, which would give back access the trash ended. A rule whose rows are all in the trash collapses as before.
- **`--expired` and `--stranded` delete by query and dispatch nothing**: those rows authorize no saved model.
- **`warden:retitle` rewrites titles with the query builder**, on purpose, so it dispatches no `RoleUpdated` or `PermissionUpdated`; `warden:upgrade` dispatches nothing either.

#### Editing the catalog

- **A model save that changes a role's or a permission's [snapshot](#snapshots)** dispatches `RoleUpdated` or `PermissionUpdated`, with the snapshot before and after, and `$changed`: the keys that differ, in snapshot order — never `v` or `key`. The title counts — relabelling `delete-accounts` as "View accounts" changes what an administrator believes they are granting — and `$changed === ['title']` is how to filter those out.
- **A save that leaves the snapshot as it was dispatches nothing**: `touch()`, the same conditions stored with their keys in another order, a recomputed identity key.
- **`restore()` is not an edit.** The snapshot has no deleted-at column, so restoring a role or a permission from the trash dispatches `RoleRestored` or `PermissionRestored`, not `RoleUpdated` or `PermissionUpdated` — unless the same save changes a column the snapshot does carry: that edit dispatches its own `RoleUpdated` or `PermissionUpdated` first, under an operation of its own (see [One call, one operation](#one-call-one-operation)). A `restore()` on a row that was not in the trash restores nothing and announces no restore, and neither does one inside [`Event::defer()`](#when-an-event-is-dispatched).
- **A partially read row is completed first.** Saving a role or a permission fetched with a partial `select()` reads the snapshot columns it is missing from its row — one query, for partial rows only, and none with events off — so `$before` describes the whole row, not half of it.
- **Columns your own model adds are not in the snapshot.** Listen to Eloquent's `updated` for those.
- **The cache is up to date when the event goes out**, as for every other event.

#### Writes that announce nothing

- **Relation writes.** `attach()`, `detach()`, `sync()`, `toggle()`, `syncWithoutDetaching()` and `updateExistingPivot()` on `roles()`, `permissions()` and a permission's `roles()` go through Eloquent's pivot models: they invalidate the cache, and no warden event reports them — flipping `forbidden` or a `restricted_to_*` column included. Write through the verbs when a log has to see it. `nestedRoles()` refuses writes altogether.
- **The query builder, `DB::table()` and raw statements**, on any warden table.
- **Anything run with model events off**: `saveQuietly()`, `deleteQuietly()`, `restoreQuietly()`, `Model::withoutEvents()`. On warden's own models that skips more than the event: a quiet delete leaves cached checks answering for the row and a role's grants unswept, and a quiet save of a permission skips the hook that computes its identity key. Follow one with `Warden::refresh()` — and `warden:clean --stranded` after a quiet role delete, not with one users database per tenant (see [Landlord vs tenant databases](#landlord-vs-tenant-databases)) — or, better, don't.
- **A trip to the trash without model events.** `deleteQuietly()` and `restoreQuietly()` on a role or a permission, a `delete()` or `restore()` inside `Model::withoutEvents()`, and a query's `delete()` or `restore()` on a `SoftDeletes` model — `Role::query()->where(...)->delete()`, `Role::onlyTrashed()->restore()` — change access in the database at once, and `explain()` sees it, but cached checks keep answering as before: a role trashed that way keeps granting, one restored that way stays denied. Follow one with `Warden::refresh()` or `php artisan warden:cache-reset`.
- **A trip to the trash through `save()`.** `$role->forceFill(['deleted_at' => now()])->save()` sends a role to the trash, and saving the column back to `null` restores it: the cache is invalidated as for `delete()` and `restore()`, but no `RoleDeleted`, `RoleRestored` or `RoleUpdated` goes out — the snapshot has no deleted-at column. The same holds for a permission. Use `delete()` and `restore()` when a log has to see it.
- **The clock.** A row stops counting at its end date without an event.

#### Testing with `Event::fake()`

`Event::fake()` with no list replaces the dispatcher Eloquent's model events go through, so warden's model hooks stop running with it: identity keys, generated titles, the tenant stamp, the catalog events, cache invalidation, and a delete's sweep and cascade. In a test suite it shows up as a unique-constraint violation on the second permission of a name, as a permission created global under a tenant, or as an `Event::assertDispatched(PermissionCreated::class)` that fails because the hook that dispatches it never ran. Fake warden's events by name instead:

```php
use ElPandaPe\Warden\Events;
use Illuminate\Support\Facades\Event;

Event::fake([
    Events\PermissionGranted::class, Events\PermissionForbidden::class,
    Events\PermissionRevoked::class, Events\PermissionUnforbidden::class,
    Events\RoleAssigned::class, Events\RoleRetracted::class,
    Events\RolesSynced::class, Events\PermissionsSynced::class,
    Events\RoleCreated::class, Events\RoleUpdated::class, Events\RoleDeleted::class, Events\RoleRestored::class,
    Events\PermissionCreated::class, Events\PermissionUpdated::class, Events\PermissionDeleted::class,
    Events\PermissionRestored::class,
]);
```

Add the pre-action events you assert on — `AssigningRole`, `RetractingRole`, `GrantingPermission`, `ForbiddingPermission`, `RevokingPermission`, `UnforbiddingPermission` — the same way. A faked pre-action event never vetoes.

#### Queued listeners

A queued listener receives the event serialized, and its values come back in two ways:

- **Read again when the job runs**: top-level models — `$authority`, `$actor`, `$restrictedTo`, and the `$role` or `$permission` of `RoleCreated`, `RoleUpdated`, `RoleRestored`, `PermissionCreated`, `PermissionUpdated` and `PermissionRestored` — with the relations they had loaded. One hard-deleted in the meantime fails the job with `ModelNotFoundException`; a soft-deleted one comes back as it is, in the trash.
- **By value, as they were at dispatch**: `$roles`, `$permissions`, a sync's `$changes`, every `$grants` and `$assignments` entry, every snapshot, and `$operation`. They outlive the rows they describe, and the models among them arrive without the relations they had loaded.

`RoleDeleted` and `PermissionDeleted` differ on both counts. The deleted row travels by value, without the relations it had loaded, so the listener gets it as it was when it went, with `$softDeleted` and `$operation`. The actor travels as an identifier and is read again when the job runs; if its row is gone by then, it arrives as an unsaved stand-in carrying only its key, on the connection the actor came from (`exists` is `false`), instead of failing the job.

A queued listener therefore always knows which operation dispatched its event. What its own warden writes join depends on the queue driver: see [One call, one operation](#one-call-one-operation).

> ⚠️ **A model that travels by value keeps every column, `$hidden` included** — `$hidden` only shapes arrays and JSON. The deleted row of `RoleDeleted` and `PermissionDeleted` is one, as are the roles and permissions in the lists, the entries and a sync's `$changes`, and the context an `AssignmentRemoval` names. If your own models (`warden.models.*`, or a context model) hold a sensitive column, make the queued listeners of any warden event that carries them implement `ShouldBeEncrypted`. None of them takes its loaded relations along: each travels as a copy without them — one copy per model and event, so after the queue `$event->roles[0] === $event->assignments[0]->role` still holds — and a queued listener that walks one loads it again, which under `Model::preventLazyLoading()` can throw. A synchronous listener gets your instances as you had them, relations and all, with two exceptions: the context you hand to `on()` and the deleted role a cascade names reach it as copies too, so an entry's context is neither the instance you handed to `on()` nor `=== $event->restrictedTo`, which stays yours, relations and all, and a cascade's role is not the model you called `delete()` on. Walk a relation from `$event->restrictedTo`: under `Model::preventLazyLoading()`, loading one from the copy can throw.

#### When an event is dispatched

- **Synchronously, as the call that wrote it finishes, inside whatever transaction you have open.** Warden opens none around your call — `Warden::operation()` included; the only one it opens is the re-point inside `where()`, and nothing is dispatched from inside it.
- **Your transaction is the audit's transaction.** A listener writing on the same connection commits or rolls back with the change, and one that throws inside your `DB::transaction()` rolls the write back with everything else in it. Outside a transaction the rows are already written when a listener runs: if it throws, the exception leaves the call with the change made.
- **A catalog row a verb names for the first time is created one level deeper.** Warden creates it with `firstOrCreate()`, which opens a savepoint when a transaction is already open, so its `RoleCreated` or `PermissionCreated` is dispatched inside that savepoint: a listener that throws there rolls back its savepoint, and the exception keeps rising. The twin a `where()` creates is inserted directly, at your transaction's level.
- **A retried transaction announces every attempt.** `DB::transaction($callback, attempts: 3)` dispatches the events of the attempts it rolls back, too.
- **Another connection is another transaction.** With `warden.connection` pointing elsewhere, your transaction on the default connection does not cover warden's writes: they commit on their own, and rolling yours back leaves them — and their announcements — standing. Likewise a listener writing to another connection, a queue or an HTTP endpoint can record a change your transaction later rolls back.
- **A listener's `can()` sees the write it hears about.** Pending cache invalidations are applied before every event is dispatched, catalog and cascade events included, so no listener answers from a payload cached before the write.
- **Not inside `Event::defer()`.** Laravel holds back every event dispatched in its callback until the callback returns — Eloquent's model events too, so warden's model hooks run after the rows are written instead of while they are. A pre-action listener's `false` then vetoes nothing, the write being made already; a role or permission created in the callback is stored without the title and tenant stamp those hooks give it — a permission without its identity key too — as under a [bare `Event::fake()`](#testing-with-eventfake); a catalog delete reads its cascade after the foreign keys removed it, so the `RoleRetracted` or `PermissionRevoked` it owes never go out; with `SoftDeletes`, a `forceDelete()` passes for a soft delete — `RoleDeleted` or `PermissionDeleted` says `softDeleted: true`, and a role's own grants and nested edges stay behind for `warden:clean --stranded` — and a `restore()` dispatches no `RoleRestored` or `PermissionRestored`; and unless `Warden::operation()` encloses the whole `Event::defer()`, the catalog events carry an operation of their own. Keep warden's writes out of it, or name the events to hold back: `Event::defer($callback, [OrderShipped::class])`.

#### Waiting for the commit

Warden dispatches synchronously on purpose: a listener that writes its audit row in the same transaction, or that vetoes a write by throwing, depends on it. When a listener should only hear about committed changes, Laravel lets that listener class wait:

```php
use ElPandaPe\Warden\Events\PermissionGranted;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class RecordGrant implements ShouldHandleEventsAfterCommit
{
    public function handle(PermissionGranted $event): void
    {
        // Runs once the open transaction commits; never if it rolls back.
    }
}
```

A queued listener implements `ShouldQueueAfterCommit` instead. Know the limits:

- **Only listener classes can wait.** A closure passed to `Event::listen()` runs at once.
- **Laravel waits for the most recent transaction still open, on any connection.** With `warden.connection` pointing elsewhere, a transaction on your default connection holds the listener although warden's rows are already committed, and discards it if that transaction rolls back.
- **Outside any transaction it runs at once.** Inside one it runs after the outermost commit, and for a retried transaction only once, for the attempt that committed.
- **It no longer shares your transaction.** An audit write that fails cannot undo the change, and a listener that throws raises after the data is committed.
- **The event was built at the write.** Its actor, scope and entries are those of that moment, not of the commit.

### Snapshots

A snapshot names a role or a permission as it was, as a plain array whose shape is frozen. `RoleUpdated` and `PermissionUpdated` carry one from before the edit and one from after, `RoleDeleted` one for each row the role held, and you can take your own:

```php
use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;

PermissionSnapshot::of($permission);   // or $permission->snapshot()
RoleSnapshot::of($role);               // or $role->snapshot()
```

The twin that `Warden::allow($user)->to('view', Document::class)->where('status', 'published')` writes reads:

```php
[
    'v' => 1,
    'key' => 12,
    'name' => 'view',
    'title' => 'View documents',
    'entity_type' => 'App\Models\Document',
    'entity_id' => null,
    'only_owned' => false,
    'scope' => null,
    'conditions' => [
        'g' => ['i' => [['and', ['c' => 'status', 'o' => '=', 't' => 'value', 'v' => 'published']]], 't' => 'group'],
        'v' => 1,
    ],
]
```

and a role reads `['v' => 1, 'key' => 3, 'name' => 'editor', 'title' => 'Editor', 'scope' => null]`.

- **`v` is the shape's version**: `PermissionSnapshot::VERSION` and `RoleSnapshot::VERSION`. Keys, order and types are frozen; a new shape will be a new version, never an edit of this one.
- **`key`, `entity_id` and `scope` compare by value.** An integer, or a string holding one (`'7'`), reads as an integer; anything else — a UUID, `'007'` — stays a string.
- **`entity_type` is what the row stores**: a morph alias, a class name, or `'*'`.
- **`conditions` has three states**, each matching what the engine does with the row:
  - `null` — the column is SQL `NULL`: no conditions;
  - the rule, in canonical form — keys sorted, types kept (`'1'` is not `1`), an empty group still a rule;
  - `['unreadable' => '<the stored text>']` — something is stored and does not decode: text that is not JSON, an empty string, the JSON literal `null`, or JSON of a shape warden does not know. Never `null`: a rule nobody can read is not the absence of one, and the engine fails closed on it.
- **A snapshot survives JSON.** `json_decode(json_encode($snapshot), true)` gives it back unchanged, so it can be stored as it is.
- **Take it from a whole row.** A column a partial `select()` left out photographs as its default — `''`, `null`, `false` — without a word, and under `Model::preventAccessingMissingAttributes()` it throws `MissingAttributeException` instead — except a permission's conditions: the snapshot reads them from the raw `options` column, so a row selected without it photographs them as none, even in strict mode. Warden's own hooks read the missing columns before they take one; a snapshot you take yourself does not.
- **`RoleDeleted` wraps them.** `$heldGrants` lists `['permission' => <snapshot>, 'forbidden' => bool, 'scope' => …, 'expires_at' => ?CarbonImmutable]`, and `$heldRoles` lists `['role' => <snapshot>, 'scope' => …, 'restricted_to_type' => ?string, 'restricted_to_id' => …, 'expires_at' => ?CarbonImmutable]`.

> ⚠️ **`snapshot()` on a model comes from warden's trait**, and in PHP a trait method wins over one inherited from a parent class. If your role or permission model extends a base class that already defines `snapshot()`, the trait's hides it — and if the two signatures are incompatible, PHP refuses to load the model class. Warden itself always calls the `Support\Snapshots` classes.

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
| `TrashedCatalogRow` | `ConfigurationException` | A name matches only a role or permission in the trash: restore it or force-delete it first |

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

> 📌 **"Through the API" includes the models.** Editing a `Grant`, an `AssignedRole` or a **catalog row** through Eloquent invalidates too — renaming a permission or rewriting its `options` reaches every cached check, in every tenant its grants live in, because a permission's own columns are baked into the payload. Moving a row's `scope` that way invalidates the tenant it left as well as the one it joined, and moving a role or a permission in or out of the trash invalidates every scope it reaches — less when a `deleting` listener of yours halts the dispatch: see [Deleting a role or a permission](#deleting-a-role-or-a-permission). What still needs `Warden::refresh()` is a write that fires no model event: the query builder (a query's `delete()` and `restore()` on a `SoftDeletes` model included), `DB::table()`, a raw statement, and a model write with its events off — `saveQuietly()`, `deleteQuietly()`, `restoreQuietly()`, `Model::withoutEvents()`, or a bare `Event::fake()` in a test.

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

A scripted rule answers for every authority unless you narrow it. Each verb below narrows the rule scripted just before it, so they chain:

```php
$fake->allow('publish')->for($editor);                  // this authority only
$fake->allow('edit', Post::class)->owned();             // only what they own
$fake->allow('edit', Post::class)->where('status', 'draft');
$fake->allow('edit', Post::class)->whereColumn('author_id', 'id');
$fake->allow('publish')->inScope(5);                    // only inside tenant 5
$fake->allow('*', '*');                                 // everything, everywhere
```

Ownership, conditions and tenancy are decided by the same pieces the database engine uses, and a test suite asserts the fake and the engine answer alike across the shapes a rule can take. Narrowing before scripting a rule throws.

> 📌 **The fake is not looser than the engine.** A rule with no entity answers entity-less checks only, a condition abstains where it has no instance to read, and an unscripted check abstains so your app's policies still decide. Where the fake cannot express something, it denies rather than granting.

> ⚠️ **`Warden::fake()` is not `Event::fake()`.** A bare `Event::fake()` also stops the model hooks warden depends on — identity keys, titles, cache invalidation. Fake warden's events by name instead: [the list](#testing-with-eventfake) is under Events.

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
php artisan warden:retitle --dry-run     # Converge titles an older Warden wrote
php artisan warden:doctor                # Audit the catalog for rules that can never be true
```

> 📌 **`warden:doctor` exits non-zero when it finds something**, so it works as a CI gate. It
> reads every stored condition back through the rule the write path enforces and reports the
> ones that would be refused today, with the permission and how many grants and forbids point
> at it. It changes nothing: adding the missing cast and rewriting the condition mean different
> things, and only you know which one you meant.

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

> 📌 **`warden.role` answers exactly like `isA()`.** An assignment past its end date does not count, and with `warden.roles.nested` on, a role reached through another one does. A denial throws `UnauthorizedException`, which Laravel renders as a 403.

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

> 📌 **Revoking removes the grant, never the catalog row.** The row is shared, so pruning it inline would destroy a rule other holders point at. `warden:clean` is the supported way to reclaim rows nothing points at, and `--duplicates` collapses rows that identify the same permission. When a grant it re-points collides with one the surviving row already holds, the survivor keeps the later end date — no end date beats any.

> 📌 **Both pivot relations mix granted and forbidden rows.** `$role->permissions()` and `$permission->roles()` return every pivot row, whichever polarity it carries — filter to read one side:
>
> ```php
> $role->permissions()->wherePivot('forbidden', false)->get();   // what it can do
> $role->permissions()->wherePivot('forbidden', true)->get();    // what it is denied
> ```

> 📌 **Load the whole permission row before changing what identifies it.** `entity_type`, `entity_id`, `only_owned`, `scope` and `options` make up its identity key, so changing any of them on a permission fetched with a partial `select()` throws a `ConfigurationException` instead of saving a key computed from half a row. A permission created in the same request counts as whole: what it left unset holds the column default. An edit that leaves those five alone — its title, say — still saves, in strict mode too, and a save never moves an existing row into the active tenant: the tenant is stamped on creation only.

> 📌 **Titles are generated once, on creation, and only when none was given.** A rename keeps the old title, and setting `title` to `null` on an update leaves it `null`. Recompute one deliberately with `Support\Titles\PermissionTitle::generate()` or `RoleTitle::generate()` — the same calls the hook makes.

> 📌 **Ask before you rewrite a title.** `PermissionTitle::generations()` and `RoleTitle::generations()` return every title Warden could have written for a name, current first — each generator this package has published is transcribed and frozen. A stored title inside that list was Warden's; one outside it was typed by a person and is not yours to overwrite.
>
> ```php
> PermissionTitle::generations('viewAny', Post::class, null, false);
> // ['View any posts', 'ViewAny posts']  ← current, then the pre-2.0 reading
> ```
>
> `php artisan warden:retitle` applies exactly that rule across the catalogue: a title an older Warden generated converges on the current wording, a title someone wrote stays, and a `null` stays `null`. Run it with `--dry-run` first.

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

> 📌 **The grant or assignment a verb creates is inserted unguarded.** Warden creates the row with its end date in a single `INSERT`, inside `Model::unguarded()` — as Laravel's `forceCreate()` does — so no `$fillable` or `$guarded` on your grant or assignment model can split it into an insert and a later update. That covers `allow()` and `forbid()` with `to()` or `toOwn()` — and `everything()`, `toManage()` and `toOwnEverything()`, which go through them — the grant a `where()`, `orWhere()`, `whereColumn()` or `orWhereColumn()` moves onto its constrained twin, and `assign()->to()`, `sync()->roles()` included. The switch is global while it lasts: an observer or listener that runs during that insert — your pivot model's `saving`, `creating`, `created` or `saved` — fills any model without mass-assignment protection. A grant that `sync()->permissions()` or `sync()->forbiddenPermissions()` creates carries no end date, and is inserted as before, through your model's mass-assignment rules.

---

## ⚙️ Configuration

Everything lives in `config/warden.php`:

| Section | Controls |
|---|---|
| `models` | Swappable Role, Permission, Grant, AssignedRole models |
| `tables` | Table names and database connection |
| `morphs` | Morph aliases (`warden.role`, `warden.permission`) |
| `gate` | Gate behavior (`run_before_policies`, `register`) |
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

Point warden tables at their own connection with `warden.connection`. The published migration honors it (`Schema::connection(...)`), and the migration class is anonymous to avoid collisions. `warden:clean --stranded` follows the split: when an authority model lives on another connection, it looks for that authority's rows there rather than on warden's. It asks the connection the authority model resolves while the command runs, so it assumes one users database: with one per tenant, every other tenant's holders would look gone, so do not run `--stranded` in that layout.

### Replace a role instead of stacking

```php
Warden::sync($user)->roles(['editor']);     // declarative
Warden::retract('viewer')->from($user);       // or surgical
Warden::assign('editor')->to($user);
```

### Long-lived processes (Tinker, Octane, queues)

Writes through the API invalidate caches automatically. What still needs `Warden::refresh()` is a write that fires no model event — the query builder, `DB::table()`, a raw statement, or a model write with its events off: see [Caching](#-caching). Tenant state and the open [operation](#one-call-one-operation) live in container-scoped bindings, so Octane requests and queue jobs reset themselves; the `sync` queue driver runs a job inside the request that queued it, and shares both.

---

## 🔄 Migrating from silber/bouncer

```bash
composer require elpandape/warden        # replaces silber/bouncer (conflict enforced)
php artisan warden:upgrade --dry-run       # report
php artisan warden:upgrade                 # in-place schema transform
vendor/bin/rector process app --config vendor/elpandape/warden/stubs/rector-silber-upgrade.php
```

The fluent API is intentionally compatible. The schema upgrades in place (`abilities` → `permissions`, `permissions` pivot → `grants`). See **MIGRATING-FROM-BOUNCER.md** for the full equivalence table.

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
