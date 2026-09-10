<?php

declare(strict_types=1);

namespace Caretaker2\Agent;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * The version of this extension, read from ext_emconf.php so it is written
 * down in one place only.
 */
final class AgentVersion
{
    public static function current(): string
    {
        $version = ExtensionManagementUtility::getExtensionVersion('caretaker2_agent');

        return $version !== '' ? $version : '0.0.0';
    }
}
