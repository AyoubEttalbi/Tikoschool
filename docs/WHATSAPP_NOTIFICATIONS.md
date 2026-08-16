# Parent notifications over WhatsApp

How TikoSchool tells a guardian their child was absent, what happens when that fails, and
why each piece is built the way it is.

The system is deliberately boring in one respect: **every decision leaves a row**,
including the decision *not* to send. A school's only real question is "was this parent
told?", and a notification system that answers it with silence is worse than one that
throws.

---

## 1. What it does

A teacher saves a register. For every student marked absent, the app records one message,
renders it, and **stops** — the notice waits in `awaiting_approval` until an admin or
assistant releases it, one row at a time from the absence log or for the whole day at
once. Only then does a worker deliver them, one at a time, spaced, through a self-hosted
WhatsApp gateway. Everything that happens after that — success, failure, refusal, delay —
is written back to the same row and shown on one screen.

**The register records; a person sends.** A wrong checkbox on a teacher's screen used to
reach a parent within seconds, from the school's own number. Now it costs a row that
nobody approves. Correcting the mistake — marking the student present or late, deleting
the absence — withdraws the waiting notice automatically.

**Send-only.** Nothing receives, reads or replies. The gateway registers no message
handler and exposes no read endpoint: every capability it does not have is one that cannot
be abused if its key leaks.

---

## 2. System design

### 2.1 Shape

```
   Teacher saves "Registres de présence"        Admin presses "Notifier le parent"
                    │                                          │
                    └────────────────┬─────────────────────────┘
                                     ▼
                        AttendanceController
                      (authorises, opens a tx)
                                     │
                                     ▼
                        OutboundMessageService
        ┌────────────────────────────┴────────────────────────────┐
        │  resolve recipient   →  skip + reason, or E.164 number   │
        │  build idempotency key                                   │
        │  render template     →  snapshot the exact text          │
        │  apply quiet hours   →  scheduled_at                     │
        │  INSERT (unique key decides duplicates)                  │
        └────────────────────────────┬────────────────────────────┘
                                     ▼
                          outbound_messages  (the record)
                                     │
              register: awaiting_approval — NOTHING DISPATCHED
              manual button: pending — dispatched ->afterCommit()
                                     │
              Admin releases (per row, or "tout" for the day)
                                     ▼
                          queue "whatsapp"  (one line, FIFO)
                                     ▼
                          SendOutboundMessage
        ┌────────────────────────────┴────────────────────────────┐
        │  gateway linked?     → no: HOLD (no attempt spent)       │
        │  number dead?        → yes: SKIP (breaker open)          │
        │  pacing lock         → reserve a slot, release the lock  │
        │  send (outside lock) → sent / failed(permanent|transient)│
        └────────────────────────────┴────────────────────────────┘
                                     ▼
                          WhatsAppChannel → App\Support\WhatsApp
                                     ▼
                    gateway (Baileys)  →  WhatsApp  →  parent

   every minute:    notifications:retry-failed  (release held, retry failed,
                                                 expire stale — never approve)
   weekly:          whatsapp:audit-numbers       (which parents are unreachable)
```

### 2.2 Layers, and what each one is not allowed to know

| Layer | Knows | Must not know |
|---|---|---|
| `AttendanceController` | a student was absent | that WhatsApp exists |
| `OutboundMessageService` | there is a message to send on some channel | which provider delivers it |
| `SendOutboundMessage` | a row id, the pacing rules | how to format a message |
| `MessageChannel` / `WhatsAppChannel` | how to hand text to a channel | anything about attendance |
| `App\Support\WhatsApp` | which provider, and its HTTP shape | anything about students |

Adding SMS is a class plus a `match` arm in the job. Nothing above the service changes.

### 2.3 Why there is no `NotificationProvider` factory

There is a `MessageChannel` interface, and exactly one implementation. Provider swapping
(self-hosted gateway ↔ the old paid vendor ↔ log ↔ null) already lives one level lower, in
`WhatsApp`'s `match` on `config('whatsapp.driver')`. A second abstraction over it would be
five files buying swappability that already exists. The interface exists for the **channel**
(WhatsApp vs SMS), not the provider behind it.

