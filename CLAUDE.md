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
- LearnGuard: v1.5.9 / 2025052020
- LearnShield: v1.5.9 / 2025052029

## OUTSTANDING — parity gap as of 2026-06-12
Commits `b865933` → `2c81fc3` (LearnShield v1.5.4 through v1.5.9) fixed the precheck
wizard but were applied to **LearnShield only**, breaking the "same fix in both
plugins" rule. LearnGuard's wizard (`_lg_work/learnguard/amd/src/assessment.js`)
still needs:

- **Scroll-to-top on each wizard step render** — LearnShield's `renderStep()` now
  sets `body.scrollTop = 0` before rendering each step; LearnGuard's `renderStep`
  does not yet.
- **Closetabs checkbox locked until scrolled to bottom** — LearnShield's
  `hookCloseTabsConfirm()` keeps the checkbox disabled (with a "scroll down" hint)
  until the wizard body is scrolled to the bottom, same pattern as the privacy
  step. LearnGuard's `wiz_closetabs()` still unlocks the checkbox immediately.
- **Photo-ID step UX**: file input starts disabled with a "loading face
  detection…" message until face-api is ready; clearer error messages; warning
  that background-removed/edited photos will fail face detection. Not yet in
  LearnGuard's photoid step.
- **Focus-trap / typing fix (`destroyMoodleModal()`)** — NEEDS INVESTIGATION before
  porting. This fix tears down Moodle's Bootstrap modal focus-trap because
  LearnShield's wizard renders on top of a Moodle quiz-start modal. Check whether
  LearnGuard's wizard has the same problem (it may not, since LearnGuard's SPA
  doesn't run inside a Moodle quiz-attempt modal) before applying.
- **`completeWizard()` fetch()-POST + explicit `cmid` fix for the precheck→attempt
  transition** — this targets LearnShield's `startattempt.php` redirect flow and
  is likely **N/A** for LearnGuard (its SPA uses its own `external.php` WS, not
  Moodle's quiz attempt start). Do not port mechanically — confirm first whether
  LearnGuard's "begin assessment" transition has an analogous popup/cmid issue.

Also stale: this file previously listed LearnShield as v1.5.4 — it has since moved
to v1.5.9 without a matching LearnGuard release. Keep this section updated as the
gap is closed, and remove it once parity is restored.

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
