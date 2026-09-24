# Order e-mails — switch-night prerequisite (a), closed

**Date:** 2026-09-13 · **Scope:** `CLEAN_CORE_STUDY` §3.4.1 prerequisite (a) / F-22 · **Status:** met, with three flags below

---

## Why this was the last blocking item on the fulfilment path

T-0 disables the Blade dashboard, which is the **only sender of order e-mail today**. The
adversarial review of wave 4C measured the consequence: two status advances and a cancel through
the core dashboard sent **0 mailables and wrote 0 outbox rows**, and nothing errored. From switch
night a customer who ordered would have received no confirmation, an order marked shipped would
have notified nobody, and the failure would have been silent.

Wave 3 had recorded the gap honestly — every order that would have sent mail wrote an
`integration_outbox` row saying so — but a queue of what is owed is not a sender.

---

## 1. The trigger table

Enumerated from the legacy code, not from memory. The sweep was
`grep -rn "Mail::\|->send(new \|Notification::send\|->notify(" backend/app backend/routes`, which
finds **five** call sites in total.

| # | Legacy trigger (file:line) | Recipients, per the legacy rule | Core implementation | Template | Ported? |
|---|---|---|---|---|---|
| 1 | `Api/OrderController.php:860` → `sendOrderEmails()` called from **:811** (checkout) | customer, **only when `payment_method === 'cash'`** | `CheckoutCompatController::addOrder()` → `OrderMailer::placedNow($id, notifyCustomer: $method === 'cash')`, after `DB::commit()` | `emails.order-confirmation` | ✅ |
| 2 | `Api/OrderController.php:872` (same helper) | **every** address in `ORDER_ADMIN_EMAILS`, one message each | same call; `OrderMailer::placed()` loops the configured list | `emails.admin-order-notification` | ✅ |
| 3 | `Api/OrderController.php:1032` → `sendOrderEmails($order, $isGuest, 'cash')` on Paymob success | customer **and** admins (it passes `'cash'` on purpose, so the customer branch runs) | **both** callback handlers: `CheckoutCompatController::callbackPayment()` (the live alias) and `Payment\PaymentCallbackController::callback()` (the scoped route the switch makes live) → `OrderMailer::placed($id, notifyCustomer: true)`, enqueued inside the transaction, sent after it | both of the above | ✅ |
| 4 | `Admin/OrderController.php:72`, guarded by `if ($previousStatus !== $order->status)` | customer only | `OrderFulfilment::advance()` → `statusChangedNow()`; `OrderFulfilment::cancel()` → enqueued in the transaction, flushed after | `emails.order-status-update` | ✅ |
| 5 | `Console/Commands/SendReEngagementEmails.php:48` | users with no recent order | — | `ReEngagementMail` | ❌ **not ported** |
| 6 | `Observers/ProductObserver.php:104` | wishlist holders on restock | — | `WishlistRestockMail` | ❌ **not ported** |
| — | `App\Mail\OrderCreatedMail` | — | — | — | ❌ **dead class**, no call site anywhere |

### Deliberately NOT ported, and why

- **`ReEngagementMail`** — marketing, not fulfilment. It is sent by a console command nobody has
  scheduled, it uses `->queue()` (which needs a worker core does not have), and it writes
  `users.last_reengagement_at`, a **legacy column core must not write**. Not a switch-night
  concern: the legacy app can keep running it, or it can lapse.
- **`WishlistRestockMail`** — this is **F-27**, decided *not to build* on 2026-09-09. Porting it
  now would reverse a decision, not close a prerequisite.
- **`OrderCreatedMail`** — a class with no call site. Porting dead code would make it look alive.
- **Auth e-mail is a SEPARATE, still-open gap.** `Api/AuthController.php:137/312/392` and
  `Auth/PasswordResetLinkController.php:35` send Laravel's own verification and password-reset
  notifications. Core's compat API has **no auth routes at all** — no `login`, no `register`, no
  `forgot_password` — so nothing about them changes at T-0 and nothing in this task touches them.
  **Flag 3 below.**

### The two asymmetries worth stating out loud

They look like bugs and are not, and getting them wrong is invisible:

