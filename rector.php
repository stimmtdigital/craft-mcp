<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpSets(php85: true)
    ->withSets([
        // Early returns - converts nested ifs to guard clauses
        SetList::EARLY_RETURN,

        // Code quality improvements
        SetList::CODE_QUALITY,

        // Dead code removal
        SetList::DEAD_CODE,
    ])
    ->withSkip([
        // Skip overly aggressive rules
        ExplicitBoolCompareRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
    ])
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true,
    );
