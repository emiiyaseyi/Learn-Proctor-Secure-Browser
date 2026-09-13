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

## CRITICAL — Always ask, never guess (standing norm)
**When a request is ambiguous or has multiple reasonable approaches, ALWAYS ask
clarifying questions or present options for Michael to choose from — do not guess
and proceed.** This applies to every session, permanently, not just when he happens
to remind me. If a request seems fully unambiguous, it's fine to proceed without
asking — but when in doubt, ask.

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
- LearnGuard: v2.0.211 / 2026090181  ← ACTIVE — update this on every version bump
- block_learnguard: v2.0.3 / 2026091303  ← ACTIVE — bumped when block files change
- LearnShield: v1.6.6 / 2026053107  ← ARCHIVED — frozen, do not bump

## v2.0.211 — Choice Essay tab: bulk-remove + Draw frequency, cross-grader regrade-history mislabel fixed (2026-09-13)

**Reported live: "another grader regraded a previously graded attempt and instead of
saving and updating the grading history too, it lost all previous graded records and
scores"** — but also confirmed "if you close and open it back, it shows," which rules
out real server-side data loss (nothing was actually destroyed in the database; a page
reload always showed everything intact). Investigated `save_grade`'s essay_grades
insert/update logic directly — each grader's row is correctly kept separate
(`learnguard_essay_grades` keyed by attemptid+slotid+graderid, by design, so two
graders' marks on the same question never overwrite each other's row), and
`calculate_total_grade()` already correctly picks whichever grader's row is most
recent — so the underlying scoring was never actually at risk.

**Found and fixed the real bug this symptom was describing — in the brand-new v2.0.210
Regrade Timeline itself.** `lg_log_grade_history()`'s "was there a prior grade"
lookup was scoped to `graderid = $USER->id` — the CURRENT grader's own row only. So
when Grader B regraded a question Grader A had already graded, Grader B had no row of
their own yet, and the history logged this as **"First Grade"** with no previous score
shown at all — exactly matching "it lost all previous graded records" when viewed
through the new Grade History popup, even though Grader A's own row/grade was still
sitting untouched in the database the whole time. Fixed: both `save_grade` and
`save_rubric_grade` now look up the most recent prior grade for that slot from ANY
grader (not just the one saving right now) before writing the new value, so a
cross-grader regrade is now correctly logged as "Regraded" with the real previous
score, and a genuine first-ever grade (no prior row from anyone) still correctly logs
as "First Grade."

**New: bulk-remove for the Choice Essay tab's own question list**, per direct request
with a screenshot showing only a per-row "Remove" button on a 41-question list — no way
to remove several at once, unlike the Fixed Questions tab's pool table which already had
this. Added the same Select All/None + "🗑 Remove Selected" toolbar and per-row
checkboxes to `editquestions.php`'s Choice Essay tab, reusing the existing `bulkremove`
action (now redirect-aware via a new `returntab` param, so a bulk remove from this tab
correctly lands back on the Choice Essay tab instead of always jumping to Fixed
Questions).

**New: "Draw frequency" panel + "Times Drawn" column on the Choice Essay tab**, ported
directly from the Fixed Questions tab's existing v2.0.156 feature (avg/min/max draw
count across the pool, plus an over-drawn/under-drawn flag at 50%+ off the average) —
this is exactly where it matters for a Choice-Essay-only assessment, since the "How Many
Questions Each Student Attempts" random-subset setting (v2.0.200) draws directly from
this list.

No schema change.

## v2.0.210 / block_learnguard v2.0.3 — access-control self-correction, Distribution-aware queue count, light-mode rubric fix, Grade History regrade timeline (2026-09-13)

**Security self-correction, caught before shipping — grader-blocked-by-hidden-activity
fix redone from scratch**: reported live — "i had to make the grader a non editing
teacher before he/she can see the grader page" (Moodle's own `require_login($course,
false, $cm)` blocks a grader from `grader.php` the moment the activity is hidden, since
a bare enrolment never grants `moodle/course:viewhiddenactivities`). The first fix
attempted here — auto-assigning the real "Non-editing Teacher" role — was WRONG and was
caught and replaced before ever being committed: re-reading `db/access.php` confirmed
that role grants `mod/learnguard:liveproctor` AND `mod/learnguard:viewreports` by
default, which would have silently made every grader a de facto proctor too — directly
the opposite of the very next instruction received: **"a grader without proctor access
should not have access to student attempt history or attempt report."** Replaced with a
brand-new, dedicated, minimal Moodle role instead: new `learnguard_ensure_grader_access_role()`
in `lib.php` creates (once, idempotently) a custom role `learnguard_grader_access`
granting ONLY `moodle/course:viewhiddenactivities` and nothing else — no LearnGuard
capability at all. `admin.php`'s and `siteadmin.php`'s grader-assignment handlers
(`addgrader_multi`, `addsitegrader`/`addsitegrader_multi`) now use this helper: a
brand-new enrolment gets the role via `enrol_try_internal_enrol()` as before, and — the
actual gap — a grader who was ALREADY enrolled in the course by any other means (e.g.
self-enrolled as a student before being made a grader) now also gets the role assigned
directly via `role_assign()`, since `enrol_try_internal_enrol()`'s role-assignment only
ever fires for a brand-new enrolment and previously left an already-enrolled grader with
no path to `moodle/course:viewhiddenactivities` at all.

**Confirmed, not a new fix needed**: investigated the specific reported URL
(`attemptdetail.php?id=953&attempt=3717`) — that page already has
`require_capability('mod/learnguard:viewreports', $context)` at its own access gate, and
`viewreports` is granted to the `teacher`/`editingteacher`/`manager` archetypes only —
never to the new minimal `learnguard_grader_access` role. So a grader holding only the
new minimal role is already correctly blocked from attempt history/reports by this
existing check; the real gap was the role-assignment mistake above, not a missing check
on this page. **Important follow-up for Michael**: if "Non-editing Teacher" was manually
assigned to any existing graders as a workaround (his own words, or triggered by this
plugin's earlier wrong fix before this correction), those specific graders currently
have unintended proctor-level access (`liveproctor`/`viewreports`) and that role
assignment should be reviewed/removed now that the proper minimal-role fix exists.

**block_learnguard's Grading Queue "Requires Grading" count made Distribution-aware**:
reported live — a grader assigned exactly 1 attempt via Grading Distribution still saw
"Requires Grading: 2" on their dashboard widget. Root cause: `grader.php` already
correctly scopes its own attempt list to a distributed grader's specific assignments
(`learnguard_grader_assignments`), but `block_learnguard.php`'s own separate Grading
Queue count query had never been taught this existed at all — it always counted every
finished/needing-grading attempt site-wide for that activity, regardless of Distribution.
Fixed: for a non-site-admin viewer, the block now batch-checks each activity's
`learnguard_grading_config` (mode `split`/`random`) and the viewer's own
`learnguard_grader_assignments` rows, and — only when Distribution actually applies to
this specific grader — re-runs a targeted query restricted to just their assigned
attempt IDs to recompute the accurate `total_finished`/`needs_grading`/`graded_count`
numbers for that row. An activity with no Distribution configured, or where the viewer
isn't in-scope for it, is left completely untouched — matching `grader.php`'s own
fallback exactly, so nothing changes for anyone until Distribution is actually active
for them.

**Light-mode Grading Rubric text was nearly invisible**: reported with a screenshot —
criteria names/descriptions on the Choice Essay rubric grading panel rendered in
near-white/light-gray text against light mode's white background. Root cause: the
rubric criteria row (built entirely in JS, in `grader.php`'s `edgOpenQuestion()`) used
hardcoded inline dark-theme colours (`color:#e9ecef` for the name, `#adb5bd` for the
weight/focus text, a `#343a40` row border) with no light-mode override at all, since
they were never CSS classes to begin with. Fixed: those inline colours are now CSS
classes (`edg-rubric-row`/`edg-rubric-crit-name`/`edg-rubric-crit-weight`/
`edg-rubric-crit-focus`) with a dark-mode default matching the original values plus a
proper `.edg-light` override (dark, readable text on the light background) — the same
pattern every other themed element on this page already uses.

**Grade History — new "Regrade Timeline" showing First Grade vs Regraded, per direct
request**: "add to the grade history to see if the assessment was regraded... new column
call status, first grade, regraded... show what the previous total is." Root cause of
why this didn't already exist: `learnguard_essay_grades` stores at most one row per
(attemptid, slotid, graderid) and every regrade does `update_record` IN PLACE — the
previous value is silently discarded with zero audit trail. The existing "Grade History"
popup was actually just "one row per distinct grader who has ever touched this slot,"
never a real timeline. New `learnguard_grade_history` table (upgrade savepoint
`2026090180`) logs every save going forward: a genuine first-time grade logs with
`oldgrade = null`; a regrade logs the previous value, but only when the score actually
changed (so re-saving identical feedback text doesn't manufacture a fake regrade entry)
— new shared `lg_log_grade_history()` helper in `grader.php`, called from both the plain
single-mark `save_grade` handler and the Choice Essay `save_rubric_grade` handler, so
both grading paths feed the same audit trail. The Grade History popup
(`edgShowGraderHistory()`) now shows a new "Regrade Timeline" section below the existing
per-grader snapshot table — Grader / Status (First Grade or Regraded, colour-coded) /
Previous score / New score / Date, read straight from the new table in chronological
order. Grades saved before this version has nothing in the new table and simply shows
"No regrade history recorded for this question yet." — the existing per-grader snapshot
table above it is completely unaffected and still shows current grades exactly as
before.

Schema: new `learnguard_grade_history` table — upgrade savepoint `2026090180`.

## v2.0.209 — "Preparing your assessment" countdown made much bigger and truly centered (2026-09-13)

Direct request from a screenshot: the 7-second countdown number (v2.0.206) sat in a
plain top-padded block, not vertically centered on the page, and was a modest 28px.
The wrapping div now uses `min-height:100vh` + flex centering (both axes), and the
countdown digit itself is 72px/900-weight — the dominant element on the screen while
the student waits, matching how a countdown is usually the whole point of a screen like
this. Scoped to this one screen (the only "preparing" screen with a live countdown
number) — the resume/review "Still preparing…" variants have no number to enlarge and
were left as they were.

No schema change.

## v2.0.208 — critical: pathway lock-in gap, grader caption bug, Export Submissions (PDF) (2026-09-13)

**CRITICAL — a student who reloaded/closed the tab between picking a Pathway role and
locking in their ONE chosen question could still freely answer all 3 (or however many
were drawn), and the grader then saw every one of them, not just the chosen one.**
Confirmed live with screenshots. Root cause: `mod_learnguard_lock_pathway_choice` (the
call that permanently deletes the other drawn questions) only ever ran from
`showPathwayQuestionPicker()`'s own click handler in the fresh-launch flow
(`launchAssessmentWithPathway()`). The moment a student's browser reloaded before that
click completed, `view.php` correctly detected the already-existing in-progress attempt
and routed into `beginResumedAssessment()` instead — a completely separate resume path
that had never heard of Pathway locking at all, so it just resumed straight into a
normal free-navigation exam with every originally-drawn question still present and
answerable.

Fixed by teaching the resume path the same thing the fresh-launch path already knew:
`mod_learnguard_get_attempt_state` now also computes `pathwaypending` (same condition —
`pathway_unitid` set and more than one still-attached question) from the attempt's
CURRENT state, and `beginResumedAssessment()` checks it before doing anything else,
routing back into the exact same "Choose Your Question" picker if a pick is still
outstanding. Also fixed, found while adding this: `is_flagged` was already being
returned by `get_attempt_state()` but was never declared in its `_returns()` structure,
so Moodle's external-API layer silently stripped it from every response — meaning
`state.confirmedSuspension` (read from `data.is_flagged`) was always `false` regardless
of the real flag. Declared it properly alongside the new field.

**grader.php's own "Question" heading was still showing the raw internal Choice Essay
placeholder** ("Choose one of the following questions to answer.") for every Choice
Essay slot, on every attempt — a separate, previously-unnoticed display bug independent
of the pathway issue above (confirmed present even for Q1, the one actually answered).
The student-facing fix (v2.0.198/201, which taught `render_question()` to skip this
placeholder for choiceessay) was never applied to grader.php's own "Question" section,
since it reads `$slot->questiontext` directly rather than going through that renderer.
Now blank for choiceessay slots — the real content already shows in the "Question
answered:" card in the Student Response section below.

**Em dashes removed from every student-facing exam/wizard string**, per explicit
request after the first targeted fix (the "Do NOT press" banner) — swept the whole
precheck wizard and live-exam text (~50 strings across `assessment.js` and
`question_engine.php`) and rewrote each into plain sentences (periods, commas, or
colons in place of "—"). Left untouched: code comments (never shown to anyone) and the
handful of places a bare "—" is used as a "no value yet" placeholder character (e.g. an
empty score/date cell) — those aren't a sentence-connecting dash and are a separate,
unrelated display convention used throughout this codebase.

**New: Export Submissions — plain question+answer PDF, no score included.** Per direct
request: a grader can select one or more finished attempts and either email each
student their own PDF (question text + their written answer, essay/Choice Essay only —
explicitly no score, grade, or rubric breakdown), or — site admin / global grader only —
download a single ZIP containing every selected student's PDF, each file named by that
student's own email address. New `classes/submission_pdf.php` builds the PDF using
Moodle's own bundled TCPDF wrapper (`lib/pdflib.php`) — no new dependency, no CDN,
matching the plugin's standing "no external libraries" decision. New
`exportsubmissions.php` page (linked from grader.php's header) lists every finished
attempt with essay/Choice Essay content, with Select All/Deselect All and the two
action buttons.

Schema: `learnguard_attempts.pathway_unitid` was already added in v2.0.183; no new
schema this version.

## v2.0.207 — critical: a slow autosave could silently overwrite a fuller, later answer (2026-09-13)

**Reported live, confirmed as the same attempt**: a student typed a long numbered-list
answer, submitted, and the grader only ever saw a short earlier fragment ("sdsadasdsadsd").
Investigated the full save pipeline end to end (client capture → WS params → PHP save) —
no truncation anywhere (PARAM_RAW throughout, `learnguard_responses.responsedata` is a
TEXT column, plenty of room). The real root cause is a race, not a size/encoding bug:

`startAutosave()` fires a new `mod_learnguard_autosave` request every 5 seconds while the
answer is changing, with no protection against overlapping requests — normal while
composing a long response, since typing 17 list items easily spans several autosave
ticks. `save_response()` was a plain last-write-wins upsert with no way to tell which
request was actually captured MORE RECENTLY on the client. If an earlier request (short,
early content) is slow over the network and completes AFTER a later one (fuller content)
— or even after the final explicit Save & Next / submit — it silently overwrites the
newer save with the older, shorter one. This can happen to any long-form answer (Essay or
Choice Essay), not just this one report.

**Fixed with a client-supplied capture timestamp, not a server-arrival-order assumption**:
every save request (`submit_answer`, `autosave`, and the background retry queue) now
sends `capturedat` — `Date.now()` recorded at the exact moment the answer was read from
the DOM, not when the request happens to reach the server. `save_response()` compares
this against a new `learnguard_responses.lastcaptureat` column and silently drops (never
applies) a write whose capture is older than the last one already accepted for that
slot — regardless of which request's network round trip finishes first. `capturedat`
defaults to 0 (no ordering check) for any existing/legacy caller that doesn't pass it, so
this is purely additive — nothing about normal single-request saves changes.

Schema: `learnguard_responses.lastcaptureat` — upgrade savepoint `2026090177`.

## v2.0.206 — 7-second preparing countdown, preload starts immediately not after it (2026-09-13)

Direct follow-up to v2.0.205's open design question: asked whether preload should start
during the countdown itself or earlier during precheck — answered "preload during
precheck." Since the exact question(s) to preload for a pathway attempt genuinely can't
be known until after the student picks a pathway role and then a question (both happen
after precheck by definition), literally moving attempt-creation into precheck would be a
much larger, riskier change to the timer-safety architecture for comparatively little
gain. Implemented the same underlying intent in a safer, already-consistent way: preload
already starts the INSTANT it can (immediately after attempt creation / immediately
after the question is locked in for pathway) — nothing was ever waiting around for a
countdown to finish before beginning to load.

**New fixed 7-second "Preparing your assessment…" countdown**, shown alongside the
existing spinner, counting 7→0 — added as a floor, not an extra wait: it runs in
parallel with the real preload (`preloadAllQuestionsBlocking`) which starts at the same
moment as before, so in the common case (preload finishes in under 7s) the countdown is
what the student actually watches — predictable and reassuring — while the content is
already sitting ready in the background. If preload genuinely takes longer than 7s
(slow connection, server distress), reveal still waits for it — the countdown only ever
adds a guaranteed minimum, never allows revealing before content is actually ready, and
the existing "still preparing, thank you for your patience" fallback wording still
appears past 20s exactly as before. Applies to every reveal path that goes through
`proceedToPreloadAndReveal()` — a normal launch and the pathway single-question reveal
both get it, since both preload gate through the same function.

No schema change.

## v2.0.205 — critical data-repair, key-name fixes, pathway redesigned to "select ONE question" (2026-09-13)

**CRITICAL — old base64-corrupted Choice Essay prompts, still visible live**: v2.0.198
fixed the JS bug that saved a prompt's text as raw, never-decoded base64 (reported again
today — a student-facing exam question and the grader both showed the literal encoded
string). That fix only stops NEW corruption going forward; it never repaired rows already
corrupted before it shipped. New upgrade step scans every `learnguard_ce_prompts` row for
content that's pure base64 (strict alphabet, ≥40 chars, decodes to mostly-printable text
containing a space) and decodes it in place — repairs the backlog in one pass on upgrade.
This also explains the "question overlapping into the question box" report: a raw base64
string has no spaces to wrap on, so it visually overflowed its container; real prompt
text wraps normally.

**Key-name labelling redone properly — "meta" and "alt+Alt" both fixed**: the v2.0.202
fix only special-cased PrintScreen and Ctrl inside the Meta/OS branch; reported again
that "meta" was still showing for other keys. Rebuilt as a proper `e.code`-to-key
resolution applied FIRST, before any branch runs, covering every key this exam tracks
(Escape, Tab, Ctrl, Alt, PrintScreen, F1-F12) — `e.code` is checked ahead of the
sometimes-unreliable `e.key` for all of them, not just two. Also fixed a separate,
genuinely distinct bug in the same log: solo Alt was logged as "alt+Alt" (`e.altKey` is
already true on the very keydown that sets it, so the existing Alt-combo branch matched
itself) — solo Alt now logs plainly as "alt".

**Screen-share bar still overlapping the Ready-step buttons**: the v2.0.201 fix (56px
top padding, still vertically centered) didn't move the buttons far enough — reported
again with a screenshot showing the overlap unchanged. Root cause: on this browser the
indicator floats as a bottom-center pill, which centering the wizard card does nothing
to avoid. The whole card is now anchored toward the TOP of the viewport instead of
centered (`align-items:flex-start` + a fixed top margin, `max-height` reduced to 80vh
with an added bottom margin), pushing the footer buttons as far from that bottom-center
zone as the content allows.

