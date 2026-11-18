# Technical Summary — PromptEdit × Pixelforge

Laravel 13 · PHP 8.5 · Inertia v3 + Vue 3 · PostgreSQL 18 · Redis · Pest 5 · PHPStan level 7

Signed-in users generate images through **Pixelforge**, an asynchronous image API priced per token. They pay Pixelforge's cost **plus 25%**. Every generation is recorded at the exact cost, never twice, and failed generations are free.

Design decisions and their reasoning: [`DECISIONS.md`](DECISIONS.md) (20 entries).
Project rules for agents: [`CLAUDE.md`](CLAUDE.md), [`.claude/rules/`](.claude/rules).

---

## 1. Status against the brief

| Requirement | Status | Where |
|---|---|---|
| Pricing: cost + 25%, estimate before submit, actual cost after, failures free | ✅ Backend done | [§4](#4-money) |
| Webhooks: signature check, duplicates and out-of-order delivery handled | ✅ Done | [§6.2](#62-webhook-ingestion) |
| Robustness: limits, overload, failures, missed webhooks | ✅ Done | [§6.1](#61-submission), [§6.3](#63-poller-safety-net) |
| Generate page (form + price before submit) | ✅ Done: `/generate`, live estimate, double-submit guard | `GenerationController`, `GenerationEstimateController`, `pages/generations/Create.vue` |
| History page (live status, estimated vs actual cost, image) | ✅ Done: `/generations`, polling while in flight; ⏳ images not stored yet (24h URL) | `GenerationHistoryQuery`, `pages/generations/Index.vue` |
| Deployment | ❌ Not started | [§10](#10-open-work) |
| Stretch: admin report, balances | ❌ Not started (report index exists) | [§10](#10-open-work) |

Tests: **402** (unit + feature on real Postgres). PHPStan level 7: 0 errors. Pint: clean.

---

## 2. Architecture (hexagonal)

```
Http / Jobs / Console  ──►  Application (UseCases + Ports)  ──►  Domain (pure PHP)
        (driving)                                                   ▲
Infrastructure (Pixelforge HTTP, Eloquent, inbox)  ──implements──► Application\Ports
```

| Layer | Path | Rule |
|---|---|---|
| Domain | [`app/Domain`](app/Domain) | Pure PHP. No `Illuminate\*`, no facades, no helpers. `strict_types`. |
| Application | [`app/Application`](app/Application) | Use cases and port interfaces. No Eloquent, facades or HTTP. |
| Infrastructure | [`app/Infrastructure`](app/Infrastructure) | Adapters. The only code that knows Pixelforge JSON or table columns. |
| Driving adapters | [`app/Http`](app/Http), [`app/Jobs`](app/Jobs), [`app/Console`](app/Console) | Thin: validate input, call a use case, map the response. |
| Wiring | [`app/Providers/HexagonServiceProvider.php`](app/Providers/HexagonServiceProvider.php) | Binds ports to adapters, defines the submit rate limiter. |

These rules are enforced on every edit by [`.claude/hooks/enforce-architecture.sh`](.claude/hooks/enforce-architecture.sh), which checks dependency direction, `strict_types`, and that money code uses no floats and does no division outside `Money` / `RoundingPolicy`. Full spec: [`.claude/rules/architecture.md`](.claude/rules/architecture.md).

---

## 3. File map

### 3.1 Domain — [`app/Domain`](app/Domain)

**Billing** — [`app/Domain/Billing`](app/Domain/Billing)

| File | Responsibility |
|---|---|
| [`Money.php`](app/Domain/Billing/Money.php) | Non-negative integer **nanodollars** (`NANOS_PER_DOLLAR = 1e9`, line 18). Parses decimal strings without floats (`fromDecimalString`, line 42). Adds and multiplies with overflow checks (`plus`, `times`). Formats as a 9-decimal string (`toDecimalString`, line 102). |
| [`RoundingPolicy.php`](app/Domain/Billing/RoundingPolicy.php) | The **only** place integer division happens: `divideHalfUp` (line 19), `divideFloor` (36), `divideCeil` (52). |
| [`TokenRates.php`](app/Domain/Billing/TokenRates.php) | Converts each published "$ per 1M tokens" rate to whole nanodollars per token, rejecting rates with more than 3 decimals. `costOf()` (line 46) is Σ tokens × rate, with **no division**. |
| [`TokenUsage.php`](app/Domain/Billing/TokenUsage.php) | Text, image, output and reasoning token counts (all non-negative). |
| [`TokenRange.php`](app/Domain/Billing/TokenRange.php) | Inclusive min..max range of token counts. |
| [`PricingSchedule.php`](app/Domain/Billing/PricingSchedule.php) | Pixelforge's token rules: text ≈ `ceil(chars/4)` ±15% (`textTokenRange`, line 82, rounded outward to whole tokens), output table, reasoning range per quality, 765 tokens per reference image. |
| [`Markup.php`](app/Domain/Billing/Markup.php) | `chargeFor()` (line 24) = cost × (100 + %) / 100, rounded **half up once**. |
| [`CostEstimate.php`](app/Domain/Billing/CostEstimate.php) | Min/max provider cost and charge before submit (`for`, line 24). |
| [`GenerationCost.php`](app/Domain/Billing/GenerationCost.php) | Final provider cost and charge: `forUsage()` (line 14), `free()` (24). |

**Generation** — [`app/Domain/Generation`](app/Domain/Generation)

| File | Responsibility |
|---|---|
| [`Generation.php`](app/Domain/Generation/Generation.php) | The entity, holding all state rules (see below). |
| [`GenerationStatus.php`](app/Domain/Generation/GenerationStatus.php) | `pending → queued → processing → succeeded \| failed`. Final states are terminal; `isBefore()` gives the order. |
| [`GenerationRequest.php`](app/Domain/Generation/GenerationRequest.php) | Validated input: prompt 1–4000 Unicode chars, ≤4 http(s) reference images, size, quality. |
| [`ImageSize.php`](app/Domain/Generation/ImageSize.php), [`Quality.php`](app/Domain/Generation/Quality.php) | Enums matching Pixelforge's values. |
| [`ProviderUpdate.php`](app/Domain/Generation/ProviderUpdate.php) | Pixelforge's view at one moment. Validated: a succeeded update needs usage, rates and `completedAt`; a failed one needs an error. |
| [`GenerationFailure.php`](app/Domain/Generation/GenerationFailure.php) | Error code and message. Own codes: `submission_abandoned`, `submission_unconfirmed` (**provisional**), `submission_not_found`, `provider_rejected_request`. |
| [`InvalidGenerationRequest.php`](app/Domain/Generation/InvalidGenerationRequest.php), [`ProviderLinkConflict.php`](app/Domain/Generation/ProviderLinkConflict.php) | Domain exceptions. |

Rules in `Generation`:
- `apply()` (line 42): idempotent and monotonic. Status only moves forward, and a final state is never left. The one exception: a provisional (unconfirmed) local failure is replaced by Pixelforge's state. On `succeeded`, the cost is computed once with the **markup fixed at creation**; on `failed`, the cost is 0.
- `recordUncertainSubmission()` (77): marks a pending generation whose submit outcome is unknown (timeout, 5xx or unreadable 202).
- `abandonSubmission()` (93): the failure is final if Pixelforge only ever answered "busy", provisional if any attempt had an unknown outcome.
- `confirmNotAccepted()` (104): turns a provisional failure into the final `submission_not_found`.
- `failBeforeAcceptance()` (123): free failure while still pending.

### 3.2 Application — use cases and ports

**Ports** — [`app/Application/Ports`](app/Application/Ports)

| Port | Implemented by |
|---|---|
| [`ImageGenerationProvider`](app/Application/Ports/ImageGenerationProvider.php) — `submit`, `fetch`, `findSubmitted`, `pricing` | [`PixelforgeImageGenerationProvider`](app/Infrastructure/Pixelforge/PixelforgeImageGenerationProvider.php) |
| [`GenerationRepository`](app/Application/Ports/GenerationRepository.php) — persistence, locked loads and poller queries | [`EloquentGenerationRepository`](app/Infrastructure/Persistence/EloquentGenerationRepository.php) |
| [`ProviderEventInbox`](app/Application/Ports/ProviderEventInbox.php) — webhook event dedupe and processing | [`EloquentProviderEventInbox`](app/Infrastructure/Persistence/EloquentProviderEventInbox.php) |
| [`TransactionRunner`](app/Application/Ports/TransactionRunner.php) | [`LaravelTransactionRunner`](app/Infrastructure/Persistence/LaravelTransactionRunner.php) |

**Use cases** — [`app/Application/UseCases`](app/Application/UseCases)

| Use case | Purpose |
|---|---|
| [`EstimateGenerationCost`](app/Application/UseCases/EstimateGenerationCost.php) | Cost range for a request (for the generate page). |
| [`StartGeneration`](app/Application/UseCases/StartGeneration.php) | Saves a **pending** generation, with its estimate and markup, *before* anything is sent. |
| [`SubmitGeneration`](app/Application/UseCases/SubmitGeneration.php) | POSTs to Pixelforge. Handles a refusal (free failure), "busy" (rethrown for retry), and unknown or unreadable responses (marked uncertain). |
| [`ApplyProviderUpdate`](app/Application/UseCases/ApplyProviderUpdate.php) | **Single path** for submit responses, webhooks and polls. Locks the row (matched by provider id, else `metadata.generation_id`), applies, saves. |
| [`AbandonSubmission`](app/Application/UseCases/AbandonSubmission.php) | Gives up on submitting: a final or provisional free failure. |
| [`ReceiveProviderEvent`](app/Application/UseCases/ReceiveProviderEvent.php) | Records a verified webhook event, deduplicated by id. |
| [`ProcessProviderEvent`](app/Application/UseCases/ProcessProviderEvent.php) | Locks the event, applies it, marks it processed — all in one transaction. Malformed or conflicting events are marked as such instead of failing forever. |
| [`RefreshGeneration`](app/Application/UseCases/RefreshGeneration.php) | Poller: `GET` the generation → `ApplyProviderUpdate`. |
| [`ConfirmUnconfirmedSubmission`](app/Application/UseCases/ConfirmUnconfirmedSubmission.php) | Poller: look it up in Pixelforge's list. Found → apply. Not found → final free failure. Never re-POSTs. |

**Exceptions** ([`app/Application/Exceptions`](app/Application/Exceptions)):
- [`ProviderUnavailable`](app/Application/Exceptions/ProviderUnavailable.php): retry later. Carries `retryAfterSeconds` and `outcomeUnknown`.
- [`ProviderRefused`](app/Application/Exceptions/ProviderRefused.php): definitive refusal, nothing billed.
- [`MalformedProviderPayload`](app/Application/Exceptions/MalformedProviderPayload.php): the response or event could not be read.

**Data** ([`app/Application/Data`](app/Application/Data)): `ProviderEvent`, `ProviderEventOutcome` (`applied`, `unknown_generation`, `ignored`, `malformed`, `conflict`), `UnconfirmedSubmission`.

### 3.3 Infrastructure — adapters

| File | Responsibility |
|---|---|
| [`Pixelforge/PixelforgeImageGenerationProvider.php`](app/Infrastructure/Pixelforge/PixelforgeImageGenerationProvider.php) | HTTP client with timeouts. `submit` (line 49) sends `Idempotency-Key` and `metadata.generation_id`. `fetch` (69). `findSubmitted` (76) scans up to 3 list pages. `pricing` (112) is cached for 10 minutes. Maps every error code ([§7](#7-pixelforge-error-mapping)). |
| [`Pixelforge/PixelforgePayloadMapper.php`](app/Infrastructure/Pixelforge/PixelforgePayloadMapper.php) | Pixelforge JSON → `ProviderUpdate` / `PricingSchedule`. Strict types; variance `0.15` becomes 1500 basis points without floats. Every parse error becomes `MalformedProviderPayload`. |
| [`Pixelforge/PixelforgeWebhookSignature.php`](app/Infrastructure/Pixelforge/PixelforgeWebhookSignature.php) | HMAC-SHA256 of `"{t}.{rawBody}"`, compared with `hash_equals`. `TOLERANCE_SECONDS = 300` (line 14). `rejectionReason()` (36) explains failures for logging. |
| [`Persistence/EloquentGenerationRepository.php`](app/Infrastructure/Persistence/EloquentGenerationRepository.php) | Maps rows ↔ entities. `findForUpdate` / `findByProviderIdForUpdate` use `FOR UPDATE`. **`save()` (line 79) refuses rows not locked in the current transaction.** Stores UTC timestamps. Poller queries (92–134); `markChecked` does not touch `updated_at`. |
| [`Persistence/EloquentProviderEventInbox.php`](app/Infrastructure/Persistence/EloquentProviderEventInbox.php) | `record` (line 24) uses `insertOrIgnore` keyed by event id and stores the raw JSON. `findUnprocessedForUpdate` (40), `markProcessed` (78), `unprocessedReceivedBefore` (90). |
| [`Persistence/LaravelTransactionRunner.php`](app/Infrastructure/Persistence/LaravelTransactionRunner.php) | `DB::transaction(..., attempts: 3)`. |

### 3.4 Driving adapters

| File | Responsibility |
|---|---|
| [`app/Http/Controllers/PixelforgeWebhookController.php`](app/Http/Controllers/PixelforgeWebhookController.php) | `POST /webhooks/pixelforge`. Checks secret configured (500 if not) → body ≤ 64 KB (`MAX_BODY_BYTES`, line 19; 413 otherwise) → signature (400, logged with reason) → records the event → queues the job → 204. |
| [`app/Jobs/SubmitGenerationJob.php`](app/Jobs/SubmitGenerationJob.php) | Unique per generation; rate limited (`pixelforge-submissions`, 30/min). Releases after Retry-After (exponential backoff when missing, capped at 60s); `retryUntil` 15 min; `failed()` → `AbandonSubmission`. |
| [`app/Jobs/ProcessProviderEventJob.php`](app/Jobs/ProcessProviderEventJob.php) | Applies one event. Deliberately **not** unique (exactly-once comes from the row lock and `processed_at`). Logs events that were not applied, and failures. |
| [`app/Console/Commands/PollPixelforge.php`](app/Console/Commands/PollPixelforge.php) | `pixelforge:poll`, the safety net ([§6.3](#63-poller-safety-net)). Thresholds are constants on lines 37–53. |
| [`routes/webhooks.php`](routes/webhooks.php) | Webhook route, registered **outside** the `web` group (no session or CSRF) via `then:` in [`bootstrap/app.php`](bootstrap/app.php). The same file exempts `webhooks/*` from maintenance mode. |
| [`routes/console.php`](routes/console.php) | Schedules `pixelforge:poll` every minute, `withoutOverlapping`, `onOneServer`. |

### 3.5 Models and other changes

| File | Note |
|---|---|
| [`app/Models/Generation.php`](app/Models/Generation.php) | Persistence record only (UUID v7 key, casts). No business rules. |
| [`app/Models/User.php`](app/Models/User.php) | `SoftDeletes`; `anonymiseAndDelete()` keeps billing history; `generations()` relation. |
| [`app/Http/Controllers/Settings/ProfileController.php`](app/Http/Controllers/Settings/ProfileController.php) | Deleting an account anonymises and soft-deletes it, instead of a hard delete. |
| [`app/Providers/AppServiceProvider.php`](app/Providers/AppServiceProvider.php) | `Model::shouldBeStrict()` outside production. |
| [`config/services.php`](config/services.php) | The `pixelforge` block ([§8](#8-configuration)). |

---

## 4. Money

Spec: [`.claude/rules/money.md`](.claude/rules/money.md).

- **Unit:** `int` nanodollars everywhere (PHP and `bigint *_nanos` columns). Pixelforge's `cost_usd` has 9 decimals, so we reconcile exactly. No floats anywhere: hook-enforced, plus `strict_types`.
- **Provider cost:** Σ tokens × (rate per 1M × 1000) nanodollars per token, using the generation's **own** `rates`. Example: docs usage 12/765/6450/3184 → **305,510,000** nanos ($0.305510000).
- **Charge:** cost × 125 / 100, **half up**, rounded once. 305,510,000 → **381,887,500**. At current rates every cost is a multiple of 2,500 nanos, so rounding never actually happens.
- **Estimate:** min/max from the token rules. Docs worst case (high, 1536x1024, 400 chars, 2 refs): provider 286,225,000–**373,875,000**, charge 357,781,250–467,343,750.
- **Markup:** stored per generation (`markup_percent`) and fixed when the generation is created.
- **Failures:** cost 0 / 0, enforced by the domain **and** by a database CHECK constraint.

---

## 5. Data model (PostgreSQL)

### `generations` — [`2026_11_17_045102_create_generations_table.php`](database/migrations/2026_11_17_045102_create_generations_table.php)

| Columns | Purpose |
|---|---|
| `id uuid` PK | UUID v7. Also the `Idempotency-Key` and `metadata.generation_id`. |
| `user_id` FK `RESTRICT` | Billing rows survive account deletion. |
| `prompt`, `size`, `quality`, `reference_image_urls`, `markup_percent` | Request as submitted. |
| `status`, `provider_id` (unique), `submission_outcome_unknown` | Lifecycle and link to Pixelforge. |
| `estimated_{min,max}_{provider_cost,charge}_nanos` | Price shown before submit. |
| `input_text/input_image/output/reasoning_tokens`, `provider_cost_nanos`, `charge_nanos` | Actual usage and cost. |
| `image_url`, `image_url_expires_at`, `failure_code`, `failure_message` (text), `completed_at` (UTC), `last_polled_at` | Result, error and poller bookkeeping. |

**CHECK constraints** (the database enforces the billing rules):
- valid status values
- estimates non-negative and min ≤ max
- cost ≥ 0, and charge ≥ cost
- token counts non-negative
- usage is all or nothing
- cost is present ⇔ the status is final
- failed ⇒ 0 / 0
- succeeded ⇒ provider id, tokens and `completed_at` all present
- accepted ⇒ provider id present

**Indexes:**
- `(user_id, created_at, id)` for history pagination
- partial `generations_pollable (last_polled_at NULLS FIRST) WHERE status IN ('queued','processing')`
- covering partial `generations_report (completed_at) INCLUDE (user_id, charge_nanos, provider_cost_nanos) WHERE status='succeeded'`

### `provider_events` — [`2026_11_17_051930_create_provider_events_table.php`](database/migrations/2026_11_17_051930_create_provider_events_table.php)

- **`id`** (`evt_…`) primary key — the dedupe guarantee.
- **Other columns:** `type`, `payload` (jsonb, raw body), `received_at`, `processed_at`, `outcome`.
- **CHECK:** `outcome` is set exactly when `processed_at` is set.
- **Index:** partial index on unprocessed events, for the sweeper.

### `users` — [`2026_11_17_050958_add_soft_deletes_to_users_table.php`](database/migrations/2026_11_17_050958_add_soft_deletes_to_users_table.php)

Adds `deleted_at`.

**Locking:** every state change is `SELECT … FOR UPDATE` → domain rule → `save()`, inside one transaction. No HTTP call is ever made while holding a lock. Lock order is always event → generation, so there is no deadlock cycle.

---

## 6. Flows

### 6.1 Submission
1. `StartGeneration` saves a pending row with its estimate and markup ([StartGeneration.php](app/Application/UseCases/StartGeneration.php)). *Controller and dispatch: not built yet.*
2. `SubmitGenerationJob` → `SubmitGeneration` POSTs with `Idempotency-Key = id` ([SubmitGenerationJob.php](app/Jobs/SubmitGenerationJob.php), [SubmitGeneration.php](app/Application/UseCases/SubmitGeneration.php)).
3. **202** (or an idempotent replay) → `ApplyProviderUpdate` stores the `gen_` id and status.
4. **Busy** (429 rate/concurrency, 503 overloaded) → release after Retry-After. Nothing was created.
5. **Unknown outcome** (timeout, other 5xx, unreadable 202) → mark uncertain, release.
6. **Refused** (400 content policy, 429 daily limit, 422/409/401) → final free failure with a readable message.
7. Retries stop after 15 minutes → abandon: `submission_abandoned` (final), or `submission_unconfirmed` (provisional) if any attempt had an unknown outcome.

### 6.2 Webhook ingestion
1. [Controller](app/Http/Controllers/PixelforgeWebhookController.php): verify → `ReceiveProviderEvent` (insert-or-ignore) → dispatch the job if the event is unprocessed → **204** within milliseconds.
2. [`ProcessProviderEventJob`](app/Jobs/ProcessProviderEventJob.php) → [`ProcessProviderEvent`](app/Application/UseCases/ProcessProviderEvent.php): lock the event → map `data.object` → `ApplyProviderUpdate` → mark processed with an outcome. All one transaction.
3. **Duplicates:** the event PK, plus the generation is already final → no-op. **Out of order:** monotonic status. **Arrives before the submit response:** matched by `metadata.generation_id`. **Ping, unknown type, other environment:** 2xx, marked ignored or unknown.

### 6.3 Poller safety net

[`PollPixelforge`](app/Console/Commands/PollPixelforge.php) runs every minute:

| Step | Selects | Action |
|---|---|---|
| 1 | events unprocessed > 10 min | `ProcessProviderEvent` inline |
| 2 | pending > 40 min | `AbandonSubmission` |
| 3 | pending > 2 min | re-dispatch `SubmitGenerationJob` (the unique lock prevents duplicates) |
| 4 | queued/processing with no update or check for 45 s | `RefreshGeneration` (GET → apply) |
| 5 | `submission_unconfirmed` not checked for 2 min | `ConfirmUnconfirmedSubmission` (list lookup) |

Pixelforge calls are capped by a budget (`pixelforge-polls`, 20/min). Combined with 30/min for submits, the total is 50 of Pixelforge's 60. A "busy" answer stops the run, and one failing item never blocks the rest.

---

## 7. Pixelforge error mapping

Implemented in [`PixelforgeImageGenerationProvider::errorFor`](app/Infrastructure/Pixelforge/PixelforgeImageGenerationProvider.php).

| Response | Exception | Outcome |
|---|---|---|
| 429 `rate_limit_exceeded` / `concurrency_limit_exceeded`, 503 `overloaded` | `ProviderUnavailable` (outcome known) | Retry after Retry-After; nothing was created |
| Timeout / connection error / other 5xx | `ProviderUnavailable(outcomeUnknown: true)` | Retry with the same key; abandoning leaves a provisional failure |
| 429 `daily_limit_exceeded` | `ProviderRefused` | Free failure: "limit reached, try after midnight UTC" |
| 400 `content_policy_violation` | `ProviderRefused` | Free failure, showing Pixelforge's message |
| 401 / 409 / 422 / missing API key | `ProviderRefused(provider_rejected_request)` | Free failure; details logged |
| Unreadable JSON | `MalformedProviderPayload` | Submit marks uncertain; webhook marks `malformed` |

---

## 8. Configuration

[`config/services.php`](config/services.php) → `pixelforge`. Variable names are in [`.env.example`](.env.example).

| Key | Env | Default |
|---|---|---|
| `base_url` | `PIXELFORGE_BASE_URL` | `https://pixelforge.promptedit.dev/v1` |
| `key` | `PIXELFORGE_API_KEY` | — (one per environment) |
| `webhook_secret` | `PIXELFORGE_WEBHOOK_SECRET` | — (that key's secret) |
| `markup_percent` | `PIXELFORGE_MARKUP_PERCENT` | 25 |
| `timeout_seconds` / `pricing_cache_seconds` / `poll_requests_per_minute` | — | 15 / 600 / 20 |

Infrastructure:
- **Database:** `DB_CONNECTION=pgsql`; locally the OrbStack container `lerd-postgres` on port **5433**. Tests use `promptedit_testing` ([`phpunit.xml`](phpunit.xml)).
- **Cache:** `CACHE_STORE=redis`, with `REDIS_PREFIX=promptedit-` and `REDIS_DB=8` / `REDIS_CACHE_DB=9`, because the Redis instance is shared with other projects. Tests use the array cache.
- **CI:** a Postgres 18 service in [`.github/workflows/tests.yml`](.github/workflows/tests.yml).

---

## 9. Tests

Run with `php artisan test --compact` (needs Postgres running). Fixtures are copied from the Pixelforge docs: [`tests/Fixtures/Pixelforge`](tests/Fixtures/Pixelforge). Helpers live in [`tests/Pest.php`](tests/Pest.php):
- `pixelforgeDocsPricing()`, `pixelforgeDocsRates()`
- `pixelforgeFixture()`, `pixelforgeEvent()`
- `postPixelforgeWebhook()` — signs exactly like Pixelforge

| Area | Files |
|---|---|
| Money math (exact datasets, values computed independently in Python) | [`tests/Unit/Domain/Billing`](tests/Unit/Domain/Billing) |
| State rules (duplicates, out-of-order, provisional failures, markup fixed at creation) | [`tests/Unit/Domain/Generation`](tests/Unit/Domain/Generation) |
| Use cases with in-memory fakes ([`tests/Fakes`](tests/Fakes)) | [`tests/Unit/Application`](tests/Unit/Application) |
| Signature verification | [`tests/Unit/Infrastructure/Pixelforge`](tests/Unit/Infrastructure/Pixelforge) |
| Adapter error mapping, list lookup, pricing cache (`Http::fake`) | [`PixelforgeImageGenerationProviderTest`](tests/Feature/Infrastructure/Pixelforge/PixelforgeImageGenerationProviderTest.php) |
| Repository round-trips, CHECK constraints, locked-save rule, UTC | [`EloquentGenerationRepositoryTest`](tests/Feature/Infrastructure/Persistence/EloquentGenerationRepositoryTest.php) |
| Apply on Postgres (stale copy, replayed 202, long messages, markup) | [`tests/Feature/Application/UseCases/ApplyProviderUpdateTest.php`](tests/Feature/Application/UseCases/ApplyProviderUpdateTest.php) |
| Submit job (release, abandon, unconfirmed, backoff) | [`SubmitGenerationJobTest`](tests/Feature/Jobs/SubmitGenerationJobTest.php) |
| Webhook endpoint (27 scenarios) | [`PixelforgeWebhookControllerTest`](tests/Feature/Http/Controllers/PixelforgeWebhookControllerTest.php) |
| Poller (refresh, budget, busy, unconfirmed, sweeps) | [`PollPixelforgeTest`](tests/Feature/Console/Commands/PollPixelforgeTest.php) |
| Account anonymisation | [`ProfileUpdateTest`](tests/Feature/Settings/ProfileUpdateTest.php) |

---

## 10. Open work

1. ~~Generate page~~ done (DECISIONS #13, #14).
2. ~~History page~~ done (DECISIONS #15, #16). ~~Image storage~~ done (#17). ~~Backlog abandon message~~ done (#18).
3. **Live verification** once the Pixelforge key and secret are set:
   - tunnel → webhook URL in Pixelforge
   - test keywords `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`
   - `/reconcile` against `/v1/usage`
4. **Deploy** to Laravel Cloud:
   - queue worker, scheduler, Redis, production Pixelforge key and webhook
   - `PIXELFORGE_IMAGE_DISK` on object storage (not `local`)
   - queue worker: `queue:work --queue=default,images` (images are copied on the `images` queue)
   - leave a few generations for the evaluators
5. **Stretch:** admin report (index ready), then balances and "Buy credits".
6. **Housekeeping:**
   - nothing committed yet
   - `.gitignore` excludes `.claude/`, `CLAUDE.md`, `AGENTS.md` — decide whether to commit them

---

## 11. Claude tooling (in `.claude/`)

- **Hooks** — [`.claude/hooks`](.claude/hooks):
  - bash and write guards
  - Pint + `php -l`, `vp` format
  - architecture and money lint
  - `verify-done` (PHPStan + Pest + vue-tsc on changed files)
  - session status
- **Agents** — [`.claude/agents`](.claude/agents): `calculation-precision`, `query-optimizer`, `webhook-resilience`, `hexagonal-architect`, `pixelforge-integrator`, `test-engineer`, `ux-reviewer`.
- **Commands** — [`.claude/commands`](.claude/commands): `/feature`, `/check`, `/review`, `/money-audit`, `/reconcile`, `/scenarios`, `/webhook-replay`, `/deploy-check`, `/walkthrough`, `/decision`, `/scaffold-port`.
- **Workflows** — [`.claude/workflows`](.claude/workflows): `review-changes`, `verify-money`.
- **Pixelforge API reference skill** — [`.claude/skills/pixelforge-api`](.claude/skills/pixelforge-api).
- **Scripts** — [`.claude/scripts`](.claude/scripts):
  - `pixelforge.sh`: read-only API calls
  - `send-webhook.sh`: signed test webhooks
