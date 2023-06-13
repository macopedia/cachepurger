<?php

namespace Macopedia\CachePurger;

use CurlHandle;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use function array_unique;
use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_getinfo;
use function curl_init;
use function curl_multi_add_handle;
use function curl_multi_exec;
use function curl_multi_init;
use function curl_multi_remove_handle;
use function curl_multi_select;
use function curl_setopt;
use function is_array;
use const CURLM_CALL_MULTI_PERFORM;
use const CURLM_OK;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_URL;

final class CacheManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var array<string, mixed>
     */
    protected array $settings;
    /**
     * @var array<string>
     */
    protected array $clearQueue = [];

    public function __construct()
    {
        $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
        $this->settings = $configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            )['tx_cachepurger.']['settings.'] ?? [];
    }

    /**
     * @param string $tag
     */
    public function clearForTag(string $tag): void
    {
        $this->clearQueue[] = $tag;
        $this->clearQueue = array_unique($this->clearQueue);
    }

    public function execute(): void
    {
        $curlHandles = [];

        if (!isset($this->settings['varnish.']) || !is_array($this->settings['varnish.'])) {
            return;
        }

        $multiHandle = curl_multi_init();

        foreach ($this->settings['varnish.'] as $varnishInstance) {
            foreach ($this->clearQueue as $tag) {
                $ch = $this->getCurlHandleForCacheClearingAsTag($tag, $varnishInstance);
                if (!$ch) {
                    continue;
                }
                $curlHandles[] = $ch;
                curl_multi_add_handle($multiHandle, $ch);
            }
        }

        if (count($curlHandles) === 0) {
            return;
        }

        // initialize all connections
        $active = null;
        do {
            $multiExecResult = curl_multi_exec($multiHandle, $active);
            $this->logger->debug('status init: ' . $multiExecResult);
        } while ($multiExecResult === CURLM_CALL_MULTI_PERFORM);

        $this->logger->debug('connections initialized, status: ' . $multiExecResult);

        // now wait for activity on any connection (this blocks script execution)
        while ($active && $multiExecResult === CURLM_OK) {
            $this->logger->debug('waiting for activity, status: ' . $multiExecResult);

            if (curl_multi_select($multiHandle) !== -1) {
                do {
                    $multiExecResult = curl_multi_exec($multiHandle, $active);
                    $this->logger->debug('status activity: ' . $multiExecResult);
                } while ($multiExecResult === CURLM_CALL_MULTI_PERFORM);
            }
        }

        foreach ($curlHandles as $ch) {
            if (curl_errno($ch) !== 0) {
                $this->logger->error('error: ' . curl_error($ch));
            } else {
                $info = curl_getinfo($ch);
                $this->logger->debug('info: ', $info);
            }
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        $this->clearQueue = [];
    }

    public function __destruct()
    {
        $this->execute();
    }

    public function clearCache(?string $cmd): void
    {
        switch ($cmd) {
            case 'pages':
                $this->logger->debug('clearCacheCmd() pages');
            // no break
            case 'all':
                $this->logger->debug('clearCacheCmd() all');

                if (isset($this->settings['tags.']) && is_array($this->settings['tags.'])) {
                    foreach ($this->settings['tags.'] as $tag) {
                        $this->clearForTag($tag);
                    }
                }
                break;
            default:
                if ((int)$cmd > 0) {
                    $this->clearForTag('PAGE-' . (int)$cmd);
                }
        }
    }

    /**
     * @param string $tag
     * @param string $varnishUrl
     * @return false|CurlHandle
     */
    protected function getCurlHandleForCacheClearingAsTag(string $tag, string $varnishUrl)
    {
        return $this->createCurlHandle($varnishUrl, 'X-Tags: ' . $tag);
    }

    /**
     * @param string $varnishUrl
     * @param string $header
     * @return false|CurlHandle
     */
    protected function createCurlHandle(string $varnishUrl, string $header, string $method = 'BAN')
    {
        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $varnishUrl);
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, 1);
        /**
         * @phpstan-ignore-next-line
         */
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, 0);
        /**
         * @phpstan-ignore-next-line
         */
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, [$header]);

        return $curlHandle;
    }
}
