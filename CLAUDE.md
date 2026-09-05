# LearnGuard — Claude Session Rules

## Project owner
Michael O. Adeniran — all commits must use this name and email `michaeladeniransnr@gmail.com`.
Never use Claude's name in commits.

## Repository layout
```
_lg_work/learnguard/      ← mod_learnguard (Moodle activity module, SPA)  ← ACTIVE
_ls_work/learnshield/     ← quizaccess_learnshield (ARCHIVED 2026-06-16 — frozen at v1.6.6)
make_zip.ps1              ← builds LearnGuard ZIP (LearnShield rebuild not required)
learnguard_learnguard.zip
learnshield_learnshield.zip  ← frozen; do not rebuild unless Michael explicitly requests
```

## LearnShield — ARCHIVED (2026-06-16)
**LearnShield (`_ls_work/learnshield/`) is archived and frozen at v1.6.6 / 2026053107.**
All future development, bug fixes, and features go into LearnGuard only.
- Do NOT make changes to any file under `_ls_work/learnshield/` unless Michael explicitly asks.
- Do NOT rebuild the LearnShield ZIP as part of normal session cleanup.
- The existing LearnShield install at v1.6.6 continues to work as-is on Michael's site.
- Historical version notes for LearnShield are kept below for reference only.

## CRITICAL — Do NOT revert working fixes
**Before changing any existing code, check git log and the current file state.**
- If a feature is working, DO NOT change how it works unless explicitly asked to fix it.
- If fixing issue X, do NOT touch code for features Y or Z that are not broken.
- When a fix is asked for, make the MINIMUM change necessary. Do not refactor surrounding code.
- If unsure whether a change will break something, ask first.
- After each fix, explicitly list what was changed so Michael can verify nothing was accidentally removed.

## CRITICAL — Fixing scope
**Only fix exactly what is asked. Nothing more, nothing less.**
- A request to "fix X" means fix X and leave everything else as-is.
- If you notice something else that could be improved while fixing X, mention it as a suggestion but do NOT implement it unless asked.
- Do not "clean up", "simplify", or "refactor" unless explicitly requested.

## After every coding session — mandatory QA + build steps (in order)
1. **PHP lint** every changed PHP file: `php -l <file>` — fix any parse errors before proceeding.
2. **JS check** every changed AMD file: `node --check <file>` — fix any syntax errors before proceeding.
3. **block_learnguard.zip** — only rebuild/commit if files under `_lg_work/block_learnguard/` actually changed this session. Skip otherwise.
4. Run `make_zip.ps1` to rebuild LearnGuard ZIP (LearnShield is archived — skip it).
5. Bump `$plugin->version` and `$plugin->release` in `_lg_work/learnguard/version.php` only.
6. Update the **"Current versions"** line in CLAUDE.md to match the new version.
7. Commit with `git -c user.name="Michael O. Adeniran" -c user.email="michaeladeniransnr@gmail.com" commit`.
8. Always push to the remote repo immediately after committing: `git push`.

## Before making breaking changes
Create a dated backup ZIP first:
```
cp learnguard_learnguard.zip learnguard_learnguard_vX.X.X_BACKUP_YYYY-MM-DD.zip
```

## Current versions (update this when you bump)
- LearnGuard: v2.0.156 / 2026090125  ← ACTIVE — update this on every version bump
- LearnShield: v1.6.6 / 2026053107  ← ARCHIVED — frozen, do not bump

## v2.0.156 — question-pool draw-frequency analysis (2026-09-05)

**Confirmed already working, no change needed**: Michael asked for a way to add newly
created question-bank questions into an existing pool (91 questions) without
re-duplicating ones already there, with the new ones automatically joining the random
draw. `editquestions.php`'s existing "📋 Add Entire Category" button (`addcategory`
action) already does exactly this — it skips any question that already has a
`learnguard_slots` row for this activity (`record_exists_select` guard, same one used by
the "Add Selected" checkbox flow) and only inserts genuinely new ones, leaving every
existing slot's order/marks/mandatory-flag completely untouched. New slots are ordinary
`learnguard_slots` rows, so they're automatically eligible for the existing least-used-
first random draw (`start_or_resume_locked()`, v2.0.142) with no separate step.

**New — draw-frequency analysis for the optional pool**: added to answer "are pool
questions actually being distributed evenly, or is the draw tilted toward a few
questions" directly, rather than requiring a manual DB check.
- New batch query in `editquestions.php`'s `slots` tab: for every question in the
  activity, counts how many real attempts (`learnguard_attempt_questions` joined to
  `learnguard_attempts`) actually included it — a live, in-plugin measurement of the
  fairness algorithm's actual output, not a theoretical guarantee.
