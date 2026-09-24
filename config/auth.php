<?php

use App\Domain\Identity\Models\UserAccount;

return [

    // Stateless API: opaque bearer access token -> `session` row -> user_account (see app/Domain/Identity).
    'defaults' => [
        'guard' => 'staff',
        'passwords' => null,
    ],

    'guards' => [
        'staff' => [
            'driver' => 'staff-token',
            'provider' => 'accounts',
        ],
    ],

    'providers' => [
        'accounts' => [
            'driver' => 'eloquent',
            'model' => UserAccount::class,
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,

];
