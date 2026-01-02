<?php

declare(strict_types=1);

namespace Macopedia\CachePurger\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

final class CacheHeaders implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);

        // TYPO3 v13+ uses request attributes for page information and cache instruction
        if ($typo3Version->getMajorVersion() >= 13) {
            $pageInformation = $request->getAttribute('frontend.page.information');
            $cacheInstruction = $request->getAttribute('frontend.cache.instruction');

            if ($pageInformation !== null) {
                $tags = [];
                $tags[] = 'T3';
                $tags[] = 'PAGE-' . $pageInformation->getId();

                $tags = implode(' ', $tags);

                $isCacheable = $cacheInstruction !== null ? $cacheInstruction->isCachingAllowed() : true;
                $response = $response->withAddedHeader('X-TYPO3-caching', $isCacheable ? 'cache' : 'no-cache');
                $response = $response->withAddedHeader('X-Tags', $tags);
            }
        } else {
            // TYPO3 v11 and v12 use $GLOBALS['TSFE']
            $tsfe = $GLOBALS['TSFE'] ?? null;

            if ($tsfe instanceof TypoScriptFrontendController) {
                $tags = [];
                $tags[] = 'T3';
                $tags[] = 'PAGE-' . $tsfe->id;

                $tags = implode(' ', $tags);

                $isCacheable = $tsfe->isStaticCacheble();
                $response = $response->withAddedHeader('X-TYPO3-caching', $isCacheable ? 'cache' : 'no-cache');
                $response = $response->withAddedHeader('X-Tags', $tags);
            }
        }

        return $response;
    }
}
