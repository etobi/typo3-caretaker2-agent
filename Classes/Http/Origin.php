<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Scheme, host and port of an address. The port is only spelled out when it
 * is not the default of its scheme.
 */
final class Origin
{
    public static function fromRequest(ServerRequestInterface $request): string
    {
        return self::fromUri($request->getUri());
    }

    /**
     * A site base may come without a scheme; https is assumed then.
     */
    public static function fromUri(UriInterface $uri): string
    {
        $origin = ($uri->getScheme() ?: 'https') . '://' . $uri->getHost();

        if ($uri->getPort() !== null && !in_array($uri->getPort(), [80, 443], true)) {
            $origin .= ':' . $uri->getPort();
        }

        return $origin;
    }
}
