<?php

return [
    'app' => [
        'controllers' => 'app/Controllers',
        'services' => 'app/Services',
        'migrations' => 'app/Database/Migrations',
        'views' => 'app/UI/views',
        'components' => 'app/UI/views/components',
        'commands' => 'app/Commands',
        'events' => 'app/Events',
        'tests' => 'app/tests',
        'models' => 'app/Models',
        'dto' => 'app/Dto',
        'seeders' => 'app/Database/Seeders',
        'middlewares' => 'app/Middlewares',
        'languages' => 'app/Languages',
    ],
    'modules' => [
        'controllers' => 'src/Controllers',
        'services' => 'src/Services',
        'migrations' => 'src/Database/Migrations',
        'views' => 'src/UI/views',
        'components' => 'src/UI/views/components',
        'commands' => 'src/Commands',
        'events' => 'src/Events',
        'tests' => 'src/tests',
        'models' => 'src/Models',
        'dto' => 'src/Dto',
        'seeders' => 'src/Database/Seeders',
        'middlewares' => 'src/Middlewares',
        'languages' => 'src/Languages'
    ],
];
