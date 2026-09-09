<?php

declare(strict_types=1);

use Caretaker2\Agent\Controller\ConnectionController;

/**
 * Module registration for TYPO3 v12 to v14.
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