- New "Times Drawn" column on the per-question pool table (mandatory questions will
  naturally show a high, near-constant count — drawn into every attempt by design; the
  optional pool's counts are the real signal).
- New summary panel above the pool table (randomized assessments only) showing average/
  min/max draw count across the optional pool, flagging any question drawn 50%+ above or
  below the average as "over-drawn"/"under-drawn" so an outlier is visible without
  scanning 90+ rows.

No schema change — no new upgrade.php savepoint needed (both new figures are computed
live from existing `learnguard_attempt_questions` data, nothing new stored).

## v2.0.155 — critical: server-side auto-suspend enforcement, review-mode answer-leak fix, face_missing email opt-in (2026-09-05)

**Critical #1 — an attempt reached risk score 760 against a threshold in the low
hundreds and was never auto-suspended**: confirmed the mechanism — auto-suspend was
ONLY ever triggered by the client's own `lgCheckAutoSuspend()` deciding to call
`mod_learnguard_auto_suspend`. A student with DevTools open (confirmed here — the same
attempt was also spamming `devtools_f12`) can pause/step around that check, override it
in the console, or simply block the WS call, while individual scored events (which is
how the score reached 760 in the first place) keep reaching the server completely
normally via a separate code path. There was no independent server-side verification
that risk_score had crossed the threshold — unlike time-limit expiry, which already has
exactly this kind of client+server backstop (`learnguard_attempt_timelimit_expired()` /
`auto_finish_expired_attempts` task).
- `attempt_manager::log_event()` now enforces the suspend threshold itself, in the same
  place it already persists `risk_score` — the moment a scored event pushes
  `risk_score >= autosuspend_score`, the attempt is suspended right there server-side
  (state, timesuspended, risk_score_at_suspend, time_last_suspended, an `auto_suspended`
  event, proctor email if enabled), completely independent of whether the client's own
  check ever runs. A DevTools-savvy student can still disable their OWN client-side
  trigger, but can no longer stop the server from suspending them once it receives any
  event that crosses the threshold.
- `classes/external.php::log_event()` now also returns `suspended: true` when this just
  fired, and `amd/src/assessment.js`'s response handler acts on it immediately — instead
  of waiting up to 15s for the next `get_messages` poll to notice the state flip, during
  which the exam would otherwise still look normal to the student despite already being
  suspended server-side.
- Known accepted trade-off: this uses the BASE effective threshold, not the narrower
  elevated post-reinstatement one (`state.reinstateSuspendThreshold`, client-only, used
  by a specific redo-precheck reinstatement flow) — a student reinstated via that
  specific flow could be server-re-suspended slightly before reaching their intended
  grace threshold. Fails toward extra caution, not away from it; flagged here rather
  than silently accepted.

**Critical #2 — review-mode "correct answer" markup could be unlocked early and reused
across attempts**: `get_question_html()`'s review flag was gated only by
`$attempt->state === 'finished'` — enough to stop seeing answers mid-exam, but NOT
enough to stop a student deliberately finishing a throwaway/early attempt (whenever
maxattempts > 1), then calling the review-mode WS directly (DevTools console, bypassing
the client's own hardcoded `review:false`) on that now-finished attempt to see every
correct answer, then using that to ace a REAL subsequent attempt — very plausibly the
actual explanation for a reported wave of suspicious 100% scores. New
`attempt_manager::can_reveal_review()` (shared by `get_question_html()` and the new
`get_all_questions_html()` from v2.0.154) additionally requires: (a) the activity's own
manual-result-release setting has actually released results (`show_results`/
`results_released` — previously not checked at all here), and (b) no further attempt is
actually possible for this user on this activity (mirrors the exact "attempts
remaining" calculation already used in `start_or_resume_locked()`, including per-user
`extra_attempts` overrides). Unlimited-attempts (maxattempts=0) activities are left
as-is — that combination with immediate results is an accepted "practice mode"
configuration, not this exploit, and blocking it there would break a legitimate use
case nobody asked to change.

**Face_missing proctor-email spam — confirmed NOT something enabled this session**: git
history shows the underlying rule (`classes/external.php::log_event()`: any event at
`severity: 'high'` emails every proctor) was added 2026-05-20, untouched until now.
`face_missing` is always logged at `'high'` severity and fires roughly every 30s while a
face is out of frame, making it by far the single biggest source of proctor-email
volume. New `learnguard/notify_facemissing_enabled` setting (default OFF) excludes it
from that blanket rule unless explicitly re-enabled; Face Missing is entirely unaffected
everywhere else (live dashboard, risk score, Proctoring Events table) — this only
controls the email.

No schema change — no new upgrade.php savepoint needed.

## v2.0.154 — preload the whole exam, non-blocking saves with a background safety net, real server-reachability indicator, outage-tagged violations (2026-09-04)

Direct follow-up to v2.0.153, implementing 3 architectural changes discussed and agreed
with Michael in response to the formal timeout complaint, plus a 4th (outage-tagging)
he asked for when offered as a trade-off during that discussion.

**1. Whole exam preloaded up front — this is what actually eliminates "Failed to load
question" for Q2 onward**, not just retrying a fetch that keeps failing:
- New `attempt_manager::get_all_questions_html()` (`classes/attempt_manager.php`) —
  batch variant of the existing `get_question_html()`, reusing the exact same per-slot
  rendering (`question_engine::render_question()`/`get_saved_response()`) in a loop, so
  behavior (shuffled-answer seeding, saved-response rehydration, review-mode gating) is
  identical to fetching one at a time.
- New WS `mod_learnguard_get_all_questions` (`classes/external.php` + `db/services.php`).
- `amd/src/assessment.js`: Q1 still loads exactly as before (fast, own small fetch) —
  `preloadRemainingQuestions()` fires right after, fetching everything else in ONE
  background call and dropping it into `state.questionCache`. `doLoadQuestion()`'s
  cache-check (previously gated to `state.reopenedForReview` sessions only) now applies
  to every session — a cache hit renders instantly with zero round trip; a miss (preload
  not finished yet, or failed) falls straight through to the normal per-question fetch,
  unchanged from before. Wired into both `launchAssessment()` and
  `beginResumedAssessment()`, guarded against firing twice.

**2. Non-blocking saves with a background safety net** — the hybrid Michael agreed to
over a literal "defer everything to completion" (which would risk losing everything on
a crashed/closed tab, worse than today):
- `submitCurrentAnswer()`: after its 3 attempts (v2.0.153) still fail, no longer shows a
  blocking red error. Queues the answer into new `state.unsavedAnswers[slot]`, shows a
  calm "⏳ Still saving your last answer in the background — you can keep going." toast,
  and immediately lets the student continue navigating.
- New `lgFlushUnsavedAnswers()` + `startUnsavedAnswerFlush()`: retries every queued slot
  every 12s (Michael's requested 10-15s range) until it succeeds. Resends with
  `targetslot: state.currentslot` (the student's ACTUAL current position, not the old
  pending slot and not 0) — the server-side `currentpage` write this triggers can only
  reaffirm wherever they are by now, never regress their resume position backward.
- Final best-effort sweep wired into the 2 points where the tab might not stay open much
  longer: `finishAttempt()` (flushes before actually calling finish — the last realistic
  chance to sync before the attempt is sealed) and both suspension-detection paths
  (`lgCheckAutoSuspend()` for client-detected auto-suspend, and the `get_messages` poll's
  suspension branch for proctor-initiated/server-confirmed suspension).

**3. Connection icon now reflects real server reachability, not just device network
status**: the existing `#lg-connection` badge (top toolbar, already visible without
leaving fullscreen) previously only read `navigator.onLine` + the browser's Network
Information API — both device-level heuristics that stay "📶 Online" through an entire
server-side outage, exactly the gap in the reported incident (local wifi was fine the
whole time). The `get_messages` poll (`startPolling()`, already running every 15s) now
also doubles as a real reachability check: success → `state.serverReachable = true`;
failure → `false` + `state.serverUnreachableSince` timestamped. `lgUpdateConnectionBadge()`
(extracted from the old `initConnectionMonitor()` closure so both the poll and the
existing browser-event listeners can refresh the same badge) shows a distinct
"🟠 Server slow" when the device is online but LearnGuard's server specifically isn't
responding — the actual answer this student needed in the moment.

**4. Proctoring events tagged when they land shortly after a real failure** — offered as
a trade-off for the deferred-save discussion, Michael confirmed yes: `callWS()` now
timestamps every failure (any WS call, any reason) into `state.lastWsFailureTime`.
`logProctoringEvent()` tags `event_data.possible_connection_issue = true` for any event
logged within 30s of that timestamp — does NOT change severity or score, purely
informational. `attemptdetail.php`'s `learnguard_format_event_data()` now shows a
"⚠️ possible connection issue" note next to the Detail text for tagged events, so a
proctor reviewing a risk score later can see a Tab Switch/Fullscreen Exit may have been
the student's own recovery attempt (retrying a stuck save, checking their connection)
rather than a genuine violation — directly what happened in the reported incident.

No schema change — no new upgrade.php savepoint needed (all new fields are in-memory
client state or existing JSON `event_data`).

## v2.0.153 — formal complaint: real, sustained server timeouts during a live exam — stronger retry on load/save/finish (2026-09-04)

**Escalation over v2.0.151**: a student formally reported that during a 20-minute MSP
2027 Basic Finance attempt, the platform was only usable for ~7 of the 20 minutes —
persistent "Failed to load question. Request timed out." AND (worse, not covered by
v2.0.151's fix) "Error saving answer: Request timed out." Screenshots show this
happening repeatedly across the session, with Tab Switch/Fullscreen Exit violations
landing in the same window — very likely a side effect of the student's own
recovery/retry attempts during the freezes, not genuine violations. The student halted
before attempting the Technical Pathway assessment specifically to report this first.

**Re-audited both server paths (`submit_answer`→`process_answer`→`save_response`, on
top of the `get_question` path already audited for v2.0.151) — still lean, no N+1
queries, no heavy computation, nothing that explains a systemic slowdown in the code
itself.** With the severity now confirmed as sustained (not an isolated blip), the
most likely explanation remains server/DB capacity under concurrent proctoring traffic
during that session — outside what a code fix alone can resolve. Flagging directly to
Michael: worth checking PHP-FPM/DB metrics or slow-query logs for the exact timestamps
in the reported screenshots.

**Strengthened client-side resilience across all three exam-critical WS calls**:
- `doLoadQuestion()`: upgraded from 1 silent retry to 2 (3 attempts total, 1.5s/3s
  backoff) before showing the error screen — one retry clearly wasn't enough against
  sustained distress.
- `submitCurrentAnswer()`: previously had **zero** retry — a single timeout went
  straight to "Error saving answer" with no recovery attempt, worse than the read-only
  question-load case since it risks the student's answer for that question. Now
  captures the response once up front (so a retry resends the identical answer rather
  than re-reading a DOM that may have moved on) and retries the same way (2 retries,
  backoff). `save_response()` on the server is a safe upsert keyed on
  attemptid+slotid — a retry can never duplicate or corrupt a save.
- `finishAttempt()`: the single most damaging call to have left unprotected — a
  timeout here left a student not knowing whether their completed exam actually
  submitted. `finish_attempt()` is already idempotent server-side (an
  already-`'finished'` attempt just returns its existing grade instead of
  re-processing — confirmed by reading `classes/external.php::finish_attempt()`), so
  retrying is safe. Same 2-retry/backoff pattern added.

**What this does and doesn't fix**: this meaningfully improves resilience to
multi-second stalls and brief drops (the far more common case) — each operation now
gets ~3 chances over ~9-10 seconds before the student sees any error at all. It
does **not** fix a genuine multi-minute server outage/overload — no amount of
client-side retry can paper over the server actually being down or saturated for that
long. That needs the underlying capacity/infrastructure looked at directly.

**Recommended for Michael to action manually** (not something code can determine on
its own): (1) this specific attempt has clear, documented evidence of platform failure
independent of the student — worth voiding it and granting a fresh attempt or Continue
& Review rather than grading it as-is; (2) the Tab Switch/Fullscreen Exit events logged
during the reported freeze window are very likely a side effect of the student trying
to recover (retrying, alt-tabbing to check connection) rather than genuine violations —
worth a manual risk-score adjustment for this attempt specifically; (3) confirm the
server issue before the student attempts the Technical Pathway assessment, since the
same infrastructure would be under the same load.

No schema change — no new upgrade.php savepoint needed.

## v2.0.152 — Chat/Break/SOS: per-activity setting is now a real tri-state, not always-AND (2026-09-04)

**The design was wrong, not just under-documented**: the per-activity "Enable Chat"/
"Enable Break"/"Enable SOS" checkboxes on each assessment's own settings form were a
plain NOT NULL 0/1 column — there was no way to represent "this activity has no opinion,
follow the site default." So `enablechat = proctoring AND site_setting AND
activity_checkbox` (an AND) was the only behavior possible: an assessment's own checkbox
could only ever narrow what the site setting already allowed, never grant something the
site setting didn't. Explicitly confirmed with Michael this is backwards from the
intended design: **the site-wide setting is the default for every assessment, but a
specific assessment's own explicit choice should win over it in EITHER direction** —
including turning a feature ON for one exam even while the site default has it off.

**Fixed by making it a real 3-state field, mirroring `autosuspend_score`'s existing
blank-means-inherit pattern**:
- `learnguard.enable_chat`/`enable_break`/`enable_sos` are now nullable (upgrade
  savepoint `2026090121`, `change_field_notnull()` — **existing rows are NOT reset to
  NULL**, so every already-configured assessment keeps behaving exactly as it does today;
  only going forward can an admin explicitly pick "Site default" on an activity to make
  it inherit and follow the site-wide setting live).
- `mod_form.php`: the 3 checkboxes are now 3-option selects — "Site default (currently
  ON/OFF)" / "Enabled for this activity" / "Disabled for this activity" — with the same
  blank-string ↔ NULL conversion in `data_preprocessing()`/`get_data()` already used for
  `autosuspend_score`. Distinct new `*_activity_help` lang strings — the OLD `enable_chat_help`
  etc. stayed on the GLOBAL settings page checkboxes (reusing the same lang string for
  both would have shown the per-activity "Site default…" explanation on the global
  setting's own help tooltip, which makes no sense there).
- New shared `learnguard_feature_enabled(?int $activityvalue, string $globalkey, bool
  $globaldefault): bool` in `lib.php` — NULL activity value falls back to the site
  setting, otherwise the activity's explicit 0/1 wins outright. Used identically by
  `view.php` (client config) and `classes/external.php` (server enforcement in
  `send_chat()`, `request_break()`, and `log_event()` for `tech_difficulty`/SOS — the
  single source of truth so client and server can never disagree.
- **Also closed a gap found while touching this**: SOS (`tech_difficulty` events) had
  no server-side enforcement at all before now — only the button's client-side visibility
  was gated on `cfg.enablesos`, so the WS call itself accepted the event regardless. Now
  checked in `log_event()` exactly like Chat/Break already were.

**Heads-up for Michael**: because existing rows keep their stored value rather than being
reset, any assessment that already has an explicit Chat/Break/SOS choice will show that
choice (not "Site default") after this upgrade — worth a once-over on assessments where
you want them to follow the site-wide setting from now on, same as the `autosuspend_score`
cleanup already in progress.

## v2.0.151 — "Request timed out" investigated + a silent one-shot retry for question loads (2026-09-04)

**Investigated the live "Failed to load question. Request timed out." report**: audited the
full path — `classes/external.php::get_question()` → `attempt_manager::get_question_html()`
→ `question_engine::render_question()`. All three are lean (a handful of indexed
`get_record`/`get_records` lookups, no N+1 loops, no external HTTP calls, no heavy
computation) — nothing in this specific code path explains a systemic slowdown. The much
more likely explanation given LearnGuard's own traffic pattern: with proctoring on, EVERY
student's browser is independently hitting the server every ~5-60s (heartbeat, periodic
risk-check, webcam/screen snapshot uploads every `snapinterval`, proctoring events) —
across a full class sitting an exam simultaneously this is a lot of concurrent request
volume hitting PHP-FPM/DB workers, which is an infrastructure capacity question, not a
LearnGuard code defect. Also plausible on lower-spec student machines: the client-side
YOLOv5n/face-api inference (`lgRunYolo()`, `initFaceMonitoring()`) does real CPU work on
the main thread, which can contend with the browser's own network-callback processing.

**amd/src/assessment.js — `doLoadQuestion()` now retries once, silently, before failing**:
- Previously a single timeout/network hiccup went straight to a "Failed to load
  question" error screen requiring the student to notice it and click Retry manually —
  the worst possible experience for exactly the kind of transient blip this is most
  often caused by. Now the first failure triggers one automatic, silent retry 1.5s later
  (`doLoadQuestion(slot, true)`); only a SECOND consecutive failure shows the error UI.
  This doesn't address server capacity (out of code's reach) but meaningfully reduces how
  often a student ever sees this screen at all, for the most common transient case.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.150 — configurable reinstate/suspension-confirmed emails + Reinstate & Notify with evidence (2026-09-04)

**settings.php — two new toggles, both on by default**:
- `learnguard/notify_reinstate_enabled` — controls whether ANY reinstatement email goes
  out (the plain Reinstate buttons on report.php/proctorview.php/admin.php, and the new
  richer flow below). Previously this email was hardcoded always-on with no setting at all.
- `learnguard/notify_suspension_confirmed_enabled` — same, for the existing "Confirm
  Suspension (Notify Student)" email on attemptdetail.php, which was also previously
  unconditional.
- Both gates live inside `lib.php`'s two notify functions (single choke point), so every
  call site is covered automatically with no per-caller changes needed. When a setting is
  off, the function still logs a row to `learnguard_suspension_notices` with
  `mail_status='skipped'` (new status value, alongside the existing sent/failed) so the
  audit trail explains why no email went out, instead of silently doing nothing.

**report.php — new "✉ Reinstate & Notify" action alongside the existing plain Reinstate**:
- New `reinstatenotify` POST handler + a `getevidence` AJAX handler (JSON, capped at the
  40 most recent snapshots and 20 most recent audio clips per attempt) feed a new modal
  that mirrors attemptdetail.php's "Confirm Suspension (Notify Student)" flow but for the
  opposite decision: tick webcam/screen snapshots and audio clips as evidence, write a
  custom message, submit — reinstates the attempt (same state flip + reinstatement-penalty
  scoring as the existing plain Reinstate) and emails the student the message with the
  ticked evidence attached as a ZIP. Unlike attemptdetail.php (already scoped to one
  attempt, evidence rendered inline), report.php lists many attempts at once, so evidence
  is fetched via AJAX per-attempt when the modal opens rather than rendered for every row
  up front.
- The existing plain "▶ Reinstate" button is unchanged (still a one-click reinstate with a
  generic notice, no evidence/message) — the new button is additive, for when a proctor
  wants to explain the decision and back it with evidence.

**lib.php — `learnguard_notify_student_reinstated()` extended, evidence-ZIP logic shared**:
- New optional `$message`, `$snapIds`, `$audioIds` params (default empty — the 3 existing
  plain-reinstate call sites in admin.php/proctorview.php/report.php are unaffected and
  keep sending the generic notice with no evidence).
- The snapshot/audio-clip → ZIP-attachment logic (previously only in
  `learnguard_notify_student_suspension_confirmed()`) is now `learnguard_build_evidence_zip()`,
  shared by both functions so a Confirm Suspension email and a Reinstate & Notify email
  attach evidence identically.

**Schema**: `learnguard_suspension_notices` gains `noticetype` (char 'suspend'|'reinstate',
default 'suspend' so existing rows are correctly backfilled) — upgrade savepoint
`2026090119`. `suspensionnotices.php` (now covering both notice types, not just
suspensions) gained a Type column + filter, a "Skipped" stat card, and a mail-status filter
option for `skipped`.

## v2.0.149 — critical: resumed attempts silently lost all screen-share evidence (2026-09-04)

**amd/src/assessment.js — `beginResumedAssessment()` never re-required screen sharing**:
- The pre-exam wizard's `screenshare` step already blocked a FIRST-time exam start
  without a valid full-screen share (this was already correct, added back in v2.0.87) —
  but that only covers the very first launch. `beginResumedAssessment()` runs on every
  subsequent resume of an already-in-progress attempt (`cfg.attemptid > 0` on page load:
  a refresh, a reopened closed tab, or a reconnect after a dropped connection) and always
  skipped straight to `initProctoring()` with a comment literally saying "skip wizard,
  re-enter proctoring." A live `getDisplayMedia()` stream is a page-scoped JS object — it
  cannot survive a reload — so `state.screenStream` was `null` on every one of these
  resumes, `initPeriodicScreenCapture()`'s `if (!state.screenStream) return;` guard then
  silently did nothing for the rest of the session, and nothing ever re-prompted the
  student to re-share. This is what produced the exact symptom reported: an attempt with
  zero screen captures on record despite screen sharing being "mandatory."
- Fixed with a new `requireScreenShareGate(onReady)`: on every resume where
  `cfg.proctoring` is on, before proctoring re-initialises, it checks whether a live
  full-screen share is already active in this page load (e.g. an approved-break resume,
  which never reloads the page and so never lost its stream) and skips straight through
  if so; otherwise it blocks with a full-viewport mandatory overlay — same
  share/validate/retry pattern as the wizard's own screenshare step (rejects tab/window/
  application surfaces, only accepts a genuine full-screen share) — until a fresh share is
  granted. `beginResumedAssessment()` was split so the reinstatement-precheck and
  Continue-&-Review-precheck branches (which already force the FULL wizard, including its
  own screenshare step, before ever reaching this point) are unaffected — the new gate is
  a no-op for them since their stream is still fresh from the wizard they just completed.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.148 — critical: risk scores site-wide never matched admin settings, no hardcoded values remain (2026-09-04)

**Root cause #1 — wrong config key names silently corrupted the client after the first
scored event**: `log_event()`'s WS response REPLACES the client's entire `cfg.scoreconfig`
object on every single scored event (a live-reconfigure mechanism so an admin's mid-exam
setting change applies without a page reload). It read `get_config('learnguard',
'score_facemissing')` and `'score_faceanomaly'` — neither is a real setting name (the
actual ones are `score_face_missing`/`score_face_anomaly`, with the underscore). Since
`get_config()` silently returns `false` for a non-existent key, the `?: default` fallback
always won — meaning every attempt's Face Missing/Face Anomaly score was reset to the
hardcoded default (10) the moment the SECOND scored event of any kind fired, regardless of
what the admin had configured. This is exactly why Michael's screenshots showed a
correctly-scored first event (matching his custom setting) immediately followed by every
subsequent one reverting to the default.

**Root cause #2 — most event types never read the admin setting in the first place**: Tab
Switch, Fullscreen Exit, Blocked Key Pressed (and its Escape/Tab/Alt-release variants),
Copy/Paste, PrintScreen, Background Change, and Camera Covered/Blank all had their score
hardcoded directly as a literal number at the `logProctoringEvent()` call site — `cfg.scoreconfig`
never had entries for any of them at all, so there was no live-reconfigure path and no
admin control whatsoever, even on page load.

**Fixed exhaustively — audited every one of the 16 configurable "Risk Scores for Flagged
Events" against its actual code path**:
- `view.php`'s `scoreconfig` array now includes all 14 client-scored event types
  (previously 7), each reading its real setting via the same `$lgscoreconfig()` helper.
- `external.php`'s `log_event()` response mirrors that array exhaustively and correctly —
  a wrong OR missing key here doesn't just fail silently, it actively corrupts whatever
  correct value view.php already sent, which is what made this bug so persistent.
- Every `logProctoringEvent()` call for these 14 types now reads `cfg.scoreconfig.X`
  instead of a hardcoded number. The Escape/Tab/Alt-release key variants (previously fixed
  at 3/2/2 regardless of the base setting) now scale proportionally off the configured
  "Blocked Key Pressed" base instead, preserving their relative severity while still
  moving with the admin's setting.
- Confirmed unaffected/out of scope (no corresponding admin setting exists for these, no
  UI to configure them): `webcam_denied`, `devtools_open`/`devtools_f12`,
  `screen_share_attempt`/`screen_share_stopped`, `poor_lighting`, `unusual_display`,
  `vm_suspected`, `multi_monitor`, and the non-phone `OBJECT_VIOLATIONS` entries
  (book/laptop/keyboard/remote) — these were already intentionally fixed values per
  earlier design decisions, not part of the 16-item settings page Michael showed.
- `attemptdetail.php`'s Proctoring Events "Time" column now shows date + time
  (`%d %b %y, %H:%M:%S`), not time alone — needed to tell fresh events apart from old ones
  at a glance, which is what made several of today's reports hard to diagnose.

**Separately investigated, not a code bug**: the Auto-suspend threshold showing suspensions
around 100-125 instead of the newly-configured 150 is explained by a per-activity
`autosuspend_score` override (set on the activity's own edit form) taking precedence over
the site-wide default — working as designed, but easy to forget exists. Told Michael where
to check/clear it per-activity rather than changing the precedence rule silently.

## v2.0.147 — Check-in modal: removed "Pause Assessment & Leave" (2026-09-04)

- The repeated-violations Check-in modal (shown after several `face_missing`/
  `audio_clip_started` events) offered "▶ Continue Assessment", "⏸ Pause Assessment &
  Leave" (self-suspends via `mod_learnguard_self_pause`), and "🚫 Don't Show Again This
  Session". Removed the Pause option per explicit request — learners are not required to
  pause during the assessment, so the modal now only offers Continue or Dismiss. The
  underlying `self_pause`/`self_resume` WS functions are untouched (still used by the
  separate SOS/break self-check-in feature) — only this one caller was removed.
- No schema change — no new upgrade.php savepoint needed.

**Also investigated (not a new bug)**: a live "Audio clip saved (noise level: 0)" toast
reported during an active exam. Traced the full pipeline in the current source — the
noise-threshold interval loop only ever calls the recording function when the level is
already above the configured threshold, and the old "bonus evidence" audio-capture path
that used to hardcode `noise_level=0` was removed entirely in v2.0.118 — nothing in the
current code can produce a 0-level clip anymore. Most likely explanation is the reporting
site wasn't yet running v2.0.118+ at the time; asked for the deployed version to confirm
rather than changing code without a reproducible case on current source.

## v2.0.146 — critical: suspended attempts were invisible in Score Matrix, indistinguishable from "no attempt" (2026-09-03)

**Root cause of Abayomi Lawal's second assessment still showing nothing after v2.0.145**:
after that fix ran, Assessment Manager still showed "0 done" (not finished) for the attempt,
yet Score Matrix now showed "N/A" (no attempt at all) instead of any pending/in-progress
indicator. Traced to `scorematrix.php`'s cell query: `WHERE a.state IN ('finished',
'voided', 'inprogress')` never included `'suspended'` — so a suspended attempt (a proctor
suspended it, or it auto-suspended on risk score, at some point) was silently excluded from
the query entirely, rendering exactly like a student who never attempted the assessment at
all. `learnguard_finish_expired_attempts()` (v2.0.134/145) also only ever looks at
`state = 'inprogress'`, so a suspended attempt was never a candidate for that fix either —
correctly so, since deciding to reinstate or confirm a suspension is a proctor judgment
call, not something that should be silently auto-resolved.
- Fixed by including `'suspended'` in the query and giving it its own clear on-screen
  indicator — "🚫 suspended" linking to the attempt's report, not counted toward totals
  until a proctor actually resolves it (reinstate or confirm) via the existing
  Suspended/Proctor tools. CSV export shows "SUSPENDED" for the same cell, also excluded
  from totals. Legend updated; "—" for no attempt at all is now "N/A" there too, matching
  the on-screen cell.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.145 — critical: found the real reason some attempts survived "Finish Overdue Attempts Now" + Score Matrix "N/A" for no attempt (2026-09-03)

**Root cause: an unresolved break could block expiry forever, no matter how much time
passed**:
- After deploying v2.0.144, Michael ran "Finish Overdue Attempts Now" — it correctly fixed
  one of a student's two stuck assessments but not the other, still showing blank/pending
  days later. Traced to `learnguard_attempt_timelimit_expired()`: `if (!empty($attempt->break_approved)
  && empty($attempt->break_end)) { return false; }` unconditionally treated ANY approved-but-
  never-formally-ended break as still active, forever. `end_break()` only fires when the
  student clicks "End Break" — a student who closes the tab during or right after an
  approved break never sends that call, leaving `break_approved` set and `break_end` empty
  permanently, which silently blocked this function from ever returning true again for that
  attempt, regardless of how many days actually passed.
- Fixed: a break with no `break_end` is now only treated as still active while its OWN
  `break_deadline` hasn't passed yet. Once that deadline passes, the break is treated as
  effectively over at that deadline (used in place of `break_end` for the elapsed-time
  calculation) and the normal time-limit check resumes.
- Added an unconditional 48-hour absolute safety net on top, independent of every other
  calculation — no attempt should ever be able to sit `inprogress` indefinitely regardless
  of which specific edge case might otherwise let it slip through every check forever.

**Score Matrix — "N/A" for a student with no attempt on an assessment**:
- The on-screen "—" for a student who never attempted a given assessment is now "N/A"
  (small, muted, with a tooltip) — display-only. It was never counted toward
  totals/averages before and still isn't; the CSV export cell for this case stays blank
  (unchanged), not "N/A".
- No schema change — no new upgrade.php savepoint needed.

## v2.0.144 — "Finish Overdue Attempts Now" manual trigger added to Score Matrix too (2026-09-03)

- Same trigger as Assessment Manager's (v2.0.143), added to `scorematrix.php` too, next to
  the legend — this is the page where a blank/"review pending" cell stuck past its
  window/review deadline actually gets noticed, so the fix should be one click away from
  there instead of requiring a trip to Assessment Manager. Site-admin only, same as the
  page's other mutating actions (void/delete) — a Score Matrix viewer grant stays read-only.
- Confirmed via the site's own Task Logs (Site Administration → Server → Task Logs) that
  `auto_finish_expired_attempts` IS actually firing on schedule (~every 5-10 minutes) but
  has been failing every single run — 0.04-0.08s duration, 3 reads, 0 writes, "Fail" result.
  That signature (fails instantly, before any write) matches the "Call to undefined
  function learnguard_is_available_for_user()" bug already fixed in v2.0.135 — almost
  certainly meaning the live site hasn't been upgraded to v2.0.135+ yet, not a new bug.
  Worth confirming the live site's actual deployed version and completing the upgrade;
  the manual button works regardless either way since it doesn't depend on cron.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.143 — Continue & Review: optional window-close deadline + manual "finish overdue now" trigger (2026-09-03)

**Root cause of scores stuck blank/"review pending" past their deadline**: a granted
Continue & Review only had a minutes-based limit (`extra_time`), which only starts
counting down once the learner actually calls `start_review()` after redoing the precheck.
A review that's granted but the learner never starts leaves `timestart` frozen at whatever
it was on the ORIGINAL attempt — so `learnguard_attempt_timelimit_expired()` (added
v2.0.134) should already treat it as wildly overdue and the 5-minute scheduled task should
force-finish it — but if the site's cron isn't confirmed running, or was only recently set
up, that backstop never actually executes, leaving affected attempts stuck 'inprogress'
indefinitely (Score Matrix showing "review pending" or a bare in-progress pencil icon with
no score, even though Assessment Manager's raw sumgrades column shows the real underlying
score is already there).

**Fixed with two additions**:
- New `learnguard_attempts.review_deadline` column — an optional ABSOLUTE deadline
  (independent of the extra_time minutes) settable when granting Continue & Review, so a
  review that's never started still has a hard, predictable cutoff instead of depending on
  timestart ever advancing. `assessmentmanager.php`'s Continue & Review panel gained a
  "Window close (optional)" datetime field alongside the existing minutes field — whichever
  limit is reached first ends the review. `learnguard_attempt_timelimit_expired()` checks
  it first, independently of the existing minutes-based check.
- New "🔄 Finish Overdue Attempts Now (site-wide)" button on Assessment Manager's Continue &
  Review Usage report — runs the exact same sweep as the scheduled task
  (`auto_finish_expired_attempts`, now refactored into a shared `learnguard_finish_expired_attempts()`
  lib.php function used by both) synchronously, on demand, for admins who don't want to
  wait on cron or aren't sure it's running. The pending-reviews table also now shows each
  row's Window Close deadline (with an "⚠️ overdue" flag) alongside its granted review time.

## v2.0.142 — fair question distribution extended to multi-bank pool draws (2026-09-03)

- `attempt_manager::start_or_resume_locked()` has two separate question-selection paths.
  The fixed-slot "Randomize" mode already had a least-used-first fairness algorithm (counts
  how many attempts have used each optional slot, sorts ascending, shuffles ties) — but the
  multi-bank pool-rules mode (drawing N questions from a question-bank category, used when
  no fixed slots are defined) only ever did a plain `shuffle()` + slice with no memory of
  what had already been asked. Over many attempts, especially with a pool not much larger
  than the draw count, this let some bank questions get drawn far more often than others by
  chance — no guaranteed balance across students.
- Fixed by applying the same least-used-first algorithm to the pool-rules draw: counts how
  many attempts on this activity have used each bank question (by `questionid`, since a
  pool draw creates a fresh `learnguard_slots` row every time rather than reusing one),
  sorts ascending with a shuffle for tie-breaking among equally-used questions, then draws
  the configured count from the front — same fairness guarantee the fixed-slot mode already
  had, now in both randomization paths.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.141 — "Select All" header checkbox ticked hidden/filtered-out rows too (2026-09-03)

- Follow-up to v2.0.140: even with save now correctly scoped to ticked rows, the header
  ☑ "Select All" checkbox itself was the remaining leak — `lgamToggleAll()` ticked every
  `.lgam-chk` in the table via a plain `document.querySelectorAll('.lgam-chk')`, regardless
  of whether that row was currently hidden by the active filter. Filtering 805 down to 505
  (e.g. "Enrolled 3 Sept") and clicking header Select All silently ticked all 805 anyway —
  so the save (and any bulk-fill run first) reached the 300 filtered-out students too.
- Fixed: `lgamToggleAll()` now skips any row with `display:none`, so it only ticks/unticks
  what's actually visible under the current filter (and current page, when pagination is
  on). "Apply to All Users" is left unchanged — its own label already promises literally
  every student regardless of filter, which is a different, intentional action from the
  header checkbox.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.140 — Save All Overrides was silently resaving all 805 students instead of just the ticked ones (2026-09-03)

- Follow-up to v2.0.139's batching fix: Michael ticked ~300 of 805 students, applied a
  bulk-fill to "Ticked Only", then saved — but the batch counter showed "batch 18 of 21",
  meaning it was still processing all 805 rows, not the 300 he selected. `lgamChunkedSave()`
  collected every row in the table regardless of tick state, matching the button's literal
  "Save ALL Overrides" name — but not the tick-then-save workflow the page's own bulk-fill
  bar is built around.
- Fixed: save now scopes to ticked rows when anything is ticked. Falls back to every row
  only when nothing is ticked, which still covers "Apply to All Users" (fills every row
  without requiring ticks first) and a direct single-row edit made without ticking that
  row's own checkbox.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.139 — critical: "Save All Overrides" returning a flat "Forbidden" on large courses (2026-09-03)

**Root cause**: "Save All Overrides" submitted one native HTML form POST containing every
student in the course at once. Each row contributes up to ~14 fields (uid, window-close
date + timestamp, extra attempts, time limit, skip-precheck, up to 7 exclusion checkboxes,
cohort name) — with Michael's 805-student course that's 8,000-11,000+ POST fields in a
single request, comfortably past PHP's default `max_input_vars` (1000) and typical
webserver/WAF field-count limits. A request rejected at that level never reaches Moodle at
all, which is why it came back as a bare "Forbidden" with no Moodle error page.
- `assessmentmanager.php`'s override form no longer submits natively — `lgamChunkedSave()`
  intercepts the submit, serialises each row client-side, and POSTs to the same
  `save_user_overrides` endpoint in batches of 40 students (≈560 fields/request, safely
  under the limits above) via sequential `fetch()` calls, with a live "Saving… batch X of
  Y" status bar. On completion the page reloads to show the persisted state; on failure the
  status bar reports which batch failed and confirms everything before it was already saved.
- Server-side `save_user_overrides` handler itself is unchanged — same per-user upsert
  logic, just invoked multiple times with a subset of users instead of once with all of
  them.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.138 — Assessment Manager: enrol-date filter bug fix, accurate "X of Y" count, pagination + Show All, Enrolled column (2026-09-03)

**Root cause of the date filter "showing all" bug**: the enrolment-date query used
`MIN(ue.timestart)` — `timestart` is only the OPTIONAL "access becomes active on" date and
is left at 0/unset for most manual enrolments, and `MIN()` across multiple enrolment
methods let a real timestamp from one method get silently overridden by an unset 0 from
another. Fixed to `MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END)`
— `timecreated` is always set to the actual moment the enrolment happened, so every student
now gets a real enrolment date instead of a false 0 that made the date filter meaningless.
Also fixes the cohort-batch auto-suggest label, which reads the same value.

**Accurate count, pagination, Show All, and a sortable Enrolled column**:
- The filter count now always reads "X of Y student(s) shown" (previously blank until a
  filter was active, and didn't show the unfiltered total for comparison).
- Added 20-per-page client-side pagination on top of the existing filter/sort, plus a
  "Show all (no pagination)" checkbox to disable it — same pattern as `grader.php`'s
  pagination from v2.0.134, adapted for this page's filter+sort+paginate combination.
- New sortable "Enrolled" column (course enrolment date) between Student and the override
  fields, using the same corrected enrolment-date value as the filter.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.137 — Assessment Manager: filter users by course-enrolment date or platform-registration date (2026-09-03)

- `assessmentmanager.php`'s user table already tracked course-enrolment timestamps
  (`$enrollTs`, previously only used to auto-suggest a cohort batch label) — reused that,
  plus added `u.timecreated` (account/platform registration date) to the `get_enrolled_users()`
  field list.
- Filter bar gained two date-range pairs — "Enrolled (course)" and "Registered (platform)" —
  alongside the existing name/cohort filters, all client-side against `data-enroldate`/
  `data-regdate` attributes already rendered per row, consistent with how the existing
  filters work. Date-only inputs are resolved to the admin's local calendar day, and a
  missing date (e.g. a very old account with no `timecreated`) never matches an active
  filter rather than silently passing it.
- No schema change — `timecreated` is a native Moodle core column; no new upgrade.php
  savepoint needed.

## v2.0.136 — Score Matrix access grants, Continue & Review re-grant override + usage report (2026-09-02)

**Score Matrix access as its own independent grant**:
- `scorematrix.php` required full site admin (`moodle/site:config`) with no other way in.
- New `learnguard_score_viewers` table (global or per-course, same `is_global`/`courseid`
  shape as `learnguard_graders`) — deliberately NOT tied to Proctor or Grader assignment,
  per explicit request, since someone should be able to see scores without also getting
  proctoring/grading powers.
- Managed from a new "Score Access" tab on `siteadmin.php` (site-admin-only, same as every
  other grant screen there): grant globally, or scoped to one course.
- `scorematrix.php` now computes `$allowedCourseIds` for a non-admin viewer (null = site
  admin/global viewer, unrestricted) and threads it through the assessments query, the
  course filter dropdown, and — since CSV export and totals both build off that same
  `$assessments`/`$cellMap` — everywhere else automatically.
- The grant is read-only: bulk void/delete, the per-cell void button, and every bulk-select
  checkbox are now gated to `$isSiteAdmin` specifically, both server-side (the 3 POST
  handlers) and in the UI, so a Score Matrix viewer can't mutate or delete attempt data.

**Continue & Review: admin override for a second grant + a usage report**:
- New "Allow re-granting to users who already used their one review" checkbox on
  `assessmentmanager.php`'s Continue & Review panel — bypasses the v2.0.133 one-review-only
  skip for ticked users, for cases like a review that was cut short by a bug (see v2.0.135)
  and never properly usable.
- New "Continue & Review Usage" report on the same page, scoped to whichever
  activity/activities are currently selected: grants given, used (resubmitted), and not yet
  used, plus a table of who has an unused grant (assessment, review minutes granted, last
  activity) so it's actionable, not just a count.
- No new upgrade.php savepoint conflict — new table added at 2026090105, above the last
  shipped version.php value per the established rule.

## v2.0.135 — critical: "Call to undefined function learnguard_is_available_for_user()" blocked students mid-exam (2026-09-02)

**Root cause: lib.php's global helper functions were never reliably loaded in WS/task
context**:
- A student (John Adebayo) reported that after answering Q1 of both MSP 2027 Advisory
  Assessment and MSP 2027 Basic Finance Assessment, every subsequent "Save & Next" click
  failed with `Error saving answer: Exception - Call to undefined function
  learnguard_is_available_for_user()` — timer running, unable to progress past question 1,
  on both assessments.
- Root cause: `classes/external.php` (the web-service class every exam-time AJAX call goes
  through) never `require_once`'d `lib.php` at the class/file level — only a handful of
  individual methods (chat/notification-related) remembered to add their own
  `require_once` before calling a bare `learnguard_*()` function. `lib.php`'s functions
  are NOT autoloaded the way this class's own namespaced methods are: a web-service
  request only loads the `external.php` class file itself, so calling a bare global
  function from it fatals unless something in that same request happened to load lib.php
  first. `submit_answer()` and `autosave()` started calling
  `\learnguard_is_available_for_user()` directly in v2.0.127 (2026-08-31) without that
  require — meaning every save on every attempt started fatally erroring instead of
  progressing, from that version onward, for three shipped versions before being caught.
- Fixed by adding one `require_once($CFG->dirroot . '/mod/learnguard/lib.php')` at the top
  of `classes/external.php`, covering every method in the class instead of relying on each
  one to remember its own require.
- The same gap existed in two of this week's new scheduled tasks:
  `auto_finish_expired_attempts` (v2.0.134, calls the same function plus
  `learnguard_attempt_timelimit_expired()` — every 5-minute run would have fataled
  immediately) and `cleanup_proctoring_data` (v2.0.128's `learnguard_notify_media_deletion_reminder()`
  call — would have fataled and aborted that day's ENTIRE cleanup run, including the
  unrelated webcam/360/events/messages retention deletions that used to run fine on their
  own). Both now `require_once` lib.php at the top of `execute()`, matching the pattern
  `send_grader_reminders`/`send_preexam_notifications` already used correctly.
- Audited the rest of `classes/` for the same pattern — `attempt_manager.php`'s one bare
  call already had its own local require; `question_engine.php` and
  `classes/event/course_module_viewed.php` call no bare `learnguard_*()` functions at all.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.134 — critical: attempts stuck 'inprogress' for hours/days past their time limit + grader pagination/bulk (2026-09-01)

**Root cause: nothing ever enforced a per-attempt's OWN time limit server-side**:
- Michael found attempts sitting `state='inprogress'` for 23+ hours (one showed "23:15:05"
  elapsed) and even multiple days — well past the exam's own time limit, which should have
  auto-submitted. The client's `initTimer()` calls `autoFinishAttempt()` when time runs out,
  and the v2.0.127/v2.0.131 server-side backstops in `submit_answer()`/`autosave()` only ever
  checked the personal/activity WINDOW closing — never this specific attempt's own time
  limit. Worse: a closed tab, crashed script, or lost connection sends the server nothing
  further at all, so even that backstop could never fire for an abandoned session — there
  was no mechanism that didn't depend on the student's browser still being alive.
- Fixed with two layers: (1) new `learnguard_effective_timelimit_seconds()` /
  `learnguard_attempt_timelimit_expired()` in `lib.php`, mirroring the client's own
  `effectiveLimit` math exactly (activity timelimit or a per-user `timelimit_override`,
  plus granted `extra_time`; a Continue & Review attempt's whole budget IS its `extra_time`)
  — wired into `submit_answer()`/`autosave()` alongside the existing window check. (2) A new
  scheduled task, `auto_finish_expired_attempts`, running every 5 minutes, scans every
  `inprogress` attempt site-wide and force-finishes any whose window or time limit has
  expired — this is what actually catches an abandoned/closed-tab session, since nothing
  else ever will. Both correctly exclude time spent on an approved break: unlike a
  suspension, `end_break()` never shifts `timestart` forward, so without this a student on
  (or just back from) a legitimate break could have been cut off for time that was never
  really theirs to lose.

**grader.php — pagination (20/page) and bulk void/delete for individual attempts**:
- The Not Yet Graded / Graded lists rendered every student at once (179 in Michael's case)
  with only client-side search/sort. Added client-side pagination on top of the existing
  filtered+sorted set — 20 per page, Prev/page-numbers/Next, resets to page 1 on search/
  sort/tab changes.
- Added a bulk-select checkbox to each attempt row (finished/suspended/voided only, not a
  live session) plus a "Void Selected" / "Delete Selected" toolbar above the tabs — same
  underlying actions as the existing single-attempt void and `scorematrix.php`'s bulk
  delete, scoped to whichever attempts are ticked on this one assessment's grader page.

No schema change — no new upgrade.php savepoint needed; the new scheduled task registers
automatically from `db/tasks.php` during the upgrade.

## v2.0.133 — whole-assessment delete/void, one-review-only rule, and grader/matrix keep pre-review scores visible (2026-09-01)

**Root cause: reopening an attempt for review hid the student's prior grading entirely**:
- `grader.php`'s main attempt query only ever selected `state IN ('finished', 'voided')` —
  the instant Continue & Review is granted, the attempt flips to `state='inprogress'` and
  vanished from the query completely, along with every essay grade, question pill, and
  score that had already been recorded for it. Across a whole course this looked exactly
  like Michael described: "Graded 0 / Not Yet Graded 0" for students who were fully graded
  before review was ever granted.
- `scorematrix.php` already included `inprogress` attempts in its query, but rendered every
  one of them as a bare "in progress" cell regardless of whether a real prior score existed
  — a 100.00 became indistinguishable from a genuinely untouched fresh attempt.
- Fixed in both: an `inprogress` attempt with `reopened_for_review=1` and a `prev_sumgrades`
  snapshot (set when review was granted) is now treated as still-graded for every purpose —
  tab bucketing, sort, CSV export, totals/averages, pass/fail badges — using
  `prev_sumgrades` in place of `sumgrades` until the learner actually resubmits. Nothing
  about the underlying `learnguard_responses`/`learnguard_essay_grades` data is at risk
  during this window, since the Continue & Review save path (v2.0.124) defers every write
  until final submit — this was purely a display gap, not a data-loss one.
- Both pages now show a "🔄 Review Pending" badge on these rows so it's clear the number
  shown is the PRIOR score, not a live one. Once the learner resubmits (`state` flips back
  to `finished` with a fresh `sumgrades`), a green ▲ / red ▼ appears next to the new score
  comparing it against `prev_sumgrades` — same pattern `scores.php` already had, now also on
  `grader.php` and `scorematrix.php`.

**A learner only gets ONE Continue & Review per attempt, enforced + communicated**:
- `assessmentmanager.php`'s Continue & Review action now skips any attempt that already has
  `reopened_for_review=1` set (granted before, whether still pending or already resubmitted)
  instead of reopening it again, and reports how many students were skipped and why.
- The "Attempt Reopened for Review" screen students see before their precheck now states
  plainly that they get one review only and it cannot be repeated.

**Whole-assessment Delete/Void, available per-row and in bulk**:
- New `learnguard_void_assessment_attempts()` in `lib.php` — voids every finished/suspended
  attempt under one assessment (same effect as the existing per-attempt Void, applied to
  all of them at once); a currently in-progress attempt is left alone, same as the existing
  per-attempt/bulk void on `scorematrix.php`.
- `siteadmin.php`'s Assessments list gained a per-row 🚫 Void button next to the existing
  per-row actions, plus a bulk "Void Selected" alongside the existing bulk Delete.
- `grader.php`'s old full-width "Danger zone" block (Michael: *"why is this here?"*) is now a
  slim inline note pointing at Site Admin → Assessments for the full list/bulk view, with
  matching Void + Delete buttons kept on this page too (per explicit request) rather than
  removed outright.
- No schema change — `reopened_for_review`/`prev_sumgrades` already existed; no new
  upgrade.php savepoint needed.

## v2.0.132 — Confirm Suspension: audio evidence, mail status, and an audit log page (2026-09-01)

**attemptdetail.php — Audio Clips can now be ticked as evidence too**:
- Each audio clip card in the Audio Clips section gained a checkbox (`sm-audio-chk`,
  `data-clipid`), matching the existing webcam/screen snapshot checkboxes. The Confirm
  Suspension modal's evidence counter and hidden-field collection on submit now cover both
  — `snapids[]` and a new `audioids[]` are both posted to `confirmsuspendnotify`.

**lib.php — learnguard_notify_student_suspension_confirmed() extended**:
- New `array $audioIds = []` parameter — selected `learnguard_audio_clips` rows are decoded
  and added to the same evidence.zip attachment as `evidence_audio_N.webm` files, alongside
  the existing snapshot images.
- `email_to_user()`'s call is now wrapped in try/catch and its return value captured — the
  function returns `bool` (previously `void`) so the caller knows whether the email actually
  sent. `attemptdetail.php`'s redirect notification now reflects this (success vs. a warning
  that the email failed) instead of always claiming success.
- Every call now inserts a row into a new `learnguard_suspension_notices` table: learnguard,
  attemptid, student, proctor, reason, evidence counts (images/audio separately), mail status
  (`sent`/`failed`), the error message on failure, and a timestamp — a permanent audit trail
  independent of whether the email itself succeeded.

**New suspensionnotices.php — Suspension Notices Log**:
- Site-admin page (`moodle/site:config`) listing every Confirm Suspension notification ever
  sent: date/time, assessment, student (+email), proctor, reason (truncated, full text on
  hover), evidence counts, a colour-coded mail status badge (failures show the error on
  hover), and a link into that attempt's `attemptdetail.php`. Search box (student/proctor/
  assessment name) + mail-status filter + pagination (50/page).
- Registered under Site Administration → Plugins → LearnGuard, linked from `storage.php`'s
  quick-links bar, and from a new "📋 View Suspension Notices Log" link next to the Confirm
  Suspension button on `attemptdetail.php` (site admins only).

**Schema**: new `learnguard_suspension_notices` table, upgrade savepoint `2026090101`.

## v2.0.131 — critical: Continue & Review auto-submitting instantly + SOS/Break kill switches (2026-09-01)

**Continue & Review was auto-submitting within ~1 second of starting, discarding answers**:
- Root cause 1 (client): `startScheduleCountdown()` reads `cfg.timeclose` — the ORIGINAL
  activity/personal window — completely unaware of `reopened_for_review` sessions. A review
  session is only ever granted AFTER that original window has already closed (that's the
  whole reason review is needed), so its very first 1-second tick saw a deeply negative
  "remaining" and immediately called `autoFinishAttempt()` before the learner could see or
  answer anything — this, not the currentpage issue fixed in v2.0.130, was the real cause of
  attempts appearing to submit instantly. Fixed: the function now returns immediately when
  `state.reopenedForReview` is true — the review's own timer (`extra_time`, via `initTimer()`)
  is what governs a review session's deadline instead.
- Root cause 2 (server, and the more damaging half): `submit_answer()`'s v2.0.127 server-side
  window-close backstop also had no awareness of `reopened_for_review`. Once a review session
  reached final submit, `flushReviewAnswers()`'s very first `mod_learnguard_submit_answer` call
  hit `learnguard_is_available_for_user()` — false, since the original window had already
  passed — which silently auto-finished the attempt and returned `window_closed:true` INSTEAD
  OF saving the answer, discarding every locally-held review edit and re-scoring off the
  stale original responses. This is what looked like "the previous score gets overridden by
  nothing" / new answers not sticking. Fixed: both `submit_answer()` and `autosave()` now skip
  the window-close check entirely when `$attempt->reopened_for_review` is set.
- Together these explain the full chain of symptoms reported: instant-looking submission,
  "Loading question…" stuck mid-navigation (a late-resolving `get_question` response landing
  in a container the premature finish had already replaced), and blank/unfilled answers on
  a subsequent visit (the attempt had already flipped back to `finished`, so the next visit
  no longer resumed the review session at all).

**SOS and Break: same global-kill-switch gap already fixed for chat in v2.0.130**:
- `learnguard/enable_sos` and `learnguard/enable_break` had the identical bug — the global
  admin setting only ever seeded the per-activity checkbox's default and was never re-checked
  at runtime. `view.php`'s `enablesos`/`enablebreaks` config and `request_break()`'s
  server-side check now both also require the global setting to be on, matching the
  `enablechat`/`send_chat()` fix.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.130 — global chat kill switch not enforced + Continue & Review landing on last question (2026-09-01)

**Chat disabled globally but students could still send messages**:
- Root cause: `learnguard/enable_chat` (the global admin setting) was only ever read as the
  DEFAULT value for the per-activity "Enable Chat" checkbox in `mod_form.php` when an activity
  is created/edited. Flipping the global setting off never touched the `enable_chat` column
  already stored on existing activities, so every activity created before the toggle kept
  working exactly as before — both the chat widget (client) and `send_chat()` (server) only
  ever consulted the per-activity value.
- Fixed by making the global setting a real master kill switch: `view.php`'s `enablechat`
  config and `classes/external.php`'s `send_chat()` now both require
  `get_config('learnguard', 'enable_chat')` to be on AND the per-activity `enable_chat` to be
  on. Global OFF now blocks chat everywhere immediately; the per-activity checkbox can still
  further restrict chat on a specific activity when the global setting is on, but can no
  longer re-enable it when the global setting is off. `send_chat()` is the actual enforcement
  point — this blocks a student's message server-side even if their page was already loaded
  with the chat widget visible before the setting was changed.

**Continue & Review: attempt appeared "already submitted" before the student could answer**:
- Root cause: `start_review()` (called once the student redoes precheck and activates a
  reopened review session) only ever set `timestart = now` — it never touched `currentpage`,
  which still held wherever the ORIGINAL attempt was left when it was first finished (usually
  the last question, since that's typically where "Finish" was clicked from). The client
  loads `state.currentslot` straight from this value, so a review session opened directly on
  the LAST question — already showing a Finish button, nothing to click Next into — which
  read to students as the assessment being submitted before they could review or change
  anything.
- Fixed: `start_review()` now also resets `currentpage` to the first question's slot
  (`attempt_manager::get_navigation_summary()[0]`) at the same moment it activates the review
  timer, so the learner always starts a review session at question 1 as intended.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.129 — upgrade crash fix: "Duplicate column name 'is_screen'" (2026-08-31)

**db/upgrade.php — savepoint 2026083021 (is_screen) upgrade failure on Michael's site**:
- Michael's upgrade to v2.0.128 crashed with a DDL error: `Duplicate column name 'is_screen'`
  on `add_field()`, even though that step is guarded by `field_exists()` and that guard
  reported the column as NOT present. Michael's site stack trace shows
  `moodle_read_replica_trait.php` in the call path — his hosting uses DB read replicas, and
  the most likely explanation is the `field_exists()` schema check was answered by a replica
  that hadn't yet caught up with an ALTER TABLE from an earlier, interrupted upgrade attempt
  (MySQL DDL auto-commits and is never rolled back by Moodle's upgrade transaction, unlike
  normal row inserts/updates — so a prior failed attempt could leave the column physically
  present while `learnguard`'s recorded plugin version never advanced past it).
- Fixed by wrapping the `add_field()` call in a try/catch for `ddl_change_structure_exception`:
  if the specific error is "duplicate column", the desired end state (column present) is
  already true, so the step now just continues instead of failing the whole upgrade; any
  other DDL error still throws and blocks the upgrade as before.
- No new schema change — this only hardens the existing 2026083021 step's error handling.

## v2.0.128 — auto-deletion of old evidence media + 1-day-ahead proctor reminder email (2026-08-31)

**classes/task/cleanup_proctoring_data.php — new evidence media retention rule**:
- Previously: the daily GDPR cleanup task only auto-deleted `snapshot_type = 'periodic'`
  (webcam, 14 days), `'360_video'` (7 days), and `'photo_id'` (30 days) snapshots. Every other
  snapshot type — `screen_capture`, all `violation_*` evidence captured on scored violations,
  `random_screenshot`, `multiple_faces`, `object_*`, `multiple_persons`, `background_change`,
  `identity_mismatch` — was never auto-deleted at all, and `learnguard_audio_clips` (recorded
  noise-violation audio) wasn't covered by any retention rule either. Both accumulate
  indefinitely and were the main driver of growing storage use.
- New: these previously-uncovered snapshot types plus the entire `learnguard_audio_clips`
  table are now deleted once they're older than a new `learnguard/retention_media_days`
  setting (default 30 days). The existing periodic/360/photo_id rules and their own retention
  periods are untouched — this only fills the gap for evidence media that had no rule before.
- New `learnguard/media_deletion_reminder_enabled` setting (default on): ~24 hours before a
  batch of evidence images/audio clips crosses the retention cutoff, `lib.php`'s new
  `learnguard_notify_media_deletion_reminder()` emails every global proctor
  (`learnguard_proctors.is_global = 1`) a summary count and a link to Storage Management so
  they can download anything they still need before the permanent deletion runs. Since the
  task runs once daily, each item passes through the ~24h reminder window exactly once before
  being deleted the following day.
- Both new settings added under the existing "💾 Retention & Storage Cleanup" section in
  `settings.php`, alongside the pre-existing snapshot/360/events/messages retention settings.
- No schema change — no new upgrade.php savepoint needed (new config settings only).

## v2.0.127 — server-side enforcement of window close during an attempt (2026-08-31)

**classes/external.php — submit_answer() and autosave() now enforce the personal/activity
window close server-side, not just client-side**:
- Previously: only `start_attempt()` checked `learnguard_is_available_for_user()` (blocking a
  NEW attempt after the window closes). Once an attempt was already `inprogress`,
  `submit_answer()` only validated ownership and state — nothing stopped a student from
  continuing to save answers past their window close if their browser never fired the
  client-side countdown auto-submit (closed tab, crashed JS, tab reopened later). The existing
  client-side countdown badge / auto-submit in `assessment.js` (`startScheduleCountdown()`)
  was already correct and unchanged — this closes the server-side gap behind it.
- `submit_answer()`: before saving, checks `learnguard_is_available_for_user($instance, $USER->id)`
  using the same per-user schedule/activity window resolution already used by `start_attempt()`.
  If the window has closed, calls `attempt_manager::finish()` (same path the client's own
  auto-submit uses) and returns a new `window_closed` flag instead of saving the answer.
- `autosave()`: same check added as a backstop so a lingering 5s autosave interval can't keep
  an attempt silently `inprogress` past the window close either.
- `amd/src/assessment.js` — `submitCurrentAnswer()`'s WS `.then()` handler now checks
  `data.window_closed` (mirroring the existing `data.section_expired` handling): shows a toast
  explaining the window closed, then calls the existing `finishAttempt()` (which is idempotent
  server-side via `finish_attempt()`'s already-finished branch) to fetch the grade and render
  the results screen.
- No schema change — no new upgrade.php savepoint needed.

## v2.0.17 — auto-recalc all attempts, has_ungraded_essays alignment, score matrix links (2026-06-28)

**grader.php + scores.php — auto-recalculate ALL finished attempts on every page load**:
- Previously: only filled null sumgrades; existing wrong scores were not corrected automatically.
- Now: recalculates every finished attempt on every page visit. Attempts with pending essay
  questions are set to null; all fully-graded attempts get a fresh correct score written.
- "Recalculate All Scores" button is still available for manual on-demand use.

**has_ungraded_essays() — uses learnguard_essay_grades as source of truth**:
- Previously: checked learnguard_responses.state = 'graded' to determine if an essay is graded.
  This diverged from calculate_total_grade() which uses essay_grades.
- Now: checks learnguard_essay_grades for the presence of a grade row per assigned essay slot.
  An essay slot is graded when any grader has submitted a grade in essay_grades. Aligned with
  the new calculate_total_grade() so both functions agree on "graded" status.

**scorematrix.php — essay-pending CASE WHEN updated to use essay_grades**:
- Score cells now hidden (NULL) when an essay slot has no row in learnguard_essay_grades,
  matching the has_ungraded_essays() and calculate_total_grade() sources of truth.

**scorematrix.php — score cells are now clickable links**:
- Each non-blank score cell links to grader_user_report.php?id=CMID&userid=UID so admins
  can click directly from the matrix into the per-student attempt detail page.

**grader.php — stats bar shows Graded / Pending count**:
- Added "Graded: X" (green) and "Pending: Y" (red) counters next to the existing Attempts/Students.

## v2.0.16 — calculate_total_grade rewrite + SA graded detection fix (2026-06-28)

**classes/question_engine.php — calculate_total_grade() rewritten from scratch**:
- Previous: used `learnguard_responses.fraction` for ALL question types including essays.
  Essay fractions are only updated when the grader.php save handler runs — old/multi-grader
  data could have stale or conflicting fractions, producing wrong scores (29.24 vs 28.78).
- New: separates manual grades (learnguard_essay_grades, latest per slot) from auto-grades
  (learnguard_responses.fraction for non-essay types only). Manual grade takes priority for
  any question type. No double-counting. No response.fraction used for essays.
- Formula: round( sum(earned_per_slot) / sum(maxmark_per_slot) × instance->grade, 5 )
  where earned = latest manual grade OR auto fraction × maxmark OR 0 (ungraded/unanswered).
- Works correctly for all question types: essay, MCQ, SA, TF, multicheck, matching.
- After deploying: click "Recalculate All Scores" on grader.php or scores.php to fix
  any stored wrong sumgrades with the corrected formula.

**grader.php — SA/auto-graded slot status fix**:
- Previous: `slotStatusMap` marked auto-graded slots (MCQ/SA/TF) as 'graded' only when
  `$has=true`, but `has` required non-empty response text OR fraction > 0. A SA question
  graded as wrong (fraction=0) with empty stored text would show as 'na' instead of 'graded'.
- Fixed: for auto-graded types, any response row in learnguard_responses = 'graded'.
  The system evaluated it; fraction=0 means wrong answer, still a graded state.

**scores.php — page-load auto-recalc now only fills null sumgrades**:
- Same "only fill null" pattern applied as grader.php — existing stored scores not overwritten.
- Use "Recalculate All Scores" button to force fresh calculation for all attempts.

## v2.0.22 — Break timer, violation evidence, assessment manager, formatting toolbar (2026-06-30)

**classes/external.php — request_break() accepts duration parameter**:
- `request_break` WS now accepts `duration` (int, minutes). Clamped to `break_min_minutes`/`break_max_minutes` admin settings (defaults 5–10 min).
- Stores `break_duration` in `learnguard_attempts`. `approve_break` now computes `break_deadline = time() + break_duration * 60` and stores it.
- `end_break` also clears `break_deadline`. `get_messages` returns `break_deadline` and `break_duration` to the client poll.

**amd/src/assessment.js — break overlay with countdown and timer freeze**:
- `requestBreak()` now shows a modal dialog letting the student select their break duration (min/max from `cfg.break_min_minutes`/`cfg.break_max_minutes`).
- `showBreakOverlay(deadline, durationMins)` shows a live countdown (MM:SS) ticking to `break_deadline`. Auto-calls `end_break` when it hits zero.
- `hideBreakOverlay()` sets `state.onBreak = false` and resumes the exam timer.
- Timer (`initTimer`) was already frozen by `state.onBreak` from prior session; the break overlay now correctly sets and clears this flag.

**settings.php — break min/max admin settings**:
- New `learnguard/break_min_minutes` (default 5) and `learnguard/break_max_minutes` (default 10) under "☕ Break Feature Settings".
- `view.php` passes both to `cfg.break_min_minutes`/`cfg.break_max_minutes` for the JS duration picker.

**admin.php — approvebreak handler sets break_deadline**:
- On break approval from proctordashboard.php form, now computes and stores `break_deadline` (so break_deadline is set whether approval comes via form POST or via `approve_break` WS).

**proctordashboard.php — break visibility for proctors**:
- "Break Requests" bar shows the requested duration (e.g., "8 min requested") alongside Approve/Deny buttons.
- New "⏸ On Break" bar shows students currently on an approved break with live countdown timers (JS `setInterval`).
- New "✅ Recently Resumed" bar shows students who finished their break in the last 30 minutes.

**amd/src/assessment.js — auto-capture evidence for every scored violation**:
- `logProctoringEvent()`: when `impact > 0`, automatically calls `captureAndUploadSnapshot('violation_<eventtype>')` (webcam photo) and `captureScreenSnapshot('violation_<eventtype>')` (screen capture).
- Also triggers a 5-second audio clip via `startAudioClipRecording()` when a mic stream is available (`violationAudioStream`, stored at module level from `initAudioClipRecording()`).
- Evidence snapshots are uploaded with the violation event type prefix so they can be correlated to specific violations in storage/gallery.

**db/upgrade.php + db/install.xml — new columns**:
- `learnguard_attempts`: `break_duration` (int5, default 5), `break_deadline` (int10, default 0).
- `learnguard_schedules`: `extra_attempts` (int5, default 0), `timelimit_override` (int10, default 0).
- Upgrade savepoint `2026063002` with idempotent `field_exists` guards.

**assessmentmanager.php — new site-admin Assessment Manager page**:
- Site admins select a course → then an assessment → see all enrolled students with attempt counts (finished/active/voided), best score, and per-user overrides.
- Per-user overrides: window close date, extra attempts (added to activity max), time limit in minutes (0 = activity default). All persisted to `learnguard_schedules`.
- Bulk Reattempt: select students, click "Grant Reattempt" to void the latest finished attempt (students can sit again). Old attempt remains visible as voided.
- Accessible from settings.php admin tree ("LearnGuard — Assessment Manager") and from siteadmin.php quicklinks bar.

**view.php — per-user extra_attempts and timelimit_override applied**:
- Reads `learnguard_schedules.extra_attempts` and adds to `$maxattempts` (unlimited stays unlimited).
- Reads `learnguard_schedules.timelimit_override` and substitutes for `$instance->timelimit` in spaconfig when non-zero.

**questionedit.php — formatting toolbar for text fields**:
- Added `lgFmtToolbar()` JS helper that renders B/I/U/• List/1. List/↵ BR buttons above Question Text, Marking Guide, and General Feedback textareas.
- Toolbar inserts HTML tags at the cursor position; select text first to wrap it.

## v2.0.21 — SA variants, grade history, notify threshold, storage perf, show_results, snapinterval (2026-06-30)

**classes/question_engine.php — render_shortanswer() shows ALL accepted answer variants**:
- Previously: only the first full-marks answer was shown in the "Correct answer" box.
- Now: all answers with fraction ≥ 1.0 are shown as a bulleted list of "Accepted answers".
  Partial-credit answers (0 < fraction < 1) are listed separately with their % credit.
- Border colour: green when the student's answer matches any full-marks variant; orange when
  it matches a partial-credit variant; red when no match. Grading still checks all variants
  for correctness (grade_response() was already correct; this is a display-only fix).
- Applies in both the student review screen and the grader panel.

**grader.php — grade history popup includes auto-graded entries**:
- `$graderHistory[]` now appends a "System (auto-graded)" entry (with grade, timestamp,
  type: 'auto') when the question type is MCQ/SA/TF and a response row exists.
- Also tagged manual entries with `type: 'manual'` for future differentiation.

**settings.php + classes/attempt_manager.php — configurable email notification threshold**:
- New admin setting `learnguard/score_notify_pct` (default 50%): proctors are emailed
  when the learner's risk score reaches this percentage of the auto-suspend score.
  Example: autosuspend=80, notify_pct=50 → email fires at 40 pts (not hardcoded 60).
- `log_event()` in attempt_manager.php now reads both settings dynamically to compute
  `$highAt`; the 'high' risk level and incident creation use this value.
- Notification message now shows the computed threshold instead of hardcoded 60.

**storage.php — lazy-load SUM(LENGTH(snapshot_url)) to avoid slow page load**:
- The summary stats now run COUNT-only on initial page load (fast, index-only scan).
- Byte-size totals are fetched asynchronously via `?action=getstats&sesskey=…` after
  the page renders, so counts appear immediately and sizes fill in within a few seconds.

**view.php — global plugin settings link for site admins**:
- A "⚙️ Plugin Settings" button (links to `/admin/settings.php?section=modsettinglearnguard`)
  is now shown in the activity manager bar for site admins only, alongside Site Admin / Overview.

**view.php — show_results enforcement in attempt history**:
- When a teacher has set "Manual result release" (`show_results=0`) and has not yet
  released results (`results_released=0`), `sumgrades` is masked as null in the
  `myattempts` array passed to the SPA, so students do not see their score in the
  "Your Attempts" history list (score column shows "—").
- Managers bypass this check and always see scores.
- The submission completion screen was already handled by external.php (`resultsheld` flag).

**view.php — snapinterval reads global plugin setting when per-activity is default**:
- Previously: `proctor_snapinterval` (per-activity DB field, default 30) was always used,
  ignoring the global `learnguard/snapinterval` admin setting.
- Now: if the per-activity value is still at the install default (30s), the global plugin
  setting is used instead. Per-activity overrides only apply when explicitly changed from 30.

## v2.0.20 — install.xml break fields, SA display case fix, grader legend (2026-06-28)

**db/install.xml — learnguard_attempts: added missing break/extra-time columns**:
- Fresh installs were missing `extra_time`, `break_requested`, `break_approved`, `break_end`
  (they existed in upgrade.php savepoint 2025051816 but not in install.xml).
- Added all four fields with correct type/length/default. No upgrade step needed — existing
  sites already have these columns from the previous upgrade run; install.xml only affects
  brand-new installs.

**classes/question_engine.php — render_shortanswer() respects casesensitive flag**:
- Added optional `bool $caseSensitive = false` 5th parameter.
- Review-mode border colour (green/red) now compares using exact-case match when `$caseSensitive=true`,
  and case-insensitive `mb_strtolower()` when false (existing default behaviour unchanged).
- `render_question()` passes `!empty($question->casesensitive)` automatically.

**grader.php — shortanswer review in grader panel also respects casesensitive**:
- Added `q.casesensitive` to the slot SELECT query in the `get_question` AJAX handler.
- Passes `!empty($slot->casesensitive)` to `render_shortanswer()` so grader panel shows
  correct green/red border when teacher has enabled case-sensitive matching.

**grader.php — legend updated with colour-coded pills**:
- Replaced the plain-text "RG / NA / ✓ Graded by me" legend with coloured pill swatches:
  green (Full marks), yellow (Partial), red (Zero/Wrong), blue (RG), red-gray (NA).

## v2.0.19 — Colour-coded pills in all views, SA case-sensitivity UI, bulk mark change (2026-06-28)

**grader.php — colour-coded question pills apply to all question types**:
- Student-row pills (`.edg-qpill`) now always show the correct colour regardless of question type:
  green (full, ≥100%), yellow (partial, >0%), red (zero, 0%) for graded questions;
  blue (rg) for ungraded essays; red-gray (na) for unanswered.
- Header nav pills in the detail panel (`.edg-hq-pill`) now also show the same colour coding
  when the panel is opened — previously all showed as the default dark/gray regardless of status.
- JS save callback updated: after saving any grade, the correct colour class is applied to both
  the student-row pill and the nav pill based on the saved grade ratio (not a hardcoded 'ok').
- Local JS score/status maps updated on save so re-opening a question shows the new colour.
- Light-mode CSS updated with `full`, `partial`, `zero` overrides for the nav pills.

**questionedit.php — case-sensitivity checkbox for short-answer questions**:
- Added a "Case-sensitive matching" checkbox to the Short Answer section UI.
- Backend (`grade_response()`, `casesensitive` DB column, save handler) was already complete.
- By default unchecked (case-insensitive). Teachers can tick to require exact-case matching.

**questionbank.php — bulk mark change + automatic attempt recalculation**:
- New "✏ Change Mark" control in the bulk action bar: number input + button.
- Select any questions, set the new mark, click Change Mark.
- Handler (`bulkchangemark`): updates `learnguard_qbank.defaultmark` and
  `learnguard_slots.maxmark` for all selected questions, then finds all finished attempts
  that included those questions (via `learnguard_attempt_questions`) and recalculates
  each attempt's `sumgrades` using the new maxmarks. Pushes updated scores to gradebook.
  Attempts with pending essay questions get their sumgrades set to null (awaiting grading).

## v2.0.18 — Raw mark display in grader + scores; in-progress attempts no longer block Graded tab (2026-06-28)

**grader.php — grade display shows raw earned / total slot maxmarks**:
- Previously showed `sumgrades / activityGrade` (e.g., "25.28 / 35.00") — the denominator
  was the Moodle activity grade setting, not the actual total marks from the questions.
- Now shows raw earned marks / sum of all assigned slot maxmarks (e.g., "32.5 / 45.00").
- Raw earned = `round(sumgrades × totalSlotMax / activityGrade, 2)` (reverse of scaling).
- Applies to finished, voided (with "(voided)" label), and pending (shows "—/totalMax").

**grader.php — tab assignment skips non-finished attempts**:
- Root cause: the allGraded loop only skipped `state='voided'`. If a student had a finished
  graded attempt AND a new in-progress reattempt, the in-progress slots showed as 'na',
  keeping the student in "Not Yet Graded" even though their finished attempt was fully graded.
- Fix: `if ($att->state !== 'finished') { continue; }` — only finished attempts are evaluated.
  Also fixes SA auto-graded students stuck due to having an in-progress reattempt.

**scores.php — same raw mark display across all output formats**:
- HTML table, CSV, Excel, PDF all now show `rawEarned / totalMax`.
- `totalMax` is batch-computed via `learnguard_attempt_questions + learnguard_slots.maxmark`.
- Percentage column unchanged — `sumgrades / activityGrade × 100` = `rawEarned / totalMax × 100`.
- Score matrix intentionally unchanged — shows sumgrades (activity-grade-scaled value).

## v2.0.15 — Grader fixes: voided handling, grader history, question deletion, per-attempt print (2026-06-28)

**grader.php — auto-recalc: only fill null sumgrades on page load**:
- Removed the page-load recalculation that overwrote ALL existing sumgrades.
- Now only recalculates for attempts where `sumgrades = null` (not yet calculated).
- Existing stored scores are trusted and never overwritten on page load by non-manager graders.

**grader.php — voided attempts do not block "Graded" tab**:
- The `$allGraded` check now skips voided attempts entirely.
- Students whose only non-voided finished attempt is graded now appear in the "Graded" tab.
- Short-answer (auto-graded) slots are correctly counted as `graded` in the tab logic.

**grader.php — voided attempt grade display**:
- Voided attempts now show their stored score + "(voided)" label instead of blank.
- Example: "28.78 / 35 (voided)" displayed in the grade column.

**grader.php — "Graded by" shows actual grader name + clickable history popup**:
- "Another grader" replaced with the actual fullname of the most recent grader.
- The name is a clickable link that opens a grade history popup modal.
- Popup lists all graders for this slot with grade value and timestamp.
- For "You" graded slots: also clickable to show history of all previous grade entries.
- MCQ/SA auto-graded slots still show "Auto-graded (you can override)".

**grader.php — last grader name in essay question pills**:
- Essay pills in the student row now show the last grader's name as a subtitle and tooltip.
- Name is batch-loaded (no N+1 queries); auto-graded/MCQ/SA pills are unaffected.

**questionbank.php — fix deletion DB error**:
- Before deleting slots, now fetches slot IDs and deletes any `learnguard_attempt_questions`
  records referencing those slot IDs. Fixes the DB error on certain question deletions.
- Applied to both single-question delete (`deleteq`) and bulk delete (`bulkdelete`).

**grader_user_report.php — per-attempt print button**:
- Each attempt accordion header now has a "🖨️ Print" button.
- Clicking it prints only that one attempt (not all attempts for the student).
- Uses class-based CSS hiding so all other accordion items are hidden during print.

## v2.0.14 — Answer ID-based save, MCQ options in PDF report, accordion attempts (2026-06-27)

**questionedit.php — ID-based answer save (prevents answer deletion when editing text)**:
- Root cause: save loop matched existing DB answers by text; editing any answer text caused
  the old row to be deleted (no text match) and a new row inserted with a new ID, breaking
  all stored MCQ student response IDs for auto-regrade.
- Fix: each existing MCQ/SA/matching answer row now includes a hidden `mcq_answer_id[]` /
  `sa_answer_id[]` / `match_answer_id[]` field with the DB row ID. PHP save loop tries
  ID-match first (updates text AND fraction in place, preserving the ID); only falls back
  to text-match for rows without a recognised ID; inserts as new when neither matches.
- JS `addAnswerRow()` and `addMatchRow()` include the hidden ID field (`value="0"`) for
  new rows added dynamically — PHP treats `0` as "no ID → insert new".
- Safety guard: if the submitted answer array is empty (e.g. old form with renamed fields),
  existing answers are preserved rather than silently deleted.

**grader_user_report.php — full MCQ option list with selection + correctness display**:
- Previously showed only the selected answer ID/text. Now loads ALL options from
  `learnguard_qbank_answers` for each MCQ/multicheck question (batch query, not N+1).
- Each option rendered as a styled row: selected+correct (green ✓), selected+incorrect
  (red ✗), unselected+correct (yellow ★), unselected+wrong (neutral).
- Handles multicheck (multiple selected IDs stored as comma-separated values).

**grader_user_report.php — collapsible accordion attempts**:
- Attempts are now accordion items; clicking the header expands/collapses that attempt.
- Most recent attempt expanded by default; "Expand All" / "Collapse All" buttons in header.
- Accordion state is preserved for print (all bodies visible via @media print CSS).

**grader_user_report.php — score breakdown table (managers only)**:
- For each attempt, a "Score Breakdown" section shows: slot, question type, stored maxmark,
  stored fraction, and contribution to totalraw. Totals row + calculated scaled score shown.
- Helps Michael diagnose score discrepancies for specific students without DB access.

## v2.0.13 — 10-item fix batch: grade display, MCQ/SA save, global grader, PDF export (2026-06-27)

**questionedit.php — type-specific field names (items 2 & 3: MCQ/SA answer pollution fix)**:
- Root cause of "Left 1"/"Right 1" being saved as MCQ/SA answers: all UI sections shared
  the same POST field names (`answer_text[]`, `answer_fraction[]`, `answer_feedback[]`).
  Even with JS disabling hidden sections, previously-saved "Left 1"/"Right 1" records in DB
  were PRESERVED by the update-in-place logic (matched by text → `usedExistingIds` marked).
- Fix: renamed each section's inputs with a type-specific prefix:
  MCQ/multicheck → `mcq_answer_text[]` / `mcq_answer_fraction[]` / `mcq_answer_feedback[]`;
  Short answer → `sa_answer_text[]` / `sa_answer_fraction[]`;
  Matching → `match_answer_text[]` / `match_answer_fraction[]` / `match_answer_feedback[]`.
- PHP now reads only the correct prefix based on `$qtype` — no cross-section pollution
  regardless of browser behaviour. JS `addAnswerRow()` and `addMatchRow()` also updated.

**grader.php — grade column shows actual score (item 1)**:
- `edg_render_student_row()` previously used `$essayGradeMap` (raw manual essay grade sum)
  which is always null for MCQ/SA-only activities → showed "— / 30.00".
- Now uses `$latestAtt->sumgrades` (the recalculated scaled grade) against `$lg->grade`.
- Auto-recalc block had `if ($isManager)` guard — removed, so ALL graders now trigger
  recalculation on page load and see up-to-date scores.

**grader.php — anonymous avatar initials (item 6)**:
- Avatar initials previously always used real first/last-name initials even in anonymous mode.
- Fixed: anonymous mode now shows `??` in the avatar circle instead of real initials.

**grader.php — voided attempts shown as Attempt 1/2/3 (item 7)**:
- Main query changed from `state = 'finished'` to `state IN ('finished', 'voided')`.
- Voided attempt tabs show a "VOID" label and strikethrough styling (`.edg-voided`).
- "🔄 Reattempt" button only shown on `state='finished'` attempts.
- Voided attempt meta shows a "⛔ Voided" badge instead of the Reattempt button.

**settings.php — Question Bank in plugin tree (item 4)**:
- Added `learnguard_questionbank` admin_externalpage pointing to `questionbank.php`.
  Now appears under Site Administration → Plugins → LearnGuard.

**grader.php / grader_user_report.php — PDF report of all attempts (item 10)**:
- New page `grader_user_report.php?id=CMID&userid=USERID`: shows all attempts for one
  student with full question text + student answer + grade per slot; browser print button
  for PDF export; anonymous-mode aware; access restricted to assigned graders and managers.
- "📄 View All Attempts" link added to each student row in the grader interface.

**siteadmin.php + block_learnguard.php — Global Grader feature (item 8)**:
- `learnguard_graders` table gains `is_global` (int 1) and `courseid` (int 10) columns;
  upgrade step `2026062702` adds these fields idempotently.
- Site admin graders tab (`siteadmin.php`) gains a "🌍 Global Graders" section:
  lists + add/remove global graders who can grade ALL activities site-wide.
- `grader.php` now also accepts users with `is_global=1` in `learnguard_graders` (falls
  back after not finding per-activity assignment).
- `block_learnguard.php` grader block now shows activities to global graders as well as
  per-activity assigned graders.

**bulkschedule.php — learner table filters by selected course (item 9)**:
- Learner rows now carry `data-courses="cid1,cid2"` from a per-user course membership map.
- JS `lgbsApplyUserFilter()` hides learner rows for courses that have no selected activity;
  `lgbsToggleCourse()` and the activity checkboxes all call it on change.
- "Select All Activities" / "Clear All" buttons also trigger the course filter.
- Learner table starts with all rows hidden until at least one activity is checked.

**Score discrepancy 37/45 → 29.24 (item 5 — investigated, works as designed)**:
- The score of 29.24 reflects the total across ALL questions (essay + MCQ), not just
  the essay sub-score of 37. `calculate_total_grade()` is correct: it uses all assigned
  question responses in the denominator, which is intentional behaviour.
- No code change made; after deploying this version and visiting the grader page, auto-recalc
  will re-run for all graders (not just managers) and show the correct current score.

## v2.0.12 — Grader pill colours, AJAX JSON fix, question-bank answer save fix, block admin visibility (2026-06-27)

**grader.php — MCQ/SA pill colour coding and correct tab placement**:
- Query 1 (AQ-based slot discovery) was missing `q.qtype` and `q.id` from its SELECT clause.
  All AQ-based questions had `$slot->qtype = null`, causing every MCQ/SA/TF slot to fail the
  `in_array($slot->qtype, $autoGradedTypes)` check → all showed as blue "RG" pills, wrong tab.
- Fixed by adding `COALESCE(q.qtype, 'essay') AS qtype, COALESCE(q.id, 0) AS qid` to Query 1.
- MCQ/SA/TF questions now land in the correct "Graded" tab with green/yellow/red pills based on
  the auto-calculated score fraction.

**grader.php — "Failed to load" AJAX error on some questions**:
- PHP notices/warnings in developer mode (from `format_text()` filter calls) were being injected
  into the response buffer before `echo json_encode(...)`, making JS `r.json()` throw.
- Also: malformed UTF-8 in answer text could make `json_encode` return false → empty response.
- Fixed: `@ini_set('display_errors', '0')` at the top of the AJAX block; `if (ob_get_level())
  { ob_clean(); }` before `json_encode`; `JSON_INVALID_UTF8_SUBSTITUTE` flag on `json_encode`.

**questionedit.php — unwanted answer variants when saving MCQ correct answer**:
- Root Bug 1: Hidden form sections (`#ui-matching`, `#ui-shortanswer`, etc.) always submitted
  their `answer_text[]` inputs even when `display:none`. Matching section defaults "Left 1" /
  "Right 1" were being saved as MCQ answer options on every save.
- Fix (JS): added a form `submit` listener that sets `disabled=true` on all inputs inside hidden
  `.lg-type-ui` sections before submit, so only the visible section's answers are posted.
- Root Bug 2: Previous save used delete-all + re-insert, destroying answer IDs. MCQ responses
  store the old answer ID; after re-insert old IDs no longer exist → `grade_response` returns 0
  for all students on auto-regrade.
- Fix (PHP): replaced delete-all+insert with update-in-place: matched answers update in place
  (fraction/feedback only — ID and text preserved), new answers insert, removed answers delete.
- Auto-regrade: truefalse block now runs BEFORE auto-regrade so correct fractions are in DB
  when `grade_response` is called for truefalse questions.

**block_learnguard.php — site admins can now see all activities**:
- `$graderSQL` previously always filtered by `WHERE g.userid = :uid` in `learnguard_graders`,
  so site admins not explicitly assigned as graders for an activity couldn't see it in the block.
- Fixed: `$isSysAdmin` now runs an alternative query with no grader-assignment filter (full
  JOIN on `learnguard` table directly), showing every LearnGuard activity site-wide.

## v2.0.10 — MCQ & Short Answer grading + cross-grader visibility (2026-06-24)

**Issue 1 — MCQ and Short Answer questions now appear in grading interface:**
- `grader.php` now loads ALL manually gradable question types: essays, MCQ, multi-select, short answer
  (was essay-only before). Query changed to `q.qtype IN ('essay', 'multichoice', 'multicheck', 'shortanswer')`.
- AJAX `get_question` handler now:
  - Fetches question answertext + fraction for MCQ/multi-select to show which options are correct
  - Renders MCQ answers with green ✓ badge if correct, red ✗ badge if incorrect
  - Renders multi-select showing all selected options with correctness badges
  - Short answer shown as plain text; essays shown as formatted text
- Graders can override any auto-calculated MCQ/SA score up to the max mark — save via `learnguard_essay_grades`
  (reuses essay_grades table for all manual-graded question types, not just essays).

**Issue 2 — Grading visibility across multiple graders fixed:**
- Slot status map (`$slotStatusMap[$aid][$sid]`) now checks if ANY grader has graded a question, not just the current user.
  Before: Query was `WHERE eg.graderid = :uid` → only showed current user's own grades.
  After: Query removed graderid filter → shows grading done by any grader.
- Grade totals on student row now sum grades from ANY grader (`SUM(grade)... GROUP BY attemptid`).
- Result: A student moves to "Graded" tab when ANY grader finishes all their questions, not just the current user.
- AJAX response includes `graded_by` field: shows "You" if current user graded it, "Another grader" otherwise.
- Status bar shows: "Graded by [grader name] (you can override)" when viewing a grade set by another grader.

**Display Improvements:**
- Status bar now shows dynamic question type: "Type: Essay | MCQ | Multi-Select | Short Answer"
- MCQ/multi-select answers rendered with correctness badges (green ✓ / red ✗)
- Shows who graded each question to avoid duplicate work
- Graders can still override any existing grade

## v2.0.9 — permanent essay-pending detection fix + Reset Essay Grades tool (2026-06-20)

**Root-cause fix for essay scores showing when essays not graded:**
- Previous CASE WHEN checked `er.state = 'needsgrading'`, but essays graded before v2.0.8
  (by old grader.php/gradeessays.php) still have `state='complete'` in DB — those slipped
  through the condition and showed stale/wrong scores even after installing v2.0.8.
- CASE WHEN in `scores.php` and `scorematrix.php` changed to `(er.id IS NULL OR er.state != 'graded')`:
  only responses explicitly marked `'graded'` by v2.0.8+ code are treated as done.
  Any other state (`'needsgrading'`, `'complete'`, `'todo'`, NULL) hides the score.
- This is the permanent fix: it works regardless of whether the v2.0.8 upgrade step ran.

**Reset Essay Grades tool (manager only):**
- New `reset_essay_grades` AJAX action added to both `grader.php` and `scores.php`.
- 🗑 Reset Essay Grades button visible to managers on both pages (next to Recalculate).
- On confirm: deletes all `learnguard_essay_grades` for the activity, resets all essay
  `learnguard_responses` to `state='needsgrading'` / `fraction=0`, nullifies `sumgrades`
  for all finished attempts, and clears Moodle gradebook entries.
- Use this to wipe test/wrong grades and start grading from scratch.

**gradeessays.php decimal fix:**
- Grade input `step="0.5"` changed to `step="any"` — same fix as v2.0.7's grader.php fix.
  Allows any decimal (0.3, 0.75, etc.) without browser silently blanking the field.

## v2.0.8 — Moodle-proper essay grading state + decimal grade column fix (2026-06-20)

**Decimal grade column fix (`db/upgrade.php` savepoint `2026062004`)**:
- Upgrade step alters `learnguard_essay_grades.grade` to `DECIMAL(10,5)` using
  `change_field_type()`. On sites where the column was INT (possible from an earlier install),
  `0.5` was truncated to `0` on save — graders saw the mark disappear when returning to a
  graded question. The DB column has been `DECIMAL(10,5)` in `install.xml` and the original
  upgrade step since the table was created; this catch-up step ensures all sites are correct.

**Moodle-proper essay response state (the "once and for all" fix)**:
- Root cause identified: `save_grade` in `grader.php` and `gradeessays.php` set
  `learnguard_responses.state = 'complete'` for manually graded essays — the same state used
  for auto-graded MCQ questions. This made it impossible to distinguish "graded essay" from
  "answered MCQ" without querying the secondary `learnguard_essay_grades` table.
- Moodle's own quiz engine uses distinct states: `'needsgrading'` (essay submitted, awaiting
  manual mark) and `'manualgraded'` (marked). LearnGuard now follows the same pattern:
  - `grader.php` and `gradeessays.php` now set `state = 'graded'` after saving a manual mark.
  - The upgrade step migrates existing data: essay response rows with `state='complete'` that
    have a grade in `learnguard_essay_grades` → `'graded'`; those without → `'needsgrading'`.
- `has_ungraded_essays()` in `question_engine.php` now checks `learnguard_responses.state`
  directly (counts rows with `state='graded'` vs total assigned essay slots) instead of querying
  `learnguard_essay_grades`. Blank essays (no response row) count as pending until graded.
- CASE WHEN in `scores.php` and `scorematrix.php` updated to check
  `(er.id IS NULL OR er.state = 'needsgrading')` via LEFT JOIN on `learnguard_responses` —
  no longer depends on `learnguard_essay_grades` at all. Score is hidden if any assigned essay
  slot either has no response row (blank) or has `state='needsgrading'`.
- `report_pdf.php`: added `'graded'` to the valid "answered" state list so graded essays render
  with green badge in attempt detail PDF.

## v2.0.7 — decimal grades + scores.php live essay guard (2026-06-20)

**Decimal grade input fix (`grader.php`)**:
- Mark input `step` attribute changed from `step="0.5"` to `step="any"` so graders can
  enter any decimal value (e.g. 2.5, 3.3, 4.75). The DB column (`learnguard_essay_grades.grade`)
  is already `DECIMAL(5)` and the server uses `PARAM_FLOAT`, so no other changes needed.

**scores.php live essay-pending guard ("once and for all" fix)**:
- Main SELECT query now embeds the same CASE WHEN logic as `scorematrix.php`:
  if any assigned essay slot has no grade in `learnguard_essay_grades`, `sumgrades` is
  returned as NULL for that row — regardless of what is cached in the `learnguard_attempts`
  table. This is a live DB check on every page load, bypassing auto-recalc and cached values.
- Three-tier assignment detection (same as scorematrix.php): AQ records first, then
  `learnguard_responses` fallback for legacy attempts, then conservative NULL when the attempt
  has no assignment evidence at all (blank-essay submissions).
- `sort=score` ORDER BY changed to use the aliased `sumgrades` column so sorting respects
  the live NULL values, not the stale stored value.
- Raw essay suffix `(N raw)` now suppressed when score is pending (null) — avoids showing
  partial grade totals alongside a "—" score cell.

## v2.0.6 — grader panel fix + score matrix essay guard + fullscreen fix (2026-06-20)

**Score matrix / essay pending scores (additional fix)**:
- `scorematrix.php` CASE WHEN updated to use AQ + responses fallback + conservative guard:
  for legacy attempts (no `learnguard_attempt_questions` records), the query now also checks
  `learnguard_responses` for essay slot rows, and — if neither exists — conservatively returns
  NULL when no essay grades are found (covers blank-essay submissions in non-random activities).
- `question_engine::has_ungraded_essays()` updated with the same three-tier logic: AQ records
  first, then responses fallback, then conservative null-check when the attempt truly has no
  assignment evidence but the activity has essay slots.

**Grader panel UI fixes**:
- Panel `position` changed from `sticky` to `fixed` so it is always visible at the top of
  the viewport when open, regardless of page scroll position. `body.overflow = 'hidden'`
  is set on open and cleared on close so background content does not scroll while grading.
- Fullscreen toggle simplified: removed `.then().catch()` chains (some browsers return
  `undefined` from `requestFullscreen`/`exitFullscreen`, causing silent errors). Now calls
  `exitFullscreen`/`requestFullscreen` directly with webkit vendor-prefix fallbacks; button
  text updated only via the `fullscreenchange`/`webkitfullscreenchange` events.
- Light mode: removed redundant inline `style="background:#495057"` from fullscreen button
  (it matched the default `.edg-hq-pill` background, but blocked light-mode CSS override).
  Added `id="edg-history-btn"` to History button and CSS rule
  `.edg-wrap.edg-light #edg-history-btn { color:#fff; }` so text stays readable on dark
  background in light mode.

## v2.0.5 — grader overhaul + essay pending scores + dismiss fixes (2026-06-20)

**Essay pending scores fix**:
- `attempt_manager::finish()` now checks for essay slots in the activity. If any exist,
  `sumgrades` is left as `null` and no gradebook push is made — score stays hidden until
  grading is complete.
- New `question_engine::has_ungraded_essays(attemptid, learnguardid)` — returns true when
  at least one assigned essay slot has no grade row in `learnguard_essay_grades`.
- `grader.php` `save_grade`: only updates `sumgrades` + pushes to gradebook after all essays
  for the attempt are graded.
- `grader.php` auto-recalc on page load and `recalc_all` AJAX action: skip/clear
  (`sumgrades = null`) for attempts still awaiting essay grading.
- `scores.php` `recalcall` AJAX and auto-recalc on page load: same guard applied.

**Grader fixes**:
- Removed `na` filter — all essay questions assigned to the student are now visible to the
  grader, including blank/unanswered ones (shown as "No response submitted"). Grader can
  award 0 for blank questions.
- Removed maxmark inference code (`grade/fraction` reconstruction). Now uses actual
  `slot->maxmark` from the DB directly, fixing `/7 instead of /6` bug when admin changes maxmark.
- Removed `window.scrollTo({top:0})` on question navigation — page no longer scrolls to top
  when the grader switches questions or submits a grade.
- Panel height changed from `max-height:70vh` to `height:calc(100vh - 140px)` with
  `overflow:hidden` on the grid container, so `.edg-qa-panel` and `.edg-cm-panel` children
  with `overflow-y:auto` now scroll properly — full student response is readable.
- Added fullscreen toggle button (⛶) in the grading panel header —
  `edgToggleFullscreen()` calls `requestFullscreen()` on the panel element.
- Added server-side grade cap in `save_grade`: returns `{ok:false}` if `$grade > $maxmark`.
- Added client-side grade cap in `edgSaveGrade()`: alerts if entered mark exceeds `max` attr.

**attemptdetail.php dismiss fixes**:
- Changed `$DB->update_record()` to three `$DB->set_field()` calls for `risk_score`,
  `risk_level`, `timemodified` — prevents accidental side effects on other columns.
- Added `bulk_dismiss_events` POST handler: dismisses multiple events in one submit,
  deducts total `score_impact` from risk score.
- Replaced event table with a bulk-dismiss form: checkboxes per dismissible row,
  "Select All Dismissible" checkbox, "✕ Dismiss Selected" button.
- Single-dismiss "✕ Dismiss" now uses JS + hidden form to avoid nesting forms.

**Head Down / No Blink admin settings**:
- Added to `settings.php`: `score_head_down` (default 5), `score_no_blink` (default 10),
  `head_down_threshold` (default 80px), `head_down_consecutive` (default 5 checks = 40s),
  `no_blink_period` (default 300s = 5min), `no_blink_ear_drop` (default 0.08).
- `view.php` wires all 6 into `$spaconfig`.
- `assessment.js` reads `cfg.head_down_threshold`, `cfg.head_down_consecutive`,
  `cfg.scoreconfig.headdown`, `cfg.no_blink_ear_drop`, `cfg.no_blink_period`,
  `cfg.scoreconfig.noblink` instead of hardcoded values.

## v2.0.0 — grader/score fix + site-wide bulk scheduling (2026-06-19)

**Score calculation fix (`calculate_total_grade()` in `classes/question_engine.php`)**:
- Denominator = ALL questions this student was assigned (from `learnguard_attempt_questions`),
  including blank/unanswered submissions — graders grade blank essays as 0/maxmark, so every
  assigned question must reduce the score if unanswered.
- Each slot's `maxmark` is fetched from `learnguard_slots` individually — different maxmarks
  per question are handled correctly. Deleted slot IDs (old questions removed by admin) fall
  back to `1.0` so orphaned responses are still included.
- Legacy attempts (no AQ records, pre-date AQ tracking) fall back to all response rows.
- Formula: `round((totalraw / totalmax) * instance->grade, 5)`
- Fixes scores showing 8.2 instead of expected 4/6 × 35 = 23.33.

**Grader.php fixes**:
- Removed 3rd fallback to all current essay slots when AQ JOIN returns nothing.
  Only slots the student was actually assigned (via AQ records or response rows) are shown.
- NA filter: pills with status `na` (blank, no response) are still filtered from the pill bar
  display, but blank submissions ARE counted in the score denominator via `calculate_total_grade()`.
- Per-attempt max grade: denominator in the grader grade display uses sum of maxmarks for
  answered/graded slots only (from `perAttemptMaxGrade`). Fixes "4.00 / 102.00" → "4.00 / 6.00".
- "🔄 Recalculate All Scores" button (manager-only) in stats bar → AJAX `recalc_all` action
  updates `learnguard_attempts.sumgrades` and gradebook for every finished attempt.

**Scores.php additions**:
- "🔄 Recalculate All Scores" AJAX button for managers — same recalculate logic as grader.php,
  accessible from the scores page.
- Raw essay grade shown in parenthesis: `23.33 / 35.00 (4 raw)` — fetched via batch query
  on `learnguard_essay_grades`. MCQ-only activities show no raw suffix.
- Risk badge displayed as a styled `<span>` not a button-like element.

**Site-wide Bulk Scheduling (`bulkschedule.php`)**:
- New site-admin page (`moodle/site:config`) accessible from `siteadmin.php` quick-link toolbar.
- Step 1: Activity selector — all LearnGuard activities grouped by course, with
  "Select All" per course and globally. Supports selecting activities across different courses.
- Step 2: Learner table — all users enrolled in any LearnGuard course, with live JS name/email/
  username search filter and "Select All Visible" bulk action.
- Step 3: Date/time window (Opens + Closes) — leave either blank for no restriction.
- Submit: upserts `learnguard_schedules` rows for each learner × activity pair;
  silently skips learners not enrolled in a specific activity.
- Per-activity (within-course) bulk scheduling still available via the Schedule tab on each
  activity's admin page (`schedule.php`).

## v1.7.1 — live-testing round 4 batch 2 (2026-06-16)
- **R7b** — Post-reinstate suspension threshold: new admin setting `reinstate_threshold_delta`
  (default 20). After reinstatement the learner's new threshold = risk_score + delta. JS
  uses `state.reinstateSuspendThreshold` instead of `cfg.autosuspend_score` when non-zero.
- **R7c/R7d** — Redo precheck before resuming: on reinstatement, "Resume" button triggers
  `runPreExamWizard()` with `state.reinstatementMode=true`; `wiz_complete()` calls
  `beginResumedAssessment()` instead of `launchAssessment()`. `initProctoring()` gains
  `lgProctoringInited` guard to prevent duplicate intervals. Reinstatement message shows
  exact new threshold to the learner.
- **R11** — Email proctor on Chat/Break/SOS: `send_chat()` calls
  `learnguard_notify_proctors_event()` on student messages; `request_break()` does the same;
  `tech_difficulty` added to the auto-notify event list in `log_event()`.
- **R14** — Screenshots work without screen sharing: `startSnapshotTimer()` also called on
  webcam denial; `captureAndUploadSnapshot()` falls back to screen stream when webcam absent;
  `initPeriodicScreenCapture()` no longer exits early; `initProctoring()` calls
  `startSnapshotTimer()` when `cfg.enablewebcam` is false.

## v1.7.0 — live-testing round 4 batch 1 (2026-06-16)
- **R8** — Face/head-pose detection race condition fixed: `initFaceMonitoring()` and
  `initHeadPoseMonitoring()` no longer look up `#lg-webcam-video` at init time (before
  `initWebcam()` creates it); element now looked up fresh inside each `setInterval` callback.
- **R9** — Escape and Tab key blocks now logged: Escape logs `key_violation` (low, 3 pts)
  and shows a toast; Tab logs silently (low, 2 pts). Alt on solo keyup also logs.
- **R10** — Right toolbar (noise meter, tools) gains `max-height:calc(100vh - 130px)` and
  `overflow-y:auto` so it no longer overlaps the bottom navigation bar.
- **R12** — Assessment name shown in header instead of brand name.
- **R13** — Resumed attempts no longer show ∞ timer: `beginResumedAssessment()` copies
  `cfg.timelimit` and `data.timestart` into state; `get_attempt_state` now returns `timestart`.

## v1.6.9 / v1.6.6 — live-testing feedback round 3, N1-N20 (2026-06-15)
A third round of live-testing feedback (20 items, N1-N20), worked through in
9 passes across both plugins. Commits: `cc39a66` (Pass1) → `e141b1a` (Pass2)
→ `8e1582d` (Pass3) → `fa233d5` (LearnShield exam-nav fix) → `60ad2b8`
(Pass4) → `b8ec89b` (Pass5) → `9adf031` (Pass6) → `4a6e85f` (Pass7) →
`5ad1a0d` (Pass8).

**Pass1 (N9, N10, N11, N17)**:
- N9 — risk-score accumulation is now frozen while an attempt/session is
  suspended (`attempt_manager::log_event()` / `suspicion_scorer::add_score()`
  in both plugins), resuming only once reinstated.
- N10 — new admin-configurable **Reinstatement Penalty**
  (`score_reinstatement`, default 10) is applied via the proctor's
  reinstate/bulk-reinstate actions in both plugins.
- N11 — fixed LearnGuard learners getting immediately re-suspended on
  resume: auto-suspend now only fires on the crossing into the threshold,
  not on every subsequent event while already at/above it; the fullscreen
  blocker overlay is now hidden while the suspended/resume screen is shown.
- N17 — the existing `admin.php` Settings/Brand tabs are now surfaced as
  direct quick-link buttons on the activity view page, where managers
  actually land.

**Pass2 — audio precheck step (both plugins)**:
- Added a "Retry Microphone" button when mic access is denied on the audio
  precheck step, mirroring the existing camera retry button.
- Fixed a Back/Continue bug where the audio step's background-noise check
  interval/timeout/AudioContext could leak across wizard steps and later
  incorrectly toggle Continue on a different step — added a per-step
  cleanup hook at the top of each render.
- Added a "scroll down" hint banner at the **top** of every scroll-gated
  wizard step (welcome, close tabs, privacy, ready/rules), in addition to
  the existing bottom-of-content hint.

**Pass3 (N6, N7, N8)**:
- N6 — photo-ID face-match precheck now uses a **50% threshold** (was 70%)
  in both plugins, with the exact invigilator-approval wording shown on the
  3rd failed attempt and on the no-face-api fallback.
- N7 — the privacy notice text is now an admin-configurable setting
  (`privacy_notice` in both plugins, pre-filled with the existing default),
  threaded through to the wizard with HTML escaping.
- N8 — the privacy step's scroll box was enlarged to 360px and top
  whitespace reduced in both plugins.

**LearnShield exam-navigation fix (`fa233d5`)**: every native `mod_quiz` page
navigation (Previous/Next page, Finish attempt, question-number nav panel) is
a full page reload, which fired LearnShield's `beforeunload` handler — showing
a "Leaving this page will be logged" confirm dialog and logging a
`page_unload` violation on every question-to-question navigation. Now detects
clicks on Moodle's own quiz navigation (`#mod_quiz_navblock`, `.submitbtns`,
`.mod_quiz-next-nav`/`-prev-nav`) and skips the confirm dialog and
`page_unload`/`tab_switch` logging for that navigation, while still catching
genuine tab abandonment. LearnGuard is a client-side SPA that never reloads
for question navigation — confirmed N/A.

**Pass4 (N1, N2, N3 + suspended-attempt visibility/reinstate)**:
- N1/N2 — LearnGuard `storage.php`: fixed totals/pagination/select-all
  mismatch.
- N3 — LearnGuard `proctordashboard.php`: fixed the "High Risk" tile
  undercount.
- LearnGuard `report.php` now shows suspended attempts (5th stat card, row
  highlight, badge, Reinstate button); `view.php` includes the `'suspended'`
  state when looking up an existing attempt, so a suspended learner
  reloading the page is routed back to the suspended screen instead of
  risking a duplicate attempt; `assessment.js`'s `beginResumedAssessment()`
  now checks suspension status before initialising proctoring/UI.
- LearnShield `proctoring.js` now checks session status on `setup()` and
  shows the suspended overlay immediately on reload if still suspended
  (previously only via the per-page-load auto-suspend check); `attempts.php`
  gained a Suspended Sessions stat card, Status column, row highlight/badge
  and a Reinstate action.

**Pass5 (N12, N18, N19)**:
- N12 — new essay **"Marking Guide"** field: `learnguard_qbank` gains
  `markingguidance`/`markingguidanceformat` columns (`install.xml` +
  `db/upgrade.php` savepoint `2026053107`); `questionedit.php` has a new
  Marking Guide textarea on the essay form (graders only, not shown to
  students); `grader.php` and `gradeessays.php` render it in a yellow
  "Marking Guide" box above each essay response. LearnShield: N/A, no custom
  question bank.
- N18 — Settings quick link: already satisfied by the N17 fix (Settings/Brand
  buttons on `view.php`'s manager bar) — no further change needed.
- N19 — fixed the unreachable "Manage Graders" page: `view.php` gained a
  manager-only "Manage Graders" button linking to `admin.php?tab=graders`;
  `siteadmin.php`'s Assessments tab gained a per-row "Graders" link to the
  same page. LearnShield: N/A, no grading-distribution equivalent (per
  v1.6.7 item 9 precedent).

**Pass6 (N15, N16)**:
- N15 — LearnShield `proctorview.php` had no navigation to other LearnShield
  admin pages and was slow to open because its "My Quizzes" query joined the
  entire `quizaccess_learnshield_sessions` table with a `GROUP BY` just to
  find which quizzes have sessions. Replaced with a `WHERE EXISTS` subquery
  (same result, indexed existence check instead of full join+dedupe), and
  added the standard `ls_nav_bar()` strip after the page header. LearnGuard's
  `proctorview.php` was missing the equivalent `learnguard_nav_bar()` strip
  too — added for parity.
- N16 — `siteadmin.php?tab=assessments` ran 3 correlated `COUNT` subqueries
  (total/active/high-risk attempts) per assessment row; replaced with 3
  grouped aggregate queries computed once and looked up per row.
  `grader.php`'s attempt list had the same pattern (a correlated per-attempt
  subquery counting this grader's existing grades) — replaced with a single
  `LEFT JOIN` to a grouped subquery. `admin.php?id=874`'s overview tab only
  runs simple indexed `count_records()` calls — no change needed.
  `course/modedit.php` is Moodle core — out of plugin scope. LearnShield's
  equivalent `admin.php` quiz listing is course-scoped (a handful of quizzes
  per course), so the same correlated-subquery pattern isn't the "too long to
  load" issue LearnGuard's site-wide listing was — confirmed N/A.

**Pass7 (N14) — email notifications on grader/proctor assignment**:
- LearnGuard `admin.php`: assigning a user as a proctor (Proctors tab) or
  grader (Graders tab) now emails them via `learnguard_send_email()`, telling
  them what they were assigned to and where to find the relevant admin page
  (Proctor Dashboard / Graders page). Both gated by the existing "Send Proctor
  Notifications" (`learnguard/notify_proctors_enabled`) setting — its
  description now mentions this new use.
- LearnShield `admin.php`: assigning a user as a proctor/teacher now emails
  them via a new `email_notifier::notify_proctor_assigned()`, gated by a new
  "Notify a user when assigned as proctor"
  (`quizaccess_learnshield/notify_proctor_assigned`, default on) setting
  alongside the other Email Notifications settings. LearnShield has no
  grader/grading concept, so only the proctor-assignment notification applies
  there.

**Pass8 (N13) — site-wide cross-course Score Matrix**: new `scorematrix.php`
in both plugins (site-admin-only, `moodle/site:config`): one row per student,
one column per assessment/quiz (optionally filtered to a single course),
Total and Average columns, CSV export. LearnGuard's cells show the latest
finished attempt's `sumgrades` (single grouped-aggregate query, no N+1);
LearnShield's cells use Moodle's native `{quiz_grades}` table (naturally
blank-until-graded). Wired into LearnGuard's `siteadmin.php` quick links and
`overview.php`'s Reports & Grading card; LearnShield's
`navhelper.php::ls_nav_bar()` gained a `'scorematrix'` entry (visible on every
LearnShield admin page), plus quick links from `admin.php`'s toolbar and
`overview.php`'s Reports & Grading card.

**Pass9 (N20) — final QA**: all 27 changed PHP files re-linted (`php -l`) and
`install.xml` re-validated as XML; all 4 changed AMD JS files re-checked
(`node --check`); both ZIPs rebuilt; versions bumped (see above).

## v1.6.8 / v1.6.5 — version-number mismatch fix (2026-06-15)
Installing the rebuilt LearnShield ZIP failed validation with "A higher
version of this plugin is already installed [2026053105]" — Michael's site
had `quizaccess_learnshield` recorded at version `2026053105` from a prior
out-of-band build, which is higher than every savepoint in this repo's
`db/upgrade.php` (which topped out at `2025052034`). That meant **all**
upgrade steps — including the brand-new `createdby` column on
`quizaccess_learnshield_incidents` from the v1.6.4 round — would have been
silently skipped on upgrade (`$oldversion >= $savepoint` for every step),
leaving `incidentreview.php` querying a column that doesn't exist.

Fix (both plugins, since the same out-of-band build may have been deployed
for LearnGuard too):
- Added a new catch-up savepoint `2026053106` to both `db/upgrade.php`
  files. LearnShield's re-applies the `createdby` field-exists check
  (idempotent). LearnGuard's re-applies the four most recent steps
  (`2025052021` learnguard_schedules, `2025052026` snapshot_type mangled-value
  fix, `2025052028` learnguard_brand_overrides, `2025052029`
  learnguard_grading_config/learnguard_grader_assignments) — all already
  idempotent via `table_exists`/`field_exists`/`set_field` guards, so they're
  no-ops if already applied and create-on-demand if not.
- Bumped both `version.php` files to `2026053106` (LearnGuard release
  `1.6.8`, LearnShield release `1.6.5`) — both now safely above the
  `2026053105` recorded on Michael's site.

## v1.6.7 / v1.6.4 — 12-item punch list + 6 suggested improvements (2026-06-15)
Closes out the full 12-item punch list (items 1-11; item 12 was already
covered by the v1.6.5/v1.6.2 and v1.6.6/v1.6.3 rounds) plus all 6 follow-up
suggestions raised during this round, across both plugins.

**Item 1 — LearnGuard admin sub-menu grouping**: `settings.php` now registers
LearnGuard's admin pages under their own "LearnGuard" sub-category in Site
Administration → Plugins, instead of the flat "Activity modules" list.
LearnShield's `settings.php` was already organised this way — no change
needed.

**Item 2 — post-photo-ID "face detection unavailable" fallback text**:
LearnGuard's `amd/src/assessment.js` `wiz_face()` fallback message ("⚠️ Auto
face detection unavailable. Tick the confirmation box below to request
invigilator review.") was reworded to "Your photo has been uploaded and will
be reviewed by your invigilator. Tick the confirmation box below to proceed."
to match LearnShield's existing wording. LearnShield confirmed already
correct, no change needed.

**Item 3 — camera-blocked / no-face-in-frame during exam**: both plugins'
1-second face-presence check (`assessment.js` / `proctoring.js`) silently
returned with no event whenever `video.readyState < 2` (webcam stream
blocked, denied, or disconnected) after the one-time `webcam_denied` event at
exam start — a student could cover/disable their camera for the rest of the
exam with zero further detections. Both now treat `readyState < 2` the same
as "no face in frame": every 30s they log a `face_missing` event
(`reason: 'camera_unavailable'`) and show a toast asking the student to
enable their webcam.

**Item 4 — "final suspension" warning on reinstate**: both plugins'
suspended/resume overlay (`showSuspendedScreen()` in LearnGuard's
`assessment.js`, the equivalent block in LearnShield's `proctoring.js`) now
shows a one-time red warning banner — "⚠️ Warning: any further violation will
result in final suspension." — alongside the "Your suspension has been
lifted!" message and Resume button.

**Item 5 — resume-to-question (LearnGuard)**:
`attempt_manager::process_answer()` set `learnguard_attempts.currentpage` to
the slot the student was LEAVING, not the slot they were navigating TO — so
resuming after a suspension/disconnect could land the student one question
behind their actual position. `submit_answer` (`classes/external.php`) now
accepts a `targetslot` param (sent by `assessment.js` on every
Next/Prev/jump-to-question), and `process_answer()` stores that as
`currentpage`, falling back to the sequential "next slot" when no explicit
target is given. LearnShield confirmed N/A — native `mod_quiz` already
tracks/resumes the current page correctly.

**Item 6 — repeating noise/audio-clip violation scoring (both plugins)**:
- The audio-clip recording cooldown was 2 minutes on both plugins, so only
  the first sustained-noise crossing in any 2-minute window was ever scored.
  Reduced to 10s (just enough to avoid overlapping the recording itself), so
  every sustained-noise crossing gets its own scored event.
- LearnShield's `suspicion_scorer::get_score_for_event()` had no entry for
  `audio_clip_started` — it always scored 0. Added a new admin-configurable
  `score_audio_clip` setting (default 5, with `setting:score_audio_clip` /
  `_desc` lang strings), matching LearnGuard's existing (but previously dead)
  `score_audio_clip` setting.
- LearnGuard's `score_audio_clip` setting existed in `settings.php` but was
  never read by `view.php`/`assessment.js` — wired into
  `view.php`'s `$spaconfig['scoreconfig']['audioclip']` and used by
  `logProctoringEvent('audio_clip_started', ...)`.

**Item 7 — bigger, colour-coded schedule countdown clocks**: the "opens
in"/"closes in" countdown digits on the pre-attempt view page were small,
plain text. Both plugins' countdown widgets (`assessment.js`'s
`lg-open-countdown-clock`/`lg-close-countdown-clock`, LearnShield's
`quizaccess_learnshield_countdown_widget()` in `lib.php`) now render the live
digits as large (22-28px), bold, tabular-figure, colour-coded text (blue for
"opens in", orange for "closes in") on their own line below the date.

**Item 8 — admin.php "Settings" tab + Suggestion 6 "Brand" tab (LearnGuard)**:
the "Settings" tab on `admin.php` now redirects to the activity's native
Moodle settings form (`course/modedit.php`). New "Brand" tab lets each
LearnGuard activity override the global brand name/primary/accent/text
colours (`learnguard/brand_*` settings) per-activity, via a new
`learnguard_brand_overrides` table (install.xml + upgrade step `2025052028`),
applied in `view.php` on top of the global settings (global wins when an
override field is empty). LearnShield has no per-activity branding concept —
confirmed N/A.

**Item 9 — grader distribution/randomisation (LearnGuard)**: new
admin-configurable distribution modes (`all` / `split` / `random` / `manual`)
for the existing Layer-1 anonymous-grading workflow, so multiple Layer-1
graders can split up finished essay attempts. New tables
`learnguard_grading_config` (per-activity mode + cap) and
`learnguard_grader_assignments` (attempt → grader map), upgrade step
`2025052029`. `lib.php`'s new `learnguard_distribute_grading()` round-robins
(split) or randomly samples up to a cap per grader (random). New "Grading
Distribution" section on the Graders tab of `admin.php`. `grader.php` filters
a non-manager Layer-1 grader's attempt list to only their assigned attempts
when distribution is active; Layer-2 graders and managers always see every
finished attempt. LearnShield confirmed N/A — no question bank/essay grading
equivalent to distribute.

**Item 10 — Live Monitor "No active sessions" (LearnGuard)**:
`mod_learnguard_get_live_sessions()` used a 2-minute `timemodified` cutoff,
but the client only sends a `session_heartbeat` every 60s — sessions would
flicker out of the live grid between heartbeats even while active. Widened to
10 minutes (600s), matching LearnShield's
`quizaccess_learnshield_get_live_sessions()` (already 600s/60s-heartbeat, no
reported issue). LearnShield confirmed already correct, no change needed.

**Item 11 — storage.php DB error (LearnGuard)**: `storage.php` called
`learnguard_activity_tabs(0, '')`, which queried a non-existent activity
instance (id 0) and threw `dml_missing_record_exception`. Replaced with
`learnguard_nav_bar(0, 'Storage Management')`, the nav helper used by other
admin-only pages. LearnShield's equivalent already used the correct helper —
no change needed.

---

**Suggestion 1 — Proctor incident notes (both plugins)**:
- LearnShield's `incident_manager` gained a `createdby` column (upgrade step
  `2025052034`; 0 = auto-generated) so the incident feed shows "Reported by:
  🤖 Auto-detected" vs. a named proctor. `incidentreview.php` gained a "➕
  Create New Incident" form (student picker, severity, title, description) —
  porting LearnGuard's existing equivalent — and now shows "Reported by" on
  every incident card. `proctordashboard.php` gained a "⚠️ Incidents"
  quick-link button next to "🛡 Proctor View".
- LearnGuard's `incidents.php` had a pre-existing bug: its incident feed used
  `INNER JOIN {user} p ON p.id = i.proctoriid`, which silently hid every
  auto-generated incident (`proctoriid = 0`, no matching user row). Changed to
  `LEFT JOIN`; the reporter line now shows "🤖 Auto-detected" when
  `proctoriid = 0`.
- **Suggestion 4 (LearnGuard email alerts on high risk)** was folded into this
  fix: `attempt_manager::log_event()`'s risk-score recalculation now detects
  the first crossing into `'high'` risk
  (`$level === 'high' && $attempt->risk_level !== 'high'`) and (a)
  auto-creates a `learnguard_incidents` row (`severity='high'`,
  `proctoriid=0`) — parity with LearnShield's
  `incident_manager::auto_create_incident()` — and (b) emails assigned
  proctors via `learnguard_notify_proctors_event()` when
  `notify_proctors_enabled` is on — parity with LearnShield's
  `email_notifier::notify_high_risk()`. LearnShield confirmed already correct
  on both counts.
- `fix_db.php`'s `lg_ensure_table('learnguard_incidents', ...)` definition had
  the same stale schema the old `install.xml` had before the v1.6.2 fix.
  Corrected to match the current `install.xml`/`upgrade.php` (step
  `2025051821`) schema — a no-op on real sites (table already exists), but now
  correct if ever run against a fresh/broken DB.

**Suggestion 2 — Scheduled storage cleanup retention settings (LearnGuard)**:
LearnGuard's daily "GDPR data retention cleanup" task
(`classes/task/cleanup_proctoring_data.php`, already registered in
`db/tasks.php`) reads `retention_snapshots` / `retention_360` /
`retention_events` / `retention_messages` from config, but `settings.php` had
no admin UI for any of them — permanently stuck at hardcoded defaults
(14/7/180/90 days). Added a new "💾 Retention & Storage Cleanup" settings
section with all 4 as admin-configurable fields, matching LearnShield's
existing "Retention & Storage Settings" section. *Observed, not fixed*:
LearnGuard's cleanup task has an unused `$chatRetain` variable reading a
non-existent `retention_chat` config (left as-is); LearnShield's
`settings.php` similarly declares `retention_event_clips` /
`retention_flagged` / `retention_environment` settings not consumed by
`retention_manager.php` (only `retention_snapshots`/`retention_logs` are) —
left as-is, noted for a future cleanup pass.

**Suggestion 3 — Risk-score trend sparkline (both plugins)**: both plugins'
`attemptdetail.php` now render a small inline SVG sparkline ("📈 Risk Score
Trend") showing cumulative risk score over time, built from each
attempt's/session's per-event `score_impact` + `timemodified` history,
anchored at 0 (start) and ending at the final risk score (finish time or now).
Dashed reference lines mark the medium (30) and high (60) risk thresholds.
Pure inline `<svg>`/`<polyline>` — no external chart library, per the standing
"no CDN" decision.

**Suggestion 5 — Bulk actions on suspended learners**: LearnShield's
`proctorview.php` Suspended tab already had full bulk
reinstate/confirm-suspension UI (checkboxes, select-all, "✅ Reinstate
Selected" / "🚫 Confirm Suspension", `is_flagged` surfaced as a "Flagged"
badge in `attempts.php`) — confirmed already correct, no change needed.
LearnGuard's Suspended tab only had a per-row "▶ Reinstate" button (cross-page
POST to `admin.php`). Ported LearnShield's pattern into LearnGuard's
`proctorview.php`: checkbox column + header select-all, "☑ Select All" / "✅
Reinstate Selected" / "🚫 Confirm Suspension" toolbar buttons, an in-page
`#sus-bulk-form` + `susBulkAction()` JS handler, and a server-side bulk
handler (`UPDATE learnguard_attempts SET state='inprogress'` for reinstate,
`SET is_flagged=1` for confirm — `learnguard_attempts.is_flagged` already
existed in the schema but was previously unused/unsurfaced). The per-row
"Reinstate" button now uses the same in-page handler. Added a "🚩 Flagged"
badge next to the risk-score badge in `results.php` (and a "Flagged" Yes/No
column in its CSV export) so confirmed suspensions are visible — parity with
LearnShield's `attempts.php`.

## v1.6.6 / v1.6.3 — live-testing feedback batch, round 2 (2026-06-14)
Follow-up fixes from the same live-testing feedback batch as v1.6.5/v1.6.2,
covering items flagged as "still not done" after that round:

- **Radio/checkbox option alignment** — In LearnGuard's
  `classes/question_engine.php`, `render_multichoice()` and
  `render_multicheck()` used `align-items:flex-start` on the option
  `<label>` flex containers, so the letter-badge/checkbox sat at the top of
  the option box instead of aligned with the answer text (especially when
  `format_text()` wraps the answer in `<p>` tags with margins, or the text
  wraps to multiple lines). Changed both to `align-items:center` and removed
  the now-redundant `margin-top:2px` on the multicheck checkbox input.
  LearnShield confirmed N/A — it has no custom MCQ renderer; question
  rendering is Moodle's native `qtype_multichoice`.

- **Suspend/resume: "Screen Sharing Stopped" overlay blocked the resume
  screen** — In LearnGuard, `showScreenSharePausedOverlay()` appends a
  full-viewport `#lg-ss-paused` overlay (`position:fixed`, z-index 99998)
  directly to `document.body`, outside `#lg-spa-container`. When a learner
  was then suspended, `showSuspendedScreen()` replaced
  `#lg-spa-container`'s contents with the "Attempt Suspended" /
  "▶ Return to Fullscreen & Resume Exam" UI, but the red screen-share-stopped
  overlay remained on top of it (still attached to `document.body`), hiding
  the resume button — matching the reported "still unable to continue after
  unsuspending" symptom. Fixed by removing `#lg-ss-paused` (if present) at
  the top of `showSuspendedScreen()` in `amd/src/assessment.js`. LearnShield
  confirmed N/A — it has no equivalent full-viewport "screen sharing
  stopped" overlay; its `screen_share_stopped` handler only logs the event.

- **360° environment recording upload "speed"** — Re-checked the recording
  → upload flow in both plugins (`wiz_env()` in LearnGuard's
  `assessment.js`, `runEnvironmentRecording()` in LearnShield's
  `preexamwizard.js`). Both already cap the recording at 15s / 200kbps
  (~0.5MB base64 payload) and already have a 12-second "taking too long"
  fallback that unblocks Continue with a retry option. The actual "stuck on
  Uploading…" symptom that prompted this item was the precheck-time
  `attemptid`-dependency bug already fixed for LearnGuard earlier in this
  batch (the `pending360Video` deferred-upload fix, v1.6.5) — LearnShield's
  `quizaccess_learnshield_send_camshot` path never depended on `attemptid`
  and was already correct. No further code change identified without either
  reducing recording quality/duration or restructuring storage — both out of
  scope for a "fix exactly what is asked" pass.

- **Live monitor "Flagged"/"Active" counter mismatch (LearnShield)** —
  `classes/session_manager.php`'s `get_active_sessions()` (used for the PHP
  page-load stat tiles on `proctordashboard.php`) had no staleness filter,
  while `classes/external.php`'s `get_live_sessions()` (the JS poll) only
  counts sessions with `timemodified >= now - 600`. A stale "active" session
  (e.g. an abandoned tab) inflated the initial server-rendered counts/tiles
  until the first JS refresh corrected them. Added the same
  `AND s.timemodified >= :activesince` (now − 600) filter to
  `get_active_sessions()` so the initial counts match the first poll.
  LearnGuard confirmed N/A — its dashboard tiles start as JS-populated "0"
  placeholders with no server-rendered initial counts to mismatch.

