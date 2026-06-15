# LearnGuard & LearnShield — Claude Session Rules

## Project owner
Michael O. Adeniran — all commits must use this name and email `michaeladeniransnr@gmail.com`.
Never use Claude's name in commits.

## Repository layout
```
_lg_work/learnguard/      ← mod_learnguard (Moodle activity module, SPA)
_ls_work/learnshield/     ← quizaccess_learnshield (Moodle quiz access rule)
make_zip.ps1              ← builds both ZIPs; always run after any change
learnguard_learnguard.zip
learnshield_learnshield.zip
```

## CRITICAL — same fix in both plugins
**Any fix, feature, or improvement applied to one plugin MUST be applied to the other.**
Never fix LearnGuard only. Never fix LearnShield only. Always both.
If the architectures differ (SPA vs quiz access rule), adapt — but the learner and proctor experience must be identical.

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

## After every coding session
1. Run `make_zip.ps1` to rebuild both ZIPs
2. Bump `$plugin->version` and `$plugin->release` in both `version.php` files
3. Commit with `git -c user.name="Michael O. Adeniran" -c user.email="michaeladeniransnr@gmail.com" commit`
4. Never push unless Michael explicitly asks

## Before making breaking changes
Create dated backup ZIPs first:
```
cp learnguard_learnguard.zip learnguard_learnguard_vX.X.X_BACKUP_YYYY-MM-DD.zip
cp learnshield_learnshield.zip learnshield_learnshield_vX.X.X_BACKUP_YYYY-MM-DD.zip
```

## Current versions (update this when you bump)
- LearnGuard: v1.6.7 / 2025052029
- LearnShield: v1.6.4 / 2025052034

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

## Tech stack
- Moodle 4.5+ (PHP 8.1+)
- LearnGuard: AMD modules via `core/ajax`, vanilla JS SPA inside a `mod_` activity
- LearnShield: AMD modules via `core/ajax` + `core/notification`, `quizaccess_` plugin wrapping Moodle Quiz
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

## LearnShield precheck trigger (CONFIRMED WORKING DESIGN)
- `is_preflight_check_required()` returns true for `view.php` only
- `setup_attempt_page()` only injects proctoring.js on `attempt.php` (not during precheck)
- `startAttempt.js` does NOT hide the "Attempt quiz" button
- `startAttempt.js` intercepts the "Start attempt" button INSIDE the time-limit modal
- After precheck → `completeWizard()` calls `mod_quiz_start_attempt` WS with all form fields
- `is_preflight_check_required()` and `setup_attempt_page()` use `$_SERVER['SCRIPT_FILENAME']` (NOT `debug_backtrace()`)

## Key PHP paths
- LearnShield models URL: `$CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/models'`
- LearnShield ONNX URL: `$CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/onnx'`
- LearnGuard models URL: `$CFG->wwwroot . '/mod/learnguard/thirdpartylibs/models'`
- LearnGuard ONNX URL: `$CFG->wwwroot . '/mod/learnguard/thirdpartylibs/onnx'`

## Proctoring features — must exist in BOTH plugins
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

## Backend / Admin features — must exist in BOTH plugins
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
- Do NOT load face-api from CDN — use the local bundled copy
- Do NOT set snapshot interval to 30000 — must be 15000
- Do NOT log fullscreen_exit when `lsQuizNavigating === true` or `lsFileDialogOpen === true`
- Do NOT call `debug_backtrace()` in rule.php — use `$_SERVER['SCRIPT_FILENAME']` instead
- Do NOT commit with Claude's name — always Michael O. Adeniran
- Do NOT skip rebuilding ZIPs after changes
- Do NOT auto-advance the 360° recording step — learner must click Upload then Continue
- Do NOT auto-advance the privacy step — scroll + tick + click required; no timers
- Do NOT run proctoring during precheck — setup_attempt_page() returns early for non-attempt.php
- Always read a file before editing it
- NEVER revert a working fix when implementing a new fix
