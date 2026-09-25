# Customer social sign-up / sign-in (Google, Facebook; Apple later)

Contract for the website (`007resort-booking-web`). Everything is under `/api/v1`, camelCase JSON, RFC 7807 `application/problem+json`
errors with a stable `code`. Implementation: `app/Domain/Customer` (`SocialLoginService`, `IdTokenVerifier`), migration
`2026_09_26_*_customer_social_login`. Existing credential model is unchanged (see `CUSTOMER_PUBLIC_API.md`).

## 1. Model

* The **website performs the OAuth authorization-code flow** (it is the confidential client) and then calls the API **server-to-server**
  with its service token. Browsers never call the social endpoints (they cannot: they hold no `r7s_` token).
* A customer (`customer` + `customer_account`) can have **0..n identities** (`customer_identity`) and **optionally a password**
  (`customer_account.password_hash` NULL = social-only). At least one login method must remain.
* The session returned is exactly the password-login `AuthResult` (`r7c_` access + `r7x_` refresh, the site keeps them server-side) plus flags.

## 2. Service token scopes (IMPORTANT for deployment)

`service_token.scope` is now a comma-set of: `public.read` (existing, GET-only public reads) and `customer.social` (new: may call the
social endpoints below). A token without `customer.social` gets `403 scope_denied` there; a `customer.social`-only token cannot read
the public GET endpoints. Existing tokens keep working unchanged (`public.read`).

Issue the website token with both: `php artisan r007:service-token create --name=booking-web --scope=public.read,customer.social`
(rotation keeps the scope). The dev token `r7s_dev_booking_web` (demo seeder) has both scopes.

## 3. Endpoints

### 3.1 `GET /public/customers/social/providers` (service token, any scope)

```json
{ "providers": [ { "id": "google", "enabled": true, "idTokenVerification": true, "emailTrusted": true },
                 { "id": "facebook", "enabled": true, "idTokenVerification": false, "emailTrusted": false } ] }
```
`enabled` = listed in `SOCIAL_PROVIDERS_ENABLED`. Hide buttons for providers not `enabled`. `emailTrusted`: whether the API honours
`emailVerified:true` from the website for that provider (see 4). Known provider ids: `google`, `facebook`, `apple` (Apple needs no migration).

### 3.2 `POST /public/customers/social/login` (service token, scope `customer.social`; rate-limited; audited)

Request (claims mode):
```json
{ "provider": "google", "providerUserId": "1098...", "email": "Ada@Example.com", "emailVerified": true,
  "givenName": "Ada", "familyName": "Lovelace", "name": "Ada Lovelace", "avatarUrl": "https://...", "phone": "+2348012345678",
  "marketingConsent": false, "termsAccepted": true, "clientIp": "203.0.113.9", "userAgent": "Mozilla/..." }
```
* required: `provider`, `providerUserId` (string 1-191, the stable OAuth `sub`/Facebook `id`; never the email), `emailVerified` (bool), `termsAccepted` (bool).
* `termsAccepted` must be `true` **when a new customer would be created** (`422 terms_not_accepted`); ignored for known identities.
* `email` optional (Facebook may omit it). `emailVerified:true` without `email` is `422 email_verified_without_email`.
* `clientIp`/`userAgent` (optional) are the END USER's, recorded on the session/audit (the API otherwise sees the website's IP).

Request (Google idToken mode, server-side verification, see 5): `{ "provider":"google", "idToken":"<JWT>", "nonce":"...?", "termsAccepted":true, "phone":"...?", "marketingConsent":false }`.
When `idToken` is present, `providerUserId/email/emailVerified/name/avatarUrl` claims are **ignored** and taken from the verified token.
(`emailVerified` is then not required.)

Success: `200` (existing customer signed in / identity linked) or `201` (new customer created). Body:
```json
{ "accessToken": "r7c_...", "refreshToken": "r7x_...", "tokenType": "Bearer", "expiresInSeconds": 28800,
  "accessTokenExpiresAt": "...Z", "refreshTokenExpiresAt": "...Z",
  "customer": { "id": "uuid", "name": "Ada Lovelace", "email": "ada@example.com|null", "phone": "..|null", "emailVerified": true, "createdAt": "...Z" },
  "isNewCustomer": true, "linkedExisting": false,
  "needsProfileCompletion": false, "missing": [], "recommended": ["phone"],
  "emailSuggestion": null,
  "identity": { "id": "uuid", "provider": "google", "linkedAt": "...Z" } }
```
* `linkedExisting`: this call attached the identity to a pre-existing customer (rule 2).
* `missing` (required to be able to use the whole site) may contain `email`, `name`. `needsProfileCompletion = missing != []`.
  `recommended` may contain `phone` (not required).
* `emailSuggestion`: an email the provider gave but that is NOT verified (or the provider is untrusted): pre-fill the "add your email" form with it.
* `customer.email` is `null` and `emailVerified:false` for a customer without a verified email. Such a customer **can sign in and browse/manage the
  profile, but cannot create ticket orders / pay** (`409 profile_incomplete`, `missing:["email"]`) until they add + verify an email.