- **Object-detection scoring gap (LearnShield)** — LearnGuard's client-side
  `OBJECT_VIOLATIONS` map awards risk-score points for `book`(10),
  `laptop`(20), `keyboard`(10) and `remote`(5) detections. LearnShield's
  server-side `classes/suspicion_scorer.php::get_score_for_event()` had no
  entries for `object_detected_book` / `object_detected_laptop` /
  `object_detected_keyboard` / `object_detected_remote`, so these detections
  were logged/toasted to the learner but contributed **zero** to the risk
  score — a parity gap. Added the 4 missing entries with the same hardcoded
  point values as LearnGuard (not admin-configurable, matching LearnGuard's
  hardcoding for these object types). `multiple_persons_detected`,
  `object_detected_cell_phone`, `multiple_faces_detected` and
  `face_identity_mismatch` were already correctly mapped on both sides.

- **Investigated, no code change — admin/search.php → overview.php
  click-through**: LearnGuard's `settings.php` registers
  `learnguard_overview` (and the other admin externalpages) inside
  `if ($ADMIN->fulltree) { ... }` — the correct, standard convention for
  `mod_*` plugin settings files. LearnShield's `settings.php` uses
  `if ($hassiteconfig) { ... }` — the correct convention for `quizaccess_*`
  plugin settings files. Both conditions are true when Moodle builds
  `admin/search.php` and the Site Administration tree for an admin user, so
  these pages should already be registered/clickable. If they still don't
  appear after this version bump (which triggers Moodle's upgrade routine
  and an admin-tree cache rebuild), try Site Administration → Development →
  Purge all caches; if it persists after that, this needs a screenshot/repro
  from Michael to pin down further.

