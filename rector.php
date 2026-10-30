<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanNot\NegatedAndsToPositiveOrsRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\Php84\Rector\Class_\DeprecatedAnnotationToDeprecatedAttributeRector;
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
        FlipTypeControlToUseExclusiveTypeRector::class,

        // Keep @deprecated as documentation only. The #[\Deprecated] attribute
        // emits runtime E_USER_DEPRECATED notices, which would spam logs for
        // still-internally-used members like Mcp::DANGEROUS_TOOLS.
        DeprecatedAnnotationToDeprecatedAttributeRector::class,

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
