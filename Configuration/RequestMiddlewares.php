<?php

declare(strict_types=1);

use Caretaker2\Agent\Http\TriggerMiddleware;

return [
    'frontend' => [
        'caretaker2/trigger' => [
            'target' => TriggerMiddleware::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
