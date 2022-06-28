<?php

namespace Macopedia\CachePurger\Hooks;

use Macopedia\CachePurger\CacheManager;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class TceMain
{
    private CacheManager $cacheManager;

    public function __construct()
    {
        $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
    }

    /**
     * @param array<mixed> $params
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $parent
     */
    public function clearCacheCmd($params, &$parent): void
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin() || $backendUser->getTSConfig()) {
            $this->cacheManager->clearCache($params['cacheCmd'] ?? null);
        }
    }

    /**
     * Called when TYPO3 clears a list of uid's.
     *
     * @param array<mixed> $params
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $parent
     */
    public function clearCacheForListOfUids($params, &$parent): void
    {
        if (!isset($params['pageIdArray'])) {
            return;
        }

        foreach ($params['pageIdArray'] as $uid) {
            $this->cacheManager->clearForTag('PAGE-' . (int)$uid);
        }
    }

    /**
     * Returns the current BE user.
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
