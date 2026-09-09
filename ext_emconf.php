<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Caretaker2 Agent',
    'description' => 'Sammelt ein Inventar dieser Instanz und meldet es an einen Caretaker2 Hub.',
    'category' => 'module',
    'author' => 'Tobias Liebig',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-14.99.99',
        ],
    ],
];
