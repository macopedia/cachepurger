<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Cache Purger',
    'description' => 'Purge cached URLs within Varnish instances',
    'category' => 'misc',
    'version' => '1.0.4',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'constraints' => [
        'depends' => [
            'typo3' => '9.5.0-11.5.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
