<?php

declare(strict_types=1);

namespace Macopedia\CachePurger\Hooks;

use Macopedia\CachePurger\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * DataHandler cache clearing hooks. TYPO3 has already decided that its own cache is cleared, so
 * Varnish follows unconditionally. Runs in backend requests as well as on the CLI (scheduler,
 * commands), so nothing here may rely on a web request.
 */
#[Autoconfigure(public: true)]
final class TceMain
{
    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    /**
     * clearCachePostProc: TYPO3 processed a cache command ("all", "pages", a page uid).
     *
     * @param array<string, mixed> $params
     */
    public function clearCacheCmd(array $params): void
    {
        $this->cacheManager->clearCache(isset($params['cacheCmd']) ? (string)$params['cacheCmd'] : null);
    }

    /**
     * clearPageCacheEval: TYPO3 clears the cache of a list of page uids.
     *
     * @param array<string, mixed> $params
     */
    public function clearCacheForListOfUids(array $params): void
    {
        foreach ($params['pageIdArray'] ?? [] as $uid) {
            $this->cacheManager->clearForTag('PAGE-' . (int)$uid, (int)$uid);
        }
    }
}
