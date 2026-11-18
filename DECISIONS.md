# Decisions

Short records of design choices, for review and the technical interview.

## 1. Money is integer nanodollars (2026-11-17)

**Context:** Pixelforge prices are per 1M tokens and `cost_usd` is an exact 9-decimal string; the docs warn that floats drift. Evaluation weighs "every generation recorded at the right cost" above everything else.
**Decision:** All amounts are `int` nanodollars (1 USD = 1,000,000,000) in PHP, through `App\Domain\Billing\Money`. Provider decimal strings are parsed with string operations, never `(float)`. Database columns will be `unsignedBigInteger *_nanos`.
**Alternatives:** `float` — inexact. `decimal(20,9)` + bcmath — exact, but every operation then needs bcmath and string handling. Cents — loses precision (one text token costs $0.000005).
**Consequences:** Provider costs are stored with no loss, and reconciliation against `/v1/usage` is an exact integer comparison. The ceiling is about $9.2B per value; overflow throws instead of turning into a float.
**Code:** `app/Domain/Billing/Money.php` (+ `tests/Unit/Domain/Billing/MoneyTest.php`)

## 2. Markup: cost × 125 / 100, rounded half up once (2026-11-17)

**Context:** Users pay Pixelforge's cost plus 25%. The brief leaves fractions of a cent to us.
**Decision:** charge = provider cost × (100 + 25) / 100. It is rounded half up to the nanodollar, in one step, on the final provider cost (`Markup::chargeFor`). All integer division lives in `RoundingPolicy`.
**Alternatives:** ceil — always favours us by up to 0.75 nanodollar. Banker's rounding — unbiased, but harder to explain. Rounding to cents — visible error of up to $0.005 on charges of a few cents.
**Consequences:** At current rates every provider cost is a multiple of 2,500 nanodollars, so the markup is always exact and rounding never triggers. The policy only matters if rates change. Maximum error is half a nanodollar per generation.
**Code:** `app/Domain/Billing/Markup.php`, `app/Domain/Billing/RoundingPolicy.php` (+ `MarkupTest`, `RoundingPolicyTest`)

## 3. Provider cost: exact per-token rates, no division (2026-11-17)