### 2.4 Data model

**`outbound_messages`** — one row per notice, ever.

| Column | Why |
|---|---|
| `idempotency_key` UNIQUE | the whole duplicate story, §4.1 |
| `student_id`, `school_id`, `attendance_id` | joins and scoping; **never** in a unique index |
| `recipient` | E.164 digits, **snapshotted** — the student's number may change tomorrow |
| `message` | the rendered text, **snapshotted** — the template may be reworded tomorrow |
| `status` | `awaiting_approval · pending · held · sent · failed · skipped · expired` |
| `skip_reason` / `hold_reason` | why nothing was sent, in a form a screen can explain |
| `attempts`, `last_error` | what was tried and what came back |
| `provider`, `provider_message_id` | which driver delivered it; the id a future webhook will match on |
| `scheduled_at`, `held_since`, `sent_at`, `failed_at` | when it may go, when it started waiting, when it went |

`$hidden = ['recipient', 'message']`. This is the second-highest-PII table in the app — a
guardian's mobile number and a child's absence — and the admin screen needs neither.

**Not called `notifications`**: `App\Models\User` uses Laravel's `Notifiable`, which
reserves that table name.

**`students`** gains two columns:

- `notifyGuardian` — the off switch (a parent asks to stop; a number is dead).
- `guardianNumberFailures` — consecutive failures, for the circuit breaker (§4.6).

### 2.5 Statuses

```
                    ┌──────────► skipped   (decided before sending: no number,
                    │                        opted out, archived, breaker open,
                    │                        or cancelled during review)
   created ─► awaiting_approval ─► pending ─► sent
                    │                │
                    │                ├──► held ──► pending  (system was down; released automatically)
                    │                │
                    │                └──► failed ─► pending  (attempted; retried inside a short window)
                    │
                    └──────────► expired      (too old to be worth delivering — the only
                                             way awaiting_approval ever leaves its wait)
```

`awaiting_approval` and `held` both mean "deliberately not sending yet", and they are
opposites in the one way that matters: `held` waits for the SYSTEM and is released the
moment the gateway returns; `awaiting_approval` waits for a PERSON and is never released
by the recovery sweep. Both expire with age. Collapsing the two would let a machine
approve what only a human may.

---

## 3. The two entry points

### 3.1 "Registres de présence" — the bulk path ✅

`/attendances?class_id=…&teacher_id=…` → `AttendanceController::store()`.

For each student marked absent, one `outbound_messages` row — recorded `awaiting_approval`,
**nothing dispatched**, whatever role saved the sheet. **One message per absence**,
delivered **one at a time** by the pacer, **once a human releases the day**.

A student absent from **Maths and French on the same day gets two messages** — two
absences, two facts, two notices. Only the *same* absence is deduplicated.

Saving the sheet is not the approval, and re-saving it must not become one either: a
teacher correcting a typo re-saves the identical sheet, and the duplicate key leaves the
waiting notice exactly as it was.

### 3.2 The manual "Notifier le parent" button

`POST /absence/{student}/notify`, admin + assistant. One notice per student **per subject
per day**, so a double-click sends nothing twice while a genuine second subject still gets
through. `subject` is validated (`max:120`) and stripped of WhatsApp's formatting
characters before it reaches a parent's phone.

The button is also the per-row approval. When the register already recorded the notice as
`awaiting_approval`, the press releases that row instead of refusing it as a duplicate —
"this parent has already been notified" is the one wrong answer for a message that never
left the building. The refusal stands for a notice that actually went out.

### 3.3 "Valider et envoyer tout" — the end-of-day release

`POST /absence-notifications/release` `{date}`, admin + assistant, from the bar the
absence log shows when the day has waiting notices. Releases every `awaiting_approval`
row **for that date's absences** (selected by the attendance's date, not the row's
creation date — a register saved today can be backfilling yesterday). Scoped to the
caller's schools like every write here.

Release re-resolves the guardian number at that moment: a number corrected — or an
opt-out — between the register and the approval wins over the snapshot, and a number
that became unusable is recorded as a skip rather than sent into the void.

