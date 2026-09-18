<?php

declare(strict_types=1);

use ElPandaPe\Warden\Events\AssignmentChange;
use ElPandaPe\Warden\Events\AssignmentRemoval;
use ElPandaPe\Warden\Events\GrantChange;
use ElPandaPe\Warden\Events\GrantRemoval;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

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

arch('post-write events leave through one door')
    ->expect('ElPandaPe\Warden\Actions')->not->toUse(Event::class)
    ->and('ElPandaPe\Warden\Checks')->not->toUse(Event::class)
    ->and('ElPandaPe\Warden\Models')->not->toUse(Event::class)
    ->and('ElPandaPe\Warden\Console')->not->toUse(Event::class);

arch('events are final and readonly')
    ->expect('ElPandaPe\Warden\Events')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly()
    ->ignoring(['ElPandaPe\Warden\Events\Actors', 'ElPandaPe\Warden\Events\Concerns']);

arch('per-row event values travel by value')
    ->expect([GrantChange::class, AssignmentChange::class, GrantRemoval::class, AssignmentRemoval::class])
    ->toBeFinal()
    ->toBeReadonly()
    ->not->toUseTrait(SerializesModels::class);

it('leaves no doc block stranded above another', function (): void {
    expect(phpFilesOffending('#\*/\s*\n\s*/\*\*#'))->toBeEmpty();
});

it('cites no tool-generated identifier in a comment', function (): void {
    expect(phpFilesOffending('#(?://|\*)[^\n]*\b[0-9a-f]{12,}\b#'))->toBeEmpty();
});

it('cites no ticket number in a comment', function (): void {
    expect(phpFilesOffending('#(?://|\*)[^\n]*\s\#\d{2,}\b#'))->toBeEmpty();
});