**Removed the em dash from the "Do NOT press" reminder banner**, per direct request —
split into two plain sentences instead.

**Pathway feature redesigned — "select ONE question" instead of a normal multi-question
exam**: reported that after picking a pathway role, the student could freely navigate
between all N+M drawn questions and had to answer every one — not what was intended.
The pathway draw itself (N from the chosen role, M from the configured other-units pool)
is unchanged, but a fresh draw is no longer revealed as a normal N+M-question exam:
- New `mod_learnguard_get_pathway_previews` WS returns read-only prompt text for every
  drawn question (each is a standalone single-prompt Choice Essay question, so the one
  prompt IS the whole question) — no interactive answer box, shown purely for the
  student to read and choose from.
- New "Choose Your Question" screen (`showPathwayQuestionPicker()`) lists all of them
  with a clear warning: "Once you choose a question, you cannot go back and see the
  others — you will only be able to answer the one you pick." Still entirely before the
  exam clock starts, same guarantee as the pathway-role screen itself.
- New `mod_learnguard_lock_pathway_choice` WS permanently deletes every OTHER drawn
  question's slot/attempt-question/response rows once the student picks one — from that
  point on the attempt genuinely only has 1 question in it (server-side, not just
  hidden client-side), matching how a manually-built multi-prompt Choice Essay question
  already works, applied here across separately-drawn questions instead of prompts
  within one question. Deliberately fails closed, not open: if either the preview fetch
  or the lock call fails even after retrying, the student sees a "Try Again" screen
  instead of silently proceeding into a state where the client thinks there's 1 question
  but the server still has N+M — a mismatch that would have desynced the exam.
- `start_attempt`'s response gained a `pathwaypending` flag and `slotid` on each
  navigation entry (both purely additive — nothing existing reads or breaks from them)
  so the client knows when this extra step is needed and which row to lock in.

**Investigated, likely just needs the plugin re-uploaded**: the grader panel width and
immediate rubric-cap flag from v2.0.204 were both reported as "still not done" — re-read
that code and it's correct as shipped. Most likely the site hasn't picked up v2.0.204/205
yet — worth confirming the Moodle plugin was actually updated before assuming a fresh bug.

**Still needs a decision before building**: the requested 7-second countdown before the
student can click Start, to give the backend time to load questions — asked whether
preload should happen during the countdown itself (after pathway/question selection) or
earlier, during precheck (while the student is still doing the camera/audio/etc. steps).

Schema: no new columns — `learnguard_ce_prompts` repair is a data fix on the existing
`prompttext` column — upgrade savepoint `2026090175`.

## v2.0.204 — grader panel: wider Comment/Rubric column, immediate rubric-cap flag (2026-09-13)

Two direct requests from a live grading test:

**Comment and Grade / Grading Rubric column widened**: `.edg-detail-content`'s grid was
`1fr 340px` (question+answer on the left, comment/grade on the right) — the rubric's own
criteria list, each with a name/focus text and a score box, was cramped in that fixed
340px. Changed to `1fr 560px`, so the right column (where the grader actually enters
marks) is now noticeably wider and the left (question/answer, read-only) proportionally
narrower.

**Rubric score inputs now flag AND clamp immediately, not just on Submit**: each
criterion box already had `max="{weight}"` and was already re-validated/capped
server-side on save (unchanged) — but a grader could still type/hold a number above the
weight in the box itself until they hit Submit, where a plain `alert()` blocked it. Now
`oninput` clamps the value back down to the weight the instant it's exceeded, flashes
the border red, and shows a small "Max N" note under the box for 2.5 seconds — the
grader sees immediately that a number was rejected, not just when they try to submit.

**Investigated, not a bug — confirmed to Michael directly**: (1) anonymous grading is
correctly scoped to non-manager graders only (`$anonymous = !empty($myGrader->anonymous)
&& !$isManager`) — a manager/admin viewing the grader always sees real names by design,
which is what the screenshot showed; (2) a 3-question Choice Essay assessment showing
"—/300" with only Q1 answered is expected given that assessment's current configuration
— without "Give each student a random subset of questions" (the v2.0.200 "How Many
Questions Each Student Attempts" setting) turned ON with a count of 1, every fixed slot
in the pool is included for every student — a standalone single-prompt Choice Essay
question has nothing internal left to "choose" once bulk-imported (v2.0.193), so 3
separate questions in the pool means 3 separate mandatory slots unless this setting
narrows the draw. Enabling it on the Choice Essay tab and setting the count to 1 will
draw only 1 of the 3 per student, correctly capping the total to that one question's
maxmark.

No schema change.

## v2.0.203 — item 3 corrected: Learner Progress moved to the actual page Michael uses (2026-09-13)

Direct correction: v2.0.201/202's Learner Progress panel was added to admin.php's
Overview tab — technically satisfying the request, but buried behind an extra click
("Admin Backend") that Michael doesn't take on the page he actually lands on. Reported
with a screenshot of that landing page (`view.php`'s manager quick-links grid — Assessment
& Content / Monitoring & Results / Management & Configuration).

**Moved (well, added — the admin.php Overview one was left as-is, no reason to remove
it) directly onto `view.php`**, below every other section on that page (the button grid,
the proctor-only alert, the no-questions warning) — a summary belongs after the actions,
not before them. Redesigned per the explicit "better UI/UX" request: a bordered card
titled "👥 Learner Progress" with the total enrolled-learner count in the header, a
segmented horizontal progress bar (green/orange/gray proportional to Completed/In
Progress/Not Yet Started), and a legend row below with each count plus its percentage —
replacing the plain colored stat boxes used on the admin.php version. Same underlying
query as before (enrolled learners via `get_enrolled_users()`, bucketed by their most
advanced attempt state on this activity) — counts learners, not attempts, so nobody is
double-counted or missing.

No schema change.

## v2.0.202 — critical: exam timer never started on a fresh launch + Typing Pattern Baseline live comparison (2026-09-13)

**Critical timer bug, reported live right after v2.0.201 shipped**: on a fresh exam
launch, the visible countdown/elapsed timer never started at all. Root cause: v2.0.167's
"preload before reveal" rewrite calls `initTimer()` (which looks up `#lg-timer` and
silently returns if it doesn't exist yet — `initTimer()`'s own early-return guard) BEFORE
`lgRevealExamContent()` — and it's `showAssessmentShell()`, called INSIDE
`lgRevealExamContent()`, that actually creates `#lg-timer` in the first place. Nothing
ever called `initTimer()` again afterward, so the interval that drives the whole visible
countdown was simply never created — on every first-time launch, for every activity,
regardless of question type. (The other two `initTimer()` call sites — plain resume and
Continue & Review — were never affected, since both run after `showAssessmentShell()`
has already built the shell earlier in the same flow.) Fixed by swapping the order:
`lgRevealExamContent()` now runs before `initTimer()`, matching the other two call sites.
Server-side enforcement of the real deadline was never affected by this (that's fully
independent, via `learnguard_attempt_timelimit_expired()`/the scheduled task) — this was
purely the visible client-side countdown never appearing/ticking.

**Typing Pattern Baseline (item 1) — built as a live impersonation-signal comparison**,
per Michael's explicit choice of that option over "just show it as a reference metric."
Two real problems fixed to make this possible:
- **The recorded baseline was never actually being saved.** The precheck keystroke
  step's own `logProctoringEvent('keystroke_baseline', ...)` call was gated on
  `state.attemptid` — which doesn't exist yet during precheck (the attempt isn't created
  until after the whole wizard finishes) — so this call never fired at all; the baseline
  was captured in a JS variable and then silently discarded every single time. Fixed:
  the average ms-between-keystrokes is now computed and kept in memory for the whole
  page session (precheck and the exam run in the same page load, so no re-fetch is
  needed), and persisted server-side via a new WS call once `launchAssessment()` obtains
  a real attemptid — same deferred pattern already used for the 360° video/identity-flag.
- **Live comparison during the exam**: a new `initTypingPatternMonitor()` tracks
  keystroke intervals while the student is actually typing in a contenteditable
  Essay/Choice Essay answer box (ignores modifier keys and pauses over 5s, so thinking
  time never skews the average). Once a stable rolling window (12+ keystrokes) is
  available, it's compared against the precheck baseline — a sustained 65%+ deviation
  (much faster/slower, or a totally different rhythm) logs a `typing_pattern_mismatch`
  event via the existing `logProctoringEvent()` pipeline (new admin-configurable
  `score_typing_mismatch` setting, default 8, same "Risk Scores for Flagged Events"
  section as every other event type) — debounced to at most once per 3 minutes so a
  sustained mismatch doesn't spam the log. This is offered as a signal for a proctor to
  review, not proof on its own — a student legitimately typing faster mid-exam under
  time pressure, or more slowly while composing a harder answer, can also cross this
  threshold; the event and its exact baseline/live/deviation numbers are all visible on
  attemptdetail.php's Proctoring Events table for a human to judge in context.

Schema: `learnguard_attempts.typing_baseline_avg` — upgrade savepoint `2026090172`.

## v2.0.201 — 18-item live student-view feedback batch (2026-09-13)

Large batch from live student-view testing, worked through item by item (9 screenshots
supplied). Confirmed with Michael first, via a clarifying question, that since the
v2.0.193 bulk-import redesign every standalone Choice Essay question now has exactly
ONE prompt — so the "choose 1 of N" screen was pure busywork for the normal case,
which turned out to be the root cause behind several of the 18 items at once.

**Choice Essay: single-prompt questions now skip the "choose" screen entirely
(items 1, 5, 6, 7, 8, 9)** — `render_question()`/`render_choiceessay()`
(`classes/question_engine.php`) now detect `count($allPrompts) === 1` and auto-select
that sole prompt, rendering it directly like a plain Essay question: no "choose one of
the following questions" list, no "Choose this question" button, no "Back to question
list" link, and the generic `questiontext` placeholder card is skipped too (it was
always just an internal Moodle-compatibility string, never real content). The full
choose/lock/back-navigation flow is preserved for a question someone deliberately
builds with more than one prompt by hand. Wording also updated for that multi-prompt
case: "Go through the question numbers and choose one to answer." replaces "Choose one
of the following prompts to answer.", and the default instructions text is now "Read
each question below carefully, then click on Choose this question:" (previously "Read
all questions below, then choose ONE to answer:").

**New per-assessment back-navigation controls for a genuinely multi-prompt question**
(item 9's configurability half): new "Allow returning to the question list after
choosing" checkbox + "Max times allowed" number field on the Choice Essay tab
(`editquestions.php`) — `ce_allow_back` (default on) and `ce_back_limit` (0=unlimited).
Enforced both server-rendered (the Back button is hidden/disabled once the limit is
reached) and client-side (a `backCount` counter lives in the same JSON answer blob the
chosen-prompt/text already use, incrementing on each use and disabling the button in
place once exhausted — no server round trip needed to enforce it). Only ever relevant
for a multi-prompt question — a standalone single-prompt question never shows this
button at all, regardless of these settings. Schema: `ce_allow_back`/`ce_back_limit` on
`learnguard` (activity-wide setting) AND mirrored onto `learnguard_qbank` (synced via
`learnguard_sync_ce_settings_to_questions()`, same pattern already used for
`show_prompts_count`/`word_limit`/`ce_instructions`) — upgrade savepoint `2026090171`.

**Grader now only ever sees the ONE prompt the student actually answered (item 8)** —
`render_choiceessay_review()` used to show the entire shown-prompt pool with the chosen
one highlighted ("Questions shown to this student:" + a "← Student chose this" tag per
card) — a grading distraction, and a genuine scope/anonymity leak for a grader
restricted to one Grading Unit (they could see other units' unchosen prompts). Rewritten
to show only the chosen prompt ("Question answered:" + one card), falling back to the
sole prompt for a standalone question.

**Layout/wording fixes**:
- Item 2: the browser's own "sharing your screen" indicator bar (shown once screen
  sharing starts, before the exam ever reaches fullscreen) was reported overlapping the
  wizard's Continue/Start Exam button. The wizard overlay (`buildWizardOverlay()`) now
  reserves 56px of top padding so its card never sits flush against the very top of the
  viewport.
- Item 4: "Select Your Pathway" screen (`showPathwaySelectionScreen()`) now genuinely
  vertically+horizontally centers (was only horizontally centered before), uses larger
  text throughout, and is renamed "Select your Pathway Role".
- Item 3: new "👥 Learner Progress" panel on `admin.php`'s Overview tab — Total
  Learners / Completed / In Progress / Not Yet Started, counted per ENROLLED LEARNER
  (via `get_enrolled_users()` + a grouped query against `learnguard_attempts`), not per
  attempt row — the existing stat cards above it count attempts, which double-counts a
  student with 2 attempts and says nothing about a student with none at all.

**Two real bugs fixed**:
- Item 10: PrintScreen and Ctrl were being logged as "Blocked key pressed: meta" —
  some keyboards/OSes/VM remote-desktop drivers report `e.key` as "Meta"/"OS" even when
  the physical key pressed was PrintScreen or Ctrl. `initKeyBlock()`'s Meta/OS branch
  (`amd/src/assessment.js`) now also checks `e.code` (the physical key, far more
  reliable here than `e.key`) and labels/handles the violation correctly when it's
  actually PrintScreen or a Control key wearing a Meta/OS mask.
- Item 11: the rich-text word counter undercounted (and sometimes glued words together)
  whenever the answer had a bulleted/numbered list or multiple paragraphs — both the
  client-side counter (`ce.textContent`) and the server-side `count_words()` used
  `strip_tags()`/`textContent`, neither of which inserts a space at block-tag boundaries
  (`</li><li>` etc. concatenate directly, e.g. "one" + "two" → "onetwo"). Both now
  insert a space at every `</li>`, `</p>`, `</div>`, `</h1-6>`, `</tr>`, `<br>` boundary
  first, so words from different list items/paragraphs/lines never merge.

**Item 12 — richer answer-box toolbar**: added Align Left / Align Center / Align Right
(`execCommand('justifyLeft'/'justifyCenter'/'justifyRight')`) and a Table button (no
native `execCommand` equivalent — inserts a plain 3×3 HTML table via `insertHTML` at
the cursor) to `render_richtext_box()`'s toolbar, alongside the existing Bold/Italic/
Underline/Bullet/Numbered-list buttons.

**Two real bugs fixed on admin.php's Graders tab**:
- Item 16 (CRITICAL) — "Assign Grader" was silently failing with no error or success
  message at all. Root cause: this tab's entire POST-handling block (`addgrader`,
  `addgrader_multi`, `removegrader`, `toggleanon`, `bulkanon`, `sendreminder`,
  `setgradingmode`, `manualassign`) sat AFTER `echo $OUTPUT->header()` — every other
  action on this page runs its POST handling BEFORE the header is echoed (so `redirect()`
  gets a clean HTTP redirect), but this one block was the sole exception, meaning every
  `redirect()` call inside it ran after output had already started. Moved the whole
  block to run before the header, matching this file's own established, working
  pattern everywhere else.
- Item 13 — the "Assign a Grader" Email/Username/ID field had no live search-as-you-type
  at all (a plain text box) — the self-contained `ajaxusersearch` search built earlier
  this session only ever covered the Proctors tab and siteadmin.php's Graders tab, never
  this per-activity form. Added the identical pattern here: a search box above the field,
  wired to the same `ajaxusersearch` GET action (now also served for `$tab === 'graders'`)
  querying `{user}` directly.

**Item 17 — "Restrict Graders to Grading Units" ported to admin.php's Graders tab**:
previously only reachable from siteadmin.php's site-wide Graders tab. Same
`learnguard_grader_units` table, same `savegraderunits` action (added to admin.php too),
scoped to graders actually assigned to this specific assessment (plus any Global
Grader) rather than every grader site-wide — the restriction itself is still keyed by
userid and applies everywhere that person grades, exactly as it does when set from
siteadmin.php.

**Items 14, 15 — investigated, confirmed already correct in code, no change needed**:
a Global Grader's course/assessment list query in `block_learnguard.php` already
includes Choice Essay in its "has gradable content" check (`qtype IN ('essay',
'choiceessay')`), and the course selector already shows `coursefullname`, not
shortname (`s($c['name'] ?: $c['short'])` where `name` is `coursefullname`) — both
confirmed by re-reading the current code. If either still looks wrong on the live
site, it's a deployment/caching gap, not a code bug — worth confirming the site is
actually running this version.

**Item 18 — re-scoped after asking Michael what he actually meant**: not "students
can't view their own attempt" (nothing lets any question type do that today) but "the
grader isn't seeing the Choice Essay question the learner submitted." Investigated
`grader.php`'s Choice Essay rendering/pill-detection thoroughly — found it already
correctly resolves the chosen prompt from the response's own `promptid` JSON field
throughout. The most likely actual cause was upstream of this: before this version's
single-prompt fix, a student had to click "Choose this question" for `promptid` to
ever get saved at all — if that click didn't fire correctly for any reason, the grader
would show "[Original prompt no longer available]" or nothing. Since a standalone
question no longer depends on that click happening (the prompt is auto-selected and
baked into the initial JSON the moment the question renders), this class of failure
should no longer be reproducible going forward. If it still happens on a NEW attempt
made after this update, that's a different bug and needs a fresh report with the
specific attempt.

**Item 1 — Typing Pattern Baseline usage — still needs Michael's choice**: not built
yet; needs a decision on what to actually do with the recorded baseline (e.g. compare
live typing rhythm during the exam against it and flag a mismatch, or just surface it
as a reference metric on the grader/report page) before building anything.

Schema: `ce_allow_back`/`ce_back_limit` on `learnguard` AND `learnguard_qbank` (mirrored,
synced via `learnguard_sync_ce_settings_to_questions()`) — upgrade savepoint `2026090171`.

## v2.0.200 — "How Many Questions Each Student Attempts" added to the Choice Essay tab (2026-09-13)

**Reported live: a 41-question Choice Essay assessment told students to attempt all 41,
not a subset.** Root cause: since v2.0.193's redesign to standalone one-prompt-per-
question, "Questions shown per student" (formerly "Prompts shown per student") only
ever controls how many of a SINGLE question's own internal prompts are shown when that
one question itself contains more than one (relevant only for a manually-built
multi-prompt question) — it was never the setting that decides how many of the pool's
41 separate QUESTIONS a student attempts. That's a completely different, pre-existing
mechanism (`learnguard.randomize` + `num_questions`) that already lives on the Fixed
Questions tab's own Randomisation section — but a Choice-Essay-only assessment has no
reason to ever visit that tab, so this setting was effectively invisible to Michael for
this kind of assessment.

**New "🎲 How Many Questions Each Student Attempts" box on the Choice Essay tab** — a
checkbox ("Give each student a random subset of questions") + a "Questions to attempt"
count, writing to the exact same `randomize`/`num_questions` columns the Fixed Questions
tab already uses (`setrandomize` action, now redirect-aware so it returns to whichever
tab it was submitted from instead of always jumping to Fixed Questions). The two tabs
can never disagree since they're the same underlying setting, just also surfaced where
it's actually needed. The existing least-used-first fairness draw (`start_or_resume_locked()`)
already operates on plain `learnguard_slots` rows with no qtype awareness, so it applies
correctly to Choice Essay's standalone questions with no further change needed —
confirmed by re-reading that code, not assumed.