- **Investigated, no code change — attempted/in-progress/completed attempts
  view**: LearnGuard's `results.php` already shows summary pills for all 4
  states (🟢 In Progress / ✅ Finished / 🚫 Suspended / ⏳ Not Started) with
  counts, a matching state filter, a state column per row, course/assessment
  filters, CSV export, and per-attempt detail/admin/live-dashboard links.
  LearnShield's equivalent is Moodle's native Quiz Grades "Overview" report
  (linked from `ls_nav_bar()`'s "🏆 Grades" item per the v1.6.1 "Option B"
  decision), which already shows each enrolled user's attempt state
  including "Not yet started" / "In progress" / "Finished". No gap found in
  either plugin.

## v1.6.5 / v1.6.2 — live-testing feedback batch (items 13-18) (2026-06-14)
Fixes from a round of live testing on learn.meristem.com.ng (feedback items
13-18 of an 18-item list; items 1-12 and 19 were completed/documented in
earlier sessions):

- **Item 13 — gallery webcam captures (blank images, only 2 shown)** and
  **item 14 — screen captures not saving to gallery**: LearnGuard's
  `send_snapshot()` validated `snapshot_type` with `PARAM_ALPHA`, which strips
  digits/underscores — so types like `screen_capture`, `photo_id`,
  `multiple_faces` were silently mangled to `screencapture`, `photoid`,
  `multiplefaces` before being stored, and the Gallery's type filter (which
  matches on the correct underscored values) never found them. Switched to
  `PARAM_ALPHANUMEXT`. Added `db/upgrade.php` step `2025052026` to repair
  already-mangled rows in `learnguard_snapshots.snapshot_type` via a
  mangled→correct value map. Also fixed `snapshot.php`: `NO_MOODLE_COOKIES`
  was set (so no Moodle session existed) while `confirm_sesskey()` was called
  with no argument *before* `require_login()` — reordered to
  `require_login()` → `confirm_sesskey($sesskey)`, and removed
  `NO_MOODLE_COOKIES` so the session is available. LearnShield's snapshot
  delivery path was checked and did not share this bug — no LearnShield change
  needed.

