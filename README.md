# PromptEdit × Pixelforge

Signed-in users generate images through [Pixelforge](https://pixelforge.promptedit.dev), a simulated asynchronous image API priced per token. They pay Pixelforge's cost plus a 25% markup, in credits where 1 credit = 1 US dollar.

Built for the [PromptEdit take-home assessment](https://pixelforge.promptedit.dev/assessment) with Laravel 13, Inertia v3, Vue 3 and PostgreSQL.

The brief ranks four things, in this order:

1. **Correct money.** Every generation is recorded at its exact cost, never twice, and failures are free.
2. **Robustness.** Limits, overloads, failures after acceptance, and duplicate, out-of-order or missing webhooks.
3. **Clear UX.** The user always knows what it costs, what is happening and what went wrong.
4. **Readable code.** Easy to follow and to change.

Every non-obvious choice is written up in [`DECISIONS.md`](DECISIONS.md), with the context, the alternatives and the consequences.

---

## What it does

| Page                                     |                                                                                                                                                                                                                                                                                           |
| ---------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Studio** `/generate`                   | Enter a prompt and choose quality, size (1:1, 3:2 or 2:3), 1–4 images and up to 4 reference image links. The price range updates as you type and is computed by the server with the same formula used when the generation starts. Your latest generations sit underneath and update live. |
| **My library** `/generations`            | Every generation with its live status, estimated range vs exact charge, and a plain-language reason if it failed. Filters, prompt search and cursor paging.                                                                                                                               |
| **Detail** `/generations/{id}`           | The image, the prompt, and the full cost breakdown: estimate, **Pixelforge cost**, markup and charge. Also Expand, Download, Share and Remix.                                                                                                                                             |
| **Admin: Spend & profit** `/admin/spend` | For the `admin` role. Per UTC day and per user: what users were charged, what Pixelforge charged us, and the profit. Each day is checked against Pixelforge's `GET /v1/usage` and marked as matching to the nanodollar or as differing by an exact amount ([#22](DECISIONS.md)).          |
| **Home, Explore, Models**                | Public pages from the design. Their links open the Studio with a prompt or setting filled in.                                                                                                                                                                                             |

The header shows the total you have actually been charged.

Images are copied to our own storage within about a minute of succeeding, so they outlive Pixelforge's 24-hour links. Shared links are permanent signed URLs.

---

## How money works

All amounts are **integer nanodollars** (1 USD = 1,000,000,000) in PHP and in `unsignedBigInteger *_nanos` columns. Pixelforge reports costs with 9 decimals, so this is lossless. No floats are used anywhere, and division happens only in `Money` and `RoundingPolicy` ([#1](DECISIONS.md)).

| Step                           | Rule                                                                                                                                                                                                                                                                                          |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Estimate** (before submit)   | From Pixelforge's live `/v1/pricing` (cached for 10 minutes) and its token rules: text = ⌈characters ÷ 4⌉ ± 15%, 765 tokens per reference image, output tokens by quality and size, reasoning tokens as a range by quality. Stored on the generation as a min–max range ([#4](DECISIONS.md)). |
| **Provider cost** (on success) | Σ tokens × rate, exact, using the generation's own `usage` and `rates` from Pixelforge, never the live price list ([#3](DECISIONS.md)).                                                                                                                                                       |
| **Charge**                     | provider cost × 125 / 100, rounded half up **once**, to the nanodollar ([#2](DECISIONS.md)). The markup is fixed on the generation when it is created ([#8](DECISIONS.md)).                                                                                                                   |
| **Failed**                     | Provider cost 0, charge 0.                                                                                                                                                                                                                                                                    |
| **On screen**                  | Estimates are rounded outward to cents, so the shown range always contains the real charge. Charges are shown exactly (e.g. `$0.3818875`) ([#14](DECISIONS.md)).                                                                                                                              |

Worked examples from Pixelforge's docs:

- **Actual cost:** the docs' example usage costs `$0.30551` at Pixelforge, so the charge is `$0.3818875`.
- **Estimate:** the docs' worst case (high quality, 1536×1024, 400 characters, 2 reference images) can cost at most `$0.373875` at Pixelforge. It is shown as `$0.35 – $0.47`, with a stored maximum charge of `$0.46734375`.

**Never twice:**

- The cost is written once, inside a transaction, on the transition to `succeeded`. That is the only time it is written.
- Webhook events are deduplicated by `evt_` id with a unique constraint.
- A double-clicked or retried form maps back to the generations it already started, using a client request id unique per user ([#13](DECISIONS.md)).

**Matching Pixelforge's records:** the detail page shows Pixelforge's own figure for each generation, so it can be checked against Pixelforge's dashboard. Billing days are the UTC `completed_at` date, the same way `/v1/usage` groups them.

---

## Robustness

| Pixelforge behaviour                              | What the app does                                                                                                                                                                                                                                                                                                                          |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `429` rate or concurrency limit, `503` overloaded | The submit job waits for `Retry-After` and retries with the **same** idempotency key (the generation's id), for up to 15 minutes. Submissions also hold back while 3 of our generations are already running at Pixelforge (its concurrency limit), instead of spending a request to hear "busy" ([#5](DECISIONS.md), [#19](DECISIONS.md)). |
| Timeout or `5xx`: outcome unknown                 | The attempt is marked uncertain. If we give up, the failure stays **provisional** ("Checking"). It is billed only if Pixelforge's records show it ran, and settled as free otherwise ([#6](DECISIONS.md), [#18](DECISIONS.md)).                                                                                                            |
| A worker dies mid-request                         | Each attempt is recorded under a row lock _before_ it is sent, so an attempt with no recorded answer can never be closed off as "never sent" ([#18](DECISIONS.md)).                                                                                                                                                                        |
| `400` content policy, `429` daily limit           | The generation fails at once, for free, with a plain-language reason.                                                                                                                                                                                                                                                                      |
| Fails after acceptance (`[fail]`)                 | Recorded as failed and free. The reason is shown with a "Try again" or "Edit prompt" action.                                                                                                                                                                                                                                               |
| Duplicate webhooks (`[duplicate]`)                | Recorded once by event id. A second delivery is acknowledged and ignored ([#11](DECISIONS.md)).                                                                                                                                                                                                                                            |
| Out-of-order webhooks                             | Status only moves forward (queued → processing → final), and a final state never changes ([#6](DECISIONS.md)).                                                                                                                                                                                                                             |
| Missing webhooks, lost jobs                       | A poller runs every minute ([#12](DECISIONS.md)). It refreshes stalled generations, applies stored events that were never processed, re-queues lost submissions, settles unconfirmed ones against Pixelforge's records and copies images. It stays under its own share of the 60/minute API budget.                                        |
| Bad or old signature                              | Rejected before anything else. The signature is an HMAC of the raw body, with a 300-second tolerance.                                                                                                                                                                                                                                      |

Webhooks are verified, recorded and acknowledged in milliseconds. Applying them happens in a queued job, all through the single use case `ApplyProviderUpdate`, with the row locked.

---

## Architecture

Hexagonal (ports and adapters), kept pragmatic. Details are in `.claude/rules/architecture.md`.

```
Http / Jobs / Console ──► Application (use cases + ports) ──► Domain (pure PHP)
Infrastructure (Pixelforge, Eloquent, storage, read models) ──implements──► Application ports
```

| Layer          | Path                                            | Contains                                                                                                                                                                                                                                       |
| -------------- | ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Domain         | `app/Domain/Billing`                            | `Money`, `RoundingPolicy`, `PricingSchedule`, `CostEstimate`, `GenerationCost`, `Markup`, `TokenUsage`, `TokenRates`                                                                                                                           |
|                | `app/Domain/Generation`                         | `Generation` (state machine and once-only cost), `GenerationRequest`, `GenerationFailure`, `ProviderUpdate`                                                                                                                                    |
| Application    | `app/Application/UseCases`                      | `StartGeneration(Batch)`, `SubmitGeneration`, `ApplyProviderUpdate`, `ReceiveProviderEvent`, `ProcessProviderEvent`, `RefreshGeneration`, `ConfirmUnconfirmedSubmission`, `AbandonSubmission`, `StoreGeneratedImage`, `EstimateGenerationCost` |
|                | `app/Application/Ports`                         | `ImageGenerationProvider`, `GenerationRepository`, `ProviderEventInbox`, `ImageArchive`, `TransactionRunner`                                                                                                                                   |
| Infrastructure | `app/Infrastructure`                            | Pixelforge client, mapper and webhook signature. Eloquent repositories. Filesystem image archive. Read models (`GenerationHistoryQuery`, `PixelforgeWorkload`).                                                                                |
| Driving        | `app/Http`, `app/Jobs`, `app/Console`           | Thin controllers, form requests, `SubmitGenerationJob`, `ProcessProviderEventJob`, `StoreGeneratedImageJob`, the `pixelforge:poll` command                                                                                                     |
| UI             | `resources/js/pages`, `resources/js/components` | Vue pages. The browser never does money arithmetic; it only displays server-formatted amounts.                                                                                                                                                 |

---

## Running it locally

**Requirements:** PHP 8.3+ (developed on 8.5) with the `redis` and `pgsql` extensions, Composer, Node 20+, PostgreSQL 16+ (developed on 18), Redis, and a Pixelforge account.

```bash
git clone <this repo> promptedit && cd promptedit
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Then edit `.env`:

| Variable                       | Value                                                                                                                                   |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_NAME`, `APP_URL`          | Your app name and its URL (`https://…` if served over TLS).                                                                             |
| `DB_*`                         | A PostgreSQL database. The app relies on Postgres row locks, partial indexes and `ON CONFLICT`.                                         |
| `REDIS_*`, `CACHE_STORE=redis` | Cache, scheduler locks and unique-job locks.                                                                                            |
| `QUEUE_CONNECTION`             | `database` is fine.                                                                                                                     |
| `PIXELFORGE_API_KEY`           | A key from the Pixelforge dashboard. **Use a separate key per environment**: each key has its own webhook URL, secret and usage report. |
| `PIXELFORGE_WEBHOOK_SECRET`    | The signing secret for that key's webhook.                                                                                              |
| `PIXELFORGE_IMAGE_DISK`        | Where image copies go: `local` for development, `s3` (object storage) when deployed.                                                    |
| `PIXELFORGE_MARKUP_PERCENT`    | `25`. It is fixed on each generation when created.                                                                                      |

```bash
php artisan migrate
composer run dev
```

`composer run dev` runs the web server, Vite, the queue listener for the `default` and `images` queues, the scheduler (the Pixelforge poller) and the log viewer. Then register at `/register`. Locally, emails go to `storage/logs/laravel.log`, including the verification link. To open the admin report, grant yourself the role with `php artisan users:make-admin you@example.com`.

**Webhooks locally:** expose the app with a tunnel (Expose, ngrok, Cloudflare Tunnel). Set `https://<tunnel>/webhooks/pixelforge` as the webhook URL for your local key in the Pixelforge dashboard. Without a tunnel the app still works: the poller picks up results within about a minute.

---

## Deploying (Laravel Cloud or any host)

- PostgreSQL and Redis.
- **Object storage** for images. Set `PIXELFORGE_IMAGE_DISK=s3` and the `AWS_*` variables. An app server's own disk is wiped on redeploy.
- A **queue worker**: `php artisan queue:work --queue=default,images`. The `images` queue is lower priority. If a worker skips it, images are never copied.
- The **scheduler** (`php artisan schedule:work`, or cron running `schedule:run` every minute). It runs the Pixelforge poller, the safety net for everything above.
- `npm run build`, then `php artisan migrate --force`. Then `php artisan users:make-admin <email>` for each operator.
- A **production** Pixelforge key, with its webhook URL set to `https://<your-domain>/webhooks/pixelforge` and its own secret.

---

## Tests and checks

```bash
php artisan test --compact          # 423 Pest tests (unit + feature, on real Postgres)
vendor/bin/phpstan analyse          # level 7
vendor/bin/pint --test              # code style
npm run types:check                 # vue-tsc
```

The tests cover:

- Exact money with datasets, including the docs' worked examples, multibyte prompts and a markup that needs rounding.
- Duplicate and out-of-order webhooks, signature failures and every Pixelforge error code.
- Lost, killed and backed-up jobs.
- Double submits.
- Image copying.
- A brute-force check that the price shown never changes inside the range the form reuses it for.

**Live scenarios.** These use your daily Pixelforge quota. Put a keyword in the prompt to force behaviour:

- `[fail]`: fails after acceptance; must be free.
- `[slow]`: takes 60–90 seconds; watch the live status.
- `[duplicate]`: every webhook arrives twice; it must be charged once.
- `[blocked]`: refused by content policy.
- `[overloaded]`: `503`; the job retries automatically.
- `[succeed]`: never fails at random.

The Pixelforge dashboard shows webhook deliveries and can resend them.

---

## Trade-offs and limits

- **Credits are shown as dollars spent.** Balances and "Buy credits" (stretch goal 2) are not built, so there is no blocking on low balance.
- **Share links are permanent signed URLs.** They can only be revoked by rotating `APP_KEY` ([#17](DECISIONS.md)).
- **Some design controls are disabled**: seed, negative prompt, style presets, upscale and favourites. They appear in the design, but Pixelforge doesn't support them ([#16](DECISIONS.md)).
- **One image per Pixelforge request.** Asking for "4 images" makes 4 generations, each priced, failed and charged on its own, and uses 4 of the daily quota.
