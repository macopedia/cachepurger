<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'CachePurger for Varnish',
    'description' => 'Ban Varnish cache objects by tag when TYPO3 clears page caches, from the backend and the CLI',
    'category' => 'services',
    'author' => 'Macopedia',
    'author_company' => 'Macopedia Sp. z o.o.',
    'state' => 'stable',
    'version' => '3.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