- **Item 15 — "X random questions chosen from a pool of Y…" text on the
  learner view**: this string existed only in a dead/unused `qInfo` variable
  in LearnGuard's `amd/src/assessment.js` `showInstructionsScreen()` — removed
  it (the `actualQ` it was based on is still used by the "❓ N Question(s)"
  info card). No LearnShield equivalent existed.

- **Item 16 — default question mark setting**: added a new global admin
  setting `learnguard/default_question_mark` (PARAM_FLOAT, default `1.0`,
  under "📋 Default Assessment Settings" in `settings.php`). Used as the
  prefill/fallback everywhere a hardcoded `1.0` mark previously appeared:
  `questionedit.php`'s new-question form, and CSV/GIFT/Aiken imports in
  `questionimport.php`. The per-question override already existed (the
  "Default Marks" field on `questionedit.php`, saved to
  `learnguard_qbank.defaultmark`) — also fixed `editquestions.php`'s single
  "Add to assessment" action, which hardcoded `maxmark => 1.0` instead of
  inheriting `$q->defaultmark` like the bulk add-multiple path already did.
  LearnShield has no question bank — confirmed N/A.

- **Item 17 — manual "add question to bank" UI**: confirmed this already
  exists (no code change) — `questionbank.php` and `editquestions.php?qtab=bank`
  both have "+ New Question" buttons → `questionedit.php` (all 6 question
  types). Also reachable via `siteadmin.php?tab=questions` →
  "+ Browse / Manage All Questions". LearnShield uses Moodle's native question
  bank UI — N/A.