No schema change — reuses the existing `learnguard.randomize`/`num_questions` columns.

## v2.0.199 — "prompt" wording renamed to "question" on the Choice Essay tab (2026-09-13)

Direct request from a screenshot of the Choice Essay tab: renamed every remaining
admin-facing "prompt" label to "question" — "Prompts shown per student" → "Questions
shown per student", "(0 = show all defined prompts)" → "...questions)", "Instructions
(shown to the student above the prompt list)" → "...question list)", the question-list
table's "Prompts" column header → "Questions", and the bulk-import widget's "essay
prompts" description → "essay questions". Purely a labelling change — no behaviour or
data change.

No schema change.

## v2.0.198 — critical: root-caused the base64-corrupted prompt bug, "Choose one of the following..." caption removed everywhere, HTML-paste blank-line artifacts fixed (2026-09-13)

**Critical, actually root-caused this time — editing and saving a Choice Essay question
via `questionedit.php` corrupted its prompt text into raw base64.** Confirmed live: the
preview popup showed literal `QW4gZW1wbG95ZWUgYnlwYXNzZXM...` instead of the real
sentence for a question the admin had opened via Edit (to set its Grading Unit) and
saved. Traced to the WAF-bypass mechanism every `.lg-fmt-ta` rich-text field uses
(base64-encodes its content into a hidden `{name}__b64` twin field before submit, since
raw HTML in a POST body can trip some hosts' WAF). For `ce_prompt_text[]` — the ONE
array-named field using this mechanism — the JS built the twin's name as
`ce_prompt_text[]__b64` (brackets in the MIDDLE), not `ce_prompt_text__b64[]` (brackets
at the END). PHP's own request parser only recognises `name[]` as array syntax when the
brackets are the very last characters — anything after a `]` is silently dropped, so
`ce_prompt_text[]__b64` actually landed the (still base64-encoded, never decoded) value
straight into the `ce_prompt_text[]` array the PHP save handler reads directly — while
the ORIGINAL textarea's own value never reached the server at all (its `name` gets
blanked to `''` by this same code, specifically to keep raw HTML away from the WAF).
Net effect: saving silently swapped a Choice Essay question's real prompt text for raw
base64 — and for a question with more than one prompt, the bug compounded further,
since `querySelector` reused the SAME single hidden field for every prompt row,
collapsing all of them down to just the last one's value. Fixed: the b64 twin field's
name is now built correctly for both array and non-array fields, and a fresh hidden
input is always created per textarea instead of reusing one via lookup. Every other
`.lg-fmt-ta` field (`markingguidance`, `generalfeedback`, `questiontext`) was never
array-named, so this specific bug never affected them.

**"Choose one of the following prompts to answer." removed entirely, everywhere** —
not renamed, REMOVED, per explicit repeated request: Choice Essay's real content should
display exactly like Essay/MCQ/Short Answer already do, with no generic caption at all.
Fixed in three places this was still showing: the bulk-import preview cards
(`lg_render_preview_q()` in `questionimport.php`, confirmed live via a fresh 8-question
import), the admin Question Bank preview popup added in v2.0.197, and the internal
`questiontext` placeholder itself is simply never displayed to admins or students
anymore — it still exists internally (Moodle-compatibility metadata some generic code
paths expect a question to have SOME text) but nothing renders it for choiceessay
questions any more.

**Fixed the empty-looking prompt cards seen in that same 8-question import preview** —
alternating real/blank/real/blank prompts, confirmed to be a real parsing gap: pasting a
numbered/bulleted list out of Word or Google Docs into the rich-text paste box often
leaves a bare `&nbsp;`-only paragraph between list items as a copy/paste artifact.
PHP's own `trim()`/`empty()` never treat `&nbsp;` (or its decoded U+00A0 non-breaking
space) as blank, so `learnguard_parse_ce_standalone()` was creating a real, empty-
looking "question" for every one of these artifacts. Now decodes HTML entities and
strips non-breaking spaces before the blank-line check, so these are correctly skipped.

No schema change.

## v2.0.197 — recovery picker gets a unit filter + creation date, admin Choice Essay preview finally shows real content, Question Bank gets a Unit column, likely root cause of the wrong-mark mystery (2026-09-13)

**"Add Existing Choice Essay Questions from Bank" picker improved**, per direct
feedback ("no select all" + "one can see all the units in that category and can tick or
select the ones to include"): added ☑ Select All / ☐ None buttons, and a row of
per-unit chip checkboxes above the list — ticking a unit bulk-ticks every question
tagged with it below. Each row now also shows its unit, its stored mark, and its
creation date/time (used to rule out duplicate-import-attempt theory live with Michael
— confirmed exactly 8 questions existed, no duplicates, so the wrong-mark issue is real
and not a duplicate-selection artifact).

**Found and fixed the actual display bug behind "the way the questions are showing is
wrong"**: the admin's own question-bank preview popup (`questionbank.php`'s
👁 Preview button) has always shown Choice Essay's generic internal placeholder text
("Choose one of the following questions to answer.") and nothing else — it never had a
branch for Choice Essay at all, so the real pasted prompt content was never visible
there, only in the live exam itself. Fixed: the preview's AJAX response now includes
each prompt's actual text and grading unit, rendered properly card-by-card, exactly
like the live exam shows it. Renamed the placeholder itself from "...prompts..." to
"...questions..." everywhere it's stored, per direct request. Confirmed (no change
needed): the real exam's "← Back to question list" button already exists and works —
it was only this separate admin preview tool that was incomplete.

**New "Unit" column on the Question Bank list** (`questionbank.php`), per direct
request — shows the Grading Unit name for every Choice Essay question (batch-resolved
from its prompt row, no N+1), or "N/A" when unset/not applicable. Also gave "Choice
Essay" its own proper type badge/colour in this list — it was previously falling
through to the generic "Choiceessay" label.

**Investigated the "I set 100, saw 91.5" mystery at length** — confirmed manually
editing and resaving a question correctly stores whatever mark is entered (ruling out a
general save bug), and confirmed via Michael that no duplicate import attempts exist in
that category (ruling out a stale-duplicate-selection explanation). No bug was found in
`lg_parse_choiceessay()`'s handling of the submitted Score value on re-reading it
multiple times. The most plausible remaining explanation is a well-known browser
behaviour: a focused `<input type="number">` silently changes its value when the mouse
wheel scrolls over it — very plausible if the page was scrolled (e.g. to reach the
paste box below) while the Score field still had focus. Both Score-per-question inputs
(the Choice Essay tab widget and the old import page) now call `this.blur()` on wheel,
so scrolling the page can never silently change the value again. Not fully confirmed as
THE cause, but a real, common footgun either way and worth closing regardless.

No schema change.

## v2.0.196 — smarter one-per-line paste, rubric bulk-fill, rubric save no longer wipes on validation warning, recovery picker for orphaned Choice Essay imports (2026-09-13)

**Bulk import parser made smarter, per direct request** ("i make it smarter"), after a
live report: 8 pasted questions with no blank lines between them were read as one giant
merged question. `learnguard_parse_ce_standalone()` now falls back to splitting by
LINE (one question per line) whenever the whole paste has no blank line anywhere at
all — the overwhelmingly common case for a numbered/bulleted list copied out of a
document. A genuinely multi-line single question is still supported: leaving even one
blank line anywhere in the paste (e.g. right after that one question) keeps the normal
blank-line-separated behaviour, so nothing existing broke. UI copy on both the Choice
Essay tab's widget and the old import page's Format Reference updated to say "one
question per line works" as the primary guidance.

**Grading Rubric bulk-fill added**, per direct request with an exact example given
("Quality of Analysis & Reasoning | 25% | Goes beyond stating opinions..."): a new
"📋 Bulk-fill from a pasted list" box on the Rubric section — paste `Name | Weight% |
Focus` one per line, click "Fill Rows From List", every existing row is replaced with
rows built from the pasted list (client-side, no server round-trip, so it's instantly
editable before saving).

**Critical fix — saving the rubric no longer wipes out what was typed on a validation
warning.** Reported live: hitting "Criteria weights must add up to 100 (currently 60)"
discarded every criterion already entered, since this is a plain page redirect, not a
live form — there was nothing to restore. `setrubric` now ALWAYS saves whatever was
submitted first, unconditionally, via `learnguard_save_rubric_criteria()`. Only if the
admin had "Enable rubric" checked while the criteria are incomplete (no rows, or
weights not summing to 100) does the enable flag itself get forced back off — with a
clear warning explaining exactly what's short, not an error that throws the work away.
Reloading the page shows exactly what was typed, ready to fix and save again.

**Investigated but not fully root-caused — a live Choice Essay bulk import reported "8
question(s) imported and added to this assessment!" yet the Choice Essay tab showed
zero.** Re-read the full insert path (`lg_insert_parsed_q()`'s qbank → prompt → slot
sequence) without finding a definitive bug in the current source — the qbank/prompt
rows clearly WERE created (confirmed by the reporter finding them, still present but
unattached, when browsing "Add Questions from Bank" — a picker Choice Essay is
deliberately excluded from since v2.0.193). Rather than guess at a fix for an
unreproduced failure, two things were done: (1) the slot-insertion step is now wrapped
in try/catch with a `debugging()` log line, so if this happens again there's a trace of
why instead of silence; (2) a genuine recovery path was built regardless of root cause
(see below), so an orphaned import is never a dead end again.

**New: "➕ Add Existing Choice Essay Questions from Bank" picker on the Choice Essay
tab** — pick a Category, see every Choice Essay question in it (already-attached ones
shown greyed out/disabled), tick the ones to add, "Add Selected". This is both the
direct fix for "there's no button to select the category and add questions from the
category" and the permanent safety net for the scenario above — a question sitting in
the bank with no assessment attached is now always recoverable from here, regardless of
how it got there.

No schema change.

## v2.0.195 — old Choice Essay import page redesigned to match Aiken's layout, Grading Rubric surfaced on the Choice Essay tab (2026-09-13)

Direct follow-up: **"the choice essay is still wrong for bulk upload/import... use the
format below [Aiken CSV layout]... each question stand alone, default mark should be
outside and not in the template to be pasted... check the screenshot from aiken import
csv format, that's what I want... Format reference is just the title."** The generic
"Import Questions" tab's Choice Essay format (`questionimport.php?fmt=choiceessay`) still
showed the old grouped TITLE:/MARKS:/SHOW:/GUIDE: directive format from before v2.0.193's
redesign — genuinely wrong now, since bulk import no longer groups multiple prompts under
one question at all.

**Redesigned to match the Aiken CSV layout exactly, as shown**: Category → Grading Unit
→ Score Per Question (all three as real form fields, chosen once for the whole batch —
no longer anything to configure inside the pasted text) → Upload File (with a matching
simplified sample template) → or paste directly → Preview & Import. Format Reference
section reduced to just the title/example — "each Choice Essay question stands entirely
on its own... paste one essay question per blank-line-separated paragraph" — no
directives, no tips about TITLE:/MARKS:/SHOW:/GUIDE: since none of that exists anymore.
`lg_parse_choiceessay()` rewritten to reuse the same `learnguard_parse_ce_standalone()`
parser the Choice Essay tab's own widget uses (moved into `lib.php` so both places share
one implementation, guaranteeing they can never drift apart) — Category/Unit/Score read
directly from the new form fields instead of per-question directives.

**Choice Essay tab's own bulk-import widget also gained a "Score per question" field**
(previously always used the site-wide default mark with no override), for consistency
with the redesigned old page — both now offer the exact same 3 batch-level choices
(Category, Unit, Score).

**Grading Rubric surfaced directly on the Choice Essay tab too**, per direct request —
**"I love what we have in [the Choice Essay tab]... only thing missing is Marking guide
that both grader and learners will see which makes the total mark per question."**
Confirmed via clarifying question this describes the existing Choice Essay Grading
Rubric feature (v2.0.186) exactly — Criteria + Weight (+ optional focus notes) rows
summing to 100%, shown to students when enabled and used by the grader to score
per-criterion instead of one flat mark box. Rather than inventing a separate "Marking
Guide" mechanism, the existing Rubric builder (enable toggle, show-to-learner toggle,
show-total-to-grader toggle, add/remove Criteria+Weight+Focus rows) is now also editable
directly on the Choice Essay tab via a new `setrubric` action, reusing the exact same
`learnguard_save_rubric_criteria()` helper and 100%-total validation mod_form.php already
enforces — both places write to the same columns/table, so they can never disagree.

No schema change — reuses the existing `learnguard_rubric_criteria` table and `rubric_*`
columns from v2.0.186.

## v2.0.194 — critical: typing "F" (or A-E) in an essay answer swallowed the letter and toggled Flag instead, plus a big "blocked keys" reminder (2026-09-13)

**Reported live: "when user press letter F on the keyboard while typing, it flags it as
a violation."** Root cause confirmed by reading the code: `initKeyboardNav()`'s
MCQ-answer-shortcut handler (A-F selects an option, F alone toggles "Flag for Review")
guards itself with `var inInput = ['input', 'textarea', 'select'].indexOf(tag) >= 0` —
but Essay/Choice Essay's answer box has been a `contenteditable` `<div>` since v2.0.179
(needed for the rich-text toolbar), not a real `<textarea>`. `document.activeElement.tagName`
for that box is `DIV`, which never matches the list, so `inInput` was always `false`
while composing an essay answer — every keystroke A through F was being intercepted:
typing "f" toggled the flag marker (read as "getting flagged") instead of typing the
letter, and A-E were silently eaten trying to click a non-existent MCQ option. **This
also means Left/Right/Up/Down arrow keys — meant for moving the text cursor while
writing — were being hijacked into Save & Next/Previous question navigation the whole
time**, a second, related bug caught by the same fix.

**Fixed**: `inInput` now also true whenever `document.activeElement.isContentEditable`,
covering the essay/choiceessay answer box exactly like a real `<textarea>` already was.
MCQ/True-False questions are completely unaffected — their answer options are native
`<label>`/radio elements, never focused as "the active input," so A-F/Flag/arrow-key
shortcuts still work there exactly as before.

**Also added, per direct request — a large, hard-to-miss reminder of what's actually
blocked while typing an essay answer**: a red-bordered banner directly above the
Essay/Choice Essay answer box listing every restriction genuinely enforced elsewhere
(Paste/Copy/Cut, right-click on this box; F1/F5/F11/F12, Escape, Alt+Tab, Print Screen
exam-wide) — every item named is real, not aspirational, so the reminder can never
promise something that isn't actually true. Shared by both Essay and Choice Essay since
both use the same `render_richtext_box()`; hidden in review mode (nothing is blocked
once the exam is over).

No schema change.

## v2.0.193 — Choice Essay bulk import redesigned as standalone questions, Choice Essay tab becomes the one-stop shop (2026-09-13)

Direct feedback on v2.0.190's Choice Essay bulk import: **"this bulk upload for
questions is totally bad approach or poor UI... I pasted the questions in bulk and had
to start selecting grading unit one after the other, what i want is when one paste the
questions, one grading unit applies to all and I can preview the whole questions as we
do in aiken format before saving"**, plus a separate confusion report — **"why is bulk
import still asking for question name/title when i expect each questions to stand
alone?"**. Confirmed via clarifying questions before rebuilding: (1) each pasted essay
prompt becomes its own fully separate, standalone Choice Essay question (a genuine
architecture change from v2.0.190's grouped multi-prompt-per-question import — the
"pick one of N prompts" pooling still exists for manually-built questions, it just no
longer applies to anything created via bulk import); (2) the import screen only ever
asks for Category and Grading Unit ONCE, applied to the whole pasted batch; (3) followed
up with "all i need to select for bulk upload are the category, and unit, then preview
and save" and a screenshot pointing at the existing "➕ Add Questions from Bank"
inline-expandable widget as the UI style to match, rather than navigating to a separate
page.