- a **card** order tells the admins at checkout and tells the **customer only when the callback
  says the money arrived**. Legacy: line 811 only calls the helper for `cash`/`whatsapp`; line 1032
  calls it with `'cash'` after a successful payment.
- a **WhatsApp** order tells the admins and **never** the customer, because it is an enquiry and
  not a sale.

Both are held by tests that assert the mailable class *and* the recipient
(`OrderMailTriggersTest`), because a count alone would pass with the wrong message.

### Not sent, deliberately

The callback's **payment-failed** branch cancels the order and returns its stock and sends
**nothing** — exactly as the legacy callback does (it restores stock in a loop and calls no
mailer). Whether a customer should be told their card was declined is a product decision; it is
flagged, not invented here.

---

## 2. The templates

Copied, not rewritten. `md5` of both sides, asserted by `OrderMailContractTest`:

| File | Identical to `backend/`? |
|---|---|
| `emails/order-confirmation.blade.php` | ✅ byte-identical |
| `emails/admin-order-notification.blade.php` | ✅ byte-identical |
| `emails/order-status-update.blade.php` | ✅ byte-identical |
| `emails/partials/header.blade.php` | ✅ byte-identical |
| `emails/partials/product-row.blade.php` | ✅ byte-identical |
| `emails/partials/footer.blade.php` | **one line** changed |

The single changed line is the copyright fallback: `config('watchizer.brand.copyright')` →
`config('notifications.brand.copyright')`, because core has no `config/watchizer.php` and the
legacy key would have rendered an empty footer line. The test diffs the two files line by line and
asserts that this is the **only** difference.

### Branding, served from core

There is nothing to serve. `partials/header.blade.php` renders the WATCHIZER wordmark as **text**,
and its own comment says why: the brand logo is dark, it was invisible on the `#111` bar, and Gmail
strips the CSS filter that would recolour it. The footer does the same. **No order e-mail loads a
branding image from anywhere**, which makes "served from core, not the legacy host" true by
construction rather than by configuration — and `OrderMailContractTest` asserts that no template
contains `dash.watchizereg`, `watchizer.brand.logo`, or any `config('watchizer.…` reference.

The only remote images are the **product thumbnails**, and they are built through core's own
`App\Storefront\ImageUrl` from core's own `storefront.asset_base`. The `Uploads_Images` tree is
physically shared during the transition (§5.4), so that base points at the legacy host today and
follows the tree when switch night moves it. Nothing in the mail code changes when it does.

### The data builder

`App\Domain\Notifications\OrderEmailData` replaces the legacy
`App\Mail\Concerns\BuildsOrderEmailData` — the templates are ported, the **builder is rewritten**,
because the legacy one reads Eloquent models with Astrotomic translations and core has neither. It
produces the same 27 keys with the same meanings, from `orders`, `order_items`, `catalog_products`,
`catalog_product_translations`, `catalog_product_variants`, `catalog_product_images`, `offers`,
`addresses`, `shipping_cities` and `users` — five queries per call, no per-line query.

Two legacy keys are deliberately absent, and no template reads either: `order` (the Eloquent model)
and `logo` (a legacy-host URL). Both the key list and each line's key list are asserted.

Three places where core is deliberately **better** than the port, each a defect in the original:

| Legacy | Core | Why |
|---|---|---|
| `trackUrl` = one global `FRONTEND_URL` | the **order's own** `storefronts.domain` | a Brand Fashion customer must not get a watchizereg.com tracking link |
| `dashboardUrl` = `APP_URL/admin/order/{id}` (the legacy dashboard) | `route('manage.orders.show')` | T-0 disables the dashboard that link opens |
| `paymentStatus` from `$order->paymentStatus->success` (whichever row the hasOne returned) | "is there a **successful attempt** on this order?" | a declined first attempt followed by a successful retry read "Pending" — in the e-mail an operator uses to decide whether to pack a parcel |

---

## 3. The outbox: kept, and promoted

**Decision: `integration_outbox` stays, and stops being a list of what is owed — it becomes the
record of what was SENT, plus the retry queue.** It is not redundant; without it the port would
have reproduced the legacy app's actual defect.

The legacy sender wraps each `Mail::send()` in a `try/catch` that writes a log line. So when the
relay refuses, the message is gone and the only trace is in `laravel.log`. That is the silence
prerequisite (a) exists to close, so "silent" is the one property the new sender may not have.

