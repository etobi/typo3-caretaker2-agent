<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Was TYPO3 über sich selbst weiß. Der einfachste Provider — und der,
 * der die Frage "welche Instanzen laufen auf welcher Version" allein
 * beantwortet.
 */
final class CoreProvider implements ProviderInterface
{
    public function getKey(): string
    {
        return 'core';
    }

    public function collect(): ProviderResult
    {
        $version = new Typo3Version();

        return ProviderResult::ok([
            'version' => $version->getVersion(),
            'branch' => $version->getBranch(),
            'majorVersion' => $version->getMajorVersion(),
            'applicationContext' => (string)Environment::getContext(),
            'composerMode' => Environment::isComposerMode(),
            'projectPath' => Environment::getProjectPath(),
        ]);
    }
}