Errors: `401 unauthenticated`, `403 scope_denied`, `422 validation_failed`, `422 provider_disabled`, `422 terms_not_accepted`,
`422 email_verified_without_email`, `422 profile_insufficient` (neither name nor email available), `422 id_token_required|id_token_unsupported|id_token_invalid`
(`meta.reason`: `malformed|bad_signature|expired|wrong_audience|wrong_issuer|nonce_mismatch|unknown_key|jwks_unavailable`),
`409 account_link_requires_confirmation`, `409 identity_conflict`, `423 account_locked` (inactive/locked account), `429 too_many_requests`.

### 3.3 Matching rules (evaluated in this order, in one DB transaction, idempotent)

1. **Known identity** (`provider`+`providerUserId`) -> sign in that customer (refresh `avatar`, `last_login_at`). Email changes at the provider are ignored (identity, not email, is the key).
2. Else if the email is **verified** (see 4) **and** a customer account with that (lower-cased) email exists:
   * account email verified, OR account has no password (`password_hash` NULL) and no successful login yet -> **link** the identity, mark the account email verified, sign in. (`linkedExisting:true`.)
   * account email **unverified AND it has a password** -> **do not link**: `409 account_link_requires_confirmation`. The API emails a one-time code + link to that address (owner proven by mailbox);
     body `meta`: `{ "confirmation": { "method": "email_code", "maskedEmail": "a***@example.com", "expiresInSeconds": 1800 } }`.
     The website shows "enter the code we emailed you" and calls 3.4. Nothing was linked and no session was issued.
   * the customer already has a *different* identity for the same provider -> `409 identity_conflict`.
   * a walk-in `customer` row with this email but **no account** is adopted (a `customer_account` is created for it).
3. Else **create** a customer (+account, +identity), email verified only if the provider verified it. Name = `name` || `givenName familyName` || email local-part (then `missing:["name"]`).
   *No email / unverified email*: the customer is still created with `login_email = NULL` (no placeholder addresses anywhere), `missing:["email"]`, `emailSuggestion` set when the provider gave one.
   The email is added later by the authenticated flow in 3.7. **An unverified email is never stored as a login email and never used to match or link.**

Concurrent first logins for the same identity/email are safe (unique constraints + retry): exactly one customer, one identity, both callers get a session.

### 3.4 `POST /public/customers/social/link/confirm` (service token, scope `customer.social`)

`{ "email": "ada@example.com", "code": "123456" }` or `{ "token": "<link token>" }` -> completes the pending link created by a 409 above and returns the
same body as 3.2 (`200`, `linkedExisting:true`). Because the mailbox owner is now proven, **the unverified pre-registered password is removed** (`password_hash = NULL`,
all existing sessions of that account revoked); the person can add their own password via reset / 3.6. Errors: `422 invalid_link_confirmation` (wrong/expired/used; code attempt-limited to 5, TTL 30 min), `429`.
The pending link stores only provider, provider user id and the non-secret profile hints (no tokens).

### 3.5 Authenticated customer endpoints (`Authorization: Bearer r7c_...`)

* `GET /customer/me/identities` -> `{ "items": [ { "id", "provider", "email", "emailVerified", "avatarUrl", "linkedAt", "lastLoginAt" } ], "hasPassword": true, "canUnlink": true }`.
* `POST /customer/me/social/link` (link another provider; `Idempotency-Key`). Two ways to authenticate, because claims are only trusted from the website:
  * website: `Authorization: Bearer r7s_...` (scope `customer.social`) **plus** header `X-Customer-Token: r7c_...` (the signed-in customer) and the claims body of 3.2 (`provider, providerUserId, email?, emailVerified, avatarUrl?`);
  * or Google `idToken` mode with the customer bearer alone (a verified token needs no service token).
  Returns `201 { "identity": {...} }` (`200` if the same identity is already linked to this customer).
  Errors: `409 identity_already_linked` (belongs to another customer), `409 identity_conflict` (customer already has that provider), `422 provider_disabled`, `422 id_token_*`.
  The identity's email is informational; linking never changes the customer's login email.
* `DELETE /customer/me/identities/{id}` -> `204`. `409 last_login_method` when it would leave no way to sign in (no password and it is the last identity, or the password exists but the account email is not verified and it is the last identity). `404` for other customers' ids.
* `POST /customer/me/password` `{ "password": "...", "currentPassword": "...?" }` (customer bearer; min 10 chars). If the account already has a password `currentPassword` is required (`422 invalid_current_password`).
  Social-only accounts can set one without it, **only if the account email is verified** (`409 email_not_verified` otherwise). Other sessions are revoked, the current one is kept. Or use the existing `forgot`/`reset` flow (works for social-only accounts with a verified email).
