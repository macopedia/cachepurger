<?php

declare(strict_types=1);

use Macopedia\CachePurger\Middleware\CacheTagResponseHeaders;

return [
    'frontend' => [
        'cachepurger/frontend/cache-tag-response-headers' => [
            'target' => CacheTagResponseHeaders::class,
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
