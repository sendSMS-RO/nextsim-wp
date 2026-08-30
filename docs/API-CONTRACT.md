# nextSIM Reseller API — Contract for the WooCommerce plugin

This is the bridge between the nextSIM Laravel platform (the eSIM reseller backend) and
this WooCommerce plugin. The plugin is a **client** of this API; it implements no eSIM
logic. Extracted from the live controllers in the `esim` repo (`routes/api.php`,
`app/Http/Controllers/Api/v2/*`). Keep this file in sync if the backend changes.

## 1. Authentication

- Laravel Passport bearer token, **one per reseller**. Every request sends:
  - `Authorization: Bearer <RESELLER_API_TOKEN>`
  - `Accept: application/json`
- The reseller creates the account and token on the nextSIM platform, then pastes the
  token into the plugin's setup screen. No account + token = the plugin can do nothing.
- **Base URL:** `https://nextsim.eu/api` (stored as a plugin setting; confirm host).
- Tokens carry an `is_test` flag on the backend (test vs live).

### Error envelope
- `401` unauthorized, `422` validation `{ "errors": true, "reasons": { "field": ["msg"] } }`,
  `429` throttled (60 req/min), `403` forbidden, `404` not found, `409` conflict.
- Backend update (local, pending deploy): every error also carries `message` +
  `error_code` consistently. The client reads both shapes; see docs/API-IMPROVEMENTS.md.
- `perPage` on the plan list: max 500 (backend update, pending deploy).

## 2. Endpoints used by the plugin

| Purpose | Endpoint |
|---|---|
| Validate token / account | `GET /api/v1/me` |
| Reseller credit balance | `GET /api/v1/balance/balance` -> `data.balance` (EUR string) |
| **List plans (cron import, paginated)** | `GET /api/v2/packages/esim` |
| Countries (categories) | `GET /api/v2/countries/available` -> `country_name`, `country_code` |
| Regions | `GET /api/v1/regions` -> `region`, `region_code`, `countries[]` |
| **Create order / top-up (async)** | `POST /api/v2/packages/esim/activate` |
| **Fetch QR / install links** | `GET /api/v2/packages/esim/order/{orderToken}/info` |
| Top-up compatibility | `GET /api/v2/packages/esim/{activationCode}/compatibility/{packageId}` |
| Consumption | `GET /api/v2/packages/esim/{activationCode}/balance` |

### 2.1 List plans — `GET /api/v2/packages/esim`
- Paginated as a Laravel **resource collection** (verified against the backend fixture
  `storage/responses/packages/listv2.json`):
  `{ data: [plans], links: {...}, meta: { current_page, last_page, total, per_page } }`
  — pagination lives in top-level `meta`, NOT under `data` (the controller docblock is
  misleading). `Package_Iterator` parses both shapes defensively.
- `data_limit_gigabytes` is a number OR the string `"Unlimited"` for unlimited plans.
- Query: `perPage` (required), `page`, `country` (ISO), `regionCode`, `route` (provider),
  `orderBy`, `orderDirection`.
- Plan fields the plugin maps to a product:
  `id`, `display_name`, `reseller_price` (what the reseller pays), `rrp_price` (suggested
  retail), `cost` (DEPRECATED), `data_limit_gigabytes`, `data_unit`, `data_cap`/`data_cap_per`,
  `period_days`, `can_top_up`, `msisdn_available`, `allow_multi_esim`, `extra_esim_price`,
  `max_esims_per_order`, `location_zone_name` (-> product category), `route`,
  `operators[]` `{country, operator_name}`.

### 2.2 Create order / top-up — `POST .../activate`
- Body: `packageId` (int, required), `activationCode` (nullable — **presence = TOP-UP**),
  `callbackUrl` (nullable), `quantity` (2..5, Multi-eSIM), `activationDate` (nullable).
- **Async.** Returns `order_token`, `status`, `cost`, `created_at`, `plan_size` — NOT the
  QR. `409` with `error_code: esim_expired` + `buy_new_package_id` when topping up an
  expired eSIM (no credit charged). NOTE: on that 409, `reasons` is a plain STRING (not
  the usual field=>messages map used by 422 validation errors).

### 2.3 Fetch QR — `GET .../order/{orderToken}/info`
- Backend update (local, pending deploy): the response now includes `status` (display
  string), `status_code` (int) and `status_key`
  (`new|canceled|paid|in_progress|completed|refunded`). The plugin fails fast on
  `canceled`/`refunded` and falls back to the QR-fields readiness check when the field
  is absent (production today): QR/activation fields are null until COMPLETED.
- Fields: `esim_activation_code`, `esim_url_qr_code` (LPA string for the QR),
  `esim_apple_install_url`, `esim_android_install_url`, `esim_smdp_server`,
  `user_activation_date`, `user_activation_end_date`, `msisdn`, `iccid`, `order_token`,
  `cost`, `route`, `plan_size`, `plan_members[]` (one per eSIM in a Multi-eSIM; each has
  its own activation code + QR + `is_creator`).

## 3. Backend behaviors that shape the plugin

1. **Activation is async** — never expect the QR back from `activate`. Deliver via polling
   `getOrderInfo` (source of truth). Statuses: `NEW`/`PAID`/`IN_PROGRESS` (QR null),
   `COMPLETED` (QR ready), `CANCELED`/`REFUNDED` (failed).
   (`app/Models/EsimOrder.php`).
2. **The activation callback webhook is UNSIGNED and NOT retried**
   (`app/Helpers/EsimActivationHelper.php`). Treat it as an untrusted best-effort ping
   that only reschedules a poll; never trust its payload.
3. **Rate limit** = 60 req/min (per IP, or per-user `rate_limit`)
   (`app/Providers/AppServiceProvider.php`). Importer: >=1s spacing + 429 backoff.
4. **Pricing:** `reseller_price` is the reseller's cost (EUR credits); `rrp_price` is the
   suggested retail. This plugin sells at `reseller_price * (1 + markup%)` unless the
   product is set to manual pricing.

## 4. Top-up flow (manual activation code)

Same as nextsim.eu: the customer manually types their Top-up activation code at purchase.
1. Customer enters `activationCode` in a "Top-up activation code" field.
2. Plugin calls the compatibility endpoint at add-to-cart and rejects the code before
   payment (fail-closed on API errors; 404 = unknown/foreign code, `reason: expired`
   = expired profile). Note: for Vodafone packages the backend's expiry pre-check is
   a no-op, so an expired Vodafone profile passes this check and fails at provisioning.
3. On order paid, plugin calls `activate` with `activationCode` set (the
   provisioning-time compatibility check remains as the safety net for codes that
   expire between add-to-cart and payment).
4. `409 esim_expired` -> no charge; offer `buy_new_package_id` as a fresh eSIM.
