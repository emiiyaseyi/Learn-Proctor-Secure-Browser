<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Response Recovery — finds Essay/Choice Essay answers that currently show blank but
 * have real, previously-typed content sitting in learnguard_response_history (logged
 * automatically before every overwrite), and restores them with one click.
 *
 * Built after a live incident: a student's client-side question cache went stale on
 * navigating back through an already-answered exam, re-rendering already-answered
 * Essay questions as blank — and, before v2.0.221, nothing stopped the next autosave
 * from actually overwriting the real saved answer with that blank content. This page
 * is the cleanup tool for anything already lost before that fix shipped.
 *
 * URL: /mod/learnguard/responserecovery.php
 *   ?mode=impact                    — site-wide summary: recoverable count + unique
 *                                      students affected, per assessment.
 *   ?courseid=X&lgid=Y               — drill into one assessment to review/restore.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2026 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/question_engine.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$courseid   = optional_param('courseid',   0,  PARAM_INT);
$lgid       = optional_param('lgid',       0,  PARAM_INT);
$mode       = optional_param('mode',       '', PARAM_ALPHA);
$q          = optional_param('q',          '', PARAM_TEXT);
$attemptid  = optional_param('attemptid',  0,  PARAM_INT);

$PAGE->set_url('/mod/learnguard/responserecovery.php', ['courseid' => $courseid, 'lgid' => $lgid, 'mode' => $mode]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Response Recovery');
$PAGE->set_heading('LearnGuard — Response Recovery');
$PAGE->set_pagelayout('admin');

/**
 * Extract the plain typed text from a raw learnguard_responses(.history) JSON blob for
 * one specific slot, matching the exact same key-resolution/fallback logic grader.php's
 * own AJAX get_question handler already uses, so this tool can never disagree with what
 * the grader shows once restored.
 */
function lgrr_extract_text(?string $responsedataJson, int $slotnum, string $qtype): string {
    $decoded = json_decode((string)$responsedataJson, true) ?: [];
    $raw = $decoded["q{$slotnum}_answer"] ?? '';
    if ($raw === '' && !empty($decoded)) {
        foreach ($decoded as $v) { if (trim((string)$v) !== '') { $raw = (string)$v; break; } }
    }
    if ($qtype === 'choiceessay') {
        $ceDec = json_decode((string)$raw, true);
        return is_array($ceDec) ? (string)($ceDec['text'] ?? '') : '';
    }
    return (string)$raw;
}

/**
 * Scans one assessment's Essay/Choice Essay slots for attempts whose current saved
 * answer is either (a) completely blank, or (b) real but meaningfully SHORTER than a
 * version already recorded in learnguard_response_history — while having real, longer
 * content recorded there. Shared by both the per-assessment detail view and the
 * site-wide impact summary so they can never disagree about what counts as "recoverable."
 *
 * Case (b) was added after a live incident distinct from the original blank-overwrite
 * bug this tool was first built for: a student's full 279-word/1933-char answer was
 * overwritten by a 63-word/458-char fragment that was itself real (non-blank) content —
 * most likely the same stale client-side question-cache re-render that caused the
 * original incident, just landing on an earlier non-blank draft instead of a blank one,
 * which the save_response() anti-regression guard (only ever rejects a BLANK incoming
 * save) has no way to catch. The longest recorded version for a slot is always treated
 * as the real answer to offer for restoration, regardless of whether it's the most
 * recent history entry.
 *
 * @return array{rows: array, uniqueusers: int} rows = one entry per recoverable slot.
 */
function lgrr_scan_assessment(int $lgid): array {
    global $DB;

    $essaySlots = $DB->get_records_sql(
        "SELECT s.id AS slotid, s.slot, q.id AS qid, q.qtype
           FROM {learnguard_slots} s
           JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE s.learnguard = :lgid AND q.qtype IN ('essay', 'choiceessay')",
        ['lgid' => $lgid]
    );
    if (empty($essaySlots)) { return ['rows' => [], 'uniqueusers' => 0]; }
    $slotIds = array_keys($essaySlots);
    [$slotInSql, $slotInParams] = $DB->get_in_or_equal($slotIds, SQL_PARAMS_NAMED, 'sl');

    $attempts = $DB->get_records_sql(
        "SELECT a.id AS attemptid, a.userid, u.firstname, u.lastname
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard = :lgid AND a.state IN ('finished', 'suspended')",
        ['lgid' => $lgid]
    );
    if (empty($attempts)) { return ['rows' => [], 'uniqueusers' => 0]; }
    $attemptIds = array_keys($attempts);
    [$attInSql, $attInParams] = $DB->get_in_or_equal($attemptIds, SQL_PARAMS_NAMED, 'at');

    // slotnum resolution per attempt+slot (same as grader.php) — randomised assessments
    // can have a different attempt-sequential slot number than the bank slot's own value.
    $aqRows = $DB->get_records_sql(
        "SELECT attemptid, slotid, slotnum FROM {learnguard_attempt_questions}
          WHERE attemptid $attInSql AND slotid $slotInSql",
        array_merge($attInParams, $slotInParams)
    );
    $slotnumMap = [];
    foreach ($aqRows as $aq) { $slotnumMap[$aq->attemptid . '_' . $aq->slotid] = (int)$aq->slotnum; }

    $currentResponses = $DB->get_records_sql(
        "SELECT attemptid, slotid, responsedata FROM {learnguard_responses}
          WHERE attemptid $attInSql AND slotid $slotInSql",
        array_merge($attInParams, $slotInParams)
    );
    $currentMap = [];
    foreach ($currentResponses as $cr) { $currentMap[$cr->attemptid . '_' . $cr->slotid] = $cr->responsedata; }

    $historyRows = $DB->get_records_sql(
        "SELECT id, attemptid, slotid, responsedata, timecreated FROM {learnguard_response_history}
          WHERE attemptid $attInSql AND slotid $slotInSql
       ORDER BY attemptid, slotid, timecreated DESC",
        array_merge($attInParams, $slotInParams)
    );
    $historyByKey = [];
    foreach ($historyRows as $hr) { $historyByKey[$hr->attemptid . '_' . $hr->slotid][] = $hr; }

    $rows = [];
    $affectedUsers = [];
    foreach ($attempts as $att) {
        foreach ($essaySlots as $slot) {
            $key = $att->attemptid . '_' . $slot->slotid;
            $slotnum = $slotnumMap[$key] ?? (int)$slot->slot;
            $curText   = lgrr_extract_text($currentMap[$key] ?? '', $slotnum, $slot->qtype);
            $curIsBlank = \mod_learnguard\question_engine::is_essay_text_blank($curText);
            $curLen    = mb_strlen(strip_tags($curText));

            if (empty($historyByKey[$key])) { continue; }

            // Find the LONGEST non-blank historical version recorded for this slot —
            // the real answer, regardless of which save happened to land last.
            $bestHist = null;
            $bestHistText = '';
            $bestLen = 0;
            foreach ($historyByKey[$key] as $h) {
                $histText = lgrr_extract_text($h->responsedata, $slotnum, $slot->qtype);
                if (\mod_learnguard\question_engine::is_essay_text_blank($histText)) { continue; }
                $histLen = mb_strlen(strip_tags($histText));
                if ($histLen > $bestLen) {
                    $bestLen = $histLen;
                    $bestHist = $h;
                    $bestHistText = $histText;
                }
            }
            if ($bestHist === null) { continue; }

            // Flag when current is blank (original case), OR when current is real but
            // meaningfully shorter than the best recorded version (truncated-overwrite
            // case) — a small/trivial length difference (e.g. a punctuation tweak) is
            // not flagged, only a drop big enough to represent lost content.
            $meaningfullyShorter = !$curIsBlank && $bestLen > ($curLen + 20) && $bestLen > ($curLen * 1.15);
            if (!$curIsBlank && !$meaningfullyShorter) { continue; }

            $rows[] = [
                'historyid'   => (int)$bestHist->id,
                'attemptid'   => (int)$att->attemptid,
                'userid'      => (int)$att->userid,
                'student'     => fullname($att),
                'slot'        => (int)$slot->slot,
                'qtype'       => $slot->qtype,
                'type'        => $curIsBlank ? 'blank' : 'truncated',
                'cursnippet'  => $curIsBlank ? '' : mb_substr(strip_tags($curText), 0, 180),
                'curwords'    => $curIsBlank ? 0 : \mod_learnguard\question_engine::count_words($curText),
                'snippet'     => mb_substr(strip_tags($bestHistText), 0, 180),
                'words'       => \mod_learnguard\question_engine::count_words($bestHistText),
                'savedat'     => userdate((int)$bestHist->timecreated, '%d %b %Y, %H:%M'),
            ];
            $affectedUsers[$att->userid] = true;
        }
    }
    return ['rows' => $rows, 'uniqueusers' => count($affectedUsers)];
}

/**
 * Restores a set of learnguard_response_history rows back into their live
 * learnguard_responses row — shared by the single-assessment "Restore Selected" form
 * and the Impact Summary's "Restore All" bulk-by-assessment action, so both can never
 * behave differently from one another.
 */
function lgrr_restore_history_ids(array $historyIds): int {
    global $DB;
    $restoredCount = 0;
    foreach ($historyIds as $hid) {
        $h = $DB->get_record('learnguard_response_history', ['id' => (int)$hid]);
        if (!$h) { continue; }
        $existing = $DB->get_record('learnguard_responses', ['attemptid' => $h->attemptid, 'slotid' => $h->slotid]);
        if ($existing) {
            $existing->responsedata = $h->responsedata;
            $existing->state        = 'needsgrading';
            $existing->timemodified = time();
            $DB->update_record('learnguard_responses', $existing);
        } else {
            $DB->insert_record('learnguard_responses', (object)[
                'attemptid'    => $h->attemptid,
                'slotid'       => $h->slotid,
                'questionid'   => $h->questionid,
                'responsedata' => $h->responsedata,
                'fraction'     => 0,
                'state'        => 'needsgrading',
                'timecreated'  => time(),
                'timemodified' => time(),
                'lastcaptureat'=> 0,
            ]);
        }
        $restoredCount++;
    }
    return $restoredCount;
}

// ── Restore action (single assessment, ticked rows — or the Inspect Attempt view,
// which reuses this same action but returns to mode=inspect instead of the
// per-assessment list). ─────────────────────────────────────────────────
if (data_submitted() && confirm_sesskey() && optional_param('rraction', '', PARAM_ALPHA) === 'restore') {
    $historyIds = required_param_array('historyid', PARAM_INT);
    $restoredCount = lgrr_restore_history_ids($historyIds);
    $returnMode = optional_param('returnmode', '', PARAM_ALPHA);
    if ($returnMode === 'inspect') {
        $retAttemptId = optional_param('returnattemptid', 0, PARAM_INT);
        redirect(new moodle_url('/mod/learnguard/responserecovery.php', ['mode' => 'inspect', 'attemptid' => $retAttemptId]),
            $restoredCount . ' response(s) restored. Students\' answers are now visible again in the grader.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect(new moodle_url('/mod/learnguard/responserecovery.php', ['courseid' => $courseid, 'lgid' => $lgid]),
        $restoredCount . ' response(s) restored. Students\' answers are now visible again in the grader.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── Restore action (bulk — every recoverable answer across ticked assessments, from
// the Impact Summary view). Re-scans each ticked assessment fresh at submit time
// (never trusts whatever the page happened to show when it was rendered) so a row
// already restored by someone else in the meantime is never double-applied. ────────
if (data_submitted() && confirm_sesskey() && optional_param('rraction', '', PARAM_ALPHA) === 'restorebulk') {
    core_php_time_limit::raise(120);
    $bulkLgids = required_param_array('lgids', PARAM_INT);
    $totalRestored = 0;
    $totalAssessments = 0;
    foreach ($bulkLgids as $bulkLgid) {
        $bulkScan = lgrr_scan_assessment((int)$bulkLgid);
        if (empty($bulkScan['rows'])) { continue; }
        $ids = array_map(function($r) { return $r['historyid']; }, $bulkScan['rows']);
        $totalRestored += lgrr_restore_history_ids($ids);
        $totalAssessments++;
    }
    redirect(new moodle_url('/mod/learnguard/responserecovery.php', ['mode' => 'impact']),
        $totalRestored . ' response(s) restored across ' . $totalAssessments . ' assessment(s).',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Response Recovery');

echo '<div style="max-width:1200px;margin:0 auto;">';
echo html_writer::tag('h3', '🩹 Response Recovery');
echo html_writer::tag('p',
    'Finds Essay / Choice Essay answers that currently show blank OR meaningfully ' .
    'shorter/truncated in the grader, where a fuller version was recorded before it was ' .
    'overwritten — and restores it with one click. Only ever looks at Essay/Choice Essay ' .
    'slots (the affected question types).',
    ['style' => 'color:#6c757d;']);

// ── Course / assessment selector — each select is the ONLY field carrying its own
// name, so switching either one always submits its own real value (a duplicate hidden
// "courseid" field here previously shadowed the select's new value on submit, making
// switching courses silently revert to the old one). ──────────────────────────────
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname
       FROM {course} c
       JOIN {learnguard} lg ON lg.course = c.id
   ORDER BY c.fullname ASC"
);
echo '<div style="display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;align-items:center;">';
echo '<form method="get" style="display:inline;">';
echo '<select name="courseid" class="form-control" style="max-width:340px;" onchange="this.form.submit()">';
echo '<option value="0">— Select a course —</option>';
foreach ($courses as $c) {
    echo '<option value="' . (int)$c->id . '" ' . ($c->id == $courseid ? 'selected' : '') . '>' .
        s($c->shortname . ' — ' . $c->fullname) . '</option>';
}
echo '</select>';
echo '</form>';

$activities = [];
if ($courseid) {
    $activities = $DB->get_records_sql(
        "SELECT lg.id, lg.name, cm.id AS cmid
           FROM {learnguard} lg
           JOIN {course_modules} cm ON cm.instance = lg.id
              AND cm.module = (SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE lg.course = :cid AND cm.deletioninprogress = 0
       ORDER BY lg.name ASC",
        ['cid' => $courseid]
    );
    echo '<form method="get" style="display:inline;">';
    echo '<input type="hidden" name="courseid" value="' . (int)$courseid . '">';
    echo '<select name="lgid" class="form-control" style="max-width:340px;" onchange="this.form.submit()">';
    echo '<option value="0">— Select an assessment —</option>';
    foreach ($activities as $a) {
        echo '<option value="' . (int)$a->id . '" ' . ($a->id == $lgid ? 'selected' : '') . '>' .
            s($a->name) . '</option>';
    }
    echo '</select>';
    echo '</form>';
}
echo '<a href="' . (new moodle_url('/mod/learnguard/responserecovery.php', ['mode' => 'impact']))->out(false) .
    '" class="btn btn-outline-primary btn-sm">📊 Impact Summary (all assessments)</a>';
echo '</div>';

// ── Inspect by Student/Attempt — direct manual lookup, independent of the automatic
// truncation heuristic above. Built after a live case where a flagged student still
// didn't show up under the assessment's own recoverable list despite being the exact
// case that prompted the fix — this shows EVERY recorded version for every essay/
// choiceessay slot on a specific attempt (not just the single "best" one the automatic
// scan picks), so an admin can see directly what the server actually has on record and
// restore any specific version manually, even one the heuristic wouldn't have flagged. ──
echo '<form method="get" style="display:flex;gap:8px;margin:0 0 16px;align-items:center;flex-wrap:wrap;">';
echo '<input type="hidden" name="mode" value="find">';
echo '<input type="text" name="q" value="' . s($q) . '" class="form-control" ' .
    'style="max-width:320px;" placeholder="🔍 Find a student by name or email…">';
echo '<button type="submit" class="btn btn-outline-secondary btn-sm">Find Student\'s Attempts</button>';
echo '</form>';

// ── Find — list every attempt matching the typed name/email, across every course. ──
if ($mode === 'find') {
    $results = [];
    if (trim($q) !== '') {
        $params = ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%', 'q4' => '%' . $q . '%'];
        $like1 = $DB->sql_like('u.firstname', ':q1', false);
        $like2 = $DB->sql_like('u.lastname', ':q2', false);
        $like3 = $DB->sql_like('u.email', ':q3', false);
        $like4 = $DB->sql_like($DB->sql_concat('u.firstname', "' '", 'u.lastname'), ':q4', false);
        $results = $DB->get_records_sql(
            "SELECT a.id AS attemptid, a.state, a.timefinish, u.firstname, u.lastname, u.email,
                    lg.name AS lgname, c.fullname AS coursename
               FROM {learnguard_attempts} a
               JOIN {user} u ON u.id = a.userid
               JOIN {learnguard} lg ON lg.id = a.learnguard
               JOIN {course} c ON c.id = lg.course
              WHERE ($like1 OR $like2 OR $like3 OR $like4)
           ORDER BY a.timefinish DESC",
            $params
        );
    }
    echo html_writer::tag('h4', '🔍 Find a Student\'s Attempts');
    if (trim($q) === '') {
        echo html_writer::div('Type a name or email above and click Find.', 'alert alert-secondary');
    } else if (empty($results)) {
        echo html_writer::div('No attempts found matching "' . s($q) . '".', 'alert alert-secondary');
    } else {
        echo '<table class="generaltable w-100" style="font-size:13px;">';
        echo '<tr><th>Student</th><th>Email</th><th>Course</th><th>Assessment</th><th>State</th><th>Finished</th><th></th></tr>';
        foreach ($results as $r) {
            echo '<tr>';
            echo '<td>' . s($r->firstname . ' ' . $r->lastname) . '</td>';
            echo '<td>' . s($r->email) . '</td>';
            echo '<td>' . s($r->coursename) . '</td>';
            echo '<td>' . s($r->lgname) . '</td>';
            echo '<td>' . s($r->state) . '</td>';
            echo '<td>' . ($r->timefinish ? userdate((int)$r->timefinish, '%d %b %Y, %H:%M') : '—') . '</td>';
            echo '<td><a href="' . (new moodle_url('/mod/learnguard/responserecovery.php',
                ['mode' => 'inspect', 'attemptid' => $r->attemptid]))->out(false) .
                '" class="btn btn-sm btn-primary">🔬 Inspect This Attempt →</a></td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

// ── Inspect one attempt — EVERY recorded version of EVERY essay/choiceessay slot,
// current + full history, each individually restorable. This is the tool for a
// specific reported case: it shows exactly what the server has, instead of relying on
// the automatic scan's "longest vs current" heuristic, which can miss a case where the
// fuller answer was never actually persisted server-side at all (only ever existed in
// the student's browser before being lost) — something no scan of the database can
// recover, and this view makes that distinction directly visible rather than silently
// looking like "nothing to do here." ─────────────────────────────────────────────
if ($mode === 'inspect' && $attemptid) {
    $att = $DB->get_record('learnguard_attempts', ['id' => $attemptid]);
    if (!$att) {
        echo html_writer::div('Attempt not found.', 'alert alert-danger');
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }
    $student = $DB->get_record('user', ['id' => $att->userid]);
    $lg      = $DB->get_record('learnguard', ['id' => $att->learnguard]);
    $cmRow   = $DB->get_record_sql(
        "SELECT cm.id FROM {course_modules} cm
          WHERE cm.instance = :lgid AND cm.module = (SELECT id FROM {modules} WHERE name = 'learnguard')",
        ['lgid' => $lg->id]
    );

    echo html_writer::tag('h4', '🔬 Inspect Attempt — ' . s(fullname($student)) . ' — ' . s($lg->name));
    echo html_writer::div(
        'Attempt state: <strong>' . s($att->state) . '</strong> · Finished: ' .
        ($att->timefinish ? userdate((int)$att->timefinish, '%d %b %Y, %H:%M') : '—') .
        ($cmRow ? ' · <a href="' . (new moodle_url('/mod/learnguard/grader.php', ['id' => $cmRow->id]))->out(false) .
            '" target="_blank">Open Grader ↗</a>' : ''),
        'alert alert-secondary');

    $slots = $DB->get_records_sql(
        "SELECT s.id AS slotid, s.slot, q.id AS qid, q.qtype
           FROM {learnguard_slots} s
           JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE s.learnguard = :lgid AND q.qtype IN ('essay', 'choiceessay')
       ORDER BY s.slot ASC",
        ['lgid' => $lg->id]
    );

    if (empty($slots)) {
        echo html_writer::div('This assessment has no Essay/Choice Essay slots.', 'alert alert-secondary');
    }

    foreach ($slots as $slot) {
        $aq = $DB->get_record('learnguard_attempt_questions', ['attemptid' => $attemptid, 'slotid' => $slot->slotid]);
        $slotnum = $aq ? (int)$aq->slotnum : (int)$slot->slot;

        $current = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slot->slotid]);
        $curText = $current ? lgrr_extract_text($current->responsedata, $slotnum, $slot->qtype) : '';
        $curLen  = mb_strlen(strip_tags($curText));
        $curWords = \mod_learnguard\question_engine::count_words($curText);

        echo '<div style="border:1px solid #dee2e6;border-radius:6px;padding:14px;margin:14px 0;">';
        echo '<h5>Q' . (int)$slot->slot . ' (' . s($slot->qtype) . ') — resolved slot# for this attempt: ' . $slotnum . '</h5>';
        echo '<div style="background:#f8d7da;padding:8px;border-radius:4px;margin-bottom:10px;">';
        echo '<strong>Current (live in grader):</strong> ' . $curWords . ' words, ' . $curLen . ' characters<br>';
        echo '<span style="font-size:12px;">' . ($curText === '' ? '<em>Blank</em>' : s(mb_substr(strip_tags($curText), 0, 300))) . '</span>';
        echo '</div>';

        $history = $DB->get_records_sql(
            "SELECT id, responsedata, timecreated FROM {learnguard_response_history}
              WHERE attemptid = :aid AND slotid = :sid
           ORDER BY timecreated DESC",
            ['aid' => $attemptid, 'sid' => $slot->slotid]
        );

        if (empty($history)) {
            echo html_writer::div(
                'No historical versions recorded for this slot. This means the server never ' .
                'received a save for this question other than what\'s shown above — if the ' .
                'student had typed more content than this, it was lost before ever reaching ' .
                'the server (e.g. a failed/never-sent save), and cannot be recovered from this ' .
                'database. The grader has nothing else to go on here.',
                'alert alert-warning');
        } else {
            echo '<form method="post">';
            echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
            echo '<input type="hidden" name="rraction" value="restore">';
            echo '<input type="hidden" name="returnmode" value="inspect">';
            echo '<input type="hidden" name="returnattemptid" value="' . (int)$attemptid . '">';
            echo '<table class="generaltable w-100" style="font-size:12px;">';
            echo '<tr><th style="width:30px;"></th><th>Saved At</th><th>Words</th><th>Characters</th><th>Preview</th></tr>';
            foreach ($history as $h) {
                $hText = lgrr_extract_text($h->responsedata, $slotnum, $slot->qtype);
                $hLen  = mb_strlen(strip_tags($hText));
                $hWords = \mod_learnguard\question_engine::count_words($hText);
                echo '<tr>';
                echo '<td><input type="checkbox" name="historyid[]" value="' . (int)$h->id . '"></td>';
                echo '<td>' . userdate((int)$h->timecreated, '%d %b %Y, %H:%M:%S') . '</td>';
                echo '<td>' . $hWords . '</td>';
                echo '<td>' . $hLen . '</td>';
                echo '<td>' . ($hText === '' ? '<em>Blank</em>' : s(mb_substr(strip_tags($hText), 0, 180))) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '<button type="submit" class="btn btn-primary btn-sm mt-2" ' .
                'onclick="return confirm(\'Restore the ticked version into the live response? This overwrites what the grader currently shows for this question.\')">' .
                '✅ Restore Ticked Version</button>';
            echo '</form>';
        }
        echo '</div>';
    }

    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

// ── Impact Summary — site-wide, per assessment: how many recoverable answers, how many
// unique students affected. Scanning every activity is heavier than the single-assessment
// view, but this is a rarely-run admin diagnostic, not a hot path. ────────────────────
if ($mode === 'impact') {
    $allLg = $DB->get_records_sql(
        "SELECT DISTINCT lg.id AS lgid, lg.name, lg.course, c.fullname AS coursename, cm.id AS cmid
           FROM {learnguard} lg
           JOIN {learnguard_slots} s ON s.learnguard = lg.id
           JOIN {learnguard_qbank} q ON q.id = s.questionid AND q.qtype IN ('essay', 'choiceessay')
           JOIN {course} c ON c.id = lg.course
           JOIN {course_modules} cm ON cm.instance = lg.id
              AND cm.module = (SELECT id FROM {modules} WHERE name = 'learnguard') AND cm.deletioninprogress = 0
       ORDER BY c.fullname ASC, lg.name ASC"
    );
    $summary = [];
    $totalRecoverable = 0;
    $totalUniqueOverall = [];
    foreach ($allLg as $row) {
        $scan = lgrr_scan_assessment((int)$row->lgid);
        if (empty($scan['rows'])) { continue; }
        $totalRecoverable += count($scan['rows']);
        foreach ($scan['rows'] as $r) { $totalUniqueOverall[$r['userid']] = true; }
        $summary[] = [
            'coursename' => $row->coursename,
            'lgname'     => $row->name,
            'courseid'   => (int)$row->course,
            'lgid'       => (int)$row->lgid,
            'count'      => count($scan['rows']),
            'unique'     => $scan['uniqueusers'],
        ];
    }
    echo html_writer::tag('h4', '📊 Impact Summary — Site-Wide');
    if (empty($summary)) {
        echo html_writer::div('✅ No recoverable answers found anywhere on the site.', 'alert alert-success');
    } else {
        echo html_writer::div(
            'Totals across every affected assessment: <strong>' . $totalRecoverable . '</strong> recoverable answer(s), ' .
            '<strong>' . count($totalUniqueOverall) . '</strong> unique student(s) affected.',
            'alert alert-warning');

        echo '<form method="post" id="rr-bulk-form">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        echo '<input type="hidden" name="rraction" value="restorebulk">';
        echo '<div style="margin-bottom:8px;">';
        echo '<input type="checkbox" id="rr-bulk-all" onchange="document.querySelectorAll(\'.rr-bulk-cb\').forEach(c=>{c.checked=this.checked;})"> ';
        echo '<label for="rr-bulk-all" style="cursor:pointer;">Select All Assessments</label> ';
        echo '<button type="submit" class="btn btn-primary btn-sm" onclick="return confirm(\'Restore EVERY recoverable answer (blank or truncated) for every ticked assessment? This cannot be selectively undone afterward — review individual assessments first if you want to check specific answers before restoring.\')">✅ Restore All Recoverable Answers (Ticked Assessments)</button>';
        echo '</div>';
        echo '<table class="generaltable w-100" style="font-size:13px;">';
        echo '<tr><th style="width:30px;"></th><th>Course</th><th>Assessment</th><th>Recoverable Answers</th><th>Unique Students Affected</th><th></th></tr>';
        foreach ($summary as $s) {
            echo '<tr>';
            echo '<td><input type="checkbox" class="rr-bulk-cb" name="lgids[]" value="' . $s['lgid'] . '"></td>';
            echo '<td>' . s($s['coursename']) . '</td>';
            echo '<td>' . s($s['lgname']) . '</td>';
            echo '<td style="text-align:center;font-weight:700;color:#dc3545;">' . $s['count'] . '</td>';
            echo '<td style="text-align:center;font-weight:700;">' . $s['unique'] . '</td>';
            echo '<td><a href="' . (new moodle_url('/mod/learnguard/responserecovery.php',
                ['courseid' => $s['courseid'], 'lgid' => $s['lgid']]))->out(false) .
                '" class="btn btn-sm btn-primary">Review &amp; Restore →</a></td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</form>';
    }
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

if (!$courseid) {
    echo html_writer::div('Select a course above, or run the Impact Summary to see every affected assessment site-wide.', 'alert alert-secondary');
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}
if (!$lgid || !isset($activities[$lgid])) {
    echo html_writer::div('Select an assessment above to scan it for recoverable answers.', 'alert alert-secondary');
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

// ── Per-assessment detail view ──────────────────────────────────────────
$scan = lgrr_scan_assessment($lgid);
$recoverable = $scan['rows'];

if (empty($recoverable)) {
    echo html_writer::div('✅ No blank or truncated recoverable Essay/Choice Essay answers found for this assessment.', 'alert alert-success');
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$truncatedCount = count(array_filter($recoverable, fn($r) => $r['type'] === 'truncated'));
echo html_writer::div(
    count($recoverable) . ' recoverable answer(s) found (' . $truncatedCount . ' truncated, ' .
    (count($recoverable) - $truncatedCount) . ' blank), across <strong>' . $scan['uniqueusers'] .
    '</strong> unique student(s).',
    'alert alert-warning');

echo '<input type="text" id="rr-search" class="form-control" placeholder="🔎 Search by student name…" ' .
    'style="max-width:320px;margin-bottom:10px;" onkeyup="lgrrFilter(this.value)">';

echo '<form method="post">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="rraction" value="restore">';
echo '<input type="hidden" name="courseid" value="' . (int)$courseid . '">';
echo '<input type="hidden" name="lgid" value="' . (int)$lgid . '">';
echo '<div style="margin-bottom:8px;">';
echo '<input type="checkbox" id="rr-all" onchange="document.querySelectorAll(\'.rr-cb\').forEach(c=>{if(c.closest(\'tr\').style.display!==\'none\'){c.checked=this.checked;}})"> ';
echo '<label for="rr-all" style="cursor:pointer;">Select All Visible</label> ';
echo '<button type="submit" class="btn btn-primary btn-sm" onclick="return confirm(\'Restore the selected answer(s)? This will overwrite the current (blank or truncated) response with the fuller recovered text shown in the green column.\')">✅ Restore Selected</button>';
echo '</div>';
echo '<table class="generaltable w-100" style="font-size:13px;" id="rr-table">';
echo '<tr><th style="width:30px;"></th><th>Student</th><th>Question</th><th>Type</th>' .
     '<th style="background:#f8d7da;">Grader Currently Shows</th>' .
     '<th style="background:#d4edda;">Will Be Restored To</th>' .
     '<th>Words</th><th>Was Typed</th><th></th></tr>';
foreach ($recoverable as $r) {
    echo '<tr data-student="' . s(mb_strtolower($r['student'])) . '">';
    echo '<td><input type="checkbox" class="rr-cb" name="historyid[]" value="' . $r['historyid'] . '"></td>';
    echo '<td>' . s($r['student']) . '</td>';
    echo '<td>Q' . $r['slot'] . ' (' . s($r['qtype']) . ')</td>';
    echo '<td>' . ($r['type'] === 'blank'
        ? '<span class="badge badge-secondary">Blank</span>'
        : '<span class="badge badge-warning">Truncated</span>') . '</td>';
    if ($r['type'] === 'blank') {
        echo '<td style="color:#dc3545;font-style:italic;">Blank / No response submitted</td>';
    } else {
        echo '<td style="color:#dc3545;">' . s($r['cursnippet']) .
            (mb_strlen($r['cursnippet']) >= 180 ? '…' : '') . ' <em>(' . $r['curwords'] . ' words)</em></td>';
    }
    echo '<td style="color:#155724;">' . s($r['snippet']) . (mb_strlen($r['snippet']) >= 180 ? '…' : '') . '</td>';
    echo '<td>' . $r['words'] . '</td>';
    echo '<td>' . s($r['savedat']) . '</td>';
    echo '<td><a href="' . (new moodle_url('/mod/learnguard/grader.php', ['id' => $activities[$lgid]->cmid]))->out(false) .
        '" target="_blank" class="btn btn-sm btn-outline-secondary">Open Grader ↗</a></td>';
    echo '</tr>';
}
echo '</table>';
echo '</form>';

echo '<script>
function lgrrFilter(q) {
    q = q.toLowerCase();
    document.querySelectorAll("#rr-table tr[data-student]").forEach(function(tr) {
        tr.style.display = tr.getAttribute("data-student").indexOf(q) === -1 ? "none" : "";
    });
}
</script>';

echo '</div>';
echo $OUTPUT->footer();