**Bulk import completely rebuilt as an inline widget directly on the Choice Essay tab**
(superseding v2.0.190's outbound link to `questionimport.php`): a collapsible "📥 Bulk
Import Choice Essay Questions" section (same expand/collapse visual pattern as "Add
Questions from Bank") with a Category dropdown, a single Grading Unit dropdown, and a
paste box. New `lg_parse_ce_standalone()` splits pasted text on blank lines — each
resulting paragraph becomes ONE new question with exactly one prompt, titled
automatically from its own text (no manual title ever required, directly answering the
"why do I need a title" confusion — a bulk-imported question genuinely does stand
alone, so there's nothing to separately name). Clicking Preview shows every parsed
question (checkboxes, Select All/Deselect All, the chosen Category/Unit shown once at
the top) before anything is saved — the exact same paste → preview → confirm shape as
the Aiken importer. Confirming inserts each selected question, its one prompt (tagged
with the chosen unit), and slots it directly into this assessment — no separate "add to
bank then add to assessment" step, matching how v2.0.190's import already worked.

**Choice Essay tab further consolidated into the one-stop place for everything
Choice-Essay-related**, per direct follow-up request ("I should be able to enter the
maximum grade, pass mark... Question randomization, Number of questions in the
selected pathway... and number of questions from other pathways"):
- **Maximum Grade and Pass Mark** — added here too (unchanged, still also on the Fixed
  Questions tab), reusing the exact same `setmaxgrade`/`setpassmark` actions with
  `qtab=choiceessay` as the return tab, since a Choice-Essay-only assessment barely
  touches the Fixed Questions tab for anything else.
- **Pathway / Role-Based Selection settings** — the enable checkbox, own-unit count,
  other-units count, and the "which units count as other" multiselect (previously only
  on the activity's Edit Settings form, mod_form.php) are now also editable directly
  here via a new `setpathway` action, reusing the exact same `learnguard_save_pathway_other_units()`
  helper and the same "at least one count must be above 0" validation mod_form.php
  already enforces. mod_form.php itself is untouched — both places write to the same
  columns, so they always agree.

**Found and fixed a real bug while building this, before it could ship**: the preview
screen's own "← Cancel" button was initially built as a GET link — the exact same
GET-vs-POST mistake just fixed in v2.0.192's Remove button. Caught and fixed before
committing (proper inline POST form, matching every other mutating action on this
page) — not shipped broken this time.

No schema change — reuses existing `learnguard_pathway_other_units`/`learnguard_ce_prompts`
tables and the `ce_*` columns from v2.0.190.

## v2.0.192 — critical: pre-deployment sweep of v2.0.190/191 found a real dead "Remove" button on the new Choice Essay tab (2026-09-13)

Asked directly to double-check backend/question-bank/learner-frontend before deploying
with no time to test — ran a targeted correctness sweep of everything changed in
v2.0.189-191 (brace/paren balance, install.xml↔upgrade.php field consistency, XML
validity, orphaned-reference grep) on top of the earlier `/security-review` pass (no
vulnerabilities found there). Found one real functional bug, not caught by any of the
above checks since it's a runtime-logic issue, not a syntax one:

**The new "✕ Remove" button on `editquestions.php`'s Choice Essay tab silently did
nothing.** It was built as a plain GET link (`action=remove&slot=X&sesskey=...`), but
every mutating action on this page — including the pre-existing `remove` handler
itself — sits behind `if (data_submitted() && confirm_sesskey())`, and Moodle's
`data_submitted()` only ever returns truthy for a POST request, never GET. Clicking the
button would reload the page with those params sitting harmlessly in the URL and the
question would still be there — no error, just nothing happening, exactly the kind of
"looks fine until someone clicks it" bug a no-time-to-test deploy is most exposed to.
Every other mutating action on this same page already used a small inline POST form
(see the existing bulk-remove/reorder buttons) — this was the one place that pattern
wasn't followed. Fixed to match: a tiny inline `<form method="post">` with the same
hidden fields, submitted via its own button.

Also ran a full `/security-review` of the same v2.0.189-191 diff — every new SQL query
either uses parameterized placeholders or interpolates only trusted internal IDs
(`$instance->id` derived from an already-capability-checked course module, never raw
request input); the new `qtype` field on `get_question`/`get_all_questions` WS responses
only ever describes the requesting student's own current question (unaffected by the
pre-existing `validate_attempt_owner()` check). No vulnerabilities found.

No schema change — logic-only fix.

## v2.0.191 — relaxed head-down/keyboard-noise scoring on Essay/Choice Essay, autosave now backs into the background-retry queue on failure (2026-09-13)

Direct request, made while thinking through Choice Essay's concurrent-deployment
readiness: **"student will be typing and they will gaze away or down while typing and
will also make noise from the keyboard while typing. All these should be considered
and not taken as been flagged."** Confirmed via clarifying questions before building:
(1) relax scope only while the student is actually ON an Essay/Choice Essay question,
reverting to normal strictness on any other question type in the same assessment; (2)
keep logging these events (visible to a proctor reviewing the attempt, same treatment
gaze-away already had) but stop them contributing to the risk score while writing.

**Investigated the actual detection code first, not assumed**: `gaze_away` already
scores 0 (informational only — no fix needed there). Two real, scored risks found:
- **`head_down`** fires after ~40 seconds of continuous downward head pose (5
  consecutive 8s-interval checks) and costs real points (default 5) — a student
  composing a long essay glancing at their hands/keyboard for 40+ seconds is completely
  normal, unlike MCQ where there's no reason to look away from the screen at all.
- **`audio_clip_started`** (sustained keyboard/background noise above the configured
  threshold for just ~2 seconds) also costs real points (default 5) and can refire
  roughly every 10-12 seconds while noise stays sustained — meaning continuous typing
  during a long essay response could rack up repeated penalties for the ENTIRE time
  spent writing, a risk MCQ never creates since there's no ongoing typing.

**Fixed by teaching the client which question type is currently on screen**:
`attempt_manager::get_question_html()`/`get_all_questions_html()` now also return each
slot's `qtype` (new field on the existing `mod_learnguard_get_question`/
`get_all_questions` WS responses — additive, no schema change, no behavior change for
any existing consumer). `assessment.js` tracks this as `state.currentqtype`, updated at
every point a question is rendered (fresh fetch, cache hit, and both preload paths) —
new `lgIsEssayQtype()` returns true for `essay`/`choiceessay`. Both `head_down` and
`audio_clip_started` now compute their score as 0 whenever `lgIsEssayQtype(state.currentqtype)`
is true, while still calling `logProctoringEvent()` exactly as before otherwise — the
event is still logged and visible on `attemptdetail.php`'s Proctoring Events table for
a proctor to review, it just no longer nudges the risk score or pushes toward
auto-suspension while the student is legitimately writing.

**Separately, per direct request to make sure a Choice Essay attempt is never lost**:
confirmed the periodic autosave (`startAutosave()`) already runs every 5 seconds —
more frequent than the requested 10s — and already covers Choice Essay's answer (its
hidden JSON field is a real form element `collectCurrentFormData()` picks up like any
other). The real gap: a failed autosave call was silently swallowed
(`.catch(function(){})`) with **no retry at all**, unlike `submitCurrentAnswer()`'s own
failure path, which already queues into the existing background-retry mechanism
(`state.unsavedAnswers` + `startUnsavedAnswerFlush()`, retries every 12s indefinitely).
A failed periodic autosave now queues into that exact same mechanism instead of being
dropped — so a long essay response being composed over many minutes can no longer
silently lose ground to a single transient save failure; it just keeps retrying quietly
in the background until it succeeds, exactly like an explicit Save & Next already does.

No schema change — no new upgrade.php savepoint needed.

## v2.0.190 / block_learnguard v2.0.2 — Choice Essay's own tab, assessment-wide settings, block fixes (2026-09-13)

Closes out the remaining items from the large consolidated feedback batch (items 1-7),
per design decisions confirmed with Michael beforehand:

**New "🗂 Choice Essay" tab on `editquestions.php`** — direct answer to "there's nothing
addressing how the choice essay will be deployed or set here... i think we should have
another tab for Choice Essay to avoid any issue with the other question types there."
This is now the ONE place a Choice Essay question is created, bulk-imported, configured,
and removed for an assessment — confirmed via clarifying questions: Choice Essay
questions are excluded entirely from the generic "🏦 Question Bank" picker and "📋 Add
Entire Category" bulk-add (both now filter `qtype != 'choiceessay'`), so they can't be
mixed in with MCQ/Essay/etc from those tabs any more. The existing "📋 Fixed Questions"
tab (marks/randomize/reorder) is left untouched and still lists every slotted question
including Choice Essay, since its total-marks and randomize-fairness math already
correctly depends on the full slot set — narrowing that specific view was judged too
risky to the existing scoring math for this batch and is flagged as a possible follow-up
if Michael still wants it fully hidden there too.

**"Prompts shown per student" / "word limit" / "instructions" are now set ONCE per
ASSESSMENT, not per question** — confirmed via clarifying question: existing
per-question values are auto-migrated (upgrade savepoint `2026090160` seeds the new
`learnguard.ce_prompts_count`/`ce_word_limit`/`ce_instructions` from the first
already-configured Choice Essay question in each assessment, so nothing looks
blank/reset). The 3 fields are removed from `questionedit.php`'s per-question Choice
Essay form (replaced with a note pointing at the new tab) — architecturally, the
underlying per-question `learnguard_qbank` columns still exist and are still what every
renderer/grader actually reads at runtime (`question_engine.php`, `grader.php`,
`gradeessays.php`, `grader_user_report.php`), so none of that call chain needed to
change. New `learnguard_sync_ce_settings_to_questions()` in `lib.php` stamps the
assessment-level values onto every Choice Essay question's `learnguard_qbank` row
whenever they're saved on the new tab, or whenever a Choice Essay question is newly
added/imported into the assessment — keeping the per-question columns and the one
assessment-level source of truth always in sync with zero risk to the existing
rendering/grading code.

**"Shuffle answers" checkbox hidden for Choice Essay** — "we dont need shuffle answers
in choice essay as we dont have answers coming with the questions." The shared
Category/Marks/Shuffle/Mandatory row on `questionedit.php` now hides the Shuffle
checkbox via the existing `showTypeUI()` type-switch JS when Choice Essay is selected
(the underlying `shuffleanswers` column is simply never read for this qtype anywhere
already, so hiding the control is sufficient — no data-layer change needed).

**Choice Essay bulk import — turned out to already exist, just not reachable**: tracing
"we need to change how the bulk import works for choice essay, it should work in
similar way to the aiken bulk import" found that `questionimport.php` already has a
fully-working paste-and-preview Choice Essay parser/importer (`lg_parse_choiceessay()`,
`lg_render_preview_q()`) — it was simply never listed as an importable format on
`editquestions.php`'s Import tab, so it was undiscoverable. Confirmed via clarifying
question the paste format (blank-line-separated prompt paragraphs, `===` between
questions) rather than inventing a new one. Now surfaced directly on the new Choice
Essay tab as "📥 Open Choice Essay Bulk Importer". Also fixed a real functional gap
found while wiring this up: `lg_insert_parsed_q()` only ever inserted into the shared
question bank — every OTHER imported type still needs a separate manual "add to
assessment" step afterward, but Choice Essay no longer has anywhere else to do that
from (it's excluded from the generic Question Bank tab now) — so importing a Choice
Essay question directly into an assessment context now also auto-slots it into that
assessment immediately and syncs the current assessment-level settings onto it, with
the post-import redirect landing back on the new tab so the result is immediately
visible.

**block_learnguard fixes**:
- Course selector dropdown now shows the full course name instead of shortname
  (`c.fullname`/`c.shortname` swap in the `<option>` label) — "let the full course name
  be the one showing and not course short name."
- **Real bug fixed — an MCQ-only (fully auto-graded) assessment permanently
  misclassified every finished attempt as "Requires Grading."** Root cause: the
  "needs grading" count was based purely on whether a `learnguard_essay_grades` row
  existed for an attempt — but an assessment with zero essay/choiceessay questions can
  never have any such row, essay or not, since there's nothing to manually grade. New
  `hasgradable` subquery (checks whether the activity has ANY essay/choiceessay slot at
  all) now gates the calculation: an activity with no manually-gradable content treats
  every finished attempt as already Graded, matching "an assessment is multiple choice
  and has been graded automatically so it should move to graded and not the requires
  grading."
- **Investigated item 7 ("hide Proctoring section for a user with no proctor access,
  same for grader") — confirmed already correct, no bug found.** Both sections are
  already gated per-course on whether that course actually has any grader/proctor rows
  for this user (`if (!empty($cGraderRows))` / `if (!empty($cProctorRows))`); the
  proctor query only ever matches an explicit `learnguard_proctors` assignment in any of
  its 3 scopes, with no fallback that could pull a grader-only user into it. Fixed a
  stale, inaccurate code comment above the proctor query that claimed graders were also
  shown there (they never were, per the actual SQL) — left as documentation only, no
  behavior change.

Schema: new `ce_prompts_count`/`ce_word_limit`/`ce_instructions` columns on
`learnguard`, with an auto-migration pass — upgrade savepoint `2026090160`.

## v2.0.189 — root-caused the recurring "suspended below threshold" bug, epoch-date display fix, 2 auto-continue timers (2026-09-13)

**Explicit instruction: "stop patching, fix afresh from scratch"** — Michael reported
students still being flagged/suspended at scores below their assessment's configured
threshold, despite this being "fixed multiple times" before (v2.0.140/v2.0.141).
Re-verified the threshold math itself first rather than assuming it was still wrong:
`attempt_manager::get_effective_autosuspend_score()` and `log_event()`'s
`if ($newscore >= $autosuspend)` enforcement are both internally correct — confirmed by
re-reading them fresh, not by memory of prior fixes.

**Real root cause: a stale-checkbox bulk-selection leak in `assessmentmanager.php`,
never fully closed by either prior attempt.** Student rows are hidden/shown via inline
`tr.style.display` toggling as search/filter/pagination changes — rows are never removed
from the DOM. A checkbox ticked while its row is visible under one filter stays
`checked` even after a later, unrelated filter change hides that row. v2.0.141 taught
the header "Select All" checkbox to respect visibility, but the actual
submit/collection side of every bulk-action button — Save All Overrides
(`lgamChunkedSave`), Grant Reattempt (`lgamSubmitReattempt`), Grant Continue & Review
(`lgamSubmitReview`), and the bulk-fill button (`lgamApplyBulk`) — never got the same
treatment, across either prior patch. A student ticked under one filter could be
silently swept into a LATER, completely unrelated bulk action after the admin filtered
to a different group — most plausibly an accidental Continue & Review grant, which
forces the much stricter `review_autosuspend_score` (default 50) instead of the
activity's own real threshold (e.g. 150), with nothing on screen suggesting the review
was ever granted.

**Fixed with one shared source of truth, not another narrow patch**: new
`lgamGetVisibleCheckedRows()` (checks both `.lgam-chk:checked` AND that the row's
`tr.style.display !== 'none'`) is now the only way any of these four functions decides
"which students are actually selected right now." `lgamChunkedSave()` keeps its
existing all-rows fallback for "Apply to All Users" unchanged; `lgamApplyBulk()`'s
"selected" mode now iterates and counts only visible-checked rows instead of every
checked box regardless of visibility.

**Also fixed — "1/01/70 01:00" (Unix epoch) shown for some suspended-attempt rows**:
`proctorview.php`'s High Risk and Suspended tab lists both displayed `a.timestart` via
`userdate()` with no guard for `timestart = 0` — the legitimate "not started yet" state
introduced in v2.0.167 (an attempt can be suspended, e.g. during an unresolved break or
before Q1 ever loaded, while its exam clock never actually started). Both now show "—"
instead of the epoch date when `timestart = 0`; the existing Suspension History tab was
already correctly guarded (only queries rows with `time_last_suspended > 0`).

**Two auto-continue timers added, per direct request, to popups that previously
required a manual click with no fallback**: the "Check-in Required" popup now
auto-continues after 5 seconds if the student clicks nothing; the "Integrity Warning"
halfway-threshold popup now auto-dismisses after 10 seconds. Both show a live countdown
in the button label, reuse the exact same continue/dismiss function on manual click and
on timeout, and clear their `setInterval` on every exit path so no stray timer is left
running.

No schema change — no new upgrade.php savepoint needed for this version.

## v2.0.188 — Distribution: closed a real global-grader gap, Layer-2 exclusivity now admin-optional (2026-09-12)

Direct statement of intent: **"no two grader should grade same assessment graded by
other grader, so each grader get different attempts under distribution."** Traced the
existing distribution code (Split/Random modes in `learnguard_distribute_grading()`) —
it already gave each per-activity Layer-1 grader an exclusive, non-overlapping set. The
real gap: a **Global Grader** (added via Site Admin → Graders → Global Graders, not
assigned to any specific activity) was never included in that distribution run at all —
they still saw and could grade every finished attempt on a distributed assessment,
including ones already exclusively assigned to a specific Layer-1 grader, silently
defeating the whole point. Confirmed and fixed as an unconditional correctness fix, not
an option.

Separately asked whether Layer-2 graders (existing, intentional second-pass reviewers
who see everything by design) should also become exclusive — answered **"admin
decide"**, so this is now a per-activity toggle rather than a fixed behaviour change.

**Fixed everywhere distribution can be triggered from**:
- `lib.php`'s `learnguard_distribute_grading()` (used by `admin.php`'s "Grading
  Distribution" section) now folds every Global Grader into the same Layer-1 split/
  random pool as the activity's own Layer-1 graders, de-duped by userid. A new
  `learnguard_distribute_pool()` helper is shared by this pass and an independent,
  optional Layer-2 pass (its own fresh split of the same attempts, no overlap among
  Layer-2 graders either) — gated by a new "Also split Layer-2 graders exclusively"
  checkbox on `admin.php`'s distribution form (off by default — unchanged behaviour
  unless explicitly turned on).
- `siteadmin.php`'s separate, simpler site-wide distribution tool (`distribsitegrader`)
  had the identical global-grader gap in its own independent query — fixed there too.
- `grader.php`'s `$distributed` check (which actually restricts what a grader's Grade
  view shows) now also applies to Layer-2 graders when `include_layer2` is on for that
  activity; unchanged for Layer 1 and for the default (Layer-2-sees-everything) case.
- `admin.php`'s distribution summary now lists Global Graders' assigned counts too
  (previously silently omitted, since it only ever looked at per-activity Layer-1 rows),
  with a note when Layer-2 exclusivity is active.

Schema: new `include_layer2` field on `learnguard_grading_config` — upgrade savepoint
`2026090157`.

## v2.0.187 — pre-launch QA pass on the rubric feature: 2 real gaps found and fixed (2026-09-12)

Asked directly for a detailed QA check before going live, right after the rubric
feature shipped. Traced the multi-grader and failure-mode paths specifically, since
those are the two areas a large new feature built in one pass is most likely to have
missed:

**Multi-grader rubric display could show a mixed-up breakdown**: `grader.php`'s
"show the existing scores when re-opening a question" logic built its `anyScores`
map (used when someone OTHER than the current grader already graded it) by looping
every score row with no ordering and no grouping by grader — for a Choice Essay
question graded by two different graders (a real, existing pattern in this codebase:
Layer 1/Layer 2 collaborative grading), each criterion would show whichever grader's
row the database happened to return last, silently mixing one grader's score on
"Analysis" with a different grader's score on "Structure" as if it were one coherent
assessment. Fixed to identify the single most-recently-active OTHER grader first, then
show only their complete, coherent set — mirroring exactly how the pre-existing plain
single-mark "anyGrade" logic already worked (one grader's whole judgement, not a
per-field blend).

**Missing-table crashes hardened, matching this file's own established pattern**: a
missing column (e.g. `rubric_enabled`) always degrades safely to `false`/null in PHP,
but a genuinely missing TABLE (`learnguard_rubric_criteria`/`_rubric_scores` — possible
only if an upgrade was somehow interrupted between adding the column and creating the
tables) throws outright. Three of this feature's new table lookups had no guard against
that: `question_engine.php`'s rubric preview (runs on every live exam question load —
could have broken the exam itself, not just the preview), `view.php`'s rubric payload
(same page every student loads to start an attempt), and `mod_form.php`'s criteria
list/count (the activity settings form itself — a crash there would block creating or
editing ANY LearnGuard activity, not just rubric-related settings). All three now
wrapped in try/catch, degrading to "no rubric" instead of a fatal error — the same
defensive pattern already used elsewhere in this codebase for
`learnguard_grader_units`/`learnguard_grading_units` (including one pre-existing,
unrelated gap of the same kind found and fixed in `mod_form.php`'s pathway feature
while checking this).

No schema change.

## v2.0.186 — Choice Essay grading rubric (2026-09-12)

Direct request, with a real rubric example supplied for reference only — confirmed
explicitly that nothing about the example (criteria names, weights, count) is
hardcoded; Michael builds his own list per assessment. Three design decisions
confirmed before building: (1) one rubric is defined per ASSESSMENT, shared by every
Choice Essay question in it — not per-question; (2) a grader enters points directly
against each criterion's own weight (e.g. a box accepting 0–25 for a 25%-weighted
criterion), summed into the question's final mark; (3) the rubric preview is shown to
students both on the pre-exam instructions screen and again alongside the answer box
while they're actually writing, when the admin has it turned on (default on, can be
disabled) — the grader never sees a computed running total unless the admin separately
turns that on too (default off).

**New "Choice Essay Grading Rubric" section on the activity settings form**: enable
checkbox, "show to students" toggle (default on), "show total to grader" toggle
(default off), and a `repeat_elements()`-based dynamic list — Criterion name / Weight %
/ Assessment focus per row, add/remove as needed. Validated at save time: weights must
add up to 100, and at least one criterion must exist if the rubric is enabled.