Fixing a wrong absence needs no release-time exclusion list: mark the student present or
late on a re-save, or delete the absence, and the waiting notice is withdrawn
(`skipped`, reason `cancelled`) before the release ever sees it.

---

## 4. Scenarios

Everything below is covered by a test. The test names are quoted so you can find them.

### 4.0 The teacher gets it wrong

The scenario the whole gate exists for. A teacher ticks "absent" for a student who was in
class, saves, and realises ten seconds later. Before the gate, the message was already in
the queue — within seconds a parent read an official school notice that was false, and
nothing in the app could unsay it.

Now the mistake is a **row nobody has approved**. The teacher fixes the sheet (present,
or late) on a re-save and the waiting notice is withdrawn automatically; nothing needs to
be known about notifications by anyone who was only trying to record attendance. If the
day's release already happened before anyone noticed, the correction has nothing left to
withdraw — but the school finds out from the screen, not from the parent.

> *"records absent notices without dispatching anything"*
> *"cancels the waiting notice when the student is marked present on re-save"*
> *"cancels the waiting notice when a re-save downgrades absent to late"*
> *"leaves a cancelled notice cancelled when the day is released"*

### 4.1 The same register saved twice

Re-saving a sheet to fix one typo used to re-message **every absent student's parent in
that class** — `store()` dispatches from outside the create/update branch.

The key is the attendance table's own unique tuple:

```
absence:{student_id}:{date}:{classId}:{teacher_id}:{sha1(subject)[0..12]}:whatsapp
```

Not the attendance row's **id**, because marking a student *present* **deletes** the row —
so a present→absent toggle would mint a new id, a new key, and a new message. One
checkbox, flipped twice, would message a parent twice.

`subject` is hashed, not interpolated: it is free text and could otherwise smuggle a `:`
into the key or overflow the 191-character column.

Insertion catches `UniqueConstraintViolationException` rather than using `firstOrCreate`,
which races: two concurrent saves both see no row and both insert.

> *"does not create a second message when the same sheet is saved again"*
> *"does not message a guardian again when a student is toggled present and absent"*

### 4.2 Two teachers saving at once — the train 🚆

Teacher A saves three absentees; teacher B saves two while A's are still going out. All
five join **one queue**, in order. The pacer lets exactly one leave per slot. B's list is
appended, not dropped and not sent in parallel.

> *"queues both teachers' registers into one line and sends them one at a time"*
> *"lets only one message through per pacing slot"*

### 4.3 Somebody unlinks the phone

A WhatsApp link is a paired device; a phone can un-pair it at any moment. From the app's
side that looks like every send failing with an ordinary error — so an evening's absences
would each burn their retry budget against a gateway that was never going to accept them,
and end up permanently failed.

The job **asks first** (`WhatsAppGateway::canSend()`). Not linked ⇒ `held`,
`attempts` untouched, nothing lost. The recovery sweep releases the backlog the moment the
link returns.

> *"holds messages instead of failing them when WhatsApp is disconnected"*
> *"holds when the gateway process is not answering at all"*
> *"sends the held backlog once the phone is linked again"*

### 4.4 The gateway is down for four days

The reason the recovery window is **not** measured in days for held messages. A window of
"the last day" silently discards Friday and Saturday when the outage runs to Tuesday —
precisely the outage this system exists to survive.

- **Held** (never attempted): released regardless of how long the outage lasted.
- **Failed** (attempted): short window, default 2 days. A failure is *evidence*, usually a
  bad number, and re-driving week-old evidence burns slots working numbers need.
- **Age** is the only thing that abandons a message: past `whatsapp.max_age_days`
  (default 5) it becomes `expired`. The notice carries its own date, so late delivery is
  still accurate — a week later it is just confusing.

Releasing into a gateway that is *still* down is refused, or every row would be re-held and
the queue would churn.

> *"survives a four-day outage without losing anything inside the window"*
> *"abandons a notice that has gone stale rather than surprising a parent"*
> *"leaves held messages held while the gateway is still down"*

### 4.5 A message that failed for a real reason

Failures are classified, because the retry logic depends on a distinction a boolean cannot
carry:

| Gateway says | Verdict | Behaviour |
|---|---|---|
| 400 / 401 / 403 / 404 / 422 | **permanent** | stop now — a rejected recipient or a bad key stays broken until a human acts, and re-pushing a rejected recipient is itself a way to get flagged |
| 5xx, timeout, connection refused | **transient** | throw, back off `60s → 5m → 15m` |
| unusable phone number | **permanent** | never reaches the network at all |
| missing API key | **permanent** | retrying a misconfiguration for hours buries the one line an operator needs |

### 4.6 A guardian number that is simply dead

Every attempt against a number that will never work costs a pacing slot a working number
could have used. `students.guardianNumberFailures` counts **consecutive** failures; at
`whatsapp.max_number_failures` (default 4) the student is skipped with
`recipient_unreachable` and appears on the screen for somebody to fix.

The counter resets on **either** a successful send **or** an edit to `guardianNumber` —
without that reset, fixing the typo would not be enough and the guardian would never be
messaged again, a worse bug than the one the counter solves.

> *"stops chasing a guardian number that keeps failing"*
> *"forgives the number as soon as one message gets through"*
> *"forgives the number when somebody corrects it"*
> *"does not reset the counter on an unrelated edit"*

### 4.7 A worker dies mid-job

The container restarts between reserving a slot and recording the outcome. The row sits
`pending` forever and nothing ever looks at it again — a parent silently never told, with
no error anywhere. The sweep rescues rows whose `scheduled_at` is more than two hours past.

> *"rescues a message stranded in pending by a dead worker"*
> *"does not disturb a message that is simply waiting for the morning"*

### 4.8 Nobody is running a worker

The silent one: rows keep being created, statuses stay `pending`, and everything else looks
healthy. The admin screen shows queue depth and warns when it is not dropping.

