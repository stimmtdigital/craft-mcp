<?php

declare(strict_types=1);

use stimmt\craft\Mcp\capabilities\ReportTools;

it('reports warnings and errors as problems, and leaves ok and info lines to stimmt_health', function () {
    $checks = [
        ['level' => 'ok', 'label' => 'Queue'],
        ['level' => 'info', 'label' => 'Database version'],
        ['level' => 'warning', 'label' => 'Admin changes'],
        ['level' => 'error', 'label' => 'OAuth keys'],
    ];

    expect(array_column(ReportTools::problems($checks), 'label'))->toBe(['Admin changes', 'OAuth keys']);
});
