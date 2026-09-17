# CachePurger for Varnish

[![Packagist](https://img.shields.io/packagist/v/macopedia/cachepurger.svg)](https://packagist.org/packages/macopedia/cachepurger)
[![TYPO3 13.4](https://img.shields.io/badge/TYPO3-13.4-orange.svg)](https://get.typo3.org/version/13)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14-orange.svg)](https://get.typo3.org/version/14)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-lightgrey.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

A small TYPO3 extension that keeps Varnish in sync with TYPO3's own page cache. Whenever TYPO3
clears the cache of a page, or of all pages, CachePurger sends a `BAN` request with the matching
`X-Tags` header to your Varnish instances. In the frontend it adds the headers Varnish needs to
tag the cached objects in the first place (see [Varnish side](#varnish-side)).

- bans single pages (`PAGE-<uid>`) and everything at once (`T3`, configurable) when TYPO3 clears
  all page caches
- bans additional per-site tags together with a page, e.g. a cached navigation
- works from the backend and from the command line (scheduler tasks, migrations, custom commands)
- adds `X-Tags` and `X-TYPO3-caching` response headers in the frontend
- supports several Varnish instances, and [different instances per site](#option-b-site-settings)
- one code base for TYPO3 13.4 and 14 LTS
- sends through TYPO3's HTTP client, so global proxy and certificate settings apply
- no TypoScript, no Extbase: plain extension configuration and site settings

## Requirements

| CachePurger | TYPO3          | PHP     |
|-------------|----------------|---------|
| 3.x         | 13.4, 14.3     | >= 8.2  |
| 2.x         | 11.5, 12.4     | >= 8.1  |

## Installation

```
composer require macopedia/cachepurger:^3.0
```

No database changes, no TypoScript include. Configure the Varnish hosts as described under
[Configuration](#configuration) and you are done. Coming from version 2? Read
[Migrating from version 2.x](#migrating-from-version-2x) first.

## Configuration

There are two places for the settings, and they can be combined:

- [Option A: extension configuration](#option-a-extension-configuration) for one Varnish setup
  shared by the whole installation,
- [Option B: site settings](#option-b-site-settings) for sites that sit behind their own Varnish.

### Option A: extension configuration

One Varnish setup for the whole installation. Set it under Admin Tools > Settings > Extension
Configuration > cachepurger, or in `config/system/settings.php` (and `additional.php` for
environment specific values):

```php
'EXTENSIONS' => [
    'cachepurger' => [
        'hosts' => 'http://varnish-frontend,http://varnish-backend',
        'tags' => 'T3',
        'timeout' => '5',
        'verifyTls' => '1',
    ],
],
```

| Setting     | Meaning                                                                                        | Default |
|-------------|------------------------------------------------------------------------------------------------|---------|
| `hosts`     | Comma separated list of Varnish URLs that receive the BAN requests                             | empty   |
| `tags`      | Comma separated tags banned when TYPO3 clears all caches or all page caches                     | `T3`    |
| `timeout`   | Seconds to wait per request; an unreachable Varnish will never block an editor for longer      | `5`     |
| `verifyTls` | Verify certificates of `https` hosts; set to `0` for self-signed certificates on internal hosts | `1`     |

Leaving `hosts` empty disables purging. Nothing breaks, the extension just logs at debug level
that there is nowhere to send bans to.

> [!NOTE]
> `hosts` and `tags` are comma separated strings because the backend edits extension settings as
> text. When you write them in `settings.php` or `additional.php` yourself, a PHP array of strings
> is accepted as well, but saving the form in the backend turns it back into a string.

### Option B: site settings

Different Varnish instances per site. If your installation hosts several sites behind different
Varnish instances, put the hosts into `config/sites/<site>/settings.yaml`:

```yaml
cachepurger:
  hosts:
    - http://varnish-site-a-frontend
    - http://varnish-site-a-backend
  tags:
    - NAV-SITE-A
```

| Key     | Meaning                                                                                                  |
|---------|----------------------------------------------------------------------------------------------------------|
| `hosts` | Varnish URLs for pages of this site. Replaces the global `hosts` for this site.                          |
| `tags`  | Optional. Extra tags banned on this site's hosts whenever a page of this site is banned, for objects your frontend caches and tags itself, e.g. a navigation or listing. Not added to page responses, and must not repeat a global tag: such entries are ignored with a warning, because banning a tag every page carries would flush the whole site on each save. |

`timeout` and the global `tags` stay in the extension configuration.

The extension ships a site set `macopedia/cachepurger` with the settings definitions. Add it to the
`dependencies` of your site's `config.yaml` and both keys become editable fields in the backend's
site settings editor. Without it the YAML above works just the same.

How bans are routed with this in place:

- **Editing a page** bans the tag `PAGE-<uid>`, plus the site's `tags` if any. The extension looks
  up the site the page belongs to and sends the ban only to that site's hosts. Sites behind other
  Varnish instances never receive it. Page uids are unique across the installation, so the ban
  cannot hit another site's pages even when sites share a Varnish.
- **A site without its own `cachepurger` keys** falls back to the
  [extension configuration](#option-a-extension-configuration), so you only configure the sites
  that differ. The global `hosts` list may stay empty if every site has its own hosts.
- **"Clear all caches"** in the backend has no page context. It bans the global `tags` on every
  host known to the installation, the global list and all site lists together, so it evicts all
  sites. Per-site "clear all" is not supported in this version: it would need a site-specific tag
  in the frontend headers. If you need it, open an issue or send a pull request.

### Varnish side

Cached objects must carry the tags to be found by a ban. The frontend middleware adds two headers
to every page response:

| Header            | Value                 | Purpose                                                        |
|-------------------|-----------------------|----------------------------------------------------------------|
| `X-Tags`          | `<tags> PAGE-<uid>`   | The configured `tags` plus the page tag; store it with the object |
| `X-TYPO3-caching` | `cache` or `no-cache` | Whether TYPO3 allows caching this response; pass on `no-cache` |

`no-cache` is sent when TYPO3 itself refuses to cache the page, for example because of an uncached
plugin or a `no_cache` flag, so Varnish should not store such responses either.

With the default configuration the header reads `X-Tags: T3 PAGE-2133`. Per-site `tags` are not
added to pages: they are banned on every page save, so a page carrying them would be evicted
site-wide each time an editor saves anything. Put them on the objects your frontend caches itself.

A minimal VCL fragment for the ban side:

```
sub vcl_recv {
    if (req.method == "BAN") {
        ban("obj.http.X-Tags ~ (^|\s)" + req.http.X-Tags + "(\s|$)");
        return (synth(200, "Banned"));
    }
}
```

## Migrating from version 2.x

Version 2 read its settings from TypoScript. That worked in the backend, but not from the command
line: TypoScript is resolved through Extbase, which needs a web request. Any scheduler task or CLI
command that triggered a cache clear, for example by copying or saving records with the
DataHandler, aborted with `NoServerRequestGivenException` before its work was finished.

Version 3 reads its settings from the [extension configuration](#option-a-extension-configuration)
or from [site settings](#option-b-site-settings) instead and does not read TypoScript at all. The
migration is a move of the same values to a new place, and it has to happen together with the
update: until the new settings exist, nothing is banned.

**Before** (version 2, TypoScript setup):

```
tx_cachepurger.settings {
    varnish {
        1 = varnish-frontend
        2 = varnish-backend
    }
    tags.0 = T3
}
```

**After** (version 3, [extension configuration](#option-a-extension-configuration) in
`config/system/settings.php` or `additional.php`):

```php
'EXTENSIONS' => [
    'cachepurger' => [
        'hosts' => 'http://varnish-frontend,http://varnish-backend',
        'tags' => 'T3',
        'timeout' => '5',
        'verifyTls' => '1',
    ],
],
```

**After, per site** (version 3, [site settings](#option-b-site-settings) in
`config/sites/<site>/settings.yaml`, only for sites behind their own Varnish):

```yaml
cachepurger:
  hosts:
    - http://varnish-frontend
    - http://varnish-backend
```

Site settings replace the hosts for that site and may add site specific `tags` that are banned with
every page of the site. The global `tags` used by "clear all caches" and the `timeout` stay in the
extension configuration, so keep the block above even with per-site hosts.

Step by step:

1. Copy the hosts into `hosts` and the tags into `tags`. Hosts are now a comma separated list
   (or a YAML list in site settings), and each host needs a scheme: `varnish-frontend` becomes
   `http://varnish-frontend`.
2. Remove the `tx_cachepurger.settings` block from your TypoScript.
3. Update to TYPO3 13.4 or 14 and PHP 8.2 if you have not already; version 3 does not support
   TYPO3 11 or 12.
4. If a Varnish host is reached over `https` with a self-signed certificate, set `verifyTls` to
   `0`. Version 2 never verified certificates; version 3 does by default.

> [!WARNING]
> The old TypoScript block is ignored by version 3. Deploy the new settings in the same release as
> the update, otherwise Varnish keeps serving stale pages until you do.

## Troubleshooting

### Nothing is banned and the log is quiet

No hosts are configured, so the extension has nowhere to send bans. Set `hosts` in the
[extension configuration](#option-a-extension-configuration), or `cachepurger.hosts` in the
[site settings](#option-b-site-settings) of the affected site. An empty value means "purging off"
on purpose, which is why nothing is logged above debug level.

### Nothing is banned and the log shows errors

Varnish is not reachable from the web server, its certificate is rejected, or it answers the
`BAN` with an error status, typically `405` when the VCL has no handler for the method. The entries
under the component `Macopedia.CachePurger.CacheManager` contain the host and the error or status.
Test that URL from the web server itself; reachability from your browser proves nothing about the
server's network. Requests go through TYPO3's HTTP client, so a proxy configured in `HTTP.proxy` is
used for Varnish too; if Varnish must be reached directly, add it to `HTTP.proxy` exceptions.

### Saving records became slow for editors

Varnish is unreachable and every save waits for the timeout, once per host and tag. Fix the network
path. Until then, lowering [`timeout`](#option-a-extension-configuration) keeps the backend usable.

### Bans arrive, but Varnish still serves the old version

Either Varnish never stored the `X-Tags` header with the object, or the ban expression in your VCL
does not match it (see [Varnish side](#varnish-side)). Fetch a page fresh, look at its `X-Tags`
response header and compare the value with the expression your VCL uses for `BAN` requests.

### Nothing is banned since the update to version 3

The settings are still only in TypoScript, which version 3 no longer reads. Move them as described
in [Migrating from version 2.x](#migrating-from-version-2x).

### A page of site A was banned on site B's Varnish

Site B has no `cachepurger.hosts` of its own and falls back to the global list, which contains
site A's hosts. Give every site that sits behind its own Varnish an explicit host list, see
[Option B: site settings](#option-b-site-settings). Note that "clear all caches" deliberately
reaches every host; only page bans are per site.

## How it works

This is background for the curious; nothing here needs configuring. TYPO3 calls two DataHandler
hooks whenever it clears page caches: one with the list of page uids, one with the cache command
(`all`, `pages`, a single uid). CachePurger turns them into tags, adds the site's additional tags
for page bans, and sends all bans in one batch at the end of the request through TYPO3's HTTP
client, one request per Varnish host and tag, at most ten in flight at a time. Site settings are
resolved once per process and cached, so mass operations such as copying a large page tree do not
pay the lookup cost for every record.

Expect more bans per save than you might think. When a page is saved, TYPO3 itself clears the
cache of that page, its parent, all its siblings and all its translations, because menus on those
pages may show the changed title. CachePurger bans exactly the pages TYPO3 clears, so a page with
many siblings and languages can produce several hundred `BAN` requests per host. That is intended
and cheap for Varnish; the concurrency cap keeps it from opening all connections at once.
