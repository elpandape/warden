<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Enums\ComparisonOperator;
use ElPandaPe\Bouncer\Enums\GateSlot;
use ElPandaPe\Bouncer\Enums\LogicalOperator;

describe('LogicalOperator', function (): void {
    it('combines with and', function (bool $carry, bool $operand, bool $expected): void {
        expect(LogicalOperator::And->combine($carry, $operand))->toBe($expected);
    })->with([
        [true, true, true],
        [true, false, false],
        [false, true, false],
        [false, false, false],
    ]);

    it('combines with or', function (bool $carry, bool $operand, bool $expected): void {
        expect(LogicalOperator::Or->combine($carry, $operand))->toBe($expected);
    })->with([
        [true, true, true],
        [true, false, true],
        [false, true, true],
        [false, false, false],
    ]);

    it('refuses to combine with not', function (): void {
        LogicalOperator::Not->combine(true, true);
    })->throws(LogicException::class);

    it('is backed by the expected values', function (): void {
        expect(LogicalOperator::And->value)->toBe('and')
            ->and(LogicalOperator::Or->value)->toBe('or')
            ->and(LogicalOperator::Not->value)->toBe('not');
    });
});

describe('ComparisonOperator', function (): void {
    it('compares values', function (ComparisonOperator $operator, mixed $left, mixed $right, bool $expected): void {
        expect($operator->compare($left, $right))->toBe($expected);
    })->with([
        [ComparisonOperator::Equal, 1, 1, true],
        [ComparisonOperator::Equal, 1, 2, false],
        [ComparisonOperator::NotEqual, 1, 2, true],
        [ComparisonOperator::NotEqual, 1, 1, false],
        [ComparisonOperator::LessThan, 1, 2, true],
        [ComparisonOperator::LessThan, 2, 1, false],
        [ComparisonOperator::GreaterThan, 2, 1, true],
        [ComparisonOperator::GreaterThan, 1, 2, false],
        [ComparisonOperator::LessThanOrEqual, 1, 1, true],
        [ComparisonOperator::LessThanOrEqual, 2, 1, false],
        [ComparisonOperator::GreaterThanOrEqual, 1, 1, true],
        [ComparisonOperator::GreaterThanOrEqual, 1, 2, false],
    ]);
});

describe('GateSlot', function (): void {
    it('is backed by the expected values', function (): void {
        expect(GateSlot::Before->value)->toBe('before')
            ->and(GateSlot::After->value)->toBe('after');
    });
});