- **Item 18 — reinstate flow: resume button not returning to assessment**:
  LearnGuard's `showSuspendedScreen()` (`amd/src/assessment.js`) was already
  correct — overlay + 20s poll of `mod_learnguard_get_attempt_state` +
  "▶ Return to Fullscreen & Resume Exam" button. LearnShield's
  `checkAutoSuspend()` (`amd/src/proctoring.js`) instead wiped
  `document.body.innerHTML` with a static "Exam Suspended" message and no way
  back. Replaced with `showSuspendedOverlay()`: a full-viewport overlay
  (`position:fixed;inset:0`, same pattern as the existing fullscreen blocker)
  with a "← Back to Course" link, that polls a new read-only WS
  `quizaccess_learnshield_get_session_status` (added to `classes/external.php`
  + `db/services.php`, returns `{status, suspended}` from
  `quizaccess_learnshield_sessions.status`) every 20 seconds; once the proctor
  reinstates the session (`proctorview.php` suspended tab sets
  `status='active'`), the overlay shows a "▶ Resume Exam" button that does
  `location.reload()`.

## v1.6.4 — LearnGuard now 100% local AI, no CDN fallback needed (2026-06-13)
LearnGuard's `view.php` already had a 3-tier fallback for `face-api.min.js`:
its own `thirdpartylibs/` copy → LearnShield's bundled `amd/build/face-api.min.js`
→ `cdn.jsdelivr.net`. LearnGuard had no copy of its own, so if installed
**standalone** (without LearnShield) it would silently load face-api.js from a
CDN — contradicting the "no CDN" standing architecture decision. Fixed by
copying `face-api.min.js` (663KB) into `_lg_work/learnguard/thirdpartylibs/`.
LearnShield already had its own bundled copy and needed no change — both
plugins are now fully self-contained with zero CDN/server dependency for all
AI detection (face-api.js, ONNX/YOLOv5n), confirmed via code-path audit of
`view.php`/`rule.php`.

