# Customer / public API (`app/Domain/Customer`)

The surface the booking website (`007resort-booking-web`) needs. Same engine as Reception: online customers hold slots through
`BookingService::hold` (channel `ONLINE`, `BOOKING_CLOUD_ENABLED` semantics unchanged), pay through the Payments Paystack flow, and
`PaymentCaptured` confirms the booking / activates the membership / issues the tickets. Contract: `docs/openapi/v1.yaml` (`x-additive`).

## Credentials (three separate, non-interchangeable token families)

| Family | Prefix | Guard | Table | Reaches |
| --- | --- | --- | --- | --- |
| Staff | `r7a_` | `staff` | `session` | staff endpoints |
| Customer | `r7c_` (refresh `r7x_`) | `customer` | `customer_session` | `/customer/*`, `/public/ticket-orders`, plus the contract paths below (ownership enforced) |
| Website service token | `r7s_` | `service` | `service_token` | scope `public.read`: GET-only public reads; `public.checkout`: guest checkout writes (`docs/GUEST_CHECKOUT.md`); `customer.social`: social sign-in endpoints (docs/CUSTOMER_SOCIAL_LOGIN.md) |

A customer token can never satisfy `auth:staff` (401), a staff token never `auth:customer` (401); a customer/service token on a route
that admits it but needs a staff permission gets 403 `permission_denied`. Tests: `tests/Feature/Customer/CustomerAuthTest.php`.

## Endpoints

* `GET /public/site` (no auth, cached 15 s): name, contact (`PUBLIC_CONTACT_*`), currency, per-facility `onlineBookable/onlineBooking/onlineTickets/sameDayAvailable/onlineNotice`, `siteAvailability` (SiteAvailability heartbeat gate).
* `POST /customer/auth/register|verify|verify/resend|login|refresh|logout|forgot|reset`, `GET /customer/me`.
* `GET /customer/bookings|entitlements|memberships`, `GET /customer/orders/{id}`.
* `POST /public/ticket-orders` (customer token; `Idempotency-Key`): `{facilityId, visitDate, lines:[{productId,quantity}]}` -> unpaid ONLINE order priced by the server. `facilityId` is the facility the ticket gives ACCESS to (pool); the selling facility (Reception) is resolved from `product_facility`. Same-day visits are refused `409 site_offline` when the Local node heartbeat is stale (Cloud node). Pay with `POST /payments/paystack/initialize {orderIds:[id], amount}`.
* Contract paths that now admit customers (404 for anything not theirs): `POST /bookings/hold`, `GET /bookings/{id}`, `POST /bookings/{id}/confirm|cancel|reschedule`, `POST /payments/paystack/initialize`, `GET /payments/paystack/verify/{reference}`, `GET /entitlements/{id}`, `POST /memberships`, `GET /memberships/{id}`.
* Customer OR service token, GET only: `GET /bookings/resources[/{id}/availability]` (online-bookable resources only, no authority config), `GET /catalog/products` (tickets only, `ticketCategory` ADULT|CHILD), `GET /memberships/plans` (active only).
* Every booking now carries `customerId` and `policy {canCancel, canReschedule, cancelBy, rescheduleBy, refundAmount, cancellationFee, reschedulesLeft, note}` computed by `Booking\Services\BookingPolicyView` from the same rules cancel/reschedule enforce.
* Staff (`config.manage`): `GET/POST /service-tokens`, `POST /service-tokens/{id}/rotate|revoke`; CLI `php artisan r007:service-token create|rotate|revoke|list`.

## Social sign-in

Google/Facebook sign-up and sign-in (website-run OAuth, server-to-server calls with a `customer.social` service token, optional Google idToken
verification, identities, profile completion, email add-by-code): see **docs/CUSTOMER_SOCIAL_LOGIN.md**.

## Security decisions

* Argon2id passwords (min 10 chars), 5 failed logins -> 15 min lock, dummy hash for unknown emails, no user enumeration (register/forgot/resend answer identically; login reveals "not verified" only after a correct password).
* Verification: 6-digit code (HMAC-keyed with `APP_KEY`, bound to the token row, 5 attempts, 30 min) or long link token; reset: single-use 60 min link token; reset revokes all sessions.
* Tokens stored as SHA-256 only; refresh rotation with reuse detection (chain revocation + CRITICAL security event). Customer access TTL defaults to 8 h (`CUSTOMER_ACCESS_TTL_MINUTES`) because the website keeps the token server-side.
* Credential endpoints do not use the `idempotency_record` table (its request hash would fingerprint the password); they are naturally idempotent. Every other mutation takes `Idempotency-Key`, namespaced per customer.
* Ownership is enforced in queries (`WHERE customer_id = me`) or by `Owns::*` (404, never 403). Customers book/buy as THEMSELVES: name/email come from the account, never the request body; Paystack email is the account email; customers cannot send tenders.
* Rate limits: register, login, verify, forgot, refresh, per-customer API, public site (`RateLimiter::for` in `CustomerServiceProvider`).
* Audit + outbox in the business transaction: `customer.register|verify|login|password.forgot|reset`, `customer.ticket_order.create`, `service_token.*`; outbox `CustomerRegistered`, `CustomerEmailVerified`, `OrderCreated`, plus the existing booking/payment/entitlement events. Secrets never enter audit/outbox.
* `CUSTOMER_ALLOWED_CALLBACK_HOSTS` (optional) restricts Paystack `callbackUrl` hosts.

## Env

`CUSTOMER_WEB_URL` (email links), `CUSTOMER_ACCESS_TTL_MINUTES`, `CUSTOMER_REFRESH_TTL_DAYS`, `CUSTOMER_MAX_FAILED_LOGINS`, `CUSTOMER_LOCKOUT_MINUTES`, `CUSTOMER_ALLOWED_CALLBACK_HOSTS`,
`SERVICE_TOKEN_GRACE_HOURS`, `PUBLIC_SITE_NAME`, `PUBLIC_CONTACT_PHONE|EMAIL|ADDRESS|MAP_URL`, `PUBLIC_OPENING_HOURS`. Mail: `MAIL_MAILER=log` in dev (the code is in `storage/logs`).
`php artisan r007:demo-seed` also creates the DEV-ONLY service token `r7s_dev_booking_web`.

## Known gaps

Paystack live keys (owner); refunds of cancelled paid bookings are recorded (`refundDue` in audit/outbox) but the Paystack refund API call is not made (Payments known gap);
guest (anonymous) checkout is now offered: see `docs/GUEST_CHECKOUT.md` (service token scope `public.checkout`); real SMS provider / SMTP delivery of tickets; Wallet passes.
