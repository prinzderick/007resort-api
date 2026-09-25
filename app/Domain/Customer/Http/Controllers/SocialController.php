<?php

namespace App\Domain\Customer\Http\Controllers;

use App\Domain\Customer\Http\Middleware\SocialLinkAuth;
use App\Domain\Customer\Services\CustomerProfileService;
use App\Domain\Customer\Services\SocialLoginService;
use App\Domain\Customer\Support\Actor;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Social sign-in (docs/CUSTOMER_SOCIAL_LOGIN.md). Credential-free of Idempotency-Key except the link call. */
class SocialController
{
    public function __construct(private readonly SocialLoginService $social, private readonly CustomerProfileService $profile) {}

    public function providers(): array
    {
        return ['providers' => $this->social->providers()];
    }

    /** Server-to-server (website service token, scope customer.social). */
    public function login(Request $request): JsonResponse
    {
        return $this->signIn($request, false);
    }

    /** Optional, no service token: Google idToken only (mobile later). Off by default. */
    public function tokenLogin(Request $request): JsonResponse
    {
        if (! config('customer.social.id_token_public_enabled')) {
            throw ApiProblem::notFound();
        }

        return $this->signIn($request, true);
    }

    private function signIn(Request $request, bool $public): JsonResponse
    {
        $d = $this->validateClaims($request);
        $c = $this->social->claims($d, $public);
        $out = $this->social->login($c, $d['clientIp'] ?? $request->ip(), $d['userAgent'] ?? $request->userAgent());

        return response()->json($out, $out['isNewCustomer'] ? 201 : 200);
    }

    public function confirm(Request $request): JsonResponse
    {
        $d = $request->validate([
            'email' => ['required_without:token', 'nullable', 'email', 'max:190'],
            'code' => ['required_without:token', 'nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'token' => ['nullable', 'string', 'max:200'],
            'clientIp' => ['nullable', 'ip'], 'userAgent' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->social->confirmLink($d['email'] ?? null, $d['code'] ?? null, $d['token'] ?? null, $d['clientIp'] ?? $request->ip(), $d['userAgent'] ?? $request->userAgent()));
    }

    // ---- authenticated customer ------------------------------------------------------------------------------------

    public function identities(): array
    {
        return $this->social->list(Actor::requireCustomer());
    }

    public function link(Request $request): JsonResponse
    {
        $viaService = RequestContext::get(SocialLinkAuth::VIA_SERVICE) === '1';
        $d = $this->validateClaims($request, false);
        $c = $this->social->claims($d, ! $viaService); // a bare customer bearer cannot vouch for claims: idToken only
        $r = $this->social->linkTo(Actor::requireCustomer(), $c, $d['clientIp'] ?? $request->ip());

        return response()->json(['identity' => $r['identity']], $r['created'] ? 201 : 200);
    }

    public function unlink(string $id): JsonResponse
    {
        $this->social->unlink(Actor::requireCustomer(), Ids::isUuid($id) ? strtolower($id) : throw ApiProblem::notFound(), request()->ip());

        return response()->json(null, 204);
    }

    public function setPassword(Request $request): JsonResponse
    {
        $d = $request->validate([
            'password' => ['required', 'string', 'min:'.config('customer.password_min_length'), 'max:128'],
            'currentPassword' => ['nullable', 'string', 'max:128'],
        ]);
        $this->profile->setPassword(Actor::requireCustomer(), $d['password'], $d['currentPassword'] ?? null, $request->bearerToken(), $request->ip());

        return response()->json(['status' => 'ok']);
    }

    public function updateMe(Request $request): array
    {
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
        ]);

        return $this->profile->update(Actor::requireCustomer(), $d);
    }

    public function startEmail(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $this->profile->startEmail(Actor::requireCustomer(), $d['email']);

        return response()->json(['status' => 'verification_sent'], 202);
    }

    public function verifyEmail(Request $request): array
    {
        $d = $request->validate(['code' => ['required_without:token', 'nullable', 'string', 'regex:/^[0-9]{6}$/'], 'token' => ['nullable', 'string', 'max:200']]);

        return $this->profile->verifyEmail(Actor::requireCustomer(), $d['code'] ?? null, $d['token'] ?? null, $request->ip());
    }

    /** @return array<string, mixed> */
    private function validateClaims(Request $request, bool $needTerms = true): array
    {
        return $request->validate([
            'provider' => ['required', 'string', 'in:google,facebook,apple'],
            'providerUserId' => ['required_without:idToken', 'nullable', 'string', 'min:1', 'max:191'],
            'idToken' => ['nullable', 'string', 'max:8192'],
            'nonce' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'emailVerified' => ['required_without:idToken', 'nullable', 'boolean'],
            'givenName' => ['nullable', 'string', 'max:100'], 'familyName' => ['nullable', 'string', 'max:100'], 'name' => ['nullable', 'string', 'max:200'],
            'avatarUrl' => ['nullable', 'string', 'max:1024'],
            'phone' => ['nullable', 'string', 'max:32'],
            'marketingConsent' => ['nullable', 'boolean'],
            'termsAccepted' => [$needTerms ? 'required' : 'nullable', 'boolean'],
            'clientIp' => ['nullable', 'ip'], 'userAgent' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
