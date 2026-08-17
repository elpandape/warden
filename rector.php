<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
