<?php

// Available as config('identity.*'). Module config.php files are auto-merged under the lowercase module name.
return [
    'access_ttl_minutes' => (int) env('IDENTITY_ACCESS_TTL_MINUTES', 15),
    'refresh_ttl_days' => (int) env('IDENTITY_REFRESH_TTL_DAYS', 30),
    'max_failed_logins' => (int) env('IDENTITY_MAX_FAILED_LOGINS', 5),
    'lockout_minutes' => (int) env('IDENTITY_LOCKOUT_MINUTES', 15),
    'step_up_seconds' => 300,

    /*
     * "Below one's own level" (architecture/06): a staff manager may grant a role / manage a staff member only if they hold every
     * NON-operational permission involved. Day-to-day operational permissions are exempt so a Manager can staff the kitchen, bar and
     * stores without holding prep-ticket/inventory permissions themselves. Everything else (finance, approvals, pricing,
     * staff/role/device/config/audit administration) must already be held by the granter.
     */
    'operational_permissions' => [
        'order.create', 'order.line.add', 'order.line.remove_unsent', 'order.send', 'tab.view_own_facility',
        'prep_ticket.view', 'prep_ticket.transition', 'receipt.reprint', 'cash_session.open', 'cash_session.close',
        'payment.take', 'payment.split', 'order.settle',
        'inventory.receive', 'inventory.transfer.create', 'inventory.count.create', 'inventory.adjustment.request',
        'inventory.purchase_receipt.create', 'supplier.manage',
    ],
];
