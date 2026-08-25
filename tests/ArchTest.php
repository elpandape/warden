<?php

declare(strict_types=1);

use function ElPandaPe\Warden\Tests\phpFilesOffending;

arch('source uses strict types')
    ->expect('ElPandaPe\Warden')
    ->toUseStrictTypes();

arch('no debugging functions left behind')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('enums live in the Enums namespace')
    ->expect('ElPandaPe\Warden\Enums')
    ->toBeEnums();

it('leaves no doc block stranded above another', function (): void {
    expect(phpFilesOffending('#\*/\s*\n\s*/\*\*#'))->toBeEmpty();
});

it('cites no tool-generated identifier in a comment', function (): void {
    expect(phpFilesOffending('#(?://|\*)[^\n]*\b[0-9a-f]{12,}\b#'))->toBeEmpty();
});

it('cites no ticket number in a comment', function (): void {
    expect(phpFilesOffending('#(?://|\*)[^\n]*\s\#\d{2,}\b#'))->toBeEmpty();
});
