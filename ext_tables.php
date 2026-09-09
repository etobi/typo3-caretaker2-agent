<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Caretaker2\Agent\Controller\ConnectionController;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Module registration for TYPO3 v11 only.
 */
(static function (): void {
    if ((new Typo3Version())->getMajorVersion() >= 12) {
        return;
    }

    ExtensionManagementUtility::addModule(
        'system',
        'caretaker2',
        '',
        '',
        [
            'routeTarget' => ConnectionController::class . '::handleRequest',
            'access' => 'admin',
            'name' => 'system_caretaker2',
            'iconIdentifier' => 'caretaker2-agent-module',
            'labels' => 'LLL:EXT:caretaker2_agent/Resources/Private/Language/locallang_mod.xlf',
        ]
    );
})();
