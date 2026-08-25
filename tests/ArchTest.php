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

it('leaves no doc block stranded above another', function (): void {
    $stranded = [];

    foreach (['src', 'tests'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../'.$dir));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (is_string($contents) && preg_match('#\*/\s*\n\s*/\*\*#', $contents) === 1) {
                $stranded[] = $file->getFilename();
            }
        }
    }

    expect($stranded)->toBeEmpty();
});
