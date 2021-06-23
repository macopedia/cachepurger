<?php

declare(strict_types=1);

namespace Macopedia\CachePurger\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

final class CacheHeaders implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $tsfe = $GLOBALS['TSFE'] ?? null;

        if ($tsfe instanceof TypoScriptFrontendController) {
            if ($tsfe->isStaticCacheble()) {
                $response = $response->withAddedHeader('X', 'cache');
            }

            $tags[] = 'T3';
            $tags[] = 'PAGE-' . $tsfe->id;

            $tags = implode(' ', $tags);
            $response = $response->withAddedHeader('X-Tags', $tags);
        }

        return $response;
    }
}