Every send is therefore two steps:

1. **enqueue** — one row per *message* (not per order), written in the same breath as the state
   change: inside its transaction where there is one, so an order that rolls back owes no e-mail
   and an order that commits always does. The row **is** the obligation.
2. **flush** — attempt delivery immediately after the commit. Success ⇒ `sent`. Failure ⇒ `pending`
   with a backoff, and the one-minute cron retries it.

### What each status means to an operator

| status | meaning |
|---|---|
| `sent` | handed to the relay, with `processed_at` |
| `pending` | owed; either not yet attempted or deferred after a failure, with `available_at` |
| `sending` | claimed by a process right now (reclaimed by `mail:drain --reclaim` if that process dies) |
| `failed` | **a real person was not told something** — attempts exhausted, or terminally impossible |
| `skipped` | there was nobody to tell (a guest order with no e-mail address) — information, not a fault |

`skipped` exists because the legacy code's `if ($customerEmail)` was a silent no-op. An operator
now sees "there was nobody to tell" instead of an empty panel and a question about the mailer.

### Exactly once, enforced by the database

- `integration_outbox.dedupe_key` is **UNIQUE** (migration M1k). A second enqueue of the same event
  for the same recipient is refused by MariaDB, so a double-clicked *Advance*, a resubmitted
  checkout or a replayed callback cannot enqueue a second message at all.
- delivery **claims** its row with a conditional `UPDATE … WHERE status = 'pending'`. Only one
  process wins, so an in-request flush and an overlapping cron tick cannot both send it.

`OrderMailTriggersTest` proves both: the same transition applied twice sends one message, and a
hand-written duplicate insert is refused by the database rather than by application code.

### Where to see what was sent for an order

- **the order screen** — a *رسائل هذا الطلب* panel: message, recipient, status, attempts, the
  error if any. Per-order, because "did he get it?" is asked about one order, on the telephone.
- **`php artisan mail:drain --report`** — the queue view, with counts by status and a table of
  failed rows. It **exits non-zero while anything is `failed`**, the same loudness
  `payments:findings` has for money.
- `integration:drain` still **refuses the `mail` channel by name**, and its message now points at
  `mail:drain`. Marking a mail row `skipped` would both erase the record and throw away a message
  somebody is waiting for.

---

## 4. Sending mode: inline, with a cron behind it

**Decision: send in-request, immediately after the state change commits — matching the legacy app —
and keep a one-minute cron as the retry path.** Not "queue everything via cron", and not "inline
only".