**Context:** cost = Σ tokens × rate / 1,000,000, with rates in USD per 1M tokens. Pixelforge says costs are exact and never rounded.
**Decision:** Each published rate is converted once to whole nanodollars per token ("12.50" per 1M = 12,500 nanodollars per token). Cost is Σ tokens × per-token rate, so it involves no division and no rounding. A rate that is not a whole number of nanodollars per token (more than 3 decimals) is rejected loudly instead of rounded. Actual cost uses the `rates` on the generation itself (fixed when it was created), never the live pricing.
**Alternatives:** Summing the per-million products and dividing once with rounding — accurate to half a nanodollar, but that error can mean rounding twice before the markup and a mismatch with `/v1/usage` day totals.
**Consequences:** At the current rates every cost is exact and reconciles with `/v1/usage` to the nanodollar. The markup is the only rounding step in the app. If Pixelforge ever published a 4-decimal rate, recording would fail visibly and need a deliberate change.
**Code:** `app/Domain/Billing/TokenRates.php` (+ `TokenRatesTest`, with the docs' examples and the rejected rates)

## 4. Estimates are a min..max range with whole-token bounds (2026-11-17)

**Context:** Text tokens are only about ceil(characters / 4), within ±15%. Reasoning tokens vary by quality. Image and output tokens are exact. The brief requires showing the cost before submission.
**Decision:**
- The estimate is a range. Each end applies Pixelforge's rules at their extremes.
- The text estimate uses Unicode characters (`mb_strlen`).
- The text bounds are floor(0.85 × estimate) and ceil(1.15 × estimate), so the range always contains the real count.
- Both provider cost and user charge are given for each end.
**Alternatives:** Exact bounds ceil(0.85e)..floor(1.15e) — tighter by at most one token ($0.000005), but they rely on how Pixelforge rounds internally. A single midpoint — would understate what users might pay.
**Consequences:** The maximum is safe to hold against a balance. The UI should show "$min – $max", labelled as an estimate.
**Code:** `app/Domain/Billing/PricingSchedule.php`, `app/Domain/Billing/CostEstimate.php` (+ `PricingScheduleTest`, `CostEstimateTest`)

## 5. Submission runs in a queued job and retries for up to 15 minutes (2026-11-17)

**Context:** Pixelforge returns 429 (60 requests/min, 3 generations in flight) and 503 overloaded with `Retry-After`, and network calls can time out. The brief scores robustness to limits and overloads.
**Decision:**
- The local generation (UUID v7) is saved before the POST. Its id is the `Idempotency-Key` and `metadata.generation_id`.
- `SubmitGenerationJob` waits out `Retry-After` on any temporary error (exponential backoff, capped at 60s, when the header is missing). It keeps retrying for 15 minutes (`retryUntil`). After 5 unexpected exceptions it stops.
- Definitive refusals (content policy, daily quota, invalid request, missing key) fail the generation at once, for free.
- Submits share a rate limit of 30 per minute (half of Pixelforge's 60), leaving room for polling.
- The job is unique per generation, so a duplicate dispatch can't race the original.
- When the job gives up, the generation fails for free. If every attempt was an explicit "busy" (429 or 503 overloaded, which Pixelforge says create nothing), the failure is final (`submission_abandoned`). If any attempt timed out or got another 5xx, the outcome is unknown, and the failure is provisional (`submission_unconfirmed`, see #6).
**Alternatives:** A synchronous POST in the web request — slow, and a 503 would surface as a user error. A fixed number of tries — the 3-in-flight limit with `[slow]` generations (60–90s each) can easily outlast 8 quick retries.
**Consequences:** Retries can never double-create or double-bill, because Pixelforge replays the same generation for the same key. Users see "pending" while we wait for capacity.
**Code:** `app/Jobs/SubmitGenerationJob.php`, `app/Application/UseCases/SubmitGeneration.php`, `app/Infrastructure/Pixelforge/PixelforgeImageGenerationProvider.php` (+ `SubmitGenerationJobTest`, `PixelforgeImageGenerationProviderTest`)

## 6. Only an unconfirmed submission can be overridden by Pixelforge (2026-11-17)

**Context:** A submit that timed out may have been accepted by Pixelforge. If we then give up and mark it failed, a later `generation.succeeded` would normally be ignored, because terminal states are final. Pixelforge would bill it, we wouldn't charge for it, and `/v1/usage` would not reconcile.
**Decision:**
- Status only moves forward, and a terminal state confirmed by Pixelforge never changes.
- A local failure is provisional only when an attempt's outcome was unknown (`submission_unconfirmed`, with a message that says we are checking). The first real Pixelforge update replaces it, including the cost.
- Every other local failure is final, because Pixelforge confirmed it created nothing: content policy, invalid request, or abandoned after explicit "busy" answers.
**Alternatives:** Never give up on submission — a generation could stay pending forever. Treat every local failure as final — loses track of billed generations. Let any local failure be overridden — would charge users after telling them they weren't.
**Consequences:** Pixelforge is always the source of truth for what was billed. A user is only ever told "we're checking" when we genuinely don't know. Reconciling unconfirmed submissions (looking them up by `metadata.generation_id` in `GET /v1/generations`) is done by the poller.
**Code:** `app/Domain/Generation/Generation.php` (`apply`, `recordUncertainSubmission`, `abandonSubmission`), `GenerationFailure::isProvisional` (+ `GenerationTest`, `SubmitGenerationJobTest`)

## 7. PostgreSQL, with row locks around every state change (2026-11-17)

**Context:** Webhooks, the poller and the submit job can update the same generation at the same moment.
**Decision:**
- PostgreSQL for the app and the tests.
- Every state change runs in a transaction that loads the row with `SELECT … FOR UPDATE`, applies the domain rules, and saves.
- The repository refuses `save()` for a row not locked in the current transaction, so a stale read can never overwrite newer state.
- `provider_id` is unique.
- CHECK constraints enforce the money rules in the database itself:
  - a cost exists if and only if the generation is finished
  - a failed generation costs 0
  - the charge is at least the provider cost
  - no negative amounts
  - token usage is all or nothing
  - valid statuses only
**Alternatives:** SQLite — ignores `FOR UPDATE` and has weaker constraint support. Optimistic version columns — more code for the same result at this scale.
**Consequences:** Concurrent duplicate events wait for each other, then do nothing. A bug or a manual edit that breaks a billing rule fails loudly instead of corrupting the report. The test suite checks the constraints and the locked-save rule against real Postgres. It does not race two connections, so lock contention is verified by review, not by a test.
**Code:** `app/Infrastructure/Persistence/EloquentGenerationRepository.php`, `LaravelTransactionRunner.php`, the `generations` migration

## 8. Markup fixed per generation at creation (2026-11-17)

**Context:** The estimate a user sees is computed with the current markup. If the markup setting changed while a generation was in flight, it would finish at a different price than quoted.
**Decision:** `markup_percent` is stored on each generation when it is created. The actual charge always uses that markup, just as Pixelforge bills each generation at the `rates` fixed at its creation.
**Alternatives:** Read the markup from config when the generation completes — simpler, but can charge a different markup than the one quoted.
**Consequences:** The markup can be changed safely at any time; it applies only to new generations.
**Code:** `app/Domain/Generation/Generation.php`, `app/Application/UseCases/StartGeneration.php` (+ `GenerationTest`, `Feature/Application/UseCases/ApplyProviderUpdateTest`)

## 9. Deleting an account anonymises it and keeps billing history (2026-11-17)

**Context:** Deleting a user cascaded to their generations. Billing history disappeared, the admin report stopped matching `/v1/usage`, and a webhook for an in-flight generation found no row.
**Decision:** The foreign key from generations to users is `RESTRICT`. "Delete account" deletes passkeys, replaces the name and email with placeholders, resets the password and 2FA, and soft-deletes the user. Generations and their costs stay.
**Alternatives:** Block deletion for users with history — worse UX. Make `user_id` nullable — keeps the money but loses who spent it.
**Consequences:** Personal data is removed; financial records remain complete. The same email can register again.
**Code:** `app/Models/User.php` (`anonymiseAndDelete`), `ProfileController::destroy` (+ `ProfileUpdateTest`)

## 10. Redis for the cache, with an isolated prefix and DBs (2026-11-17)

**Context:** The pricing cache, the shared submit rate limiter and the unique-job locks all live in the cache store, and they must be shared by every web and worker process.
**Decision:** `CACHE_STORE=redis` (phpredis). The local Redis is shared with other projects and `cache:clear` flushes a whole DB, so the app uses its own DB numbers (8, and 9 for the cache) and the prefix `promptedit-`. Tests use the array cache.
**Alternatives:** Database cache — works, but adds load on Postgres for every rate-limit check.
**Consequences:** In production, point `REDIS_*` at a dedicated Redis instance.
**Code:** `.env.example`, `config/services.php`

## 11. Webhooks: verify, record once in an inbox, apply in a job (2026-11-17)

**Context:** Pixelforge delivers webhooks at least once, in any order, possibly before our submit call returns, and possibly never. A 2xx must come within 10 seconds, otherwise it retries (6 attempts in total).
**Decision:**
- `POST /webhooks/pixelforge` sits outside the `web` middleware group (no session or CSRF).
- The signature is checked over the raw body: HMAC-SHA256 of `t.body`, compared in constant time, with timestamps more than 300 seconds old or ahead rejected. A request that fails returns 400 and changes nothing.
- The event is stored in `provider_events` with its `evt_` id as primary key, using an insert that ignores conflicts. A duplicate changes nothing. A job is queued (after commit) only while the event is still unprocessed.
- `ProcessProviderEventJob` locks the unprocessed event. It is deliberately not unique: the row lock plus `processed_at` already guarantee exactly-once, and a unique lock could silently drop a re-queue after a job was lost. In one transaction it applies the generation snapshot through the same `ApplyProviderUpdate` used by submit and poll, and marks the event processed with an outcome.
- Pings, unknown event types and generations from another environment get 2xx and are marked processed.
- A signed but unreadable event (unknown status, missing field), or one naming a different Pixelforge generation, is marked `malformed` or `conflict` and logged instead of failing forever. The poller restores the generation's state.
- Every rejection is logged with its reason (missing or stale signature, mismatch, oversized body), never the secret or the body. Bodies over 64 KB get 413 before hashing. Webhooks keep working in maintenance mode.
- A missing signing secret returns 500, so Pixelforge keeps retrying until it is configured.
**Alternatives:** Apply inside the request — slower, and a crash could lose work after the 2xx. Dedupe in the cache — not durable.
**Consequences:** Each event is applied at most once (guaranteed by the primary key and by marking it processed in the same transaction). Ordering is handled by the monotonic status rules. A crash leaves the event unprocessed so it can be retried. Missing events are covered by the poller.
**Code:** `app/Http/Controllers/PixelforgeWebhookController.php`, `app/Infrastructure/Pixelforge/PixelforgeWebhookSignature.php`, `app/Application/UseCases/{ReceiveProviderEvent,ProcessProviderEvent}.php`, `app/Infrastructure/Persistence/EloquentProviderEventInbox.php` (+ `PixelforgeWebhookControllerTest`, `PixelforgeWebhookSignatureTest`)

## 12. A poller as the safety net, modelled on how Leonardo and Gemini are used in practice (2026-11-17)

**Context:**
- Leonardo documents webhooks but not their retries, and integrators are advised to record generation ids and poll for any that never call back.
- Gemini Batch jobs are polled by state. Users report jobs that succeeded and were billed, but whose results could not be retrieved, and token counts that differ from billing.
- Pixelforge documents that webhook delivery isn't guaranteed.
**Decision:** `pixelforge:poll` runs every minute (`withoutOverlapping`, `onOneServer`) and:
1. applies webhook events still unprocessed after 10 minutes;
2. re-queues submissions still pending after 2 minutes (the job is unique, so this does nothing while the original is alive) and abandons ones pending for over 40 minutes, longer than any job could still be sending;
3. refreshes queued or processing generations with no update or check for 45 seconds, least recently checked first, through `GET /generations/{id}` → `ApplyProviderUpdate`;
4. resolves `submission_unconfirmed` failures by scanning `GET /generations` (newest first, at most 3 pages) for our `metadata.generation_id`:
   - found → Pixelforge's state wins and the generation is billed if it succeeded;
   - list goes back past the submission time with no match → final `submission_not_found`, free;
   - otherwise try again later.
- Never re-POST to find out: if the first attempt never arrived, that would create the generation now.
- Polling stays under 20 requests per minute, so with 30 for submissions the total is 50 of Pixelforge's 60.
- A "busy" answer stops this run. One failing item never blocks the others. HTTP calls are never made inside a transaction.
**Alternatives:** Poll every in-flight generation every minute — wastes the shared limit when webhooks work. Re-POST unconfirmed submissions with the same key — simpler, but can create a generation after the user was told it failed.
**Consequences:** Every accepted generation reaches a final state even if every webhook is lost, and every unconfirmed submission resolves to billed or definitely free. The cost is a small, bounded number of extra requests.
**Code:** `app/Console/Commands/PollPixelforge.php`, `app/Application/UseCases/{RefreshGeneration,ConfirmUnconfirmedSubmission}.php`, `PixelforgeImageGenerationProvider::findSubmitted`, `routes/console.php` (+ `PollPixelforgeTest`)

## 13. One generation per form submission: a client request id with ON CONFLICT (2026-11-18)

**Context:** A double click, a retried POST after a timeout, or a browser resend must not start (and charge for) a second generation. The generation id can't be the guard because it is created on the server, after the request arrives.
**Decision:**
- The form sends a `client_request_id` (UUID from `crypto.randomUUID()`), regenerated whenever any field changes. It is stored with a unique `(user_id, client_request_id)` index.
- `StartGeneration` first looks the id up. A repeat returns the existing generation without fetching prices, so it still succeeds while Pixelforge pricing is down.
- New ids insert with `INSERT … ON CONFLICT DO NOTHING RETURNING id`. If a concurrent duplicate won, its row is returned. We don't catch the unique violation, because in Postgres a failed statement aborts the surrounding transaction.
- A repeat with different values (`ClientRequestReused`) is refused with a form error rather than silently showing the old generation.
- Only a new generation dispatches `SubmitGenerationJob`, after commit.
**Alternatives:** Disable the button only — doesn't cover retries or a second tab. Fingerprint the request (hash of prompt and params) — would block deliberately generating the same prompt twice. Use the client id as the primary key — trusts client input for a global key.
**Consequences:** At most one generation per submission, even under concurrency. The id is scoped per user. Rows created before the column existed were backfilled with their own id.
**Code:** `app/Application/UseCases/StartGeneration.php`, `EloquentGenerationRepository::{add,findByClientRequest}`, `app/Http/Controllers/GenerationController.php`, `resources/js/pages/generations/Create.vue`, migration `2026_11_18_035348_…` (+ `StartGenerationTest`, `GenerationControllerTest`, `EloquentGenerationRepositoryTest`)

## 14. Prices on screen: estimates rounded outward to cents, charges shown exactly (2026-11-18)

**Context:** Estimates and charges are exact nanodollars (e.g. a $0.357781250–$0.467343750 estimate, a $0.381887500 charge). Users need a readable range that never understates the price, and a charge that matches what was recorded.
**Decision:**
- The estimate range is shown as the minimum rounded **down** and the maximum rounded **up** to whole cents (`Money::floorToCents` / `ceilToCents`), e.g. "$0.35 – $0.47". The shown range always contains the real charge.
- The actual charge is shown exactly, with trailing zeros trimmed down to cents ("$0.3818875"). Display never rounds a recorded amount.
- Nanos travel as strings next to the display text; the browser never does money arithmetic.
- The form clears ("Updating price…") and disables Generate from the moment an input changes until the new estimate arrives, so the button's "up to $X" always matches the values being submitted.
**Alternatives:** Round both ends to nearest — could show a maximum lower than the charge. Show nine decimals everywhere — exact but unreadable. Round the charge to cents — the screen would disagree with the record and with `/v1/usage` reconciliation.
**Consequences:** Estimates read like prices. The charge is exact, which looks unusual but matches billing.
**Code:** `app/Domain/Billing/Money.php`, `GenerationEstimateController`, `GenerationHistoryQuery` (+ `MoneyTest`, `GenerationControllerTest`)

## 15. History refreshes by polling while anything can still change (2026-11-18)

**Context:** Webhooks update rows in the background; the page needs to reflect them without websockets.
**Decision:**
- The history page polls with a partial reload (`only: ['generations', 'serverNow']`) every 3 s, only while some row is `in_flight`. Polling stops once everything on the page is final.
- `in_flight` is decided on the server: pending, queued, processing, or a provisional `submission_unconfirmed` failure (which can still turn into a charged success, so it shows "Checking" and never "not charged").
- Elapsed time uses the server clock (`serverNow` offset), not the browser's.
- History uses cursor pagination on `(user_id, created_at, id)`. A tampered cursor redirects to page one.
**Alternatives:** Websockets (Reverb) — extra infrastructure for a few updates per minute. Poll always — wasted queries when nothing is moving. Offset pagination — rows shift as new generations arrive.
**Consequences:** One small indexed query every 3 s per open page, only while something is in flight. Deep pages filter rather than seek (Laravel's `OR` cursor condition); acceptable at this scale, with a row-value keyset query as the upgrade path.
**Code:** `app/Infrastructure/ReadModels/GenerationHistoryQuery.php`, `GenerationController::index`, `resources/js/pages/generations/Index.vue`, `resources/js/components/generations/*`

## 16. Showcase design: real features wired, the rest shown disabled (2026-11-18)

**Context:** The UI was redesigned from a Claude Design file ("AI Image Generator — Showcase Screens"). The design shows features Pixelforge doesn't have (models, 16:9 and 9:16, style presets, seed, negative prompt, upscale, favourites, credits).
**Decision:**
- Each screen maps onto what exists:
  - Studio = generate form plus recent generations, as one continuous live grid. A run of images from the same prompt and settings is fenced by dividers. Submitting returns to the studio.
  - The "Model" picker = quality.
  - Aspect ratio = the three Pixelforge sizes.
  - My library = history, in the design's masonry layout.
  - Detail = `generations.show`, owner-only (another user's id returns 404).
  - Home, Explore and Models are public. Their showcase images are static art, and their links prefill the studio.
  - The brand is `APP_NAME`.
- **Number of images (1–4):** each image is its own Pixelforge generation, with its own idempotency key, estimate and charge. `StartGenerationBatch` records all of them in one transaction, so either every image starts or none does. Per-image client ids are derived from the form's id (UUIDv5, `image-2`…), so a resubmitted form maps every image back to its generation. The estimate shown is the per-image estimate × count, rounded outward to cents after multiplying.
- **Expand, Download, Share:**
  - Expand opens a lightbox.
  - Download goes through `generations.download`, which fetches Pixelforge's cross-origin URL server-side and sends it as an attachment. The browser ignores `download` on another origin.
  - Share uses the system share sheet, or else copies the image link, which works for 24 hours.
- Controls with no backend are shown but disabled, with "Coming soon" or the reason.
- The design's "credits" chip shows what the user has actually been charged (sum of `charge_nanos` on succeeded generations, exact).
- Generate stays disabled until the server's price for the current inputs, count included, is known. The price sits next to the button, not on it.
- Polls reload only the props that change in flight. The spent total and secondary lists reload once, when everything settles.
**Alternatives:**
- Leave design-only controls out: not faithful.
- Make them look active: misleading.
- Multi-image as one row with an `n` column: Pixelforge bills and fails per image, so one row would need a split charge and a partial-failure state.
**Consequences:** One click can start up to 4 generations, using 4 of the 500/day quota. Each image fails and is charged independently.
**Code:** `StartGenerationBatch`, `StoreGenerationRequest::clientRequestIds`, `GenerationEstimateController`, `GenerationController::{create,show,download}`, `GenerationHistoryQuery::{recentForUser,findForUser,samePromptForUser,totalChargedTo}`, `resources/js/pages/**`, `resources/js/components/{atelier,generations}/*`, `resources/css/app.css` (`.prompt-glow`)

## 17. Images are copied to our storage within a minute of success (2026-11-18)

**Context:** Pixelforge's image links expire 24 hours after completion. Evaluators will look later, and Download and Share need something that lasts.
**Decision:**
- Every minute the poller queues `StoreGeneratedImageJob` for succeeded generations that need a copy. It skips expired links and images marked unstorable, and takes the soonest-to-expire first, so dead rows can't crowd out live ones.
- The job runs `StoreGeneratedImage`:
  - It fetches through the `ImageArchive` port (`FilesystemImageArchive`) outside any transaction.
  - It records `stored_image_path` once, under the row lock.
  - The path is fixed (`generations/{user}/{id}.{ext}`), so a retry overwrites rather than duplicates.
  - Transient errors retry. Permanent ones (4xx, not PNG/JPEG/WebP, over 25 MB) set `image_unstorable` and stop.
- Images are private files served by `generations.image`, a **signed, relative, permanent** URL that works without signing in. The pages and shared links use the same URL, and with no valid signature the route returns 403.
- If the file is missing, the route falls back to Pixelforge's link while it works, then returns 404. Responses carry `nosniff` and a sandbox CSP.
- Disk: `PIXELFORGE_IMAGE_DISK` (default `local`). It **must be object storage when deployed**, because an app server's disk doesn't survive a redeploy.
**Alternatives:**
- Dispatch on the success transition itself: three entry points (submit, webhook, poll) and a port only for queueing. The poller sweep is one place and well inside 24 hours.
- Public disk URLs: depend on `APP_URL` and storage links, and expose a guessable folder layout.
**Consequences:**
- A shared link can't be revoked short of rotating `APP_KEY`; a per-row nonce in the signature is the upgrade.
- A link with extra query parameters (e.g. `utm`) fails its signature.

## 18. Submission attempts are counted before they are sent (2026-11-18)

**Context:**
- A job that expired in a backed-up queue was abandoned as "Pixelforge stayed too busy" though nothing was ever sent.
- Fixing that showed the deeper risk. An attempt whose answer was never recorded (worker killed mid-request, answer lost, an error after a 202) left no trace. Abandoning it as final could make a generation Pixelforge did create and bill free and unreconcilable.
**Decision:**
- `SubmitGeneration` calls `beginSubmissionAttempt()` under the row lock **before** each POST. If the generation is no longer pending (e.g. abandoned meanwhile), nothing is sent.
- A "busy" answer increments `busy_refusals`.
- `abandonSubmission()` decides the failure from the counts:
  - Any attempt without a definitive answer (`submission_outcome_unknown`, or attempts > busy refusals) gives `submission_unconfirmed`. It is provisional: reconciliation turns it into `submission_not_found`, or a late success bills it.
  - Every attempt answered busy gives `submission_abandoned`.
  - No attempt ever sent gives `submission_not_sent`.
- `SubmitGenerationJob::failed()` abandons only if an attempt was made. A job that never ran leaves the row pending, and the poller re-queues it.
**Alternatives:** Mark every attempt "uncertain" up front. That is simpler, but every busy-only failure would need a Pixelforge lookup to settle.
**Consequences:**
- One extra row write per attempt.
- "Busy" means every attempt we sent was refused as busy. One busy answer followed by our own throttling still reads as "busy", which is accepted.
**Code:** `Generation::{beginSubmissionAttempt,recordBusyRefusal,abandonSubmission}`, `SubmitGeneration`, `AbandonSubmission`, `SubmitGenerationJob`, `PollPixelforge`, migrations `2026_11_18_075850_*`, `2026_11_18_080632_*`

## 19. Background work: bounded, prioritised, never silently stopped (2026-11-18)

**Context:** A review of every job and the poller for fault tolerance and wasted work.
**Decision:**
- **Poller overlap lock expires in 5 minutes** (`withoutOverlapping(5)`). The default 24 hours meant one run killed mid-way (deploy, out of memory) would stop polling, abandoning and image copying for a day.
- **Each poll run stops starting Pixelforge calls 40 seconds in**, so a slow Pixelforge (up to 15 s per call) can't push a run into the next minute.
- **Submissions wait while Pixelforge already runs 3 of ours** (`WaitForPixelforgeCapacity`). Only rows active in the last 5 minutes count, so a generation stuck in our records can't block submissions forever. Without this, the 4th image of a batch would spend a request every 5 s on a guaranteed 429 out of the shared 60/minute budget. A race between workers still ends in a 429, handled as busy.
- **Image copies run on a low-priority `images` queue**, and workers take `--queue=default,images`, so 30-second downloads never delay submissions or webhook events.
- **Every job has a timeout** below the queue's 90 s `retry_after` (submit 45 s, event 30 s, image 60 s), so a hung call can't make a second worker pick up the same job.
- **A webhook whose job can't be queued still gets a 2xx.** The event is already recorded, a redelivery would be deduplicated anyway, and the sweep applies it.
- **`composer run dev` runs the scheduler and both queues.** Failed jobs are pruned after a week.
**Consequences:**
- Production workers must listen to `default,images`. If they only take `default`, images are never copied.
- The scheduler must run, since it is the safety net for everything above.
**Code:** `routes/console.php`, `PollPixelforge`, `app/Jobs/Middleware/WaitForPixelforgeCapacity.php`, `PixelforgeWorkload`, `StoreGeneratedImageJob`, `SubmitGenerationJob`, `ProcessProviderEventJob`, `PixelforgeWebhookController`, `AppServiceProvider::configureDevCommands` (+ `tests/Feature/Jobs/JobResilienceTest.php`)

## 20. The price check only runs when the shown price could change (2026-11-18)

**Context:** The generate form asked the server for an estimate after every pause in typing (400 ms debounce), and on every edit to a reference link. Most of those requests could not change what the user sees.
**Decision:**
- The estimate depends on the prompt only through its length (text tokens = ceil(characters / N)), and the user sees the total range rounded outward to cents.
- `EstimateGenerationCost` also returns **the prompt lengths for which that shown price is exactly the same**. It walks token counts outward from the current one, which costs at most a few milliseconds.
- For the docs' worst case (High, 3:2, two references), "$0.35 – $0.47" holds for any prompt of 1–1,876 characters.
- The form reuses a held estimate while the settings (size, quality, reference count, image count) match and the prompt length stays in that range. It also keeps the last 20 estimates for 5 minutes, so switching settings back costs nothing.
- Only when no held estimate covers the inputs does the price dim and a (debounced) request go out. The browser compares lengths only; it does no money arithmetic.
- Submitting always re-prices exactly on the server, so a reused display never changes what is charged or stored.
**Alternatives:**
- A longer debounce: slower feedback, and still requests that can't change anything.
- Sending pricing rules to the browser: the browser would do money math, and two formulas would drift.
**Consequences:** Typing a prompt usually costs one estimate request instead of one per pause. A brute-force test checks every prompt length 1–4,000 in three scenarios: inside the range the shown price is identical, and just outside it differs.
**Code:** `EstimateGenerationCost`, `PricedRequest`, `GenerationEstimateController`, `resources/js/pages/generations/Create.vue` (+ `EstimateGenerationCostTest`)
