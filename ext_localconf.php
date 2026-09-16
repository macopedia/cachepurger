<?php

declare(strict_types=1);

use Macopedia\CachePurger\Hooks\TceMain;

defined('TYPO3') || die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearPageCacheEval']['cachepurger']
    = TceMain::class . '->clearCacheForListOfUids';
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['cachepurger']
    = TceMain::class . '->clearCacheCmd';
