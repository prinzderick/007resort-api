<?php

return [
    // HMAC key for QR tokens. Empty => derived from APP_KEY. Share between Local and Cloud nodes if both verify.
    'qr_key' => env('TICKET_QR_KEY'),
];
