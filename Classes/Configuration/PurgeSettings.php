<?php

declare(strict_types=1);

namespace Macopedia\CachePurger\Configuration;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the purge settings from the extension configuration and, for page bans, from the
 * site settings of the site the page belongs to. No request is needed: the purge hooks run inside
 * the DataHandler, which scheduler tasks and CLI commands use as well.
 *
 * Hosts: site settings ("cachepurger.hosts") for the page's site, otherwise the global "hosts".
 * Tags: the global "tags" are banned on "clear all"; a site may add "cachepurger.tags", which do
 * not replace the global ones but are banned together with every page of that site.
 * Timeout and TLS verification: extension configuration only.
 */
#[Autoconfigure(public: true)]
final class PurgeSettings
{
    /** Extension key, also the top-level key in site settings ("cachepurger.hosts", "cachepurger.tags"). */
    private const KEY = 'cachepurger';

    /**
     * Site values resolved once per process; the DataHandler calls the purge hooks for every
     * single record it touches.
     *
     * @var array<string, list<string>>
     */
    private array $siteValues = [];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SiteFinder $siteFinder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getVarnishHosts(?int $pageId = null): array
    {
        $hosts = $this->listFromSiteSettings('hosts', $pageId);
        return $hosts !== [] ? $hosts : self::toList($this->extensionConfiguration('hosts'));
    }

    /**
     * Additional tags a site wants banned on its hosts whenever one of its pages is banned,
     * e.g. a navigation or listing object the frontend caches per site ("cachepurger.tags").
     *
     * @return list<string>
     */
    public function getSiteTags(int $pageId): array
    {
        $siteTags = $this->listFromSiteSettings('tags', $pageId);
        // A global tag is carried by every page; banning it on a page save would flush everything.
        $forbidden = array_intersect($siteTags, $this->getGlobalTags());
        if ($forbidden !== []) {
            $this->logger->warning('Site tags must not repeat the global tags, ignoring: ' . implode(', ', $forbidden), ['pageId' => $pageId]);
        }
        return array_values(array_diff($siteTags, $forbidden));
    }

    /**
     * Every host known to the installation: the global list plus the hosts of all sites.
     * Used for "clear all", which has no page and therefore no single site.
     *
     * @return list<string>
     */
    public function getAllVarnishHosts(): array
    {
        $hosts = $this->getVarnishHosts();
        foreach ($this->siteFinder->getAllSites() as $site) {
            $hosts = [...$hosts, ...self::siteValue($site, 'hosts')];
        }
        return array_values(array_unique($hosts));
    }

    /**
     * Tags banned when TYPO3 clears all page caches ("all" / "pages").
     *
     * @return list<string>
     */
    public function getGlobalTags(): array
    {
        return self::toList($this->extensionConfiguration('tags'));
    }

    public function getTimeout(): int
    {
        $timeout = (int)$this->extensionConfiguration('timeout');
        return $timeout > 0 ? $timeout : 5;
    }

    /**
     * Whether TLS certificates of https Varnish hosts are verified. Off skips verification for
     * these requests only, e.g. for self-signed certificates on an internal network.
     */
    public function verifyTls(): bool
    {
        $value = $this->extensionConfiguration('verifyTls');
        return $value === null || $value === '' ? true : (bool)$value;
    }

    /**
     * Accepts a comma separated string (extension configuration) or an array (YAML lists).
     *
     * @return list<string>
     */
    public static function toList(mixed $value): array
    {
        if (is_string($value)) {
            return GeneralUtility::trimExplode(',', $value, true);
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn ($item): string => trim((string)$item), $value), static fn (string $item): bool => $item !== ''));
        }
        return [];
    }

    /**
     * @return list<string>
     */
    private function listFromSiteSettings(string $key, ?int $pageId): array
    {
        if ($pageId === null || $pageId <= 0) {
            return [];
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return [];
        }
        return $this->siteValues[$site->getIdentifier() . ':' . $key] ??= self::siteValue($site, $key);
    }

    /**
     * Reads "cachepurger.<key>" from a site. With the settings definition of the shipped site set
     * the dotted identifier resolves directly; without it TYPO3 flattens only scalar leaves, so a
     * list has to be taken from the nested "cachepurger" array.
     *
     * @return list<string>
     */
    private static function siteValue(Site $site, string $key): array
    {
        $settings = $site->getSettings();
        $value = $settings->get(self::KEY . '.' . $key);
        if ($value === null) {
            $group = $settings->get(self::KEY);
            $value = is_array($group) ? ($group[$key] ?? null) : null;
        }
        return self::toList($value);
    }

    private function extensionConfiguration(string $path): mixed
    {
        try {
            return $this->extensionConfiguration->get(self::KEY, $path);
        } catch (\Throwable) {
            return null;
        }
    }
}