**Grading (`grader.php`)**: a Choice Essay question in a rubric-enabled assessment
swaps the single mark box for one score input per criterion (each capped, both
client- and server-side, at that criterion's own weight — never trusts the client).
The sum, scaled to the question's actual maxmark, is written to the exact same
`learnguard_essay_grades.grade` column a plain single-mark save would use — every
downstream calculation (`calculate_total_grade`, `has_ungraded_essays`, Score Matrix,
gradebook push) keeps working completely unchanged, since this only changes how that
one number gets arrived at, not where it lives. Per-criterion scores are additionally
kept in a new `learnguard_rubric_scores` table so the breakdown itself is never lost.

**Consistency guards, learned from the Grading Units restriction work earlier today**:
`gradeessays.php` — a separate, independently-linked grading page — now refuses to show
a plain score box for a rubric-graded Choice Essay question at all (a flat score there
would silently blow away the per-criterion breakdown with no record of how it was
reached); it points to the Grader interface instead. The "Reset Essay Grades" tool
(`grader.php` and `scores.php`) now also clears `learnguard_rubric_scores`, not just
`learnguard_essay_grades`, so a reset is a genuine clean slate.

**Student-facing preview**: `view.php` only ever sends the rubric to the client when
both `rubric_enabled` and `rubric_show_learner` are on (grading itself always enforces
`rubric_enabled` server-side regardless of this flag). Rendered on the instructions
screen (`assessment.js`) and, per the "both" answer, again as a collapsible
"📋 Grading Rubric" block next to the answer box on the Choice Essay question itself
(`question_engine.php`), so it stays visible for reference while actually writing.

Schema: `rubric_enabled`/`rubric_show_learner`/`rubric_show_total_grader` on
`learnguard`; new `learnguard_rubric_criteria` (the admin-built criteria list) and
`learnguard_rubric_scores` (per-criterion grader scores) tables — upgrade savepoint
`2026090155`.

## block_learnguard v2.0.1 — Grading Units-aware queue (2026-09-12)

Asked directly whether the block needed updating so restricted graders "can grade
easily without issues" — it did. `get_content()`'s Grading Queue had zero awareness the
Grading Units restriction feature (v2.0.180) existed: a grader restricted to one unit
still saw every activity they're assigned to grade, with a "Requires Grading" count that
included every essay/choiceessay question site-wide, not just theirs. `grader.php` itself
was already fully correct here — only this dashboard widget hadn't been taught about it.

**Fixed, without touching the unrestricted (default) path at all**: for a non-admin
grader who has rows in `learnguard_grader_units`, the queue query now only lists an
activity at all when it has at least one gradable slot in one of their allowed units
(essay via `q.unitid` directly; Choice Essay via an `EXISTS` against `ce_prompts.unitid`
for that question) — an activity entirely outside their units no longer clutters their
dashboard. A visible "🎯 You're restricted to: X, Y" note also now shows above their
queue, with an honest caveat that the numeric counts are activity-wide (Choice Essay's
unit is only knowable per-response, after decoding which prompt a specific student
chose — too expensive to resolve exactly for a sidebar widget re-rendered on every page
load) and the exact list is what `grader.php`'s own Grade view shows. A grader with NO
rows in `learnguard_grader_units` (today's default, unchanged) sees exactly what they
always have — the new SQL only ever narrows, never adds anything to the query.

No schema change — reads the existing `learnguard_grader_units`/`learnguard_grading_units`
tables from the Grading Units feature.

## v2.0.185 — admin.php Proctors search: same fix as the Grader search (2026-09-12)

Confirmed with Michael, who agreed to fix it: `admin.php`'s "Assign Proctors" live search
had the identical bug just fixed in siteadmin.php's Grader search — calling Moodle
core's `/user/selector/search.php` without the `class`/`file` params it needs, silently
falling back to "type it and press Enter" every time (existing `catch` block already
swallowing the failure). Ported the same fix: a new `ajaxusersearch` GET action on the
Proctors tab that queries `{user}` directly (name/username/email, capped at 15 results)
instead of depending on a core endpoint whose contract this plugin doesn't control.

`_lg_work/block_learnguard/` confirmed untouched this whole session (checked
`git status`/`git log` on that path directly) — no block ZIP rebuild needed per the
standing rule.

No schema change.

## v2.0.184 — pre-launch QA pass on v2.0.180-183: 3 real gaps found and fixed (2026-09-12)

Asked directly for a detailed QA check before going live. Re-read every new code path
from today's builds (Grading Units, grader search, word limit, anti-cheat, pathway
selection) end-to-end rather than re-running only syntax checks. Found and fixed:

**The grader live-search almost certainly never actually searched anything.** The
pattern copied from `admin.php`'s existing Proctors search — calling Moodle core's
`/user/selector/search.php?sesskey=..&search=..&context=..` directly — is missing the
`class`/`file` parameters that endpoint needs to know WHICH selector subclass (and
therefore which pool of users, under what permissions) to search; without them it has no
way to run at all. The existing code's own `catch` block ("Could not search — type a
full email/username and press Enter") was already silently swallowing this failure,
which is very likely why the Proctors search has quietly never shown live suggestions —
plain typing still worked as the fallback, so nothing looked broken. Rather than ship
the identical, likely-nonfunctional pattern for the new Grader search, built a genuine
self-contained search: a new `ajaxusersearch` GET action on `siteadmin.php`'s Graders
tab that queries `{user}` directly (name/username/email, `LIKE`, capped at 15 results)
and returns JSON — no dependency on a core endpoint this plugin doesn't control the
contract of. (The pre-existing Proctors search on `admin.php` was left as-is — not
touched today, flagged here as a known likely-non-functional feature Michael may want
fixed separately.)

**Deleting a Grading Unit didn't clean up the new pathway "other units" pool** —
`deletegradingunit`'s existing cleanup (un-assign questions/prompts, remove grader
restrictions) was written before `learnguard_pathway_other_units` existed; a deleted
unit could be left as a dangling reference in an activity's pathway configuration. Now
cleaned up in the same handler, alongside everything else it already un-assigns.

**Added mod_form.php validation**: enabling the pathway feature with both "questions
from chosen unit" and "questions from other units" left at 0 would let a student pick a
pathway and then hit "no questions available" live during an exam — now caught as a
form validation error at save time instead.

**Confirmed correct, no change needed**: `attempt_manager::start_or_resume_locked()`'s
pathway branch is fully gated behind `pathway_enabled` and returns before ever touching
the existing fixed-slot/pool-rules code — traced through line by line to confirm
`pathway_enabled=0` (every existing activity, unchanged) produces byte-identical
behaviour to before this feature existed. The "Select Your Pathway" screen is only ever
shown via `launchAssessment()` (gated on `!cfg.attemptid`, i.e. never on a resume) —
confirmed no other call site could reach it inappropriately. Every new schema element
added via `db/upgrade.php` this session is also present in `db/install.xml`, so a fresh
install and a fully-upgraded site end up with identical schema.

**Told to Michael directly, not a code fix**: for the pathway feature to have anything
to draw from, Choice Essay questions still need to be added to the assessment the normal
way (Question Bank → Add to Assessment) — pathway mode draws its pool from those
already-assigned questions, filtered by unit, rather than needing a separate
assignment step. Also: toggling pathway mode OFF on an activity that already has
choiceessay questions assigned this way will make ALL of them render as a normal
fixed-slot exam (expected, not a bug) — worth remembering before flipping it off.

No schema change.

## v2.0.183 — Pathway / role-based question selection (Choice Essay only) (2026-09-12)

Direct request: **"is it possible that for an assessment, we have a setting where the
user select the role (this will be from the grading unit selected) and then the system
will produce N number of questions from that unit and N number of questions from other
units... this is only for assessment with choice essay questions only... note that the
timer wont start yet too until the question they select shows on the screen."** Three
design decisions confirmed before building: (1) two separate admin-set numbers — one for
the chosen unit, one for the combined "other units" pool, not one shared N; (2) the admin
explicitly picks which units count as the "other units" pool, not automatically every
remaining unit; (3) since a Choice Essay question's unit is technically tagged per-PROMPT
(a question could in theory mix prompts across units), a question only counts toward a
unit's pathway pool when EVERY one of its prompts shares that same unit — a mixed-unit
question is simply never drawn by this feature, rather than guessing via "majority wins".

**New "Pathway / Role-Based Selection" section on the activity settings form**
(`mod_form.php`): enable checkbox, "questions from chosen unit" (N), "questions from
other units pool, combined" (M), and a multi-select of which Grading Units count as that
"other units" pool. Deliberately a separate mode from the existing randomize/pool-rules
settings — mutually exclusive with them, only ever applies to Choice Essay questions.

**Exam flow**: a brand-new attempt (never a page-reload resume — that reuses the same
already-drawn slots) on a pathway-enabled activity shows a **"Select Your Pathway"**
screen — listing every eligible unit — before the attempt is even created, matching the
exact request that the exam clock must not start until the chosen questions are on
screen (the existing v2.0.167 clock-deferral architecture already didn't start the clock
until content is ready; this just adds one more step before that point, so the guarantee
carries through unchanged). Picking a pathway calls `mod_learnguard_start_attempt` with
the chosen unit id, which the server independently re-validates against the same
eligible-unit list rather than trusting the client.

**Question draw** (`attempt_manager::start_or_resume_locked()`, new pathway-only branch):
draws N Choice Essay questions from the chosen unit and M from the union of the
admin-selected "other units" pool (excluding the chosen unit), using the exact same
least-used-first fairness algorithm the existing multi-bank pool-rules draw already uses
(new shared `draw_fair_questions()` helper) — so pathway questions get the same fair
distribution guarantee across students that the rest of the codebase already provides.
Drawn questions become ordinary pool-style slots (`learnguard_slots` with `attemptid` set
to this attempt, `learnguard_attempt_questions` rows) — the same pattern the existing
pool-rules draw uses, so every existing response/grading/grader-unit-restriction code
path keeps working with zero changes.

Schema: `pathway_enabled`/`pathway_own_count`/`pathway_other_count` on `learnguard`;
`pathway_unitid` on `learnguard_attempts` (which unit the student picked); new
`learnguard_pathway_other_units` table (the admin-selected "other units" pool per
activity) — upgrade savepoint `2026090152`.

## v2.0.182 — grader user search, Choice Essay word limit, anti-cheat answer box (2026-09-12)

Three separate requests bundled into one build session:

**Live name/email search on "Add Grader to this Assessment"** — the field on
`siteadmin.php`'s Graders tab (screenshot supplied) required typing an exact email or
username with no suggestions. Ported the same live-search pattern `admin.php`'s Proctors
section already uses successfully (`/user/selector/search.php`, a genuine Moodle core
endpoint): a visible search box shows up to 15 matching name/email/username results as
you type (debounced 300ms), clicking one fills the hidden field the form actually
submits; typing a full value and pressing Enter still works directly, matching the
existing fallback behaviour. **Confirmed (no change needed)**: assigning a grader
already emails them (`learnguard_send_email()` in the `addsitegrader`/`addsitegrader_multi`
handler), gated by the existing "Send Proctor Notifications" setting
(`learnguard/notify_proctors_enabled`, default ON) — this already covers proctor AND
grader assignment per its own description.

**Choice Essay word limit**: new "Word limit for the student's answer?" field on the
Choice Essay question form (0 = no limit, matches the existing "Show how many prompts"
field's style). Once exceeded, the live word counter turns red and appends "⚠️ Exceeds
word limit" — client-side only, and deliberately does not block typing or Save & Next
("flags... and informs them", not "prevents it", per the exact request). The same flag
is recomputed live (never trusted from what the client last showed) and surfaced to
graders on `grader.php`, `gradeessays.php`, and `grader_user_report.php`, and to the
student on their own post-submission review screen — all four read a single new shared
`question_engine::count_words()` helper against the question's `word_limit`, so the flag
can never silently disagree between the answer box, the grader, and the review screen.

**Answer box hardened against paste, right-click, and grammar-checking tools** — applied
to BOTH Essay and Choice Essay, since both share the same rich-text answer box
component (`render_richtext_box()`/`render_richtext_sync_script()`):
- **Paste is now actually blocked**, not just scored. The existing site-wide clipboard
  handler (`initClipboard()` in `assessment.js`) only ever logged a `clipboard_paste`
  violation — it never called `preventDefault()`, so pasting itself still worked. The
  answer box now has its own `paste`/`drop`/`dragover` listeners that call
  `preventDefault()` (the exact pattern already used for the pre-exam keystroke-baseline
  typing test), so a paste attempt is both impossible AND still scored as a violation by
  the existing site-wide listener — belt and suspenders, not a replacement.
- **Right-click on the answer box is disabled** — `initFullscreen()`'s existing global
  `contextmenu` blocker already covered this while proctoring is active; the answer box
  now also blocks it directly as its own listener, so it stays disabled even in the rare
  case the global block doesn't apply (e.g. during precheck/review, where the answer box
  can still render but proctoring isn't "active" yet).
- **Spellcheck/autocorrect/autocapitalize disabled** (`spellcheck="false"
  autocorrect="off" autocapitalize="off"`) — no red squiggly underlines or OS-level
  suggestions on the answer box.
- **Grammarly (and similar grammar-checking extensions) disabled on this specific
  field** via `data-gramm="false" data-gramm_editor="false" data-enable-grammarly="false"`
  — these are Grammarly's own documented opt-out attributes; the extension checks for
  them and skips injecting its floating icon/suggestions into a field that carries them,
  rather than something LearnGuard has to fight against after the fact.

Schema: new `word_limit` field on `learnguard_qbank` (0=no limit) — upgrade savepoint
`2026090151`.

## v2.0.181 — cross-check audit of v2.0.180: 5 real gaps found and fixed (2026-09-12)

Asked directly to cross-check the Grading Units feature for anything missed or built
partially. Audited every place in the codebase that still says `qtype = 'essay'` on its
own (choiceessay is manually graded exactly the same way, so any such place is a
candidate for the same gap already fixed twice in v2.0.180) — found 5 more, some
independent of Grading Units entirely and dangerous in their own right:

**Most serious — `attempt_manager::finish()` could publish a score before any human
graded a Choice Essay answer**: the check that decides whether to leave `sumgrades`
null pending manual grading only ever looked for `qtype = 'essay'`. An assessment built
entirely from Choice Essay questions (no plain Essay) would get its score computed and
pushed to the gradebook the INSTANT the student clicked finish — before anyone ever
graded it. This is upstream of every other essay-pending check in the codebase; fixing
it here closes the gap at its root rather than only at each display surface.

**`gradeessays.php` — a real, separate, actively-linked grading page with none of
v2.0.180's protections**: reachable from `admin.php`'s "✍️ Grade Essays Now" button,
`attemptdetail.php`'s "📝 Grade Essays" button, and `proctordashboard.php`'s "📝 Grade"
button — a genuine parallel entry point to `grader.php`, not a leftover. It had zero
Choice Essay support (query only selected `qtype = 'essay'`) and, worse, **completely
bypassed the new grading-unit restriction** — a grader restricted to "Strategy" on
`grader.php` could simply use this page instead and grade/see every essay question
site-wide, defeating the whole point of the feature. Now: queries both types, decodes
and displays the Choice Essay prompt+answer the same way `grader.php` does, filters out
restricted-unit rows before rendering, and independently re-validates the restriction in
the save handler (defense in depth, same pattern as `grader.php`'s two AJAX handlers).
Also found and fixed a second, independent bug on this same page while adding Choice
Essay support: unlike `grader.php`/`scores.php`, it pushed `sumgrades` to the gradebook
unconditionally after any grade save, with no check for other still-ungraded essay
slots on the same attempt — fixed with the same `has_ungraded_essays()` guard used
everywhere else.

**`grader_user_report.php` — the read-only "📄 View All Attempts" report leaked
restricted content**: reachable independently of `grader.php` (linked from the grader
row), it showed a student's full essay/Choice-Essay answer text regardless of any unit
restriction — a restricted grader couldn't grade a Compliance question on `grader.php`
but could still read it in full here. Now applies the identical resolve-and-skip logic
(plain Essay via `q.unitid`, Choice Essay via the chosen prompt's `ce_prompts.unitid`)
before a slot is added to the report.

**`scorematrix.php` and `scores.php` — the live "is this score actually ready to show"
CASE WHEN only checked for `qtype = 'essay'`**: independent of `has_ungraded_essays()`
(which was already fixed for Choice Essay in v2.0.180) — these two pages compute their
own pending/hidden-score logic directly in SQL and had the identical unfixed gap. A
Choice-Essay-containing attempt could show a final score on Score Matrix or the Scores
page while that Choice Essay answer still sat ungraded. Fixed both to check
`qtype IN ('essay', 'choiceessay')`, same as everywhere else. `scores.php`'s and
`grader.php`'s "Reset Essay Grades" admin tool had the same narrower filter (would
silently skip resetting Choice Essay grades) — fixed for consistency.

**Also fixed, cosmetic**: `admin.php`'s "no essay questions" info notice on the Graders
tab showed even when an assessment had only Choice Essay questions (misleading, though
the actual grader-assignment UI and "Grade Essays Now" button were never gated by this
count, so nothing was ever functionally hidden) — now checks both types.

No schema change.

## v2.0.180 — Grading Units: restrict a grader to specific subject-matter questions (2026-09-11)

Direct follow-up to the v2.0.179 confirmed gap: **"i need a way to seperate the questions
so that its easy to assign to graders... so a/some graders will grade questions related to
Strategy and the grader will not see questions from compliance (this is based on the one
the user answer)... let me know how you plan to implement and build this and let me choose
approaches."** Presented two design decisions before writing any code; Michael chose:
(1) a brand-new "Grading Unit" concept, entirely separate from the existing question-bank
Categories (those drive random pool draws/per-section timers — an unrelated concern) —
**"option 1 but i will be the one to give it name that i want and no hardcoding"**;
(2) an attempt-centric filtered grading view where restricted questions are fully hidden
(not just greyed out) — **"option 1 above, the anonymous grading also works here"**,
explicitly requiring anonymous grading to keep working.

**New "🎯 Grading Units" tab on `siteadmin.php`**: admin-managed list — add, rename, delete.
Deleting a unit un-assigns it from any question/prompt/grader that referenced it rather
than leaving a dangling reference.

**New "Restrict Graders to Grading Units" section on the existing Graders tab**: one row
per grader (union of every global/per-assessment grader) with a checkbox per unit — tick
to restrict, leave unticked for no restriction (today's unchanged default). **Keyed by
userid, not a specific `learnguard_graders` row**, per Michael's own framing — one
person's restriction applies across every assignment they hold, not per-assignment.

**Unit assignment UI**: a "Grading Unit" dropdown on the Essay question form in
`questionedit.php` (→ `learnguard_qbank.unitid`), and a per-prompt dropdown on each Choice
Essay prompt row (→ `learnguard_ce_prompts.unitid`) — different prompts under one Choice
Essay question can belong to different subjects, so the unit has to live on the prompt,
not the parent question.

**`grader.php` — three real fixes, not just the new restriction**:
1. **Choice Essay now actually appears in the grading interface** — its qtype was missing
   from every whitelist query on this page (confirmed pre-existing gap from v2.0.179).
   Added throughout: slot queries, the AJAX `get_question` handler (now shows which prompt
   the student chose plus their written answer), and the "last graded by" pill subtitle.
2. **`has_ungraded_essays()` (`classes/question_engine.php`) only ever checked `qtype =
   'essay'`** — a Choice Essay answer could never actually block score publication or a
   gradebook push, regardless of grading state. Fixed to check both `essay` and
   `choiceessay` — a real scoring-correctness bug, fixed independently of whether any
   grader is ever unit-restricted.
3. **A Choice Essay response's own JSON blob (`{promptid,text,shown}`) is never a literally
   empty string**, even unanswered — a naive "any non-empty value" check (used for the
   "RG"/"NA" pill) would misreport every unanswered Choice Essay slot as answered. Fixed
   with a qtype-aware check that decodes the blob and tests the real `text` field.

**Unit resolution differs by question type**: a plain Essay's unit reads directly off
`learnguard_qbank.unitid`; a Choice Essay's unit can only be known AFTER the student
answers — decoded from that response's `promptid`, looked up on
`learnguard_ce_prompts.unitid`. Both are batch-resolved once into `$resolvedUnitMap` and
applied as a single pruning pass over the already-built per-attempt slot/status/score
maps, so every downstream use on the page (pills, tab bucketing, per-attempt grade totals,
the client-side nav-pill fallback) respects the restriction automatically with no separate
filtering logic per place. **A question/prompt with no unit assigned (unitid=0) stays
visible to every grader** — only explicitly-tagged items are ever filtered, so nothing
changes for anyone until units are created and a grader is actually restricted. The AJAX
`get_question` handler independently re-checks the restriction server-side too (defense in
depth — a restricted grader could otherwise still reach a hidden slot directly by slotid).

**Anonymous grading confirmed unaffected**: the restriction only prunes which slots a
grader's view contains — it never touches the anonymous-mode identity/avatar logic, which
reads from the same (now-pruned) maps exactly as before.

Schema: new `learnguard_grading_units` (id, name, sortorder) and `learnguard_grader_units`
(userid, unitid) tables, plus `unitid` on `learnguard_qbank` and `learnguard_ce_prompts` —
upgrade savepoint `2026090149`.

## v2.0.179 — Essay/Choice Essay: word count, formatting toolbar, custom instructions, scrollable box (2026-09-11)

**Context**: "Choice Essay" (`choiceessay` qtype — a student sees N essay prompts, picks
one, writes their answer to only that one) turned out to already exist, fully built,
just not something Michael had found yet in Question Bank → New Question. Confirmed it
matches his description exactly. He then asked what was missing: assessment instructions
(only shown before the exam starts, same for every question type, not a gap); word count
(missing); formatting (missing) — asked to build the missing parts.

**Word count + rich-text formatting for BOTH Essay and Choice Essay's answer box**: the
plain `<textarea>` is now a toolbar (Bold/Italic/Underline/Bullet/Numbered list) +
`contenteditable` box via `document.execCommand()` — no editor library, consistent with
the standing "no CDN" decision — plus a live "N words · N characters" counter. A hidden
`<textarea>` (Essay) / the existing hidden JSON field (Choice Essay, already used for the
chosen-prompt tracking) is what actually gets submitted — `collectCurrentFormData()`
reads real form elements, and a contenteditable div has no `.value` and would otherwise
be silently dropped, losing the answer.

**Found and fixed while building this, before it could ship as a live bug**:
- The contenteditable box read the saved answer straight into `innerHTML` unescaped — an
  OLD plain-text answer containing a bare `<`, `>`, or `&` (e.g. "5 < 10 was my point")
  would misrender or vanish. New `question_engine::render_essay_answer_html()` — escapes
  and newline-to-`<br>`s an old plain-text value, passes a new HTML value through as-is —
  is now the one place every essay/choiceessay answer must go through to display safely,
  used consistently in the input box itself AND everywhere else an answer is later shown
  (see below).
- `attachAnswerListeners()` in `assessment.js` only watched real `textarea`/`input`
  elements for the nav-dot "answered" indicator and the 2-second idle autosave — typing
  into the new contenteditable box fired neither, since its hidden sync textarea is only
  ever updated programmatically (which never dispatches a real `input` event). Added a
  parallel listener on `[contenteditable]` elements.
- An emptied contenteditable box commonly leaves behind a stray `<br>` rather than a truly
  empty string — a plain `trim() !== ''` check would wrongly count that as "answered".
  New `question_engine::is_essay_text_blank()` (strips tags first) now backs
  `grade_response()`'s and `has_response()`'s essay/choiceessay emptiness checks.
- No `max-height`/`overflow-y` on the contenteditable box — unlike a `<textarea>`, which
  scrolls internally once content exceeds its rows, a plain contenteditable div just keeps
  growing taller forever, pushing the rest of the page down. Capped at 340px with its own
  scrollbar, `resize:vertical` still allowed for a student who wants more room.
- Every OTHER place an essay/choiceessay answer is displayed to a human was auditied and
  fixed to go through the same safe-render path (previously all used a bare
  `nl2br(s($answer))` — literal escaped tag text for any newly-formatted answer):
  `grader.php`'s review panel, `gradeessays.php`, and `grader_user_report.php` (which also
  never had a real `choiceessay` branch at all — it fell into the generic default and
  showed the raw `{promptid,text,shown}` JSON blob verbatim; now correctly extracts just
  the written text). `grader_user_report.php`'s "Blank" vs "Pending" badge also checked
  `rawAnswer === ''`, which for choiceessay is never true (rawAnswer is the whole JSON
  blob) — a genuinely blank choiceessay answer showed as "⏳ Pending"; now checks
  `state === 'noresponse'` first.

**New: per-question custom instructions for Choice Essay**, per direct request — the text
shown above the prompt list ("Read all questions below, then choose ONE to answer:") was
a hardcoded string with no way to customise it. New `ce_instructions` column on
`learnguard_qbank` (upgrade savepoint `2026090148`); `questionedit.php`'s Choice Essay
form gained an "Instructions (shown to the student above the prompt list)" field,
pre-seeded with that exact existing default text (not left blank behind a placeholder) so
it's visible and editable the moment the form opens. Empty/unset (any Choice Essay
question created before this field existed) falls back to the same default text
unchanged — no existing question's behaviour changes on its own.

**Confirmed, not a gap**: `grader.php`'s main question list only ever queried
`qtype IN ('essay','multichoice','multicheck','shortanswer','truefalse')` —
`choiceessay` was never included, so a Choice Essay question doesn't currently surface in
the main grading interface at all (pre-existing, independent of this change). Flagged to
Michael as a separate follow-up, not fixed here — out of scope for "word count +
formatting".

No new upgrade savepoint conflict — `2026090148` is above the last shipped version.

## v2.0.178 — Reclaim Disk Space now refuses to run without enough free space first (2026-09-08)

Asked directly to confirm the v2.0.176 background-task fix couldn't lead to the same
disk-exhaustion incident again — checked honestly instead of just reassuring: it did not.
v2.0.176 fixed the reported SYMPTOM (a hung page with zero feedback) by moving
`OPTIMIZE TABLE` off the web request into a cron-run ad-hoc task, but never addressed
what the underlying operation itself needs to run: InnoDB rebuilds a table by writing an
entirely NEW copy alongside the old one before swapping it in, so it genuinely needs
roughly the table's own current size again in free disk space — confirmed live minutes
earlier (79GB → 91GB and climbing) when it ran without that headroom. Running the exact
same operation in the background doesn't change that requirement; it would just fail
invisibly-to-a-browser-tab instead of visibly.

**`classes/task/reclaim_disk_space.php` now checks first**: computes each table's actual
current size via `information_schema.TABLES` (`DATA_LENGTH + INDEX_LENGTH`), adds a 20%
margin, and compares against `disk_free_space()` before ever calling `OPTIMIZE TABLE`. If
there isn't enough room, it refuses outright with a specific message (needed vs.
available) recorded as the task's error status — surfaced by the existing status display
next to both "Reclaim Disk Space" buttons, no further UI change needed. Best-effort, not
airtight: `disk_free_space()` checks the web app's `dataroot` filesystem, which is the
same disk as MySQL's data directory on a typical single-VPS setup like this site's, but
isn't guaranteed to be in every possible hosting configuration.

