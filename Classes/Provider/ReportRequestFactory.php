<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Http\InstanceOrigin;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;

/**
 * Some of TYPO3's own checks look at the request they run in: whether it
 * came over HTTPS, which host it was made to. Under CLI there is none, and
 * a hub-triggered push runs in a request to the trigger endpoint, not to
 * the front door. Both get the same stand-in: a request to the instance's
 * origin, so the checks judge the installation rather than the way the
 * agent happened to be called.
 */
final class ReportRequestFactory
{
    /** @var InstanceOrigin */
    private $instanceOrigin;

    public function __construct(InstanceOrigin $instanceOrigin)
    {
        $this->instanceOrigin = $instanceOrigin;
    }

    /**
     * A GET to the root of the instance as the web server would hand it
     * over, or null when the installation does not say where it lives.
     */
    public function create(): ?ServerRequestInterface
    {
        $origin = $this->instanceOrigin->find();
        if ($origin === null) {
            return null;
        }

        try {
            $uri = new Uri(rtrim($origin, '/') . '/');
            if ($uri->getHost() === '') {
                return null;
            }

            $https = $uri->getScheme() !== 'http';
            $port = $uri->getPort();

            $serverParams = [
                'HTTPS' => $https ? 'on' : 'off',
                'HTTP_HOST' => $uri->getHost() . ($port !== null ? ':' . $port : ''),
                'SERVER_NAME' => $uri->getHost(),
                'SERVER_PORT' => (string)($port ?? ($https ? 443 : 80)),
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'SCRIPT_NAME' => '/index.php',
                'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
            ];

            $request = new ServerRequest($uri, 'GET', 'php://temp', [], $serverParams);

            return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
