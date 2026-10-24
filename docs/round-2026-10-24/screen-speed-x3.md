# Screen speed («سرعة الشاشة») — round 2026-10-24, worktree x3

The moderator's three hot screens were measured on a seeded dataset (`tests/Feature/Inbox/ScreenSpeedTest.php`:
1 supervisor + 2 moderators on an open shift, 50 conversations with customers and tags, 500 Arabic messages
with attachments, 5 open windows of one moderator, open support cases), acting as the moderator, counting
statements with `DB::enableQueryLog()` and measuring the response bytes and the wall time of the request
inside the test process (sqlite `:memory:`, so the ms column is the PHP cost, not MySQL's).

Run it yourself (prints the numbers to STDERR; `SCREEN_SPEED_DUMP=1` also lists every statement):

```
php vendor/bin/pest tests/Feature/Inbox/ScreenSpeedTest.php
```

## Before → after (seeded data, moderator, 50 conversations / 5 windows)

| Screen | Request | Queries before → after | Bytes before → after | ms before → after (warm) |
|---|---|---|---|---|
| Inbox list | `GET /inbox/conversations` (page of 30) | 6 → 6 (was already flat) | 42,513 → 33,450 (−21 %) | ~62 → ~47 |
| Inbox page | `GET /inbox` (Inertia props) | 18 → 17 | 45,466 → 45,522 (Inertia encodes itself; unchanged) | ~119 → ~110 |
| Thread | `GET /inbox/conversations/{id}` | 10 → 10 (locked chat: 12 → 11) | 10,390 → 6,938 (−33 %) | ~69 → ~48 |
| Thread page | `GET /inbox/conversations/{id}/messages` (50) | 4 → 4 | 7,919 → 4,882 (−38 %); 50 msgs: 21,303 → 18,150 | ~14 → ~11 |
| My desk | `GET /queue/me` (5 windows) | **20 → 14** | 7,038 → 6,048 (−14 %) | ~54 → ~42 |

Growth check (the second test): the list costs 6 queries at 10, 50 and 100 conversations; the thread costs
the same at 10 and 50 messages; `/queue/me` cost **17 / 20 / 25** queries at 2 / 5 / 10 windows before and
costs **14 / 14 / 14** after — the only per-row N+1 found.

Bytes are measured with Arabic bodies: the default `json_encode` writes an Arabic letter as `ا`
(6 bytes) instead of its 2 UTF-8 bytes, so the escape form was the largest single waste on every web
response. On a real page (Arabic names, tags, previews) the saving is larger than on the fixture, whose
customer names are Latin.

## What was changed

| File | Fix |
|---|---|
| `app/Http/Resources/ShiftMemberResource.php` | `usingSettings()` + the settings read once for all windows and the cap — `window()` called `QueueEntryResource::silenceLeft($e, null)`, which read `queue_settings` once **per window** (the N+1 of `/queue/me`), plus `cap()` read it again. |
| `app/Http/Controllers/Concerns/QueueDeskEndpoints.php` (`me` / `payload`) | Passes the settings it already read into `ShiftMemberResource`; `me` answers web callers with `JSON_UNESCAPED_UNICODE`. |
| `app/Http/Resources/ConversationResource.php` | `jsonOptionsFor()` / `jsonOptions()` / `newCollection()`: web responses encode Arabic as UTF-8, `api/*` keeps the escaped form (a mobile client that reads the body without a charset would otherwise see mojibake). |
| `app/Http/Resources/MessageResource.php` | Same encoding rule for the single message and the messages page. |
| `app/Http/Controllers/Concerns/ConversationEndpoints.php` (`show`) | Lock holder read from the `lockedBy` relation `attachUsers()` already filled (SoftLock::holder() was one more `users` query on a locked chat); the detail JSON uses the web encoding. |
| `app/Http/Controllers/Web/InboxController.php` (`index`) | `QueueSetting::current()` read once instead of twice for the page props. |
| `tests/Feature/Inbox/ScreenSpeedTest.php` (new) | Seeds the dataset, prints the numbers, asserts the ceilings (list ≤ 7, page ≤ 17, show ≤ 12, messages ≤ 5, me ≤ 14), asserts no per-row growth (10/50/100 rows, 2/10 windows, 10/50 messages) and that web JSON carries no `\u06..` escapes. |

Not changed on purpose:

- `QueueSetting::current()` got no process-level memo. The remaining repeat reads per request are
  `ScoreKeeper::cap()` (one) and the queue-off check; a memo would break tests that change the row with
  `QueueSetting::query()->update()` and would hide settings changes from long-lived workers.
- `ShiftMemberResource` still reads the member's open windows itself (two statements that duplicate the
  payload's `entries` query). Reusing `entries` relies on `shift_member_id ⇒ assigned_user_id`, which
  the code keeps but no constraint enforces, so it stayed as a separate read.
- The list's `queue_entry.bot_summary` per row is read by `QueueBanner.vue` (`conversation.queue_entry.bot_summary`)
  and by the mobile API, so it stayed. `last_message_preview` was already capped at 80 characters.
- Two constant per-process reads on the first list of a worker (`bot_intents` labels, load-test channel ids)
  live in `app/Bot` and `app/Simulator`, outside this round's files.

## Polling intervals

All of them already met the targets, so none was changed:

| Composable | Interval | Push that covers the same data |
|---|---|---|
| `inbox/useConversationList.ts` `COUNTS_INTERVAL_MS` | 30 s, visible tab only (target ≥ 30 s) | counts are not pushed; rows arrive by `ConversationUpdated` |
| `inbox/useConversationList.ts` via `useEcho().poll` | 5 s **only while the websocket is down** | `ConversationUpdated` / `MessageCreated` on the inbox channel |
| `useMyQueue.ts` `QUEUE_POLL_MS` / `QUEUE_IDLE_POLL_MS` | 30 s / 60 s, **only while the websocket is down**, visible tab (target ≥ 20 s) | `QueueEntryUpdated`, `queue.assigned`, `QueueMemberUpdated` on `user.{id}` |
| `useBoard.ts` `BOARD_POLL_MS` | 15 s, only while the websocket is down (target ≥ 15 s) | board channel events |
| `useBoard.ts` `BOARD_REFRESH_MS` | 60 s while live (who is online and the day's numbers are not broadcast) | — |
| `useHeartbeat.ts` | 60 s (presence is counted for 2 min) | — |

The 5 s list fallback is the only aggressive timer; it runs only when there is no push at all, so it was
left alone (raising it would slow the inbox exactly when the websocket is down).

## Live copy (read-only)

The owner's copy (`social_crm_promax`) holds 8 conversations, 113 messages and 1 open window — too small
to show anything the seeded test does not. For the record, a read-only script that boots the app and calls
the same query classes as moderator #3 measured: list page 6 queries / 8,653 bytes escaped → 7,426 bytes
UTF-8 (−14 % on mostly Latin fixture names), desk entries 4 queries / 1,363 bytes. No writes were made.

## Verified

- `php vendor/bin/pest tests/Feature/Inbox --parallel --processes=6` → 231 passed (940 assertions).
- `php vendor/bin/pest tests/Feature/Queue --parallel --processes=6` → 612 passed (3308 assertions); this
  includes `WindowEndpointsTest` and `BoardTest`. (Paratest takes one path per run, so the suites ran one
  after the other.) The page tests need `public/build/manifest.json`: the worktree had no Vite build, so
  `npx vite build` was run once (gitignored output).
- `php vendor/bin/pest tests/Feature/MobileApi --parallel --processes=4` → 159 passed, 1 failed:
  `DeviceTokenModelTest > exposes the mobile and push config` expects 9 push types and `config/crm.php`
  now has 10 (`queue.waiting_no_desk`) — another session's uncommitted change in this worktree, not this round's.
- `php vendor/bin/pint` on every changed PHP file.
- No TypeScript was changed, so `vue-tsc` / `vitest` were not needed for this round.

## Not verified

- The ms column is the test process on sqlite; MySQL timings on the real dataset were not measurable
  (the live copy is tiny) — the owner's server latency recorder (`record-list-latency`) is the place to
  watch after deploy.
- Real browser payload sizes with gzip/brotli on the wire: the `\uXXXX` → UTF-8 saving shrinks under
  compression, but the client still parses a third less JSON.