No schema change.

## v2.0.177 — critical: Media Gallery's own count query was an unbounded 5-table join (2026-09-08)

**Reported live, on a version that had already fixed everything found so far**:
`siteadmin.php?tab=media` ("🖼 Media Gallery") hung indefinitely — confirmed isolated to
just this one page (rest of the site fine) and confirmed already on v2.0.176. A genuinely
new bug, never touched by any of today's earlier fixes (those were storage.php and the
reclaim/download actions — this is a completely different query in a different file).

**Root cause**: the page's row-count query joined `learnguard_snapshots` (772,000+ rows)
against `learnguard_attempts`, `user`, `learnguard`, and `course` — FOUR tables — with no
narrowing WHERE clause at all whenever no assessment/student filter was set, which is
exactly what a bare, unfiltered page load does (the default, most common way to land on
this page). None of today's earlier index work touches this — a join across the entire
table before any filtering happens can't be helped by an index on `snapshot_type`, since
there's no `snapshot_type` filter active in the unfiltered case at all.

**Fixed**: `filter_lgid`/`filter_student` are the ONLY reasons this query ever needed
those 4 joins — `snapshot_type` and the date range are both plain columns already on
`learnguard_snapshots` itself. When neither join-requiring filter is set (the common
case), the count and the current page's row IDs are now found with a single-table query
against `learnguard_snapshots` alone (benefiting from the `(snapshot_type, timemodified)`
index from v2.0.170), and the 4-table join only ever runs afterward, bounded to the ≤48
IDs actually being displayed on that one page — cheap regardless of table size. The
`filter_lgid`/`filter_student` case still uses the full join (a narrower, more deliberate
filtered view, not the default landing state) — flagged as a remaining slower path if it
ever needs the same treatment.

No schema change — reuses the existing v2.0.170 index.

## v2.0.176 — "Reclaim Disk Space" also moved to a background task (2026-09-08)

Direct follow-up to v2.0.175's fix for bulk ZIP export — reported live that
`siteadmin.php`'s "🗜 Reclaim Disk Space" button (media and audio tabs) hung
indefinitely with zero feedback after deleting `violation_gaze_away` (108,125 rows).
Identical root cause: `OPTIMIZE TABLE` on a table with 700,000+ rows and large BLOB
content is a genuine full-table rebuild that runs synchronously in the request and can
easily exceed a web request/gateway timeout, the same infrastructure ceiling that broke
the download feature.

**Same fix shape as v2.0.175**: new `classes/task/reclaim_disk_space.php` (ad-hoc task)
runs `OPTIMIZE TABLE` on `learnguard_snapshots`/`learnguard_audio_clips` via cron, no
request timeout involved. Status tracked via 4 plugin config values
(`reclaim_status`/`reclaim_started`/`reclaim_finished`/`reclaim_error`) rather than a new
table, since this is a single site-wide operation with no per-item state to track. Both
"Reclaim Disk Space" buttons now show live status next to them (queued/running →
✅ finished at [time] → or a clear error) instead of a plain button with no memory of
what happened last time it was clicked.

**Known limitation, told to the user directly**: this fix does not affect a reclaim
attempt already stuck on the OLD synchronous code — only queued clicks after upgrading
to this version get the background treatment. The already-hung request needs to resolve
on its own or be handled the same way the earlier stuck query was (check the DB process
list; avoid force-killing an `OPTIMIZE TABLE` mid-rebuild if avoidable, since letting it
complete is safer than interrupting it).

No schema change — reclaim status lives in plugin config, not a new table.

## v2.0.175 — "Download ALL" moved to a background task after batches ALSO hit a Gateway Timeout (2026-09-08)

**Reported live**: clicking "Download Batch 1" (v2.0.174's 5,000-item bounded batch)
returned a hard **Gateway Timeout** — "The gateway did not receive a timely response
from the upstream server or application." This is the hosting infrastructure's own
timeout, layered in front of PHP — `core_php_time_limit::raise()` only controls PHP's
own execution limit and has zero effect on this. No amount of further batch-size tuning
can reliably dodge a ceiling this code can't see or control; three consecutive attempts
(single-shot → 5,000-item batches → still failing) confirmed guessing at ever-smaller
batch sizes wasn't the right fix.

**Moved to a genuine background task**: new `classes/task/build_storage_export.php`
(`\core\task\adhoc_task`) builds the ENTIRE type's ZIP with no request/gateway timeout
at all, since it runs via cron, not a web request. Result is written to a dedicated
`{dataroot}/learnguard_exports/` directory (deliberately not `$CFG->tempdir`, which
Moodle's own cleanup can clear more aggressively than an admin browsing away and coming
back later might expect) — new shared `classes/storage_export.php` computes status
(`none`/`building`/`ready`/`error`) from marker files, no new database table needed.
`storage.php`'s filtered view now shows "▶ Build Export" → "⏳ Building…" → "✅ Ready,
⬇ Download ZIP" (a fast, already-built-file stream, nothing processed at download
time) → or a clear error with a "↻ Retry" button, replacing the old
button-that-either-works-or-goes-blank/timeout with something that always tells you
where it actually stands. Also skips ZIP's own DEFLATE compression pass for
already-compressed image/video content (`ZipArchive::CM_STORE`) — wasted CPU time for
no size benefit, on the same request that was already struggling.

