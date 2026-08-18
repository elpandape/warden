<?php

declare(strict_types=1);

use ElPandaPe\Warden\Constraints\ColumnConstraint;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Enums\ComparisonOperator;
use ElPandaPe\Warden\Tests\Fixtures\Plain;

it('fails closed comparing columns without an authority', function (): void {
    $constraint = new ColumnConstraint('a', ComparisonOperator::Equal, 'b');

    expect($constraint->passes(new Plain, null))->toBeFalse();
});

it('passes empty groups trivially', function (): void {
    expect(new Group([])->passes(new Plain, null))->toBeTrue();
});

it('compares only compatible types in relational operators', function (): void {
    expect(ComparisonOperator::LessThan->compare(1, 'abc'))->toBeFalse()
        ->and(ComparisonOperator::GreaterThanOrEqual->compare('b', 'a'))->toBeTrue()
        ->and(ComparisonOperator::LessThanOrEqual->compare(2, '10'))->toBeTrue()
        ->and(ComparisonOperator::NotEqual->compare('5', 5))->toBeFalse()
        ->and(ComparisonOperator::GreaterThan->compare(null, 1))->toBeFalse();
});

it('never short-circuits a group that opens with or-where', function (): void {
    $group = new ElPandaPe\Warden\Constraints\Builder()->orWhere('name', 'X')->group();

    // The leading OR must not read a stale true clause: nothing matched yet.
    expect($group->passes(new Plain, null))->toBeFalse();
});

it('fails every operator closed on null and undecidable pairs', function (): void {
    expect(ComparisonOperator::Equal->compare(null, null))->toBeFalse()
        ->and(ComparisonOperator::NotEqual->compare(null, 'closed'))->toBeFalse()
        ->and(ComparisonOperator::NotEqual->compare('open', null))->toBeFalse()
        ->and(ComparisonOperator::NotEqual->compare(true, 1))->toBeFalse()
        ->and(ComparisonOperator::NotEqual->compare('a', 'b'))->toBeTrue()
        ->and(ComparisonOperator::NotEqual->compare(2, 3))->toBeTrue();
});