Legacy sends synchronously in-request (its own comment: *"Emails send SYNCHRONOUSLY … no
worker/cron needed … this runs after DB::commit(), so the order is already safely persisted"*).
Matching it keeps the customer's confirmation arriving while they are still on the thank-you page,
which is the behaviour the shop has today and the one a shopper notices losing.

### What a slow SMTP round trip does to the request — measured

**The real send in §6 took 3.69 s for one message**, end to end, inside a 200 response: Gmail's
TLS handshake from Egypt plus the message. That is the honest cost, and it is the operator's or the
shopper's wait. With four admin recipients a COD checkout would carry roughly **four of those,
sequentially** — around fifteen seconds — because the legacy sender loops the addresses and so does
this one.

Three things bound it:

1. **`config/mail.php` now sets `'timeout' => env('MAIL_TIMEOUT', 10)`** instead of Laravel's
   `null` (which means PHP's `default_socket_timeout`, 60 s on most builds). An unreachable relay
   therefore costs a noticeable pause, not a minute per message — and nothing is lost when it
   expires: the row stays `pending` and the cron retries.
2. **nothing is sent inside a transaction.** `OrderMailer::flush()` refuses while
   `DB::transactionLevel() > 0`, so an SMTP round trip never holds the locks on an order and its
   product rows. On the cancel path that matters: the enqueue is inside the transaction (so the
   customer is not told about a cancellation that rolled back) and the send is after it.
3. **`ORDER_MAIL_INLINE=false`** switches the whole thing to cron-only in one environment variable,
   with no code change and nothing lost — the obligation rows are written either way. That is the
   setting for a host whose relay turns out to be slow or rate-limited, and the reason the decision
   is reversible without a deploy.

### The cron path, proven to run as configured

`routes/console.php` registers `mail:drain --reclaim` `->everyMinute()->withoutOverlapping()`. It
**rides the `schedule:run` entry wave 3 already needs** for
`inventory:reconcile-cancellations`, so switch night adds **no new crontab line** (§5.2.1).

Proven, not assumed — `schedule:list` and then a real `schedule:run` in its own process:

```
*  * * * *  php artisan inventory:reconcile-cancellations ...... Next Due: 58 seconds
0  * * * *  php artisan integration:drain --channel=morabaa ..... Next Due: 37 minutes
*  * * * *  php artisan mail:drain --reclaim ................... Next Due: 58 seconds
30 3 * * *  php artisan inventory:verify ....................... Next Due: 15 hours

before cron: id=413 event=order.status.cancelled status=pending attempts=0
  2026-09-13 12:24:52 Running ["artisan" mail:drain --reclaim] ......... 625.84ms DONE
after cron : id=413 event=order.status.cancelled status=sent attempts=1 processed_at=2026-09-13 12:24:53
```

What that proves: the scheduler dispatches the command, and the command delivers a message that
inline sending had left behind. What it does **not** prove is that Hostinger's crontab is installed
— that is a hosting step, and it is already a switch-night runbook item because wave 3's
cancellation reconciler depends on the same single `schedule:run` line.

---

## 5. Failure behaviour

> *"An SMTP failure must never lose the order, block a status change, or show a 500. Log it, record
> it against the order, and make it visible to the operator — a failed notification is an
> operational fact, not an exception."*

Nothing in `OrderMailer` throws at its caller. `OrderMailFailureTest` proves each half with a
**real** transport failure — the SMTP transport is pointed at `127.0.0.1:1` with a one-second
timeout, so Symfony genuinely throws a `TransportException` from inside `Mail::send()`. A mocked
throw would only prove the catch block catches what the test threw.

| Requirement | Proof |
|---|---|
| never lose the order | a checkout whose e-mail fails: order untouched, stock unchanged, both messages on record as `pending` |
| never block a status change | `PUT /manage/orders/{id}/status` with the relay dead: **redirect, no session errors**, status is `shipped` |
| never a 500 | same, and the cancel path: redirect, order `cancelled`, `order_cancel` movement in the ledger |
| log it | `Log::warning` (deferred) / `Log::error` (permanent) with the outbox id, the attempt count and the exception **class** — a `TransportException` is a relay problem, the same words from something else are a bug. Recipients are masked in the log (`m***@example.com`) |
| record it against the order | the row: `attempts`, `available_at`, and `last_error` carrying the exception class and 400 characters of its message |
| visible to the operator | the order screen's panel, red for `failed`; and `mail:drain --report`, non-zero exit while any row is failed |
| and eventually delivered | deferred → `mail:drain` → `sent`, `attempts = 2`: the count is the evidence it was a retry and not a fresh message |
| and eventually given up on | after `max_attempts` (5 by default, 3 in the test) the row is `failed` and is never retried again — a queue that retries forever is a queue nobody reads |

---

## 6. Evidence

### Rendered messages, `MAIL_MAILER=log`

One real compat checkout (`POST /api/add_order`, COD, in-process through the HTTP kernel) then the
fulfilment walk. Five messages, which is exactly what the legacy rules require:

```
POST /api/add_order -> 200
order             : #000036 (id 2409), total 3,190.00 EGP, status processing
advance -> shipped    status now shipped
advance -> delivered  status now delivered
advance -> completed  status now completed

outbox rows for this order
  event                      kind                       status   tries  error
  order.status.completed     status_update              sent     1      -
  order.status.delivered     status_update              sent     1      -
  order.status.shipped       status_update              sent     1      -
  order.placed               admin_notification         sent     1      -
  order.placed               customer_confirmation      sent     1      -
```

Saved under **`core/docs/wave4c/mail/`**, one file per message:

| File | What it shows |
|---|---|
| `01-order-placed-customer-confirmation.html` | "Order Confirmed · تم استلام طلبك", `#000036`, the line with its thumbnail, subtotal / shipping / **3,190 EGP**, the address block, "Cash on Delivery · الدفع عند الاستلام", the *Track Your Order* button pointing at `https://watchizereg.com/order-list` |
| `02-order-placed-admin-notification.html` | "🛒 New Order Received", customer type **Guest**, payment status **Cash on Delivery (unpaid)**, the product table with SKU / model / bucket, grand total, and *Open Order in Dashboard* → **`/manage/orders/2409`** (core, not the legacy dashboard) |
| `03-status-shipped.html` | the `shipped` badge and *"Your order is on its way to you."* / *"طلبك في الطريق إليك."* |
| `04-status-delivered.html` | *"Your order has been delivered. We hope you love it!"* / *"تم توصيل طلبك."* |
| `05-status-completed.html` | *"Your order is complete. Thank you for shopping with Watchizer!"* / *"تم اكتمال طلبك."* |

**Redaction:** this repository is public, so every e-mail address and phone number in those files
was replaced with `[address redacted]` / `[phone redacted]`, and the raw MIME log (which carries
`To:` headers) was deleted. The substitution is asserted not to have eaten the evidence: each file
is re-checked for its own markers afterwards. The rendering, the copy, the totals and the links are
untouched. Nothing in them was a customer's data in the first place — the probe order used the
shop's own mailbox and a placeholder phone.

### The one real send

Authorised by the brief, and exactly one message left this machine. A **WhatsApp** probe order was
used deliberately: under the legacy rules that notifies the admins and never the customer, so "one
real send" is true by construction rather than by care.

```
mailer      : smtp via smtp.gmail.com:587, timeout 10s
recipients  : 1 — [the developer's own address]
POST /api/add_order -> 200 in 3.69s (the SMTP round trip is inside this)
order       : #000037 (id 2410), 3,190.00 EGP, whatsapp
outbox      : id=415 event=order.placed kind=admin_notification status=sent attempts=1 to=[redacted]
```

**Sent to the developer's own personal address** (named in the hand-off, not written here: this repository is public and an address in a committed file is an address in a search index). Never a customer's. The
SMTP values copied into `core/.env` work: host, port, TLS, username, password and from-address all
accepted by Gmail on the first attempt. This is the **admin notification**, which is the one the
brief asked to include.

### Everything else

| Check | Result |
|---|---|
| `compat:diff` (126 cases as it stood that day; 127 since — study §3.8.6) | **PASS — zero unexplained**, 58 byte-identical, 68 sanctioned-only. Compat checkout responses did not change shape because sending moved |
| Pest ×2 | **808 passed / 12 008 and 11 980 assertions, 0 failed**, 7 skipped (the opt-in `CAPTURE_SCREENS` captures) |
| PHPStan (level 10, **default flags**) | no errors |
| Pint | passed |
| `tsc --noEmit` | clean |
| `core:transform` after the closing rebuild | 83 reconciliation checks, all reconcile; 13 687 inserted, 0 updated |
| `inventory:verify` | 1 060 checks over 530 products — ledger, variant columns, aggregate and `in_stock` all agree |
| `payments:findings` | no open findings |
| servers | stopped, ports 8000 and 8011 verified free |

New tests: **32** across four files in `tests/Feature/Notifications/` — `OrderMailTriggersTest`
(11), `OrderMailContractTest` (11), `OrderMailFailureTest` (7), `OrderMailTransactionGuardTest`
(3); plus three rewritten in `DropCleanCommandTest` and two updated wave-3 tests.

---

## 7. Two things the harness run found that were not about e-mail

### 🔴 `core:drop-clean` had stopped clearing the whole migration ledger

Found by running the rebuild, not by reading it. The command cleared `core_migrations` rows matching
`LIKE '2026_09_1%'`. Wave 4C's M1j (`2026_09_20_…`) and this wave's M1k (`2026_09_21_…`) do not
match, so:

- the rebuild dropped `integration_outbox` and recreated it from `create_clean_core` — **without
  `dedupe_key`**;
- M1k did not re-run, because its ledger row still claimed it had;
- **the UNIQUE index that makes an order e-mail exactly-once was gone, and `migrate` reported
  nothing to do.**

M1j was harmless by luck (its targets are preserved tables). M1k was not, and any future
`2026_09_2x` core migration would have been silently skipped by every rehearsal and by switch
night itself.

**Fixed:** there is no pattern. `core_migrations` is core's **own** migration repository
(`config/database.php`); the legacy application records its own in `migrations` and never touches
this table, so every row is a core migration by construction and the honest operation is "clear the
ledger". The command now also warns when a ledger row names a migration with no file on disk. The
rebuild after the fix ran **all 11** migrations.

**And the test that should have caught it was a tautology.** It asserted
`MIGRATION_PATTERN === '2026_09_1%'` and then that rows matching `'2026_09_1%'` start with
`2026_09_1` — true of any pattern and any data. It has been replaced by three tests that assert the
property: the clear covers *every* row, every ledger row has a file, and `integration_outbox` really
carries `dedupe_key` with its unique index **after** a rebuild.

### The harness needs pacing on this machine

The first harness run reported 64 unexplained differences, all of them **429**: core's own
`throttle:api` (60/min per IP, `RateLimiter::for('api')`) refused the run, because 126 cases in 73
seconds is over the limit and the file cache still held the counter from the evidence scripts. With
`php artisan cache:clear` and `--pace=1500` the run is clean. Worth adding to the harness notes —
the limit is correct behaviour, and the harness is a client that trips it.

Three further cases (`cors:preflight:*`) differ **only** under `--compat=inproc`: the legacy side
goes through a web server that adds PHP's default `Content-type: text/html; charset=UTF-8` to a 204,
and an in-process request has no web server to add one. Verified directly with `curl -X OPTIONS`
against both dev servers — both emit the header — and the run with `--compat=http://127.0.0.1:8000`
is byte-identical. **`inproc` is not a valid mode for the CORS preflight cases**; the passing run
above used real HTTP on both sides.

---

## 8. Loud flags

1. **`ORDER_ADMIN_EMAILS` was NOT in `core/.env`.** The brief stated it had been copied across with
   the mail variables; `grep -nE "ADMIN_EMAIL|ORDER_ADMIN" core/.env` returned nothing, as did
   `FRONTEND_URL`, `WATCHIZER_WHATSAPP_SUPPORT`, `MEDIA_URL_BASE` and `STOREFRONT_ASSET_BASE` (only
   `APP_URL` was present). The MAIL_* keys were all there.
   I set `ORDER_ADMIN_EMAILS` to **your own address, as a placeholder** (the value is in
   `core/.env`, which is gitignored — it is deliberately not repeated in this file, because the
   repository is public). **Replace it with the real admin list before T-0.** The
   other four keys need nothing: core's own config supplies every value the templates use
   (`storefronts.domain` for the tracking link, `notifications.whatsapp_support` and
   `notifications.brand.copyright` with the legacy defaults).
   An empty list is **not** treated as "nobody wants to know": `OrderMailer` writes a visible
   `failed` row against the order naming the missing variable, and `mail:drain --report` exits
   non-zero while it stands. That is asserted by a test.

2. **A rebuild erases the mail log.** `integration_outbox` is one of the 41 clean tables
   `core:drop-clean` drops, so a rehearsal rebuild destroys the record of what was sent while the
   `orders` rows survive — which is exactly what happened during this task's closing rebuild
   (`mail:drain --report` now says "no order e-mail on record" for orders whose messages demonstrably
   went out). That is acceptable **before** the switch and must not happen after it. It needs a line
   in the rehearsal rules and in the switch-night runbook: **after T-0 there is no more
   `core:drop-clean`**, and if one is ever needed the mail rows must be exported first.

3. **Auth e-mail is a separate, still-open silence.** Verification and password-reset mail live in
   the legacy app (`AuthController`, `PasswordResetLinkController`). Core serves no auth endpoint,
   so T-0 changes nothing about them *today* — but the moment core serves login or registration,
   the same gap opens again, with no template ported and no sender. It belongs on the register as
   its own item, not inside prerequisite (a).

4. **Brand Fashion's e-mails will say WATCHIZER.** The ported header and footer hard-code the
   wordmark as text, and the brief said not to rewrite the copy. `brandName` already resolves from
   the order's own storefront and `trackUrl` already points at the right domain, so the data is
   ready — but the two partials need a per-storefront variant before Brand Fashion takes an order.

5. **A declined card tells the customer nothing.** Legacy parity, stated in §1. If that should
   change it is a product decision and a new template, not a port.
