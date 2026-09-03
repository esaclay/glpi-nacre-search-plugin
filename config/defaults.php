<?php

return [
    'plugin' => [
        'name' => 'nacresearch',
        'version' => '1.0.0',
    ],
    'ui' => [
        'selector_hint' => 'nacre',
        'result_limit' => 100,
        'debounce_ms' => 120,
        'modal_title' => 'Recherche de code NACRE',
        'button_label' => 'Chercher un code NACRE',
    ],
    'data' => [
        'source' => 'public/data/nacre.json',
    ],
];
