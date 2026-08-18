<?php

declare(strict_types=1);

arch('source uses strict types')
    ->expect('ElPandaPe\Warden')
    ->toUseStrictTypes();

arch('no debugging functions left behind')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('enums live in the Enums namespace')
    ->expect('ElPandaPe\Warden\Enums')
    ->toBeEnums();
