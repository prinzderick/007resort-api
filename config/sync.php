<?php

/*
 * Sync engine (ADR-0013, architecture/sync/*). Every value is env-overridable; defaults are safe for production.
 * Durations are seconds unless the key says otherwise.
 */
$node = strtolower((string) (env('APP_NODE') ?: env('NODE_ROLE', 'local')));

return [
    // Peer this node talks to. Local -> Cloud base URL (".../api/v1" is appended). Cloud never calls Local.
    'peer_url' => env('PEER_URL'),
    // Bearer credential THIS node presents to its peer (secret; never logged).
    'peer_node_token' => env('PEER_NODE_TOKEN'),
    // Credentials the peer may present to US: comma-separated "<peerNode>[@<siteUuid>]:<sha256-hex>" entries.
    // Two entries for the same peer = rotation window (old + new both valid). Generate with `php artisan r007:sync:token`.
    'node_token_hashes' => env('NODE_TOKEN_HASHES', ''),

    // Local pushes its outbox to Cloud; Cloud serves its outbox by pull. Local's inbox is closed by default (ADR-0005).
    'push_enabled' => filter_var(env('SYNC_PUSH_ENABLED', $node === 'local'), FILTER_VALIDATE_BOOL),
    'pull_enabled' => filter_var(env('SYNC_PULL_ENABLED', $node === 'local'), FILTER_VALIDATE_BOOL),
    'accept_push' => filter_var(env('SYNC_ACCEPT_PUSH', $node === 'cloud'), FILTER_VALIDATE_BOOL),
    'serve_pull' => filter_var(env('SYNC_SERVE_PULL', $node === 'cloud'), FILTER_VALIDATE_BOOL),
    'accept_heartbeat' => filter_var(env('SYNC_ACCEPT_HEARTBEAT', $node === 'cloud'), FILTER_VALIDATE_BOOL),
    'heartbeat_enabled' => filter_var(env('SYNC_HEARTBEAT_ENABLED', $node === 'local'), FILTER_VALIDATE_BOOL),

    // Heartbeat / node health (architecture/sync/heartbeat-and-node-health.md).
    'heartbeat_interval' => (int) env('SYNC_HEARTBEAT_INTERVAL', 30),
    // A site is OFFLINE only after this long without a heartbeat (default 3 missed heartbeats).
    'stale_after' => (int) env('SYNC_STALE_AFTER', 90),
    // Reject heartbeats whose sentAt differs from server time by more than this (replay protection).
    'heartbeat_max_skew' => (int) env('SYNC_HEARTBEAT_MAX_SKEW', 300),

    // Outbox publisher.
    'publish_interval' => (int) env('SYNC_PUBLISH_INTERVAL', 5),
    'pull_interval' => (int) env('SYNC_PULL_INTERVAL', 5),
    'batch_size' => (int) env('SYNC_BATCH_SIZE', 50),
    'max_batches_per_run' => (int) env('SYNC_MAX_BATCHES_PER_RUN', 20),
    'backoff_base' => (float) env('SYNC_BACKOFF_BASE', 5),
    'backoff_cap' => (float) env('SYNC_BACKOFF_CAP', 300),
    // Per-event FAILED results from the peer (poison) become terminal FAILED after this many attempts.
    // Network/5xx/429 failures are never terminal: the event waits (with capped backoff) until the peer is back.
    'max_attempts' => (int) env('SYNC_MAX_ATTEMPTS', 8),
    'http_timeout' => (int) env('SYNC_HTTP_TIMEOUT', 10),
    // Cloud->Local pull: how long a served-but-unacknowledged event is hidden before it is served again.
    'pull_lease' => (int) env('SYNC_PULL_LEASE', 60),
    'pull_batch' => (int) env('SYNC_PULL_BATCH', 100),

    // Inbox.
    'inbox_max_batch' => (int) env('SYNC_INBOX_MAX_BATCH', 100),
    // A version-gap (out-of-order) event that stays deferred this long escalates to ENTITY_VERSION_CONFLICT.
    'defer_escalate_after' => (int) env('SYNC_DEFER_ESCALATE_AFTER', 900),
    'defer_backoff_base' => (float) env('SYNC_DEFER_BACKOFF_BASE', 2),
    'defer_backoff_cap' => (float) env('SYNC_DEFER_BACKOFF_CAP', 60),
    // Trace every applied sync event in the receiving node's own audit chain (architecture/17 §7.1).
    'audit_applied' => filter_var(env('SYNC_AUDIT_APPLIED', true), FILTER_VALIDATE_BOOL),

    // Node-credential rate limit on the sync endpoints (per credential, per minute, via Redis).
    'rate_limit_per_minute' => (int) env('SYNC_RATE_LIMIT_PER_MINUTE', 600),

    'queue' => env('SYNC_QUEUE', 'default'),
];