**Also noted (not yet fixed)**: `_lg_work/learnguard/siteadmin_ai.php` is dead
code left over from the superseded `learnguard-ai/` Docker+Ollama
architecture — its `ai_ollama_*` settings aren't read anywhere else, but the
page is still linked from `overview.php`'s "🤖 AI Services" card and could
mislead Michael into thinking Docker is required.

## v1.6.3 / v1.6.1 — wizard + overview parity restored (2026-06-12)
The parity gap opened by LearnShield v1.5.4 → v1.5.9 (precheck wizard fixes
applied to LearnShield only) is now closed:

- **Scroll-to-top on each wizard step render** — ported to LearnGuard's
  `renderWizardStep()` (`_lg_work/learnguard/amd/src/assessment.js`): sets
  `body.scrollTop = 0` before rendering each step, matching LearnShield.
- **Closetabs checkbox locked until scrolled to bottom** — ported to
  LearnGuard's `wiz_closetabs_html()`/`wiz_closetabs()`: checkbox starts
  `disabled` with a "↓ Scroll down…" hint, unlocked once the wizard body is
  scrolled to the bottom (or immediately if the content doesn't need scrolling).
- **Photo-ID step UX** — ported to LearnGuard's `wiz_face_html()`/`wiz_face()`:
  the passport-photo file input starts `disabled` with a "Loading identity
  verification system…" message, a warning that background-removed/edited
  photos will fail detection, and the input is enabled (with a ready/fallback
  status message) once face-api finishes loading or fails.
- **`destroyMoodleModal()` focus-trap fix** — confirmed **N/A** for LearnGuard.
  It works around Moodle's Bootstrap quiz-attempt-start modal focus trap, which
  only exists for LearnShield's `startAttempt.js` (wizard renders on top of
  `mod_quiz`'s own modal). LearnGuard's wizard is a standalone SPA
  (`view.php` → `js_call_amd('mod_learnguard/assessment', ...)`) with no Moodle
  modal involved — no porting needed.
- **`completeWizard()` fetch()-POST + explicit `cmid` fix** — confirmed **N/A**
  for LearnGuard. That fix targets LearnShield's `mod_quiz` `startattempt.php`
  redirect flow. LearnGuard's `wiz_complete()` → `launchAssessment()` calls its
  own `mod_learnguard_start_attempt` WS with `cmid` already explicit — no
  redirect/form-POST involved.

**LearnShield Scores parity (Option B)** — `navhelper.php`'s `ls_nav_bar()`
gained a "🏆 Grades" nav item linking to Moodle's native Quiz Grades report
(`/mod/quiz/report.php?id={cmid}&mode=overview`), which already covers
sort/filter/export for quiz attempts. No new custom Scores page was needed.

**Both plugins' `overview.php` rewritten** into comprehensive card-based
feature/link grids — every admin page, proctoring feature, and security
feature is listed with either a direct link or a "📍 reached from…" note for
contextual (per-activity/per-quiz) pages. LearnShield's rewrite added a local
`lsOvCard()` helper (it has no shared `ls_banner()`/`ls_brand()` like
LearnGuard's `learnguard_brand()`/`learnguard_banner()`) and kept its existing
"Built-in Features" / "How to Enable" / "vs LearnGuard" cards as supplementary
reference content.

**`overview.php` is now the first admin page for both plugins** — registered
as the first `admin_externalpage` in `modsettingslearnguard` /
`modsettingsquizcat` (before all other plugin admin pages), so it's the first
item under Site Administration → Plugins → … for each plugin. LearnShield's
`settings.php` also gained a "Learn Shield Quick Links" heading (mirroring
LearnGuard's `learnguard_links_heading`) with "🗺 Plugin Overview" as the first
button.

## v1.6.2 bugfixes (pre-existing, found during QA for v1.6.1)
Fixed two critical pre-existing bugs in LearnGuard, both unrelated to the
v1.6.1 Scores page: (1) `db/install.xml` line 191 had
`NAME="timemodified"TYPE="int"` (missing space) — this made the whole file
invalid XML, breaking fresh installs; (2) `results.php` and
`export_scores.php` queried non-existent `a.score`/`a.maxscore` columns on
`{learnguard_attempts}` — `results.php` was fatal-erroring for every admin, and
all 3 export formats in `export_scores.php` were broken. Both now use
`a.sumgrades` + `$instance->grade`/`lg.grade AS maxgrade`, same pattern as
`scores.php`.

## Tech stack (LearnGuard only — LearnShield archived)
- Moodle 4.5+ (PHP 8.1+)
- LearnGuard: AMD modules via `core/ajax`, vanilla JS SPA inside a `mod_` activity
- face-api.js v0.22.2 — bundled in `thirdpartylibs/models/` (TinyFaceDetector, faceLandmark68Tiny, faceRecognitionNet)
- ONNX Runtime Web — bundled in `thirdpartylibs/onnx/ort.min.js`
- YOLOv5n — bundled in `thirdpartylibs/onnx/yolov5n.onnx`
- COCO-SSD (CDN fallback only when ONNX unavailable)
- All AI detection runs client-side in the student's browser — zero server load

## Standing architecture decisions
- No Docker required — all detection is browser-based (ONNX + face-api.js)
- No paid APIs or licence keys — everything is MIT/Apache open-source
- Whisper NOT bundled (74 MB, overkill — noise level monitoring is sufficient)
- MinIO NOT used — Moodle's own file storage handles snapshots and recordings
- Screenshots every 15 seconds (not 30)
- face-api uses TinyFaceDetector (not ssdMobilenetv1 — too large)

## Legacy / superseded folders (do not build on these without asking)
- `learnguard-ai/` — an earlier "Phase 4" Docker + FastAPI + Ollama AI-gateway
  architecture (see `learnguard-ai/ARCHITECTURE.md`). This contradicts the
  standing decision above (no Docker, fully client-side ONNX/face-api). Treat as
  superseded/legacy unless Michael explicitly asks to revive it.
- `extracted/proctoring/` — third-party reference plugin (BrainStation
  `quizaccess_proctoring`), kept for reference only, not part of the active build.

## Key PHP paths (LearnGuard)
- Models URL: `$CFG->wwwroot . '/mod/learnguard/thirdpartylibs/models'`
- ONNX URL: `$CFG->wwwroot . '/mod/learnguard/thirdpartylibs/onnx'`

## Proctoring features — LearnGuard (LearnShield archived at v1.6.6)
| Feature | Notes |
|---|---|
| Pre-exam wizard (15 steps) | welcome, closetabs, browser, internet, camera, audio, photoid, face, screenshare, keystroke, monitor, environment360, summary, privacy, fullscreen, ready |
| Welcome acknowledgment checkbox | Student must tick before Continue |
| Passport/Photo ID upload | Required step; also accepts employee/staff ID |
| 360° environment recording | Explicit Upload button; Continue only after upload confirmed |
| Face detection + liveness (blink) | TinyFaceDetector + faceLandmark68Tiny |
| Face identity matching | faceRecognitionNet, every 5 min during exam |
| Precheck summary step | Before privacy — shows any flagged notes from precheck steps |
| Privacy step | Must scroll to bottom → checkbox appears → tick → Continue enabled (no auto-advance) |
| Violation toasts shown to learner | showToast() for every flagged event |
| Chat widget suppression | CSS + API override + setInterval + MutationObserver; includes :fullscreen CSS |
| Fullscreen enforcement | Blocker overlay; quiz reload does NOT log as violation; file dialogs do NOT log as violation |
| Screenshots every 15s | Both periodic and random |
| 50% threshold warning | Shows once when score passes halfway mark toward suspension; phrased as "halfway mark" not "X% of Y" |
| Auto-suspend on high risk | Score ≥ threshold |
| Mobile device block | Hard block |
| Multiple monitor detection | Log + toast |
| YOLOv5n object detection | phone, book, laptop, keyboard, multiple persons |
| Audio noise monitoring | THRESHOLD=8, strict |
| Proctoring violations NOT logged during precheck | proctoring.js only runs on attempt.php |
| Per-learner scheduling | Admin can assign each learner a personal `timeopen`/`timeclose` window (checkbox selection or CSV import) via the Schedule admin page. LearnGuard stores this in `learnguard_schedules`; LearnShield stores it in Moodle's native `{quiz_overrides}` |
| Dual countdown (opens/closes) | Live countdown shown (a) on activity/quiz view before the learner starts, and (b) as a badge during the live attempt. Uses server-time offset so client clock drift can't be exploited. LearnGuard auto-submits the attempt when the personal window closes; LearnShield's countdown is informational (Moodle's own quiz timer already enforces the override) |

## Backend / Admin features — LearnGuard (LearnShield archived)
| Feature | Notes |
|---|---|
| Live monitor grid | Screen default; webcam toggle per-card or bulk; 15s auto-refresh |
| Gallery | Shows actual images + 360° videos; checkboxes to select + delete |
| Suspended page | Bulk reinstate/confirm actions; Review links include courseid |
| Storage management page | `storage.php` — shows size by type, filter, delete |
| 360° video viewer | `videos360.php` — lists and plays precheck recordings |
| Precheck tab | Shows step-by-step progress per candidate |
| Bulk assignment | `bulkassign.php` — enable/disable proctoring per course or quiz |
| Course/quiz switcher | On live monitor page; dropdown to switch between quizzes |
| Schedule admin page | `schedule.php` — assign per-learner `timeopen`/`timeclose` windows via checkbox selection or CSV import (`name,username,email,datestart,dateend,timestart,timeend`); lists/removes existing schedules with open/closed status badges |
| Scores admin page | LearnGuard: `scores.php` — sortable (name/attempt/state/score/risk/started/finished), filterable (state, risk level, name/email search) list of every attempt's score, with summary stat cards and CSV/Excel/PDF export of the current view. LearnShield: covered by Moodle's native Quiz Grades report, linked from `ls_nav_bar()`'s "🏆 Grades" item (Option B — see v1.6.1 notes) |
| Overview page | `overview.php` in both plugins — card-based grid linking/describing every feature and admin page; registered as the FIRST admin page under each plugin's Site Administration → Plugins section |

## LearnGuard anonymous grading (grader.php — FIXED)
`grader.php` (the page reached from the admin "Graders" tab) was rewritten to
query `learnguard_slots` JOIN `learnguard_qbank` (qtype='essay') and decode
`learnguard_responses.responsedata`, mirroring `gradeessays.php`'s confirmed-working
pattern. Saving a grade now updates `learnguard_essay_grades`,
`learnguard_responses.fraction/state`, and recalculates+stores
`learnguard_attempts.sumgrades` via `question_engine::calculate_total_grade()` +
`grade_update()`. This fix is LearnGuard-only — LearnShield's grading is unrelated
and was left untouched.

## Common mistakes to avoid
- Do NOT use `ssdMobilenetv1` — use `TinyFaceDetector` everywhere
- Do NOT load face-api from CDN — use the local bundled copy in `thirdpartylibs/`
- Do NOT set snapshot interval to 30000 — must be 15000
- Do NOT commit with Claude's name — always Michael O. Adeniran
- Do NOT skip rebuilding the LearnGuard ZIP after changes
- Do NOT touch anything under `_ls_work/learnshield/` — LearnShield is archived
- Do NOT auto-advance the 360° recording step — learner must click Upload then Continue
- Do NOT auto-advance the privacy step — scroll + tick + click required; no timers
- Always read a file before editing it
- NEVER revert a working fix when implementing a new fix
