<?php

declare(strict_types=1);

namespace Macopedia\CachePurger\Middleware;

use Macopedia\CachePurger\Configuration\PurgeSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Adds the X-Tags and X-TYPO3-caching response headers Varnish stores with the cached object,
 * so bans by tag can hit it later. The tags are the configured global tags (banned on
 * "clear all") plus the page tag. Site specific tags are deliberately not added: they are banned
 * with every page save, so pages carrying them would be evicted site-wide on every save.
 */
final class CacheTagResponseHeaders implements MiddlewareInterface
{
    public function __construct(private readonly PurgeSettings $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $pageInformation = $request->getAttribute('frontend.page.information');
        if (!$pageInformation instanceof PageInformation) {
            return $response;
        }
        $cacheInstruction = $request->getAttribute('frontend.cache.instruction');
        $isCacheable = !$cacheInstruction instanceof CacheInstruction || $cacheInstruction->isCachingAllowed();
        $tags = [...$this->settings->getGlobalTags(), 'PAGE-' . $pageInformation->getId()];

        return $response
            ->withAddedHeader('X-TYPO3-caching', $isCacheable ? 'cache' : 'no-cache')
            ->withAddedHeader('X-Tags', implode(' ', array_unique($tags)));
    }
}
