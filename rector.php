<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanNot\NegatedAndsToPositiveOrsRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
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
        // New in Rector 2.8: style rewrites (`!== null` to `instanceof X`)
        // across dozens of files that change no behaviour. Skipped so a
        // tooling bump does not rewrite unrelated code; apply them, if at
        // all, as a cleanup of their own.
        ExplicitNullableObjectInstanceRector::class,
        NegatedAndsToPositiveOrsRector::class,
    ])
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true,
    );
