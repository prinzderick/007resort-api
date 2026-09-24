<?php

namespace App\Domain\Devices\Http\Controllers;

use App\Support\Http\ApiProblem;
use App\Support\Realtime\Channels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/broadcasting/auth — Pusher-protocol private channel authorization for Reverb (contract api/realtime.md §2).
 * Bearer token (+ X-Device-Token) authenticates; channel rules live in App\Support\Realtime\Channels (modules register their own).
 */
class BroadcastAuthController
{
    public function auth(Request $request): JsonResponse
    {
        $d = $request->validate(['socket_id' => ['required', 'string', 'max:64'], 'channel_name' => ['required', 'string', 'max:200']]);
        $channel = $d['channel_name'];
        if (! str_starts_with($channel, 'private-')) {
            throw ApiProblem::unprocessable('validation_failed', 'Only private channels are supported.', ['channel_name' => ['Must start with "private-".']]);
        }

        $allowed = Channels::authorize(substr($channel, strlen('private-')));
        if ($allowed !== true) {
            throw ApiProblem::permissionDenied('channel.subscribe');
        }

        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');

        return response()->json(['auth' => $key.':'.hash_hmac('sha256', $d['socket_id'].':'.$channel, $secret)]);
    }
}
