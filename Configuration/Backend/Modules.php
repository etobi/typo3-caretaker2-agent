<?php

declare(strict_types=1);

use Caretaker2\Agent\Controller\ConnectionController;

/**
 * Modulregistrierung für TYPO3 v12 bis v14.
 *
 * TODO v11: dort gibt es Configuration/Backend/Modules.php noch nicht,
 * die Registrierung läuft über ext_tables.php mit addModule(). Das ist die
 * einzige Stelle im Agent, an der die Spanne v11–v14 zwei Codepfade
 * erzwingt — alles andere trägt unverändert.
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
