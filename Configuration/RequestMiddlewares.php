<?php

return [
    'frontend' => [
        'cachepurger/cms-frontend/headers' => [
            'target' => \Macopedia\CachePurger\Middleware\CacheHeaders::class,
            'before' => [
            ],
            'after' => [
                'typo3/cms-frontend/output-compression',
            ],
        ],
    ]
];
