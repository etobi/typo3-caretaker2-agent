<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Caretaker2 Agent',
    'description' => 'Collects an inventory of this instance and reports it to a Caretaker2 hub.',
    'category' => 'module',
    'author' => 'Tobias Liebig',
    'state' => 'alpha',
    'version' => '0.1.2',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-14.99.99',
        ],
    ],
];
