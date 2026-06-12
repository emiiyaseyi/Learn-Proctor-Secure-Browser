# Learn-Proctor-Secure-Browser

AI-based exam proctoring for Moodle, shipped as two parallel plugins that must
stay in feature/UX parity (see `CLAUDE.md` for the full rules):

- **LearnGuard** (`_lg_work/learnguard/`) — `mod_learnguard`, a standalone
  assessment activity module (SPA) with its own question bank, proctoring
  dashboard, and AI settings.
- **LearnShield** (`_ls_work/learnshield/`) — `quizaccess_learnshield`, a quiz
  access rule that adds the same proctoring/precheck experience to standard
  Moodle Quiz attempts.

All face/object/audio detection runs client-side (face-api.js TinyFaceDetector +
ONNX YOLOv5n) — no Docker, no paid APIs, no server-side AI.

## Status (as of 2026-06-12)
- LearnGuard: v1.6.2 / 2025052023
- LearnShield: v1.6.0 / 2025052030

Both plugins implement the full 15-step pre-exam wizard, live monitor, gallery,
storage management, suspended/reinstate flow, bulk assignment, and 360°
environment recording (see feature tables in `CLAUDE.md`).

### New in v1.6.0 — per-learner scheduling & dual countdown
Admins can now assign each learner (individually via checkboxes, or in bulk via
CSV import) a personal date/time window for an assessment/quiz, via a new
**Schedule** admin page (`schedule.php`) in both plugins. LearnGuard stores this
in a new `learnguard_schedules` table; LearnShield stores it in Moodle's native
`{quiz_overrides}` table (no new LearnShield DB table). Learners see a live
"opens in / closes in" countdown both when opening the activity (before
starting) and as a badge during the live attempt — LearnGuard auto-submits the
attempt when the personal window closes, while LearnShield's badge is purely
informational since Moodle's own quiz timer already enforces the override.

This release also fixes LearnGuard's anonymous-grading admin page
(`grader.php`), which previously queried non-existent tables/columns — it now
mirrors `gradeessays.php`'s working query/grading pattern. LearnShield's grading
was unaffected and left as-is.

### New in v1.6.1 — LearnGuard "All Scores" page
A new `scores.php` admin page (Scores tab) lists every attempt's score for an
assessment, sortable by student, attempt #, state, score, risk, start/finish
time, and filterable by state, risk level, and name/email search. Summary cards
show totals, finished/in-progress/suspended counts, and the average score.
Export the current filtered/sorted view as CSV, Excel, or a print-ready PDF.
LearnShield-side parity is still under review (Moodle's native Quiz Grades
report may already cover this) — see `CLAUDE.md`.

### v1.6.2 — critical bugfixes
Fixed two pre-existing LearnGuard bugs found during QA: an invalid-XML typo in
`db/install.xml` (broke fresh installs) and a non-existent `a.score`/`a.maxscore`
column reference in `results.php` (fatal error for all admins) and
`export_scores.php` (all export formats broken). Both now compute scores from
`a.sumgrades` against the assessment's max grade.

### Known gap — parity behind on LearnGuard
The last several commits (LearnShield v1.5.4 → v1.5.9) fixed precheck-wizard bugs
(typing blocked by Moodle's focus trap, popup-after-precheck, missing `cmid`,
scroll-to-top, closetabs checkbox lock, photo-ID UX) but were applied to
**LearnShield only**. LearnGuard's wizard (`assessment.js`) still needs the
applicable UX fixes ported over. See the "OUTSTANDING — parity gap" section in
`CLAUDE.md` for the itemized list and notes on which fixes are/aren't applicable
to LearnGuard's SPA architecture.

## Build
```
./make_zip.ps1
```
Builds `learnguard_learnguard.zip` and `learnshield_learnshield.zip`. Run after
every change (see `CLAUDE.md` → "After every coding session").

## Legacy folders
- `learnguard-ai/` — superseded Docker/FastAPI/Ollama architecture plan, not part
  of the active build (see `CLAUDE.md`).
- `extracted/proctoring/` — third-party reference plugin, kept for reference only.