* `PATCH /customer/me` `{ "name"?, "phone"? }` -> profile (`Customer`). `422` if phone is used by another customer.
* `POST /customer/me/email` `{ "email": "..." }` -> `202`; emails a 6-digit code + link to that address (1/min). `409 email_in_use` if another account uses it (ask them to sign in with that account and link the provider there).
* `POST /customer/me/email/verify` `{ "code": "123456" }` (or `{ "token": "..." }`) -> `200` `Customer`; sets `login_email` + verified, fixes `missing`. `422 invalid_verification`.

### 3.6 Password login for social-only accounts

`POST /customer/auth/login` answers the same generic `401 invalid_credentials` (no enumeration, same timing). The website copy should read:
"If you signed up with Google/Facebook, use that button, or reset your password to add one" and link to the existing forgot-password page (`POST /customer/auth/forgot` works for social-only accounts that have a verified email).

### 3.7 Optional: `POST /customer/auth/social/token` (no service token; mobile app later)

Google `idToken` only (claims are refused: `422 id_token_required`). Disabled unless `SOCIAL_ID_TOKEN_PUBLIC_ENABLED=true` (then `404`). Same body/response as 3.2. Rate-limited per IP.

## 4. Email-verified trust (account-takeover model)

* Claims from the website are trusted only from a service token with `customer.social` (server-to-server); browsers cannot reach the endpoint.
* `emailVerified:true` is honoured only for providers in `SOCIAL_TRUSTED_EMAIL_PROVIDERS` (default `google`; Apple's `email_verified` may be added).
  For other providers (default Facebook) the email is treated as **unverified**: never used to link, never stored as login email; it is offered as `emailSuggestion` and must be verified by code (3.5).
* `SOCIAL_REQUIRE_ID_TOKEN=google` (optional) refuses claims mode for the listed providers (forces server-verified tokens).
* Identity key is `(provider, providerUserId)`; `UNIQUE(provider, provider_user_id)` and `UNIQUE(customer_id, provider)`.
* Pre-registration hijack (attacker registers victim's email with a password, never verifies): rule 2 refuses silent linking, and the confirm step wipes that password.
* Provider access/refresh tokens are **never** received or stored. The `idToken` itself is never stored or logged.

## 5. Google `idToken` verification (`SOCIAL_GOOGLE_CLIENT_ID`)

RS256 only; signature via Google JWKS (`SOCIAL_GOOGLE_JWKS_URL`, default `https://www.googleapis.com/oauth2/v3/certs`, cached per `Cache-Control` (min 5 min, max 24 h), one forced refetch per minute on unknown `kid`);
`iss` in {`https://accounts.google.com`, `accounts.google.com`}; `aud` = one of the configured client ids (comma list); `exp`/`iat`/`nbf` with 60 s leeway; `sub` required;
`nonce` compared if the caller supplies `nonce`; `email_verified` (bool or "true") maps to `emailVerified` (false => treated as unverified, not an error). Not configured => `422 provider_disabled`.

## 6. Security, limits, audit

* Rate limits: `social-login` 120/min per service token, 20/min per `provider|providerUserId`, 30/min per `clientIp`(if given); `social-confirm` 10/min per email+ip; profile/email/password endpoints use `customer-api` plus a per-customer 5/min limit on email/password changes.
* Audit rows (same transaction, hash chained, no PII beyond the platform norm, never tokens): `customer.social.register`, `customer.social.login`, `customer.social.link`, `customer.social.link.pending`, `customer.social.link.confirmed`, `customer.social.unlink`, `customer.password.set`, `customer.email.change`; failures as security events `CUSTOMER_SOCIAL_REJECTED` (reason) . Outbox: `CustomerRegistered` (new customers), `CustomerEmailVerified`.
* Erasure: identities are `ON DELETE CASCADE` on the customer and removed by `SocialLoginService::eraseIdentities()` (there is no customer-erasure endpoint yet).

## 7. Env

`SOCIAL_PROVIDERS_ENABLED=google,facebook` · `SOCIAL_TRUSTED_EMAIL_PROVIDERS=google` · `SOCIAL_REQUIRE_ID_TOKEN=` · `SOCIAL_GOOGLE_CLIENT_ID=` (comma list) · `SOCIAL_GOOGLE_JWKS_URL` ·
`SOCIAL_ID_TOKEN_PUBLIC_ENABLED=false` · `SOCIAL_LINK_CONFIRM_TTL_MINUTES=30`.

## 8. Website checklist

1. Perform the OAuth code flow (state + PKCE, verify `state`), read the provider's profile server-side, then call 3.2 with the service token.
2. Store `accessToken/refreshToken` like a password login. Branch on the `409 account_link_requires_confirmation` -> code screen -> 3.4.
3. If `needsProfileCompletion`: send the user to a "complete profile" form (`PATCH /customer/me`, `POST /customer/me/email` + `/verify`); `recommended:["phone"]` is a soft prompt.
4. Account page: `GET /customer/me/identities`, link (3.5, send `X-Customer-Token`), unlink, set password.
5. Only show buttons for `enabled` providers (3.1). Send the end user's IP as `clientIp`.
