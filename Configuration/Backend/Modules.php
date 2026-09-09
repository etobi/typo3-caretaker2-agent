<?php

declare(strict_types=1);

use Caretaker2\Agent\Controller\ConnectionController;

/**
 * Module registration for TYPO3 v12 to v14.
 *
 * v11 does not read this file; there the registration goes through
 * ext_tables.php with addModule(). Together with the rendering and the
 * scheduler task, this is one of the three places in the agent where the span
 * v11 to v14 forces two code paths.
 */
return [
    'caretaker2_agent' => [
        'parent' => 'system',
        'access' => 'admin',
        'path' => '/module/system/caretaker2',
        'iconIdentifier' => 'caretaker2-agent-module',
        'labels' => [
            'title' => 'LLL:EXT:caretaker2_agent/Resources/Private/Language/locallang.xlf:module.title',
            'description' => 'LLL:EXT:caretaker2_agent/Resources/Private/Language/locallang.xlf:module.description',
        ],
        'routes' => [
            '_default' => [
                'target' => ConnectionController::class . '::handleRequest',
            ],
        ],
    ],
];