Production's supervisor runs `queue:work --queue=whatsapp,default`. **Locally, `composer
dev` runs `queue:listen` on the default queue only** — bulk absences will not send on a dev
machine unless you start a worker for the `whatsapp` queue.

### 4.9 An absence recorded at 22:15

Quiet hours. Messages created outside `school.notify_from` … `notify_until`
(09:00–21:30) are **scheduled** for the next opening rather than sent at midnight or
dropped. `scheduled_at` drives both the row and the queue delay.

> *"holds a late-evening absence until the next morning"*
> *"holds an early-morning absence until the window opens"*

### 4.10 A parent with no usable number

Three distinct skips, each recorded rather than logged:

| `skip_reason` | Meaning |
|---|---|
| `no_number` | no `guardianNumber` at all |
| `unnormalisable` | present but cannot be a phone number |
| `opted_out` | `notifyGuardian = false` |
| `student_archived` | the student was soft-deleted |
| `recipient_unreachable` | the breaker opened |

An unnormalisable number is the nastiest failure in the whole feature, because it does not
error — it delivers to nobody while every screen shows success. `whatsapp:audit-numbers`
exists to find them and exits non-zero when it does.

### 4.11 Two children of the same parent

Both are absent, the parent gets two messages. Different children, different facts —
treated as correct, not deduplicated.

### 4.12 Sent ≠ delivered

A 2xx from the gateway means it **accepted** the message, not that a phone displayed it.
Real delivery is only observable through webhooks, which this app does not implement.
`provider_message_id` is stored ready for the day it does.

---

## 5. Not getting the number banned

The gateway drives a real WhatsApp account with nothing in front of it. A burst of
near-identical automated messages is what gets a number blocked, and **nothing upstream
throttles for you**.

| Control | Default | Where it lives | Why there |
|---|---|---|---|
| Minimum gap | 8s | cache | losing it to a `cache:clear` costs one tight interval |
| Jitter | 0–4s | cache | a message every *exactly* N seconds is a machine signature |
| Cross-process lock | 10s TTL | cache | two workers must not both decide they may send |
| Daily cap | 250 | **database** | a safety net that evaporates on a cache flush is not a safety net |

**The lock covers the decision, not the send.** An earlier version held it through the HTTP
call — 20s timeout, 10s TTL — so a merely *slow* gateway let the lock expire mid-send, a
second worker acquired it, saw the same "clear to go" state because nothing had been
recorded yet, and sent concurrently. The pacer producing the exact burst it exists to
prevent. The slot is now **reserved** under the lock; the network call happens after.

A failed send still consumes its slot: pacing governs how often the gateway is *contacted*,
and a failure contacted it just as much as a success.

Jobs **re-queue** rather than `sleep()`. A sleeping worker does no other work, and sleeping
inside the lock was how the TTL got outlived in the first place.

---

## 6. The message

`resources/views/notifications/whatsapp/absence.blade.php`. A Blade file, not a
`notification_templates` table: there is no admin UI to edit a template, so a table would
be a config store edited with raw SQL, and a file is diffable, greppable and reviewable.

**The rendered text is snapshotted onto the row.** Rewording the template tomorrow must not
rewrite what a parent was told yesterday.

### 6.1 School details — brand vs branch

`schools` holds **branches**: "Tiko school C1", "Tiko school C2". That is what the app
needs internally to scope students, classes and money. It is **not** what a parent knows
the school as.

So the split is per value, not global:

| Value | Source | Why |
|---|---|---|
| Name | `config('school.name')` — **always** | a parent knows "Tiko School", not which branch row their child sits in. Every branch brands identically |
| Instagram | config | brand-level, and **empty omits the line** rather than linking an account that is not the school's |
| Phone | the **branch's** `phone_number`, config as fallback | this genuinely differs per branch, and it is the number the parent is asked to call |

Both mistakes have now been made and fixed: reading config for everything sent messages
branded with a literal left over from the old controller ("Centre Red city"), and reading
the row for everything would brand them "Tiko school C2".

### 6.2 Right-to-left text

Arabic with Latin names, subjects, URLs and digits embedded in it. Left alone, Unicode's
bidi algorithm reorders the neutral characters around each Latin run: a sentence ending
`... بمركز Tiko School.` shows the full stop on the **wrong side**, and a grouped phone
number can render with its groups out of order — worse than ugly, because a parent may dial
it.

Two invisible controls, both load-bearing:

| | Codepoint | Where | Job |
|---|---|---|---|
| RLM | `U+200F` | start of every Arabic line | pins the line's base direction so a leading emoji or digit cannot let the first Latin word decide it |
| FSI … PDI | `U+2068` … `U+2069` | around every interpolated value | seals the value into its own run so neutrals at its edges stay with the Arabic |

*Isolate*, not *embed*: embedding still lets edge neutrals join the surrounding run, which
is exactly the misplaced-full-stop bug. Unbalanced isolates are worse than none — an
unclosed run swallows the rest of the message — so a test counts them.

### 6.3 Escaping, and why there is none

The template prints every value with `{!! !!}`, not `{{ }}`, and that is deliberate.

This is a plain-text WhatsApp message. It is never parsed as HTML, so Blade's escaping is
not protection here — it is corruption. It turned the Instagram link's `&` into `&amp;`,
and it would have told a guardian that their child **"O&#039;Brien"** was absent.

What actually protects the message is `plain()`, which strips the characters **WhatsApp
itself** renders as formatting — `*`, `_`, `~`, backtick — from anything a human typed.
Without it, staff free-text could dress arbitrary content, including a link, up as an
official school notice.

One exception: values that come from **config** skip `plain()` (`isolateTrusted()`). It
strips `_` for WhatsApp italics, which silently turned `utm_source` into `utmsource` and
produced a dead Instagram link in every message. Config is operator-controlled; there is
nothing to sanitise.

---

## 7. The gateway

`tools/whatsapp-gateway/` — ~200 lines of Node over
[Baileys](https://github.com/WhiskeySockets/Baileys). It speaks **Evolution API's HTTP
contract** (`POST /message/sendText/{instance}`, `apikey` header), so swapping to Evolution
API proper is one env var and no code change.

### Why not Evolution API / WAHA / WPPConnect

All three are the same category — unofficial, Baileys or WhatsApp-Web underneath, same ToS
position and same ban risk. The choice was made by the VPS:

| | RAM | Needs |
|---|---|---|
| WPPConnect | ~500 MB | a full Chromium per session |
| WAHA (default engine) | ~400 MB | Docker, Chromium; some features are paid |
| Evolution API | ~250 MB | Docker + Postgres |
| **this** | ~150 MB | node |

The trade is maintenance: WhatsApp changes its protocol every few months, and Evolution has
a community that reacts to that. Switching later costs one env var, by design.

### Endpoints

| | Auth | |
|---|---|---|
| `GET /health` | none | `{state, instance, hasQr}` — no secrets |
| `GET /qr` | none | HTML page with the QR, self-refreshing |
| `GET /qr.json` | **apikey** | the QR as data, for the admin screen |
| `POST /logout` | **apikey** | unlink the phone and clear credentials |
| `POST /message/sendText/{instance}` | **apikey** | `{number, text}` |

`state` ∈ `connecting · qr · open · logged_out`.

### Two things that will bite you

**`AUTH_DIR` must survive a restart.** It holds the linked-device credentials. Lose it and
somebody has to physically scan a QR again — notifications are down until a person with the
school phone stands at a screen. In Docker: a named volume.

**It listens on loopback only, deliberately.** This process can message every parent in the
school from the school's number, and `/qr.json` hands out the credential that links a new
device. Never bind it to a public interface.

---

## 8. The admin screen

**AUTRE → Notifications WhatsApp**, `/notifications`, **admin only** — tighter than the
notify button assistants may press, because the QR shown there is a **credential**: whoever
scans it links their own device to the school's WhatsApp and can read every conversation on
it.

- **Service card** — connection state in French with its consequence ("Rien n'est envoyé.
  Les messages attendent — ils ne sont pas perdus"), the QR with three-step instructions
  when one is needed, and **Déconnecter** behind a confirmation that states the cost before
  the click. Polls only while a QR is on screen (codes expire in ~20s).
- **Réglages du service** — driver, session, queue depth, failed jobs, pacing, daily cap
  used/total, sending hours, abandon thresholds.
- **Seven status tabs**, each also the filter for its own count. `held` counts **all days**,
  not just the one being viewed — a four-day backlog would be invisible on a today-only
  screen. `À valider` is the approval queue: its rows carry **Envoyer** (the release,
  one at a time) rather than Renvoyer.
- **Table** — student, status, reason, attempts, time, retry. Guardian numbers and message
  bodies are never sent to the browser.

Retry is offered on `failed`, `skipped`, `awaiting_approval` and `expired` only. Never on
`pending` (a job is already coming — a second one double-sends) and never on `held`
(released automatically; a manual nudge would only re-hold it).

**The absence log is the review surface** (`/absence-log`, admin + assistant): each absent
row's WhatsApp button reads **À valider** while its notice waits, and pressing it approves
that one row. When the day has anything waiting, a bar above the table offers
**Valider et envoyer tout** for the day being viewed.

---

## 9. Configuration

```env
WHATSAPP_DRIVER=log        # log | evolution | wasender | null
```

**`log` is the default and it fails safe, not loud**: the app records the message, writes
it to the log, and no parent hears anything. Deploying without setting this on the server
means nothing is delivered.

| Key | Default | |
|---|---|---|
| `EVOLUTION_API_URL` | `http://127.0.0.1:8080` | keep on loopback or a private network |
| `EVOLUTION_API_KEY` | — | must match the gateway's `GATEWAY_API_KEY` |
| `EVOLUTION_INSTANCE` | `tikoschool` | |
| `WHATSAPP_MIN_SECONDS` | `8` | gap between sends |
| `WHATSAPP_JITTER_SECONDS` | `4` | random extra |
| `WHATSAPP_DAILY_CAP` | `250` | counted from the table |
| `WHATSAPP_MAX_NUMBER_FAILURES` | `4` | circuit breaker; `0` disables |
| `WHATSAPP_MAX_AGE_DAYS` | `5` | after which a notice expires |
| `WHATSAPP_COUNTRY_CODE` | `212` | |
| `SCHOOL_NOTIFY_FROM` / `_UNTIL` | `09:00` / `21:30` | quiet hours |
| `SCHOOL_NAME` / `_PHONE` / `_INSTAGRAM` / `_HOURS` | | fallbacks only; the school row wins |

