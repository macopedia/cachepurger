<?php
/**
 * @phpstan-ignore-next-line
 */
defined('TYPO3_MODE') || die();

call_user_func(
    static function (string $extensionKey): void {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearPageCacheEval']['purge'] = 'Macopedia\CachePurger\Hooks\TceMain->clearCacheForListOfUids';
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['purge'] = 'Macopedia\CachePurger\Hooks\TceMain->clearCacheCmd';
    },
    'cachepurger'
);
