<?php

// config for Guava/KnowledgeBasePanel
return [
    'panel' => [
        'id' => env('FILAMENT_KB_ID', 'knowledge-base'),
        'path' => env('FILAMENT_KB_PATH', 'tai-lieu-huong-dan'),
    ],

    'docs-paths' => [
        base_path('Modules/Category/docs'),
        base_path('Modules/Process/docs'),
        base_path('Modules/User/docs'),
    ],

    'model' => \Guava\FilamentKnowledgeBase\Models\FlatfileDocumentation::class,

    'cache' => [
        'prefix' => env('FILAMENT_KB_CACHE_PREFIX', 'filament_kb_'),
        'ttl' => env('FILAMENT_KB_CACHE_TTL', 'forever'),
    ],
];