---

## 10. Operations

```bash
# Start the gateway (once, then keep it running)
cd tools/whatsapp-gateway && npm install
GATEWAY_API_KEY=<same as EVOLUTION_API_KEY> node gateway.mjs
# then scan the QR at /notifications, or http://127.0.0.1:8080/qr

php artisan notifications:retry-failed --dry-run   # what the sweep would do
php artisan notifications:retry-failed             # release held, retry failed, expire stale
php artisan whatsapp:audit-numbers --active        # which parents are unreachable
```

**Scheduled** (`bootstrap/app.php`):

| | When | |
|---|---|---|
| `notifications:retry-failed` | every 30 min | a gateway reconnected at 10:15 should not wait for tomorrow; and a whole day released in one burst is a ban risk |
| `whatsapp:audit-numbers --active` | Mondays 06:00 | exits non-zero on an unusable number |

A worker must consume the **`whatsapp`** queue:

```
php artisan queue:work --queue=whatsapp,default --timeout=90 --max-time=3600
```

---

## 11. Privacy

Children's attendance data and guardians' mobile numbers.

- `OutboundMessage` hides `recipient` and `message` from serialisation; the admin screen
  never receives either.
- The job carries **an id**, not a number and a body — an earlier version serialised both
  into `jobs` and left them in `failed_jobs` indefinitely.
