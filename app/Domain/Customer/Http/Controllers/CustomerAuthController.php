<?php

namespace App\Domain\Customer\Http\Controllers;

use App\Domain\Customer\Services\CustomerAuthService;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /customer/auth/*. Credential-carrying endpoints deliberately do NOT use the Idempotency-Key table (its request hash would
 * store a fingerprint of the password); they are naturally idempotent instead (register/forgot/resend answer identically on repeat).
 */
class CustomerAuthController
{
    public function __construct(private readonly CustomerAuthService $auth) {}

    public function register(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'password' => ['required', 'string', 'min:'.config('customer.password_min_length'), 'max:128'],
        ]);
        $this->auth->register($d, $request->ip());

        return response()->json(['verificationRequired' => true, 'email' => CustomerAuthService::email($d['email'])], 201);
    }

    public function verify(Request $request): JsonResponse
    {
        $d = $request->validate([
            'email' => ['required_without:token', 'nullable', 'email', 'max:190'],
            'code' => ['required_without:token', 'nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'token' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->auth->verify($d['email'] ?? null, $d['code'] ?? null, $d['token'] ?? null, $request->ip(), $request->userAgent()));
    }

    public function resend(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $this->auth->resend($d['email']);

        return response()->json(['status' => 'accepted'], 202);
    }

    public function login(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:190'], 'password' => ['required', 'string', 'max:200']]);

        return response()->json($this->auth->login($d['email'], $d['password'], $request->ip(), $request->userAgent()));
    }

    public function refresh(Request $request): JsonResponse
    {
        $d = $request->validate(['refreshToken' => ['required', 'string', 'max:200']]);

        return response()->json($this->auth->refresh($d['refreshToken'], $request->ip(), $request->userAgent()));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout((string) RequestContext::get(RequestContext::CUSTOMER_ACCOUNT_ID), $request->bearerToken(), $request->boolean('all'));

        return response()->json(['status' => 'signed_out']);
    }

    public function forgot(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $this->auth->forgot($d['email']);

        return response()->json(['status' => 'accepted'], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:200'], 'password' => ['required', 'string', 'min:'.config('customer.password_min_length'), 'max:128']]);
        $this->auth->reset($d['token'], $d['password'], $request->ip());

        return response()->json(['status' => 'password_changed']);
    }
}
