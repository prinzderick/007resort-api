<?php

/*
 * DEV-ONLY fake Paystack (sandbox stand-in). Never deploy. Run:
 *   php -S 127.0.0.1:8093 scripts/dev-paystack.php
 * and point the API at it: PAYSTACK_BASE_URL=http://127.0.0.1:8093 PAYSTACK_SECRET_KEY=<any dev value>.
 * Implements just what PaystackAdapter uses: POST /transaction/initialize, POST /charge (bank transfer / dynamic virtual account), GET /transaction/verify/{ref}; plus a hosted page
 * /pay/{ref} with "Pay" / "Decline" buttons that redirects to the callback URL like Paystack does. No real money, no real keys.
 */

$store = sys_get_temp_dir().'/r007-dev-paystack.json';
$load = fn () => is_file($store) ? (json_decode((string) file_get_contents($store), true) ?: []) : [];
$save = fn (array $d) => file_put_contents($store, json_encode($d), LOCK_EX);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$json = function (array $body, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
};

if ($method === 'POST' && $path === '/transaction/initialize') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $ref = (string) ($in['reference'] ?? bin2hex(random_bytes(6)));
    $d = $load();
    $d[$ref] = ['amount' => (int) ($in['amount'] ?? 0), 'currency' => $in['currency'] ?? 'NGN', 'email' => $in['email'] ?? '', 'callback' => $in['callback_url'] ?? null, 'status' => 'abandoned', 'id' => random_int(4000000000, 4999999999)];
    $save($d);
    $json(['status' => true, 'message' => 'Authorization URL created', 'data' => ['authorization_url' => 'http://127.0.0.1:8093/pay/'.rawurlencode($ref), 'access_code' => 'ac_'.substr($ref, -8), 'reference' => $ref]]);

    return;
}
if ($method === 'POST' && $path === '/charge') {
    // "Pay with Transfer": a dynamic virtual account for the reference. Open /pay/{ref} and press "Pay now" to simulate the customer's transfer.
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $ref = (string) ($in['reference'] ?? bin2hex(random_bytes(6)));
    $d = $load();
    $d[$ref] = ['amount' => (int) ($in['amount'] ?? 0), 'currency' => $in['currency'] ?? 'NGN', 'email' => $in['email'] ?? '', 'callback' => null, 'status' => 'abandoned', 'id' => random_int(4000000000, 4999999999)];
    $save($d);
    $json(['status' => true, 'message' => 'Charge attempted', 'data' => ['reference' => $ref, 'status' => 'pay_offline', 'account_number' => '99'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'account_name' => '007 RESORT (DEV SANDBOX)', 'bank' => ['name' => 'Sandbox Bank', 'slug' => 'sandbox-bank'], 'account_expires_at' => $in['bank_transfer']['account_expires_at'] ?? null]]);

    return;
}
if ($method === 'GET' && preg_match('#^/transaction/verify/(.+)$#', $path, $m)) {
    $ref = rawurldecode($m[1]);
    $t = $load()[$ref] ?? null;
    if ($t === null) {
        $json(['status' => false, 'message' => 'Transaction reference not found'], 404);

        return;
    }
    $json(['status' => true, 'message' => 'Verification successful', 'data' => ['id' => $t['id'], 'status' => $t['status'], 'reference' => $ref, 'amount' => $t['amount'], 'currency' => $t['currency'],
        'gateway_response' => $t['status'] === 'success' ? 'Successful' : 'Declined']]);

    return;
}
if (preg_match('#^/pay/(.+)$#', $path, $m)) {
    $ref = rawurldecode($m[1]);
    $d = $load();
    if (! isset($d[$ref])) {
        http_response_code(404);
        echo 'Unknown reference';

        return;
    }
    if ($method === 'POST') {
        $d[$ref]['status'] = ($_POST['action'] ?? '') === 'pay' ? 'success' : 'failed';
        $save($d);
        $cb = $d[$ref]['callback'] ?: 'http://127.0.0.1:8092/payment/return';
        header('Location: '.$cb.(str_contains($cb, '?') ? '&' : '?').'reference='.rawurlencode($ref).'&trxref='.rawurlencode($ref));
        http_response_code(303);

        return;
    }
    $naira = number_format($d[$ref]['amount'] / 100, 2);
    echo '<!doctype html><meta charset="utf-8"><title>Paystack (DEV sandbox)</title><body style="font-family:sans-serif;max-width:28rem;margin:4rem auto">'
        .'<h1>Paystack <small>(dev sandbox)</small></h1><p>Pay <strong>NGN '.$naira.'</strong> to 007 Resort &amp; Spa</p><p>Ref: <code>'.htmlspecialchars($ref).'</code></p>'
        .'<form method="post"><button name="action" value="pay" style="padding:.6rem 1.2rem">Pay now</button> <button name="action" value="decline" style="padding:.6rem 1.2rem">Decline</button></form></body>';

    return;
}
http_response_code(404);
echo 'not found';
