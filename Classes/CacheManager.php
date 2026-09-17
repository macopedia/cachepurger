<?php

declare(strict_types=1);

namespace Macopedia\CachePurger;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use Macopedia\CachePurger\Configuration\PurgeSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\Request;

/**
 * Collects cache tags to ban and sends the BAN requests to the configured Varnish hosts in one
 * batch at the end of the request (or when execute() is called explicitly).
 *
 * Requests go through TYPO3's HTTP client, so the installation's global HTTP settings
 * (proxy, CA bundle, ...) apply.
 */
#[Autoconfigure(public: true)]
final class CacheManager
{
    private const METHOD = 'BAN';
    private const TAG_HEADER = 'X-Tags';

    /**
     * Number of BAN requests in flight at the same time. A page save can produce hundreds of tags
     * (TYPO3 clears parent, siblings and translations too); firing them all at once would flood
     * Varnish and the local socket table.
     */
    private const CONCURRENCY = 10;

    /**
     * Queued bans grouped by target host list.
     *
     * @var array<string, array{hosts: list<string>, tags: array<string, string>}>
     */
    private array $queue = [];

    public function __construct(
        private readonly PurgeSettings $settings,
        private readonly GuzzleClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Bans a tag on the hosts responsible for the given page, or on the global hosts when no page
     * is given. Site specific additional tags (site setting "cachepurger.tags") are banned along.
     */
    public function clearForTag(string $tag, ?int $pageId = null): void
    {
        $tags = $pageId > 0 ? [$tag, ...$this->settings->getSiteTags($pageId)] : [$tag];
        $this->enqueue($this->settings->getVarnishHosts($pageId), $tags);
    }

    /**
     * "all" / "pages": bans the global tags on every host of the installation, including hosts
     * that are only configured per site, since there is no page to pick a site from.
     */
    public function clearCache(?string $cmd): void
    {
        match (true) {
            $cmd === 'all', $cmd === 'pages' => $this->enqueue($this->settings->getAllVarnishHosts(), $this->settings->getGlobalTags()),
            (int)$cmd > 0 => $this->clearForTag('PAGE-' . (int)$cmd, (int)$cmd),
            default => null,
        };
    }

    public function execute(): void
    {
        if ($this->queue === []) {
            return;
        }
        $queue = $this->queue;
        $this->queue = [];

        $options = [
            'connect_timeout' => $this->settings->getTimeout(),
            'timeout' => $this->settings->getTimeout(),
        ];
        if (!$this->settings->verifyTls()) {
            $options['verify'] = false;
        }
        $client = $this->clientFactory->getClient();

        (new Pool($client, $this->requests($queue, $client, $options), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled' => function (ResponseInterface $response): void {
                $this->logger->debug('Varnish BAN sent', ['status' => $response->getStatusCode()]);
            },
            'rejected' => function (\Throwable $reason): void {
                $this->logger->error('Varnish BAN failed: ' . $reason->getMessage());
            },
        ]))->promise()->wait();
    }

    public function __destruct()
    {
        $this->execute();
    }

    /**
     * One BAN request per host and tag, created lazily as the pool consumes them.
     *
     * @param array<string, array{hosts: list<string>, tags: array<string, string>}> $queue
     * @param array<string, mixed> $options
     * @return \Generator<int, callable(): PromiseInterface>
     */
    private function requests(array $queue, ClientInterface $client, array $options): \Generator
    {
        foreach ($queue as $entry) {
            foreach ($entry['hosts'] as $host) {
                foreach ($entry['tags'] as $tag) {
                    $request = new Request($host, self::METHOD, 'php://temp', [self::TAG_HEADER => $tag]);
                    yield fn (): PromiseInterface => $client->sendAsync($request, $options);
                }
            }
        }
    }

    /**
     * @param list<string> $hosts
     * @param list<string> $tags
     */
    private function enqueue(array $hosts, array $tags): void
    {
        if ($hosts === [] || $tags === []) {
            $this->logger->debug('No Varnish hosts or tags configured, nothing banned', ['tags' => $tags]);
            return;
        }
        $key = implode('|', $hosts);
        $this->queue[$key]['hosts'] = $hosts;
        foreach ($tags as $tag) {
            $this->queue[$key]['tags'][$tag] = $tag;
        }
    }
}
