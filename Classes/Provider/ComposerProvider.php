<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Core\Environment;

/**
 * composer.json and composer.lock, verbatim.
 */
final class ComposerProvider implements ProviderInterface
{
    public function getKey(): string
    {
        return 'composer';
    }

    public function collect(): ProviderResult
    {
        if (!Environment::isComposerMode()) {
            return ProviderResult::unavailable(
                'not_composer_mode',
                'This installation is not managed by composer.'
            );
        }

        $root = $this->projectRoot();
        $json = $this->read($root . '/composer.json');
        $lock = $this->read($root . '/composer.lock');

        if ($json === null && $lock === null) {
            return ProviderResult::unavailable(
                'composer_files_missing',
                sprintf('Neither composer.json nor composer.lock is readable under %s.', $root)
            );
        }

        $data = [
            'projectRoot' => $root,
            'json' => $json,
            'lock' => $lock,
            'summary' => $this->summarize($json, $lock),
        ];

        if ($lock === null) {
            // Without the lock the hub knows the constraints but not what is
            // actually installed — enough to show, not enough to evaluate.
            return ProviderResult::degraded(
                $data,
                'lock_missing',
                'composer.json was read, composer.lock is missing. Without the lock there is no telling what is installed.'
            );
        }

        if ($json === null) {
            return ProviderResult::degraded(
                $data,
                'json_missing',
                'composer.lock was read, composer.json is missing. Security matching is possible, judging updates is not.'
            );
        }

        return ProviderResult::ok($data);
    }

    private function projectRoot(): string
    {
        $composer = getenv('COMPOSER');
        if (is_string($composer) && $composer !== '') {
            $dir = dirname($composer);
            if (is_dir($dir)) {
                return rtrim($dir, '/');
            }
        }

        return rtrim(Environment::getProjectPath(), '/');
    }

    private function read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        return $this->stripCredentials($contents);
    }

    /**
     * Private repositories occasionally carry credentials inline in their URL.
     * Those must never leave the instance.
     */
    private function stripCredentials(string $contents): string
    {
        return (string)preg_replace(
            '#(?<=[/\'"])([a-z][a-z0-9+.-]*://)[^/@\s"\']*:[^/@\s"\']*@#i',
            '$1***:***@',
            $contents
        );
    }

    /**
     * A few numbers so the hub can show something without parsing the files.
     *
     * @return array<string, mixed>
     */
    private function summarize(?string $json, ?string $lock): array
    {
        $summary = [
            'rootName' => null,
            'phpConstraint' => null,
            'requireCount' => 0,
            'lockedPackages' => 0,
            'lockedDevPackages' => 0,
            'contentHash' => null,
        ];

        $decodedJson = $json === null ? null : json_decode($json, true);
        if (is_array($decodedJson)) {
            $summary['rootName'] = $decodedJson['name'] ?? null;
            $summary['phpConstraint'] = $decodedJson['require']['php'] ?? null;
            $summary['requireCount'] = is_array($decodedJson['require'] ?? null)
                ? count($decodedJson['require'])
                : 0;
        }

        $decodedLock = $lock === null ? null : json_decode($lock, true);
        if (is_array($decodedLock)) {
            $summary['lockedPackages'] = is_array($decodedLock['packages'] ?? null)
                ? count($decodedLock['packages'])
                : 0;
            $summary['lockedDevPackages'] = is_array($decodedLock['packages-dev'] ?? null)
                ? count($decodedLock['packages-dev'])
                : 0;
            // Tells the hub whether composer.json and composer.lock still match.
            $summary['contentHash'] = $decodedLock['content-hash'] ?? null;
        }

        return $summary;
    }
}