**Depends on cron actually running** — an ad-hoc task only executes once
`admin/cli/cron.php` (or the site's configured cron trigger) picks it up, same
dependency already flagged for the scheduled `auto_finish_expired_attempts` task back
in v2.0.144. The old bounded-batch flow (v2.0.174) is kept as an explicit,
clearly-labelled fallback link for a type small enough that one batch reliably
finishes, given the current site's Gateway Timeout is confirmed tighter than 5,000
items' worth of processing time.

No schema change — the export directory + marker files need no new table.

## v2.0.174 — "Download ALL [type]" redesigned as reliable batches after a live blank-page failure (2026-09-08)

**Reported live**: clicking "Download ALL violation_gaze_away (108,125)" (already passed
v2.0.173's raised 10GB/200k size check — 5.1GB estimated) loaded for a few seconds then
went to a blank white page — no ZIP, no error, no explanation. Production sites hide PHP
error output, so a fatal error there is silent by default; the old code also had zero
try/catch, so any failure was indistinguishable from nothing happening at all.

**Real root cause, not just missing error handling**: attempting to add 100,000+
individual entries to one `ZipArchive` in a single request is fragile independent of
total byte size — `ZipArchive` keeps an in-memory index of every entry until `close()`,
so entry COUNT is its own resource limit, separate from the size estimate v2.0.172/173
were checking. The size cap alone was never going to catch this.

**Redesigned "Download ALL [type]" as a sequence of bounded batches (5,000 items each)
instead of one attempt at everything**: new `?action=downloadallbatch` (replaces
`downloadalltype`) processes one batch at a time, ordered by id for stable pagination
across batches. Capping every batch at 5,000 items regardless of type also naturally
bounds per-request size on its own — even Screen Capture's 36.8GB works out to a few
hundred MB per batch — so the separate byte-size ceiling from v2.0.172/173 is no longer
needed. The client shows "Batch X of Y" with a button per batch (a file-download
response gives JS no way to know when it's actually finished, so this can't be safely
auto-sequenced — deliberate one-click-per-batch instead, which also means always knowing
exactly how far through a large download you are).

**Also added, so this specific failure mode (silent blank page) can never recur**: the
whole batch handler is wrapped in try/catch — a missing PHP zip extension, a temp-file
creation failure, or any other exception now redirects back with the actual error message
instead of dying silently. `@ini_set('memory_limit', '512M')` best-effort before each
batch (a genuine memory-exhaustion fatal is one of the few PHP failure modes that can
still bypass try/catch entirely regardless — which is exactly why batch size was cut
from "everything" down to 5,000 in the first place, not relying on this alone).

No schema change.

## v2.0.173 — Storage Management: raised the "Download ALL" ceiling, with a real reason (2026-09-08)

Direct pushback on v2.0.172's 3GB/6,000-item cap, for `violation_gaze_away` specifically
(108,125 items, ~5.1GB) — confirmed this data genuinely needs preserving before
deletion, AND confirmed current disk state first (79GB/100GB used, 21GB free) rather
than raising the cap blind. Raised to 10GB/200,000 items — comfortable headroom over the
confirmed 5.1GB case, while still refusing something the scale of Screen Capture
(36.8GB). `core_php_time_limit::raise()` bumped from 600s to 1800s to give a request this
size a real chance to finish — noted plainly that a web server/proxy's own timeout is
still outside what this code can control, and a cutoff partway loses nothing (nothing is
deleted by this action) but may need a page-by-page fallback or a proxy-timeout check
with hosting.

**This number is only safe because of a specific, confirmed, current disk state — not a
permanent judgment.** Worth revisiting (lowering again) once the immediate cleanup is
done and free space is no longer known to be generous; left an explicit code comment to
that effect rather than silently normalizing a higher ceiling.

No schema change.

## v2.0.172 — Storage Management: download before delete, size-checked (2026-09-08)

Direct follow-up to the storage crisis (v2.0.170/171) — asked for a way to download
media before deleting it, since deletion is permanent and some of what's being cleared
(Photo IDs specifically) is identity-verification data worth archiving first.

**New `storage.php` actions, both capped to avoid recreating the exact crisis this whole
round of work started from**:
- **"⬇ Download Selected"** — appears next to "Delete Selected" once 1-300 items are
  ticked (300 is the same cap the Audio Clips tab's existing "Download Selected" already
  uses); posts the selected IDs, streams back one ZIP.
- **"⬇ Download ALL [type]"** — appears next to "Delete ALL [type]" on a filtered view.
  Re-checks the real size SERVER-SIDE first (same unordered 200-row sample as
  `getstats` — never trusts whatever the client already has displayed) and refuses
  outright above 3GB or 6,000 items, pointing at the page-by-page Select-All +
  Download-Selected route instead. Screen Capture (36.8GB) and Webcam Snapshot (2.6GB
  but 211,471 rows) would be refused; Photo ID (~322MB) and 360° Video (~1.6GB) go
  straight through. `core_php_time_limit::raise()` on both new actions, same insurance
  already applied to the equivalent siteadmin.php actions.

Both build the ZIP into a temp file one row at a time (existing pattern from
`siteadmin.php`'s `downloadmedia`/`downloadaudio`), not in memory, so this stays safe on
a memory-constrained server regardless of item count within the caps above.

No schema change.

## v2.0.171 — EMERGENCY HOTFIX: Storage Management's own size-estimate query was taking down the entire LMS, not just this page (2026-09-08)

**Reported live, mid-crisis**: right after upgrading to v2.0.170 to fix Storage
Management's slowness, visiting the page caused the ENTIRE LMS — every page, not just
LearnGuard — to hang. Confirmed by the user this was the same frozen page sitting on
screen, not a fresh state.

**Root cause found by re-reading the exact query, not assumed**: `?action=getstats`
(fires unconditionally in the background on every single page load, estimating byte
size per type for the summary cards) sampled 200 rows per type via `ORDER BY id DESC
LIMIT 200` for any type over 500 rows. The new `(snapshot_type, timemodified)` index
added in v2.0.170 does NOT cover `id` — so this ordering could not use it. For a type
with 500,000+ rows (Screen Capture: 516,328; Webcam Snapshot: 211,471, both real numbers
from the live site), the database had to gather every matching row and sort the entire
set by id before it could return the top 200 — an expensive operation that, under this
site's current load, was severe enough to stall the database for every other query
platform-wide, not just this one page. This is a WORSE regression than the original
"just this page is slow" problem — v2.0.170's index fix was correct and necessary, but
this specific query was never re-examined against it and turned out to still be
dangerous at this table's actual scale.

**Fixed by dropping `ORDER BY id DESC` entirely** — this is only ever used to average an
estimate, so which specific 200 rows come back has never mattered. An unordered `LIMIT
200` lets the database return the first 200 matching rows it finds via the index and
stop immediately, with no sort step over the full matching set at all.

**Told the user directly**: kill the currently stuck database query/process manually (or
via hosting support) — this fix cannot un-stick a query that's already running; it only
prevents the next one from ever being this expensive. Do not revisit Storage Management
again until this version is deployed.

No schema change — the type_time index from v2.0.170 is correct and untouched; only this
one query's `ORDER BY` was removed.

## v2.0.170 — critical: root-caused why Storage Management goes super slow at scale + chunked delete-all + a real evidence-badge bug (2026-09-08)

**Reported live: the whole LMS went down overnight from a full disk (56GB directories,
44GB database — 26GB of that was Moodle's own backups, deleted to recover), and once
back up, Storage Management itself was "super slow with timeout response," blocking the
exact cleanup needed.** Root-caused by reading the actual schema, not guessed:

**`learnguard_snapshots` and `learnguard_audio_clips` had no usable index for the
queries that matter most at scale.** `learnguard_snapshots` only ever had composite
indexes LEADING with `attemptid` (`attemptid_type`, `attemptid_time`) — useless for a
site-wide query filtered ONLY by `snapshot_type` or date, which is exactly what
Storage Management's stats, every "Delete ALL [type]" action, and the daily retention
cleanup task all do. Every one of those was silently falling back to a full table scan
over every row's actual TEXT blob content (`snapshot_url`, `audiodata` — LearnGuard
stores webcam/screen/360°/audio media as base64 directly in these DB TEXT columns, not
as separate files, which is also why the *database* itself — not just moodledata — grew
to 44GB). `learnguard_audio_clips` had the identical gap (only an `attemptid` index, no
date-based one at all). New indexes added: `learnguard_snapshots(snapshot_type,
timemodified)`, `learnguard_audio_clips(timecreated)` — upgrade savepoint `2026090139`.
This alone should make every one of these pages, and the retention cleanup task,
dramatically faster with no other change needed, since the existing queries already
filter exactly the way these new indexes are shaped for.

**`storage.php`'s "Delete ALL [type]" is now chunked, not a single unbounded DELETE**:
even with the new index, a live crisis is exactly when a type is at its largest — a
single `DELETE ... WHERE snapshot_type = X` covering, say, hundreds of thousands of
large-blob rows could still run long enough to hit a web server/proxy timeout
independent of the database's own speed. New `?action=deletebatch` deletes up to 2,000
rows per call; the button now shows a live "X deleted so far, Y remaining" progress bar
that keeps calling itself until nothing is left, then reloads — same chunked-with-
progress pattern as Assessment Manager's `lgamChunkedSave()`. **This is the button to use
for the 360° videos / photo IDs / screenshots part of a cleanup plan** — filter to the
type on `storage.php`, click "Delete ALL", let it run.

**`siteadmin.php`'s two filtered bulk-deletes (Media tab, Audio Clips tab) and the
Media tab's per-type delete** now call `core_php_time_limit::raise(300)` before their
single big DELETE — cheap insurance against PHP's own execution-time cutoff on top of
the new indexes. These are NOT yet converted to the same chunked-AJAX pattern as
storage.php — flagged as a fast, low-risk follow-up if they're still too slow at his
scale (Audio Clips' "Delete All Filtered" needs a wide date-range filter set first; it
deliberately refuses to run with no filter at all, as a safety net against an accidental
full wipe).

**Also fixed — the Continue & Review evidence-phase badge genuinely wasn't clickable**:
reported directly ("not able to click on it"). Root cause: gated entirely behind
`review_granted_time`, a column that only gets populated by a grant made through
v2.0.166's code — every review already in progress or already granted on the live site
had it at 0, making the badge correctly-but-uselessly non-clickable for virtually every
real attempt. Now falls back to `timestart` (which `start_review()`/`start_clock()` has
always overwritten to when the review's own timer actually activated, in every version
of this code) when no precise grant time was recorded — the badge and filter now work
for any reviewed attempt, not just ones granted after v2.0.166 shipped.

## v2.0.169 — reinstatement clock fix, fullscreen re-entry error feedback, proctor-not-instructor wording, Continue & Review evidence-phase filter (2026-09-07)

**Reported live — Basic Finance, reinstated student stuck on a persistent error despite
staying online**: traced to the exact same class of bug already fixed for a fresh
attempt and a Continue & Review session (v2.0.167), just never extended to genuine
reinstatement. `proceedResumedAttempt()` computed `resumedNow`/`suspendedFor` and called
`initTimer()` IMMEDIATELY on entry — correctly excluding the precheck-redo time, but the
clock was then already running while `loadQuestion()` tried to fetch Q1, and
`doLoadQuestion()` only ever retried twice before showing a persistent "Failed to load
question" error with a manual Retry button — which, with nobody clicking it, "remains"
exactly as reported. Fixed: reinstatement now takes the same path as the other two —
`preloadAllQuestionsBlocking()` first (silent, indefinite retry, "Resuming your
assessment…" instead of a spinner with no explanation), `resumedNow` captured LATE
(right before reveal) so the suspendedFor shift now also excludes any loading delay, not
just the precheck-redo time. A plain page reload mid-exam (no suspension involved) is
UNCHANGED — its clock was already continuously running server-side regardless of what
the client does, so there was nothing to gain by deferring reveal there.

**Fullscreen "Return to Fullscreen" button — added visible failure feedback**:
`fsEnterDirect()` (the blocker's click handler) previously swallowed a failed
`requestFullscreen()` call completely silently — no error, no UI change, indistinguishable
from the click not registering at all. Directly matches an earlier live report: a
student's own screenshot showed normal browser chrome still visible behind "Fullscreen
Required" with zero indication anything had been tried. Every failure path (rejected
promise, synchronous throw, resolved-but-still-not-active, no Promise returned at all)
now shows a specific, visible message directly on the blocker via new
`lgFsBlockerError()` — e.g. "Your browser blocked fullscreen. Click the button again, or
check your browser's site permissions for this page." The underlying WHY some browsers
refuse programmatic re-entry (Permissions Policy, OS-level restriction, rate-limiting
after repeated toggles) is still not something client code can detect or fix — but the
student now at least knows something was tried and failed, instead of silence.

**"Contact your instructor" → "Contact your proctor"** on every proctoring-related
contact-for-help message (identity-approval-denied, no-attempts-remaining, both
suspension-screen variants) — these are proctor decisions, and the suspension screen
already said "suspended by the proctor" one line above, so "instructor" read as
inconsistent. Left ONE occurrence unchanged on purpose: "Your results will be released
by your instructor" on the results-pending screen — that's a grading/release action, a
genuinely different role from a proctoring contact point, and changing it to "proctor"
would be factually wrong.

**Continue & Review evidence now separable from the original attempt, per direct
request**: a granted review reuses the SAME attempt row rather than creating a new one,
so its events/snapshots always sat mixed together chronologically with the original
attempt's — no way to tell which phase produced a given piece of evidence, and the "🔄
Continue & Review" badge was a static label, not clickable. Now:
- The badge is clickable (when `review_granted_time` exists — see v2.0.166) and filters
  Proctoring Events + every webcam/screen snapshot below by a shared "Evidence Phase"
  toolbar (All / Original Attempt Only / Review Session Only), scrolling to the events
  table when a specific phase is selected.
- Every event row and snapshot thumbnail carries `data-phase="original"|"review"`,
  computed by comparing its own timestamp against `review_granted_time` — a small "🔄"
  marker also appears inline on review-phase event rows even in the "All" view.
- Fixed a confusing side-effect of `timestart` now correctly reflecting when the REVIEW
  started (v2.0.167/168): a review granted after the original attempt already finished
  could show "Started" AFTER "Finished" in the header, reading like a data bug. Now
  labelled explicitly ("Review started" / "Originally finished") when that's the case,
  plus an explicit "🔄 Continue & Review granted: [date]" line.
- The attempt-number switcher already marked sibling reviewed attempts with 🔄
  (v2.0.160) — confirmed genuine separate attempts (2, 3…) already get fully separate
  events/snapshots for free, since they're different attemptid rows; no change needed
  there.

No schema change — uses the existing `review_granted_time` column (v2.0.166).

## v2.0.168 — pre-go-live review of v2.0.167: 2 real gaps found and fixed (2026-09-07)

Asked directly to re-verify v2.0.167 (the exam-clock decoupling) before going live,
specifically re: multiple simultaneous users. Two real, if narrower, gaps found on
re-reading the new code against that exact question:

**1. The new indefinite preload lost the cohort-wide stagger the old preload had** —
`preloadAllQuestionsBlocking()` (new in v2.0.167) fired its first attempt immediately,
with no randomised delay, unlike `preloadRemainingQuestions()` right above it (which has
carried a "stagger the first attempt across 0-4s" specifically for a cohort exam opening
for everyone at the same scheduled instant — v2.0.163). Every student's browser would
have fired the same bulk `get_all_questions` call in the same instant right as a
scheduled exam opened — exactly the load-concentration problem v2.0.163 already fixed
for the OLD preload path, quietly reopened by the new one. Fixed: the same 0-4s stagger
now applies to the first attempt only (a retry after a real failure already has its own
backoff and shouldn't wait an extra random delay on top).

**2. `start_exam_clock` had no retry at all** — a single failed call meant the exam still
worked fine for the student (`initTimer()` already falls back safely to the client's own
"now"), but the SERVER's `timestart` stayed at 0 forever for that one attempt, silently
disabling `learnguard_attempt_timelimit_expired()`'s enforcement for it — a narrow but
real gap in the server-side time-limit backstop this whole feature depends on elsewhere.
New `lgStartExamClockRobust()`: retries a few times before giving up on blocking the
reveal any further (same as everywhere else this session), then keeps quietly retrying
in the background every 30s — safe, since `start_clock()` is idempotent — until the
server actually records it, or the attempt finishes/is suspended (interval self-clears
either way). Both call sites (`launchAssessment()`, the Continue & Review branch of
`proceedResumedAttempt()`) now share this one helper instead of duplicating the
call-then-fallback logic.

Also re-confirmed (no change needed): `start_clock()`'s own idempotent write has no
meaningful race under concurrent calls — worst case is a 1-2 second drift in exactly
when timestart gets set, not data corruption or a duplicate attempt (attempt CREATION
is still protected by the existing `core\lock\lock_config` lock in
`start_or_resume()`, untouched by any of this); the new abandoned-pre-start-attempt
cleanup in `learnguard_finish_expired_attempts()` only ever touches rows idle 24h+, so
it's a no-op for the normal case of many students simultaneously mid-preload at once.

No schema change.

## v2.0.167 — critical: exam clock no longer starts until content is actually ready to show (2026-09-07)

**Root cause, confirmed by re-reading the code**: `start_or_resume_locked()` set
`timestart = time()` the INSTANT an attempt row was inserted — before Q1 was ever
fetched. A formal complaint arrived describing exactly this: 17 minutes of "Failed to
load question. Request timeout." after clicking Begin Assessment, working normally only
once ~14 of those minutes were already gone, leaving 3 minutes to answer 19 questions.
The clock had been silently running through the entire failure the whole time — no
amount of retrying the LOAD would have helped, since the harm was already done by the
time Q1 ever appeared. Michael's own proposed fix ("preload while precheck runs") ran
into exactly this fact once traced through: preloading earlier doesn't help if the clock
already started at attempt-creation, wherever that moment is moved to.

**Fixed at the root — the clock is now a separate, explicit step from creating the
attempt**:
- `start_or_resume_locked()` and `external.php::start_review()` (the Continue & Review
  equivalent) now both leave `timestart = 0` ("not started") instead of `time()`.
- New `attempt_manager::start_clock()` / WS `mod_learnguard_start_exam_clock` — sets
  `timestart = time()` the FIRST time it's called for an attempt, and is a no-op
  (idempotent, same pattern as the existing `begin_section()`) on every call after that.
- The client (`amd/src/assessment.js`) now preloads the ENTIRE exam
  (`preloadAllQuestionsBlocking()` — new, retries silently and indefinitely, no error
  screen ever, unlike the existing best-effort `preloadRemainingQuestions()` which still
  backs `doLoadQuestion()`'s per-question fallback elsewhere) BEFORE calling
  `start_exam_clock` and revealing any exam content. However long that takes — seconds or,
  in a repeat of the reported incident, many minutes — now costs the student nothing,
  because the clock genuinely has not started yet. A "Preparing your assessment…" message
  (explicitly non-alarming, refreshes to reassuring wording past 20s) fills the wait
  instead of a spinner with no explanation. Applied to both a fresh attempt
  (`launchAssessment()`) and a granted Continue & Review session
  (`proceedResumedAttempt()`'s review branch) — reviews are often short (minutes), so this
  exact bug could have cost review time just as easily. A plain page-reload resume and a
  reinstatement-after-suspension resume are UNCHANGED — their timers were already
  correctly protected by the existing suspended-time offset logic and don't create a new
  attempt, so this fix doesn't apply there.
- Since navigation only ever needs `state.questionCache` (100% populated before reveal
  now, for these two paths), "Next" always renders instantly with zero further round
  trips for the rest of the exam — directly the behavior asked for ("if the user moves to
  the next question... a question is given to the user until the whole questions are
  preloaded without any issue").

**Guarded every place that measures elapsed time or displays "Started" against the new
`timestart = 0` state** so nothing misinterprets "not started yet" as "started decades
ago": `learnguard_attempt_timelimit_expired()` returns false immediately for timestart=0
(previously `now - 0` would have instantly tripped the 48-hour safety net and
force-finished a student's attempt before they'd even seen a question);
`learnguard_finish_expired_attempts()` now separately cleans up ONLY genuinely-abandoned
pre-start attempts (idle 24h+, never started) by deleting them outright — not finishing,
since nothing was ever shown or answered, so it was never a real graded attempt; every
"Started" display checked (`attemptdetail.php`, `admin.php`, `report.php`, `report_pdf.php`,
`siteadmin.php`'s Live Monitor and In-Progress grids) now shows "—"/"Not started
yet"/"Preparing…" instead of "1 Jan 1970" or an absurd elapsed duration. Not every export/
report page in the codebase was individually re-audited for this — the highest-visibility
ones were; flagging this scope boundary rather than claiming exhaustive coverage.

**Also, per explicit instruction — save failures are now fully silent, no error ever
shown**: `submitCurrentAnswer()`'s "⏳ Still saving your last answer in the background"
toast is removed. The underlying retry-forever mechanism (`startUnsavedAnswerFlush()`,
already existed) is unchanged — a save that fails after 3 attempts is queued and retried
silently every 12s, indefinitely, with zero visible indication to the student that
anything ever went wrong.

No schema change — `timestart`'s column default was already 0; only the application code
that WRITES to it changed.

## v2.0.166 — grader_user_report.php: pending-grant placeholder + Continue & Review before/after answer diff (2026-09-07)

**Reported live**: granting a reattempt or Continue & Review left no trace on the
student's own attempt report — no sign a grant was ever made, and once a review was
resubmitted, no way to see what the student's answer actually was before vs. after.

**Pending-grant placeholder card**: when `learnguard_schedules.extra_attempts > 0` for a
student and they haven't yet used or started that extra slot (mirrors
`start_or_resume_locked()`'s own completedCount check exactly, including the
`reopened_for_review` fix from v2.0.164), a greyed "Attempt N — Granted, not started"
card now appears in the report right alongside their real attempt cards.

**Continue & Review before/after answer diff, colour-coded**: turned out the data for
this already existed — `question_engine::save_response()` has always logged a response's
previous content to `learnguard_response_history` before overwriting it, independent of
review entirely. New `attempt_manager::get_review_changes()` reads that trail and, using
a new boundary timestamp (`learnguard_attempts.review_granted_time`, set the moment
Continue & Review is granted — new column, upgrade savepoint `2026090135`), splits it
into "edited before this review" vs. "edited during this review". Only questions whose
answer actually changed get a diff block — an amber "Previous answer" box (with its
original score) directly beneath the current answer. Reviews granted before this column
existed fall back to a best-effort reconstruction (the single most recent history entry)
rather than showing nothing, per explicit request — can be wrong if a student re-edited
the same question more than once during the ORIGINAL attempt with no recorded boundary
to disambiguate, but is the closest available approximation.

**Also wired `review_granted_time`** into both existing Continue & Review grant call
sites (`assessmentmanager.php`, `scorematrix.php`'s quick-grant) — no other behavior
changed there.

Refactored the report's current-answer extraction into a shared
`lgur_extract_answer_display()` so the current and pre-review answers render through
identical logic and can't silently drift apart.

## v2.0.165 — schedule/review grants now also keep course enrolment alive (2026-09-07)

**The gap**: granting a personal window (Assessment Manager's per-user override) or a
Continue & Review deadline only ever wrote to `learnguard_schedules`/
`learnguard_attempts` — Moodle's own `{user_enrolments}` was never touched. A student's
course enrolment can independently be suspended or already set to expire before
whatever date was just granted, silently making the grant pointless: the student can't
even reach the activity to use it. Reported directly, with the course Participants page
as evidence (enrolment status shown there is a separate mechanism from anything
LearnGuard controls).

**New `learnguard_ensure_course_access($userid, $courseid, $minuntil)` in `lib.php`**:
reactivates every one of the student's enabled enrolment methods in the course
(manual, self, cohort sync — whichever they actually have) via `enrol_get_plugin()`
→ `update_user_enrol()`, and — only when a concrete date was actually granted — pushes
out any enrolment that would otherwise expire before it. Confirmed with Michael this
should always reactivate even a deliberately-suspended enrolment (his own grant is
being treated as the explicit call each time), across every enrolment method, both
retroactively and going forward. Never shortens/suspends — only ever activates/extends.

**Wired into all 3 places that grant a schedule window or a review**:
- `assessmentmanager.php`'s `save_user_overrides` (per-user window close date) — one call
  per user with their granted `timeclose`.
- `assessmentmanager.php`'s `continue_review` (bulk grant) and `scorematrix.php`'s
  single-cell "Grant Continue & Review" — both call it per grant; the score-matrix quick
  grant sets no absolute deadline, so only the reactivation half applies there.

**Retroactive, per explicit request** — new upgrade savepoint `2026090134` sweeps every
currently-relevant grant already made (a personal window close date still in the
future; a review still pending with reopened_for_review=1) and fixes the matching
enrolment right there, so upgrading alone covers grants already made rather than
requiring Michael to re-save each one.

No new table — only the existing `enrol`/`user_enrolments` core tables are touched.

## v2.0.164 — critical: reopening an attempt for Continue & Review silently freed up a whole extra attempt (2026-09-07)

**Reported live**: a student (Boluwatife Odugbenro) with a maxattempts limit ended up
with TWO full attempts, both showing "review pending" — #1 scored 76.67, #2 scored
26.67 — despite only ever being granted a review of attempt #1, not a second attempt.

**Root cause, in `classes/attempt_manager.php`**: `start_or_resume_locked()`'s
attempt-limit check counts `state IN ('finished', 'suspended')` to decide whether the
student has any attempts left. Granting Continue & Review flips the reopened attempt's
`state` back to `'inprogress'` (by design, so the student can answer again) — which
silently dropped it OUT of that count while the review sat pending, undercounting the
student's real attempt total by one. With maxattempts=1, the count went from 1 back to
0 the instant review was granted, so the very next "Begin Assessment" click passed the
limit check clean and created a brand-new, entirely unauthorized attempt #2 — exactly
what was reported. `can_reveal_review()` had the identical undercount in the exact same
calculation (its own comment already said it must mirror `start_or_resume_locked()`
exactly) — left inconsistent, it would have disagreed about whether a further attempt
was still possible.

**Fixed**: both counts now also include any attempt with `reopened_for_review = 1`,
regardless of its current state. That column is set once and left at 1 permanently
(existing schema comment: "audit marker") even after the review is resubmitted back to
`'finished'` — so this correctly keeps counting it before, during, and after the review,
with no double-count risk (an attempt is never both freshly 'finished' AND newly
reopened at the same instant).

**Needs Michael's action**: Boluwatife Odugbenro's attempt #2 was never a legitimate
grant — worth voiding it and keeping attempt #1's granted review (76.67, pending) as the
one that counts. Worth checking whether any other student granted Continue & Review
managed to start an extra attempt the same way while this bug was live.

No schema change — no new upgrade.php savepoint needed (uses the existing
`reopened_for_review` column).

**Also shipping in this build — fullscreen blocker now pauses the exam timer**
(`amd/src/assessment.js`, `initTimer()`): previously a genuinely-stuck "Fullscreen
Required" screen (browser/OS re-entry failure, not gaming) kept the timer running with
zero protection — directly reported live (see the MSP 2027 Advisory Assessment formal
complaint) alongside Michael's own reproduction showing full browser chrome and the
taskbar still visible behind the blocker. Confirmed with Michael this was a deliberate
prior anti-cheat choice, not an oversight, before reversing it — now `state.fsBlocked`
freezes the timer exactly like suspension/break/screen-share-pause already do. Gaming is
still deterred independently: every fullscreen exit is still scored as a violation
(`initFullscreen()`'s `fullscreenchange` handler, unchanged) regardless of timer state,
so repeatedly exiting to "steal" paused time still pushes toward auto-suspension. The
underlying question of WHY `requestFullscreen()` sometimes fails to re-enter fullscreen
at all is still unresolved — this fix only stops it from costing the student their exam
time while that's being investigated.

## v2.0.163 — concurrency audit: preload had no retry, every retry/interval was unjittered (2026-09-06)

Asked directly: "is there anything we have solved that... will run simultaneously with
large number of users." Two more student complaints (persistent freezing/slow response
with 20/30 unanswered; "Error saving answer" repeatedly with 17/30 unanswered) prompted
re-verifying both the preload (v2.0.154) and save-and-continue (v2.0.153/154) fixes
directly against the current code rather than assuming they held up.

**`preloadRemainingQuestions()` had ZERO retry** — confirmed by re-reading the code: a
single failure (most likely to happen exactly when many students start together — the
worst possible moment for one large request to compete for capacity) permanently left
that whole attempt with an empty cache, silently falling back to a live per-question
fetch for every remaining question — fully exposed to server load for the entire exam
instead of just the first one. This plausibly explains the "persistent freezing
throughout" report on its own. Now retries up to twice with backoff, same as every
other exam-critical WS call.

**Textual proof one complaint predates the fix entirely**: the second complaint quotes
verbatim "Error saving answer. Please check your connection and try again." — grepping
the current codebase confirms this exact string no longer exists as a shown message
anywhere (v2.0.153 replaced it with the retry-then-queue-and-continue flow); it only
survives in code comments describing the old behavior for context. Strong evidence that
attempt was running on a version older than 2026-09-04, not current code.

**Broader concurrency gap found and fixed — no backoff or interval anywhere had
jitter**: every retry added this week (`doLoadQuestion`, `doSubmit`, `finishAttempt`,
`doUploadSnapshot`, `preloadRemainingQuestions`) used a fixed linear-backoff formula
with zero randomization. Harmless for one student, but when a whole cohort hits a
shared slow moment together, every client's retry lands at the exact same relative
offset too — a synchronised "thundering herd" that re-hits the server in a wave rather
than smoothing load out, the opposite of what a retry is for at scale. New `lgJitter()`
helper (up to 50% random jitter on top of the base delay) applied to all 5 retry sites,
including the just-added preload retry.

**Also fixed the same issue at its root — synchronised periodic captures**: since a
cohort exam typically opens for everyone at the same scheduled instant,
`startSnapshotTimer()` (webcam, every ~15s) and `initPeriodicScreenCapture()` (screen,
same interval) previously had every student's browser start its interval within
seconds of each other — meaning the whole cohort's webcam/screen uploads (real files,
not small JSON pings) landed on the server in synchronised waves every ~15 seconds for
the entire exam duration. Both now get an independent random one-time delay before
their first tick, spreading each student's capture grid out while leaving the
steady-state cadence unchanged. `startUnsavedAnswerFlush()` — precisely the recovery
mechanism that activates DURING a widespread server-distress event, exactly the moment
synchronisation would do the most damage — converted from a fixed `setInterval` to a
self-rescheduling jittered loop so it keeps desynchronising over time rather than
staggering once and then re-locking into step.

No schema change — no new upgrade.php savepoint needed.

## v2.0.162 — critical: confirmed suspensions never actually closed, causing "double work" + silent no-notification gap (2026-09-05)

**Confirming a suspension never finished the attempt — it just sat there forever**:
confirmed live with hard evidence — Mail History showed 3 students ("Suspend" =
Confirm Suspension) already emailed with a final decision, yet all 3 were STILL sitting
on the Suspended Students list needing "Reinstate," exactly the reported "double work."
Root cause: `attemptdetail.php`'s "Confirm Suspension" and `proctorview.php`'s bulk
"Confirm Suspension" both only ever set `is_flagged=1` — neither touched `state`, which
stayed `'suspended'` forever (or could later drift to `'inprogress'` if something else
reinstated it, producing the exact reported "Suspension Confirmed / now: Inprogress"
when it should read "now: Finished"). Both now also call
`attempt_manager::finish($attempt, $instance)` — grades whatever was answered and sets
state to `'finished'`, the same as any other completed attempt; `finish()` works from
any starting state, it only reads `learnguard_attempt_questions`/`learnguard_responses`.
`proctorview.php`'s Suspended tab query and count also now exclude `is_flagged=1` rows
directly, as a belt-and-suspenders safety net.

**Retroactive fix, not just going forward**: per explicit request ("update so when i
upgrade the plugin it auto reflect... on the ones i have worked on"), new upgrade
savepoint `2026090131` finds every already-confirmed attempt still sitting unfinished
and finishes it right there — upgrading alone clears the backlog, no need to re-touch
each one manually.

**Silent no-op → real notification**: both `attemptdetail.php` handlers
(`confirmsuspendnotify`, `reinstatenotify`) and both `report.php` handlers (`reinstate`,
`reinstatenotify`) guard on `$attempt->state === 'suspended'` but previously had NO
`else` branch — if the attempt had already changed state by the time the form was
submitted (very plausible given the bug above: multiple pages disagreeing about
whether something still needed action), the code silently did nothing at all, with zero
feedback. This read as "the mail notification is broken" when actually no action ran.
All four now show a clear warning ("This attempt is no longer suspended... someone may
have already acted on it. No email was sent.") instead of silently doing nothing.

No new fields — schema-free upgrade step (data fix only).

## v2.0.161 — 48-hour feedback audit: closed the gaps found (2026-09-05)

Ran a full audit of every piece of feedback from the prior 48 hours against the actual
current code (not memory) to catch anything missed or only partially done. Found and
fixed two real gaps:

**"Server slow" badge was still shown to students** — v2.0.154 built server-reachability
detection then immediately over-surfaced it: Michael explicitly corrected that this
diagnosis should be backend/proctor-facing only, not shown to the student, but the
"🟠 Server slow" badge was never actually removed from `lgUpdateConnectionBadge()` despite
saying it would be. Removed — students now only ever see their own device's generic
network status, exactly as before any of this existed. The backend-facing half is now
properly implemented instead: `startPolling()` logs a `connection_server_reachable`
event (low severity, 0 score, `down_for_seconds` in the detail) when the server-reachability
poll recovers after an outage — visible on attemptdetail.php's Proctoring Events table,
invisible to the student.

**Evidence tags for 5 violation types never matched the Evidence column at all** —
`multiple_faces_detected`, `multiple_persons_detected` (both YOLO and COCO-SSD fallback
paths), `background_change`, `face_identity_mismatch`, and every `object_detected_*`
type each had their own explicit `captureAndUploadSnapshot()` call using a short tag
(`'multiple_faces'`, `'object_book'`, etc.) that never matched attemptdetail.php's
Evidence-column lookup (`'violation_' + event_type`) — completely invisible dead weight,
duplicating work the generic auto-capture in `logProctoringEvent()` (which uses the
correct tag) already does. Fixed all 5 to use the matching tag, turning them from wasted
duplicate uploads into a genuine second, independent evidence attempt for exactly the
kind of report Michael raised ("Multiple Faces Detected" with nothing in Evidence).

**Investigated but not changed, per explicit direction**: the audio "Level: 0" mystery
was re-audited from scratch — confirmed the only live call site mathematically cannot
pass a 0 noise level, and the `logAsViolation`/hardcoded-0 path described in an old
comment is dead code with no remaining caller. Michael chose to drop further
investigation rather than chase it further without a fresh, version-confirmed
reproduction. The connectivity grace-period feature (exit fullscreen to troubleshoot
without penalty) and a "Duplicate Question" button were both discussed and explicitly
deprioritized — not built.

No schema change — no new upgrade.php savepoint needed.

## v2.0.160 — attemptdetail.php: Reinstate & Notify parity + attempt-number switcher (2026-09-05)

**"Reinstate & Notify" button added, matching "Confirm Suspension (Notify Student)"**:
Michael pointed out attemptdetail.php only had the suspend-side notification action, not
the reinstate-side one that already existed on report.php (v2.0.150). Ported the same
flow here — new "✅ Reinstate & Notify" button + modal alongside the existing "🚫 Confirm
Suspension" one (both shown together while an attempt is suspended, as the two opposite
proctor decisions), reusing report.php's `reinstatenotify` handler logic and
`learnguard_notify_student_reinstated()`. Simpler than report.php's version: no AJAX
evidence-fetch endpoint needed, since attemptdetail.php already renders every webcam/
screen/audio evidence checkbox inline for one specific attempt (report.php lists many
attempts at once, so it fetches evidence on demand when its modal opens).

**Attempt-number switcher**: a `<select>` next to the student's name (only rendered when
more than one attempt exists) lists every attempt this same student has on this same
activity — picking one reloads this same page for that attempt instead of requiring a
trip back through report.php to find a granted reattempt. Also added a "🔄 Continue &
Review" badge next to the header when the CURRENT attempt has an active/completed review
session (`reopened_for_review`) — the closest direct equivalent to a distinct "review
progress page" without inventing a new page for state attemptdetail.php already shows.

No schema change — no new upgrade.php savepoint needed.

## v2.0.159 — Score Matrix: clickable N/A reminders + Grant Continue & Review from any score cell (2026-09-05)

**"N/A" cell → attempt reminder email, plus a batched "Send to All"**:
- Every "N/A" cell (no attempt at all for that student/assessment) on the first attempt
  row is now a clickable button, site-admin only. One click, one confirm → sends that
  student a reminder email stating the assessment hasn't been attempted, with the
  effective window-close date/time for THAT student (personal schedule override
  respected via the existing `learnguard_effective_window()`).
- New "📧 Send Reminders to All (N/A)" toolbar button collects every N/A cell currently
  on the page and sends in batches of 5 via sequential `fetch()` calls (new
  `sendreminderbatch` AJAX action, JSON response) — same batching reasoning as every
  other bulk action added today, so a course with hundreds of not-yet-attempted
  students doesn't fire hundreds of emails in one request.
- New `learnguard_notify_student_attempt_reminder()` in `lib.php`, gated by new
  `notify_reminder_enabled` setting (default on).

**Score cell → "View Report" or "Grant Continue & Review"**: every scored cell (any
score, not just 0 — a proctor may want to grant a review from any of them) now opens a
small menu instead of linking straight to the report. "Grant Continue & Review" reopens
that SPECIFIC attempt (already known from the cell, unlike `assessmentmanager.php`'s
bulk grant which has to look up each user's latest attempt) with the same one-review-
per-attempt rule, and emails the student — the message explicitly states **the review
must be completed within the assessment window** (again via
`learnguard_effective_window()`), the exact wording requested. New
`learnguard_notify_student_review_granted()`, gated by new
`notify_review_granted_enabled` setting (default on).

**Both new email types logged to Mail History**: `learnguard_suspension_notices` gains
two new `noticetype` values — `reminder` and `reviewgranted` (no underscore, since the
page's type filter uses `PARAM_ALPHA`) — alongside the existing `suspend`/`reinstate`.
`suspensionnotices.php` (surfaced as "Mail History") updated: filter dropdown, badge
colours, and its "View Attempt" link now guards against `attemptid = 0` (a reminder has
no attempt to link to, since none exists yet).

No schema change — no new upgrade.php savepoint needed (`noticetype` is already a plain
CHAR(20) column with no enum constraint).

## v2.0.158 — mandatory identity-unverified flag, evidence-upload retry (2026-09-05)

**Evidence-upload retry**: `uploadSnapshot()` (used by every webcam/screen evidence
capture) had zero retry on WS failure — a single transient timeout under server load
permanently discarded a violation's only evidence with no trace, the same
already-fixed-elsewhere pattern (question load/save/finish, v2.0.153) that had not yet
been applied here. Refactored into `uploadSnapshot()` (encodes the canvas once) +
`doUploadSnapshot()` (retries twice with backoff, reusing the same encoded image data
rather than re-touching a canvas that may be gone by retry time).

**Face-detection policy decision — confirmed with Michael**: camera access and screen
sharing were both already hard-blocking with no bypass (verified directly in
`wiz_camera()` and the mandatory screen-share gate — Next stays disabled, Retry only, no
fallback). Face detection was the one gap: when face-api never detects a face at all,
`showFallback()` puts the wizard into a self-tick-or-invigilator-approval state, and in
`faceapprovalmode: 'auto'` the self-tick alone was enough to proceed with zero human
verification. Rather than removing that path outright (would leave a student with a
genuinely malfunctioning camera unable to ever start if no proctor is online), Michael
chose: keep the self-tick as a last resort, but make it loudly, unmissably flagged for
review.

- New `learnguard_attempts.identity_unverified` column (upgrade savepoint `2026090127`).
- `wiz_face()`: new `faceFallbackActive` flag set inside `showFallback()`; the AUTO-mode
  checkbox handler now sets module-level `pendingIdentityUnverified = true` when the
  self-tick fires in that state (the flag decision happens during precheck, before any
  attempt row exists).
- `launchAssessment()`: once a real `attemptid` exists, calls new WS
  `mod_learnguard_flag_identity_unverified` — sets the column, logs an `identity_unverified`
  event (high severity, 0 score — this is a visibility flag, not a violation score), and
  emails proctors if enabled.
- `attemptdetail.php`: a red, impossible-to-miss banner at the very top of the page
  (before even the quick-action buttons) whenever this flag is set — "Identity Not
  Verified — face detection could not confirm this student during precheck..."

No LearnShield equivalent — no shared face-verification precheck exists there.

## v2.0.157 — last 9 hardcoded violation scores made configurable, blank/missing screen-evidence fixes, suspension-status display bug (2026-09-05)

**All remaining hardcoded violation scores now admin-configurable**: v2.0.148 made 14
event types configurable but deliberately left 9 others hardcoded (`devtools_open`,
`devtools_f12`, `webcam_denied`, `screen_share_attempt`, `screen_share_stopped`,
`poor_lighting`, `unusual_display`, `vm_suspected`, `multi_monitor`) plus the
`OBJECT_VIOLATIONS` map (`book`, `laptop`, `remote`, `keyboard`, `headphones`) — at the
time treated as intentional/out of scope. Michael explicitly asked for `devtools_open`
(wants to raise it to 50) and, by extension, the rest — completing the same exhaustive
treatment as v2.0.148 for every remaining event type. New `score_*` settings added
(defaulting to the PREVIOUS hardcoded values, so nothing changes until explicitly
adjusted), threaded through `view.php`'s `scoreconfig`, `external.php`'s `log_event()`
live-reconfigure response, and every corresponding `assessment.js` call site — including
generalizing the object-detection score lookup (`lgObjectScore()`) that previously only
special-cased `cell phone`/phone to cover every class in `OBJECT_VIOLATIONS`.

**Screen-evidence gaps — two distinct root causes found and fixed**:
- **Blank/black screen captures**: confirmed live — 24 consecutive solid-black screen
  captures within ~4 seconds at the very start of a session, immediately followed by
  normal captures. The very first frames grabbed right as `getDisplayMedia()` starts
  delivering data can be solid black (full-sized, non-zero dimensions — passes the
  existing zero-dimension guard) before the browser's compositor actually hands over
  real content — a warm-up artifact, not a sharing failure. New `lgIsBlankFrame()`
  samples a handful of points on the captured canvas; if every sampled point is
  identical (almost always pure black), the frame is skipped rather than uploaded as a
  useless black square. Applied to both the `ImageCapture` path and the `<video>`
  element fallback in `captureScreenSnapshot()`.
- **Missing screen evidence for violations (reported: Escape key, DevTools)**:
  `captureScreenSnapshot()` was already silently returning with zero trace whenever
  `state.screenStream` was missing or its track wasn't live — indistinguishable from
  "there was nothing worth capturing." Now logs a `screen_capture_unavailable` marker
  event (0 score, no student-facing toast, debounced to once/minute) so a proctor
  reviewing an incident can see evidence is missing because of a technical gap in
  collection, not because nothing happened.

**Suspension History status bug**: `proctorview.php`'s history table only ever checked
`is_flagged` (set by "Confirm Suspension") while the attempt's state was still
`'suspended'` — reinstating a confirmed suspension (a proctor can decide a suspension
was genuine and still let the student finish for grading purposes) silently dropped the
status back to a plain "Reinstated — in progress" with no trace the suspension had ever
been confirmed. `is_flagged` now takes priority regardless of current state — shows
"Suspension Confirmed" with a "now: Inprogress"/etc. subtext for what happened to the
attempt afterward, instead of losing the confirmation from view entirely.

**Mail History surfaced from `proctorview.php`**: the existing Suspension Notices Log
(`suspensionnotices.php`, v2.0.132/150 — every Confirm Suspension / Reinstate & Notify
email ever sent, with sent/failed/skipped status) was previously only linked from
`storage.php` and `attemptdetail.php`. Added a "📧 Mail History" entry to
`proctorview.php`'s tab strip (links straight out to the existing page — no logic
duplicated), gated to the same `moodle/site:config` capability that page itself
requires, plus a matching button directly in the Suspended tab's header so a proctor
can jump straight from "who's suspended" to "did the email actually send" without
hunting for the page.

No schema change — no new upgrade.php savepoint needed.

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
