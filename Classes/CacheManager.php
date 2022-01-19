<?php

namespace Macopedia\CachePurger;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;

use function array_merge;
use function array_unique;
use function count;
use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_getinfo;
use function curl_multi_add_handle;
use function curl_multi_close;
use function curl_multi_init;
use function curl_multi_remove_handle;
use function curl_setopt;
use function implode;
use function is_array;

final class CacheManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array<string, mixed>
     */
    protected array $settings;
    /**
     * @var array<string>
     */
    protected array $clearQueue = [];
    /**
     * @var array<string>
     */
    protected array $clearQueueTags = [];
    /**
     * @var array<string>
     */
    protected array $clearQueueSoftTags = [];

    public function __construct()
    {
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $configurationManager = $objectManager->get(ConfigurationManagerInterface::class);
        $this->settings = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        )['tx_cachepurger.']['settings.'] ?? [];
    }

    /**
     * @param string $path
     */
    public function clearForUrl(string $path): void
    {
        $this->logger->debug('clearCacheForUrl: ' . $path);

        $this->clearQueue[] = $path;
        $this->clearQueue = array_unique($this->clearQueue);
    }

    /**
     * @param string $tag
     */
    public function clearForTag(string $tag): void
    {
        $this->logger->debug('clearCacheForUrl: ' . $tag);

        $this->clearQueueTags[] = $tag;
        $this->clearQueueTags = array_unique($this->clearQueueTags);
    }

    /**
     * @param string $tag
     */
    public function clearSoftForTag(string $tag): void
    {
        $this->logger->debug('clearCacheSoftForTag: ' . $tag);

        $this->clearQueueSoftTags[] = $tag;
        $this->clearQueueSoftTags = array_unique($this->clearQueueSoftTags);
    }

    public function execute(): void
    {
        $curlHandles = [];
        $this->logger->debug('execute: ', $this->clearQueue);

        if (
            !is_array($this->settings['domains.']) ||
            !is_array($this->settings['varnish.']) ||
            (
                count($this->clearQueue) === 0 ||
                count($this->clearQueueSoftTags) === 0
            )
        ) {
            return;
        }

        $multiHandle = curl_multi_init();

        if (!is_resource($multiHandle)) {
            return;
        }

        foreach ($this->settings['varnish.'] as $varnishInstance) {
            foreach ($this->clearQueue as $path) {
                $ch = $this->getCurlHandleForCacheClearing($path, $varnishInstance);
                if (!is_resource($ch)) {
                    continue;
                }
                $curlHandles[] = $ch;
                curl_multi_add_handle($multiHandle, $ch);
            }
            foreach ($this->clearQueueTags as $tag) {
                $ch = $this->getCurlHandleForCacheClearingAsTag($tag, $varnishInstance);
                if (!is_resource($ch)) {
                    continue;
                }
                $curlHandles[] = $ch;
                curl_multi_add_handle($multiHandle, $ch);
            }

            $ch = $this->getCurlHandleForSoftCacheClearingAsTag($this->clearQueueSoftTags, $varnishInstance);
            if (!is_resource($ch)) {
                continue;
            }
            $curlHandles[] = $ch;
            curl_multi_add_handle($multiHandle, $ch);
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
            if (!is_resource($ch)) {
                continue;
            }

            if (curl_errno($ch) !== 0) {
                $this->logger->error('error: ' . curl_error($ch));
            } else {
                $info = curl_getinfo($ch);
                $this->logger->debug('info: ', $info);
            }
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        $this->clearQueue = [];
        $this->clearQueueTags = [];
        $this->clearQueueSoftTags = [];
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

                if (isset($this->settings['domain.'])) {
                    $this->clearForUrl($this->settings['domain.']);
                }
                if (isset($this->settings['domains.']) && is_array($this->settings['domains.'])) {
                    foreach ($this->settings['domains.'] as $basePath) {
                        $this->clearForUrl($basePath);
                    }
                }
                if (isset($this->settings['tags.']) && is_array($this->settings['tags.'])) {
                    foreach ($this->settings['tags.'] as $tag) {
                        $this->clearSoftForTag($tag);
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
     * @param array<string> $paths
     */
    public function addPathsToQueue(array $paths): void
    {
        $this->clearQueue = array_merge($this->clearQueue, $paths);
        $this->clearQueue = array_unique($this->clearQueue);
    }

    /**
     * @param array<string> $tags
     */
    public function addTagsToQueue(array $tags): void
    {
        $this->clearQueueTags = array_merge($this->clearQueueTags, $tags);
        $this->clearQueueTags = array_unique($this->clearQueueTags);
    }

    /**
     * @return false|resource
     */
    protected function getCurlHandleForCacheClearing(string $url, string $varnishUrl)
    {
        $this->logger->debug('getCurlHandleForCacheClearing: ' . $url);

        return $this->createCurlHandle($varnishUrl, 'X-Url: (' . $url . ')');
    }

    /**
     * @param string $tag
     * @param string $varnishUrl
     * @return false|resource
     */
    protected function getCurlHandleForCacheClearingAsTag(string $tag, string $varnishUrl)
    {
        $this->logger->debug('getCurlHandleForCacheClearing: ' . $tag);

        return $this->createCurlHandle($varnishUrl, 'X-Tags: ' . $tag);
    }

    /**
     * @param array<string> $tags
     * @param string $varnishUrl
     * @return false|resource
     */
    protected function getCurlHandleForSoftCacheClearingAsTag(array $tags, string $varnishUrl)
    {
        $combinedTags = implode(' ', $tags);

        $this->logger->debug('getCurlHandleForCacheClearing: ' . $combinedTags);

        $header = 'ykey-purge: ' . $combinedTags;

        return $this->createCurlHandle($varnishUrl, $header, 'PURGE');
    }

    /**
     * @param string $varnishUrl
     * @param string $header
     * @return false|resource
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
