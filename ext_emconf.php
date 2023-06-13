<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Cache Purger',
    'description' => 'Purge cached URLs within Varnish instances',
    'category' => 'misc',
    'version' => '2.0.0',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
