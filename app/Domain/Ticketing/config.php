<?php

return [
    // HMAC key for QR tokens. Empty => derived from APP_KEY. Share between Local and Cloud nodes if both verify.
    'qr_key' => env('TICKET_QR_KEY'),

    // facility_unit.code of the Sports Store: where RENTAL / store items of an order are released (Reception flow).
    'store_facility_code' => env('TICKET_STORE_FACILITY_CODE', 'SPORTS-STORE'),
];
