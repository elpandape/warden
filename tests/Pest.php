<?php

declare(strict_types=1);

use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/Database/helpers.php';
require_once __DIR__.'/helpers.php';

pest()->extend(TestCase::class)->in(__DIR__);

expect()->extend('toQueryExactlyWhatItCanCheck', function (string $permission): void {
    /** @var User $authority */
    $authority = $this->value;

    $queryable = Account::query()->whereCan($authority, $permission)->pluck('id')->sort()->values()->all();

    $checkable = Account::query()->get()
        ->filter(fn (Model $account): bool => Gate::forUser($authority)->allows($permission, $account))
        ->pluck('id')->sort()->values()->all();

    expect($queryable)->toBe($checkable);
});
