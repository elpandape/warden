# Bouncer

> Roles & permissions for Laravel — instance-level grants, explicit forbids, ownership,
> multi-tenancy, ABAC. PHP 8.3+ · Laravel 11+.
>
> Based on [Bouncer](https://github.com/JosephSilber/bouncer) by Joseph Silber — this package
> is a modernized evolution of his original work (MIT).

![Version](https://img.shields.io/badge/version-0.1.0-blue) ![PHP](https://img.shields.io/badge/php-%5E8.3-777bb3) ![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-ff2d20) ![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen) ![PHPStan](https://img.shields.io/badge/phpstan-max-4b5563)

> **Status: alpha (v0.1.0).** The public API lands incrementally between v0.3.0 and
> v0.9.0 — each tagged release documents what shipped. Do not use in production before v1.0.0.

## What's available (v0.1.0)

- Package skeleton: service provider, full config file, translations (en/es).
- Schema v2 migration (four tables: `permissions`, `roles`, `assigned_roles`, `grants`) —
  frozen for the whole 0.x cycle, publishable and customizable.
- `Context`: the instance-based registry that replaces the original's global static state.
- Quality gates: 100% test coverage, 100% type coverage, PHPStan max, Pint, Rector.

The fluent API (`Bouncer::allow($user)->to(...)`, `forbid()`, roles, ownership,
multi-tenancy, ABAC, `whereCan()`, `explain()`) ships between v0.3.0 and v0.9.0.

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