- Phone numbers are redacted in logs (`2126******32`), and third-party response bodies are
  scrubbed of long digit runs before logging — gateways routinely echo the payload they
  rejected.
- `whatsapp:audit-numbers` prints raw numbers **only** for the unusable list, where seeing
  the malformed text is the point. Do not redirect its output to a shared log.

---

## 12. Known limits

- **Unofficial.** Baileys is reverse-engineered WhatsApp Web. It is against WhatsApp's ToS
  and the school's number can be banned. The official path is Meta's WhatsApp Business
  Cloud API — free service conversations, ~$0.03–0.05 per utility conversation in Morocco —
  at the cost of business verification and template pre-approval. The provider seam exists
  so that switch is a config change.
- **No delivery receipts.** `sent` means the gateway accepted it. Needs webhooks.
- **One guardian per student.** `resolveRecipient()` is written for a list and returns one;
  the schema already allows several rows per absence because `recipient` is in the key.
- **No parent-facing opt-out.** `students.notifyGuardian` is staff-operated.
- **Gender.** No field on students, so the Arabic addresses guardians in the masculine
  (`ابنكم`) — unchanged from the original message rather than guessed from a name.

---

## 13. Tests

| File | Covers |
|---|---|
| `tests/Feature/AbsenceApprovalTest.php` | the approval gate: register records without dispatching, per-row and whole-day release, scoping, the double-approval race, correction cancels, the sweep's refusal to approve, expiry |
| `tests/Feature/OutboundMessageTest.php` | the record: dedup, snapshots, skips, quiet hours, retry, template branding, bidi isolates |
| `tests/Feature/WhatsAppSendingTest.php` | the gateway call: endpoint, auth, failure classification, normalisation, pacing, lock-not-held-across-send, daily cap |
| `tests/Feature/NotificationRecoveryTest.php` | the scenarios in §4: two subjects, the train, disconnection, multi-day outages, expiry, the breaker, stranded jobs |
| `tests/Feature/NotificationsPageTest.php` | the admin screen: access, PII, gateway panel, retry rules |
| `tests/Feature/ManualNotifyTest.php` | the manual button: creation, duplicate refusals, releasing the register's waiting copy |

```bash
php artisan test --filter="Notification|WhatsApp|Outbound"
```

Two traps this repo has been bitten by and these tests avoid:

- `Http::fake()` **merges** stubs; calling it twice leaves the first pattern registered and
  first-match-wins. Tests that change gateway state use a closure reading a live variable.
- The `Schedule` is empty until a console command boots it, so asserting on it without
  `Artisan::call('schedule:list')` first passes vacuously.
