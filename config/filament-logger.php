<?php
return [
    'datetime_format' => 'd/m/Y H:i:s',
    'date_format' => 'd/m/Y',

    'activity_resource' => \Z3d0X\FilamentLogger\Resources\ActivityResource::class,

    'resources' => [
        'enabled' => true,
        'log_name' => 'Resource',
        'logger' => \Z3d0X\FilamentLogger\Loggers\ResourceLogger::class,
        'color' => 'success',
        'exclude' => [
            //App\Filament\Resources\UserResource::class,
        ],
    ],

    'access' => [
        'enabled' => true,
        // Bọc AccessLogger gốc qua App\Listeners\FilamentAccessLoginLogger — sự kiện Login là sự
        // kiện TOÀN CỤC (mọi guard), không riêng Filament, và AccessLogger gốc vỡ (TypeError) khi
        // guard không phải "web" đăng nhập (VD guard "tenant" của Portal khách thuê) vì giả định
        // user luôn có cột "name". Xem giải thích đầy đủ trong chính listener đó.
        'logger' => \App\Listeners\FilamentAccessLoginLogger::class,
        'color' => 'danger',
        'log_name' => 'Access',
    ],

    'notifications' => [
        'enabled' => true,
        'logger' => \Z3d0X\FilamentLogger\Loggers\NotificationLogger::class,
        'color' => null,
        'log_name' => 'Notification',
    ],

    'models' => [
        'enabled' => true,
        'log_name' => 'Model',
        'color' => 'warning',
        'logger' => \Z3d0X\FilamentLogger\Loggers\ModelLogger::class,
        'register' => [
            //App\Models\User::class,
        ],
    ],

    'custom' => [
        // [
        //     'log_name' => 'Custom',
        //     'color' => 'primary',
        // ]
    ],
];
