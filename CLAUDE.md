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
- LearnGuard: v1.6.4 / 2025052025
- LearnShield: v1.6.1 / 2025052031

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
