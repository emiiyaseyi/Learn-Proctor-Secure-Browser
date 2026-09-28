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
 * Required Moodle callbacks for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();

// -----------------------------------------------------------------------
// Feature support
// -----------------------------------------------------------------------

/**
 * Declare which Moodle module features this plugin supports.
 *
 * @param string $feature FEATURE_xx constant
 * @return bool|null
 */
function learnguard_supports(string $feature): ?bool {
    switch ($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_MOD_PURPOSE:             return MOD_PURPOSE_ASSESSMENT;
        default:                              return null;
    }
}

// -----------------------------------------------------------------------
// Instance management
// -----------------------------------------------------------------------

/**
 * Called when a new LearnGuard activity is created.
 *
 * @param stdClass $data Data from the module form.
 * @param mod_learnguard_mod_form|null $mform
 * @return int  New instance ID.
 */
function learnguard_add_instance(stdClass $data, $mform = null): int {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = time();

    // Ensure boolean flags are saved correctly.
    $data->proctoring         = (int)($data->proctoring         ?? 1);
    $data->proctor_fullscreen = (int)($data->proctor_fullscreen ?? 1);
    $data->proctor_webcam     = (int)($data->proctor_webcam     ?? 1);
    $data->proctor_audio      = (int)($data->proctor_audio      ?? 1);
    $data->proctor_tabdetect  = (int)($data->proctor_tabdetect  ?? 1);
    $data->proctor_keyblock   = (int)($data->proctor_keyblock   ?? 1);
    $data->proctor_clipboard  = (int)($data->proctor_clipboard  ?? 1);
    $data->proctor_env_record = (int)($data->proctor_env_record ?? 1);
    $data->block_mobile       = (int)($data->block_mobile       ?? 1);

    // pathway_other_units is a multi-select array, not a column on {learnguard} — pull it
    // out before insert_record() (which would otherwise try to write an array value) and
    // persist it to its own pivot table below.
    $pathwayOtherUnits = $data->pathway_other_units ?? null;
    unset($data->pathway_other_units);

    // Rubric criteria rows come from mform's repeat_elements as parallel arrays, not
    // columns on {learnguard} — pull them out the same way before insert_record().
    $rubricNames   = $data->rubric_name   ?? null;
    $rubricWeights = $data->rubric_weight ?? null;
    $rubricFocuses = $data->rubric_focus  ?? null;
    unset($data->rubric_name, $data->rubric_weight, $data->rubric_focus,
        $data->rubric_criteria_repeats, $data->rubric_criteria_add, $data->rubric_criteria_delete);

    $id = $DB->insert_record('learnguard', $data);
    $data->id = $id;

    learnguard_save_pathway_other_units($id, $pathwayOtherUnits);
    learnguard_save_rubric_criteria($id, $rubricNames, $rubricWeights, $rubricFocuses);

    learnguard_grade_item_update($data);

    // Save grade to pass if provided.
    if (isset($data->gradepass)) {
        learnguard_grade_item_update($data, null, (float)$data->gradepass);
    }

    return $id;
}

/**
 * Called when an existing LearnGuard activity is updated.
 *
 * @param stdClass $data
 * @param mod_learnguard_mod_form|null $mform
 * @return bool
 */
function learnguard_update_instance(stdClass $data, $mform = null): bool {
    global $DB;

    $data->id           = $data->instance;
    $data->timemodified = time();

    $pathwayOtherUnits = $data->pathway_other_units ?? null;
    unset($data->pathway_other_units);

    $rubricNames   = $data->rubric_name   ?? null;
    $rubricWeights = $data->rubric_weight ?? null;
    $rubricFocuses = $data->rubric_focus  ?? null;
    unset($data->rubric_name, $data->rubric_weight, $data->rubric_focus,
        $data->rubric_criteria_repeats, $data->rubric_criteria_add, $data->rubric_criteria_delete);

    $DB->update_record('learnguard', $data);

    learnguard_save_pathway_other_units($data->id, $pathwayOtherUnits);
    learnguard_save_rubric_criteria($data->id, $rubricNames, $rubricWeights, $rubricFocuses);

    $gradepass = isset($data->gradepass) ? (float)$data->gradepass : null;
    learnguard_grade_item_update($data, null, $gradepass);

    return true;
}

/**
 * Replaces the "other units" pool for one activity's pathway feature with the given
 * list of unit ids. $unitids may be null (mform submitted no pathway_other_units at
 * all, e.g. the select was never rendered because no Grading Units exist yet) — treated
 * as "leave whatever is currently stored untouched", not "clear it".
 */
function learnguard_save_pathway_other_units(int $learnguardid, ?array $unitids): void {
    global $DB;
    if ($unitids === null) {
        return;
    }
    $DB->delete_records('learnguard_pathway_other_units', ['learnguard' => $learnguardid]);
    foreach (array_unique(array_map('intval', $unitids)) as $unitid) {
        if ($unitid <= 0) { continue; }
        $DB->insert_record('learnguard_pathway_other_units', (object)[
            'learnguard' => $learnguardid,
            'unitid'     => $unitid,
        ]);
    }
}

/**
 * Replaces one activity's Choice Essay grading rubric with the given rows, from
 * mform's repeat_elements parallel arrays. $names being null means the form never
 * submitted rubric fields at all — leaves whatever is currently stored untouched.
 * Blank-named rows (unused repeat slots) are silently skipped, matching how Choice
 * Essay's own prompt rows already handle unused repeated inputs.
 */
function learnguard_save_rubric_criteria(int $learnguardid, ?array $names, ?array $weights, ?array $focuses): void {
    global $DB;
    if ($names === null) {
        return;
    }
    $DB->delete_records('learnguard_rubric_criteria', ['learnguard' => $learnguardid]);
    $sortorder = 0;
    foreach ($names as $i => $name) {
        $name = trim((string)$name);
        if ($name === '') { continue; }
        $DB->insert_record('learnguard_rubric_criteria', (object)[
            'learnguard' => $learnguardid,
            'name'       => clean_param($name, PARAM_TEXT),
            'weight'     => max(0, (int)($weights[$i] ?? 0)),
            'focus'      => clean_param((string)($focuses[$i] ?? ''), PARAM_TEXT),
            'sortorder'  => $sortorder++,
        ]);
    }
}

/**
 * Choice Essay's "prompts per student" / "word limit" / "instructions" are set once per
 * ASSESSMENT (editquestions.php's Choice Essay tab), not per question. The underlying
 * per-question columns on learnguard_qbank still exist and are what every renderer/
 * grader actually reads at runtime — this keeps that whole call chain (question_engine,
 * grader.php, gradeessays.php, grader_user_report.php, questionimport.php) unchanged.
 * Call this any time the assessment-level values change, or right after a Choice Essay
 * question is added to this assessment, so every such question's stored columns stay in
 * sync with the one assessment-level source of truth.
 */
/**
 * Splits pasted text into standalone Choice Essay questions — one blank-line-separated
 * paragraph = one question with exactly one prompt. No grouping, no directives, no title
 * required: the title is auto-generated from the prompt's own text, since these are meant
 * to each stand entirely on their own (superseding the earlier multi-prompt-per-question
 * grouped format with TITLE:/MARKS:/SHOW:/GUIDE: directives). Shared by editquestions.php's
 * Choice Essay tab widget and questionimport.php's Choice Essay format tab, so both use
 * identical parsing.
 */
function learnguard_parse_ce_standalone(string $content): array {
    $content = trim($content);
    $paras = preg_split('/\n{2,}/', $content);
    // No blank line anywhere in the whole paste, but more than one line — almost
    // certainly one question per line (e.g. pasted from a numbered/bulleted list where
    // each item is its own line with no blank line after it), not one giant multi-line
    // question. Fall back to splitting by line in that specific case only — if the
    // admin genuinely wants a single multi-line question, leaving at least one blank
    // line somewhere in the paste (even just after that one question) keeps it intact.
    if (count($paras) === 1) {
        $singleLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $paras[0])), fn($l) => $l !== ''));
        if (count($singleLines) > 1) {
            $paras = $singleLines;
        }
    }
    // A line/paragraph that's genuinely blank once you decode HTML entities and strip
    // tags — e.g. a bare "&nbsp;" left behind between items when pasting a numbered/
    // bulleted list out of Word or Google Docs into the rich-text box. PHP's own
    // trim()/empty() never treat "&nbsp;" (or the decoded U+00A0 non-breaking space) as
    // blank, so without this these show up as extra "questions" with invisible content
    // sitting between the real ones.
    $isBlankLine = function (string $s): bool {
        $decoded = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace("\xC2\xA0", ' ', $decoded); // U+00A0 non-breaking space
        return trim($decoded) === '';
    };
    $out = [];
    foreach ($paras as $para) {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', trim($para))),
            fn($l) => $l !== '' && !$isBlankLine($l)));
        if (empty($lines)) { continue; }
        $prompttext = implode('<br>', $lines);
        $plaintext  = trim(strip_tags($prompttext));
        if ($isBlankLine($prompttext)) { continue; }
        $out[] = [
            'title'      => mb_substr($plaintext, 0, 100),
            'prompttext' => $prompttext,
        ];
    }
    return $out;
}

function learnguard_sync_ce_settings_to_questions(int $learnguardid): void {
    global $DB;
    $lg = $DB->get_record('learnguard', ['id' => $learnguardid],
        'ce_prompts_count, ce_word_limit, ce_instructions, ce_allow_back, ce_back_limit');
    if (!$lg) { return; }
    $qids = $DB->get_fieldset_sql(
        "SELECT DISTINCT q.id
           FROM {learnguard_slots} s
           JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE s.learnguard = ? AND s.attemptid = 0 AND q.qtype = 'choiceessay'",
        [$learnguardid]
    );
    if (empty($qids)) { return; }
    [$insql, $inparams] = $DB->get_in_or_equal($qids);
    $DB->execute(
        "UPDATE {learnguard_qbank}
            SET show_prompts_count = ?, word_limit = ?, ce_instructions = ?,
                ce_allow_back = ?, ce_back_limit = ?
          WHERE id $insql",
        array_merge([$lg->ce_prompts_count, $lg->ce_word_limit, $lg->ce_instructions,
            $lg->ce_allow_back, $lg->ce_back_limit], $inparams)
    );
}

/**
 * Permanently delete a single attempt and every row that references it — the
 * attempt-scoped subset of learnguard_delete_instance()'s cascade, for tools (Score
 * Matrix's bulk delete) that need to remove one specific attempt rather than a whole
 * activity. Unlike voiding (state='voided', kept visible for history), this removes
 * the row entirely, so it also disappears from any report built from learnguard_attempts.
 *
 * @param int $attemptid Attempt ID.
 * @return void
 */
function learnguard_delete_single_attempt(int $attemptid): void {
    global $DB;
    $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) {
        return;
    }
    if ($attempt->uniqueid) {
        question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    }
    $DB->delete_records('learnguard_snapshots',         ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_events',            ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_attempt_questions', ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_responses',         ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_response_history',  ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_essay_grades',      ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_audio_clips',       ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_attempt_sections',  ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_messages',          ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_rubric_scores',     ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_attempts',          ['id' => $attemptid]);
}

/**
 * Void every attempt under one assessment — the whole-assessment equivalent of the
 * existing per-attempt "Void" action (state='voided', history kept and still visible,
 * fully reversible). Only finished/suspended attempts are touched, same as the
 * per-attempt and bulk void actions on scorematrix.php — a currently in-progress
 * attempt (a live exam session) is deliberately left alone.
 *
 * @param int $lgid learnguard instance id
 * @return int number of attempts voided
 */
function learnguard_void_assessment_attempts(int $lgid): int {
    global $DB;
    $count = $DB->count_records_select('learnguard_attempts',
        "learnguard = :lgid AND state IN ('finished', 'suspended')", ['lgid' => $lgid]);
    if ($count > 0) {
        // prevstate/timevoided let learnguard_reverse_void_attempt() undo this exactly —
        // "SET prevstate = state, ..." reads the OLD state for every row in the same
        // UPDATE statement before state itself gets overwritten below it, so this is
        // safe as one bulk statement rather than looping 769 individual updates.
        $DB->execute(
            "UPDATE {learnguard_attempts} SET prevstate = state, timevoided = :now, state = 'voided'
              WHERE learnguard = :lgid AND state IN ('finished', 'suspended')",
            ['lgid' => $lgid, 'now' => time()]
        );
    }
    return $count;
}

/**
 * Void a single attempt, recording what it was before so
 * learnguard_reverse_void_attempt() can restore it exactly. The one place every
 * single-attempt void action should go through instead of a bare set_field — a
 * "mistaken bulk void" (e.g. clicking Void This Assessment) needs to be undoable,
 * which a plain state='voided' write with no memory of the prior state never was.
 *
 * @param int $attemptid
 * @param string $currentstate the attempt's state BEFORE voiding (caller almost always
 *        already has this loaded — pass it to skip a redundant lookup).
 * @return bool true if the attempt was actually voided (false if already voided).
 */
function learnguard_void_attempt(int $attemptid, string $currentstate): bool {
    global $DB;
    if ($currentstate === 'voided') { return false; }
    $DB->set_field('learnguard_attempts', 'prevstate',  $currentstate, ['id' => $attemptid]);
    $DB->set_field('learnguard_attempts', 'timevoided',  time(),        ['id' => $attemptid]);
    $DB->set_field('learnguard_attempts', 'state',       'voided',      ['id' => $attemptid]);
    return true;
}

/**
 * Reverse a void — restores the attempt to whatever state it was in before, using the
 * recorded prevstate when available (every void since this feature shipped records it).
 * An attempt voided by an OLDER version of the code (no prevstate on record) falls back
 * to a best-effort guess: 'suspended' if it has any suspension trace on it, else
 * 'finished' — the overwhelmingly common case, since a live in-progress attempt is
 * never a candidate for voiding in the first place.
 *
 * @param int $attemptid
 * @return string|false the state it was restored to, or false if it wasn't voided.
 */
function learnguard_reverse_void_attempt(int $attemptid) {
    global $DB;
    $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt || $attempt->state !== 'voided') { return false; }
    $restoreState = !empty($attempt->prevstate)
        ? $attempt->prevstate
        : (!empty($attempt->timesuspended) ? 'suspended' : 'finished');
    $DB->set_field('learnguard_attempts', 'state',      $restoreState, ['id' => $attemptid]);
    $DB->set_field('learnguard_attempts', 'prevstate',  '',            ['id' => $attemptid]);
    $DB->set_field('learnguard_attempts', 'timevoided', 0,             ['id' => $attemptid]);
    return $restoreState;
}

/**
 * Called when a LearnGuard activity is deleted.
 *
 * @param int $id Instance ID.
 * @return bool
 */
function learnguard_delete_instance(int $id): bool {
    global $DB;

    if (!$instance = $DB->get_record('learnguard', ['id' => $id])) {
        return false;
    }

    // Attempt-scoped tables — cascade via every attempt on this activity. This list had
    // fallen behind the schema: attempt_questions, responses, response_history,
    // essay_grades, audio_clips, and attempt_sections were never cleaned up, left as
    // orphaned rows after every previous activity deletion.
    $attempts = $DB->get_records('learnguard_attempts', ['learnguard' => $id]);
    $attemptIds = array_keys($attempts);
    foreach ($attempts as $attempt) {
        if ($attempt->uniqueid) {
            question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
        }
    }
    if (!empty($attemptIds)) {
        list($insql, $inparams) = $DB->get_in_or_equal($attemptIds, SQL_PARAMS_NAMED);
        $DB->delete_records_select('learnguard_snapshots',         "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_events',            "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_attempt_questions', "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_responses',         "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_response_history',  "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_essay_grades',      "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_audio_clips',       "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_attempt_sections',  "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_rubric_scores',     "attemptid $insql", $inparams);
    }
    $DB->delete_records('learnguard_attempts', ['learnguard' => $id]);

    // Precheck-time tables — keyed by course module id, not the activity id.
    $cm = get_coursemodule_from_instance('learnguard', $id, 0, false, IGNORE_MISSING);
    if ($cm) {
        $approvalIds = $DB->get_fieldset_select('learnguard_face_approvals', 'id', 'cmid = :cmid', ['cmid' => $cm->id]);
        if (!empty($approvalIds)) {
            list($ainsql, $ainparams) = $DB->get_in_or_equal($approvalIds, SQL_PARAMS_NAMED);
            $DB->delete_records_select('learnguard_fa_messages', "approvalid $ainsql", $ainparams);
        }
        $DB->delete_records('learnguard_face_approvals',    ['cmid' => $cm->id]);
        $DB->delete_records('learnguard_precheck_sessions', ['cmid' => $cm->id]);
    }

    // Activity-scoped tables. Note: learnguard_qbank/_qbank_cats/_qbank_answers/
    // _ce_prompts and learnguard_learner_cohorts are deliberately NOT deleted here —
    // questions are a shared bank that other activities' slots may still reference,
    // and cohorts are a per-course resource independent of any one activity.
    $DB->delete_records('learnguard_slots',              ['learnguard' => $id]);
    $DB->delete_records('learnguard_pools',               ['learnguard' => $id]);
    $DB->delete_records('learnguard_pathway_other_units', ['learnguard' => $id]);
    $DB->delete_records('learnguard_rubric_criteria',     ['learnguard' => $id]);
    $DB->delete_records('learnguard_proctors',            ['learnguard' => $id]);
    $DB->delete_records('learnguard_graders',             ['learnguard' => $id]);
    $DB->delete_records('learnguard_incidents',           ['learnguard' => $id]);
    $DB->delete_records('learnguard_messages',            ['learnguard' => $id]);
    $DB->delete_records('learnguard_schedules',           ['learnguard' => $id]);
    $DB->delete_records('learnguard_brand_overrides',     ['learnguard' => $id]);
    $DB->delete_records('learnguard_grading_config',      ['learnguard' => $id]);
    $DB->delete_records('learnguard_grader_assignments',  ['learnguard' => $id]);

    // Remove grade item.
    learnguard_grade_item_delete($instance);

    $DB->delete_records('learnguard', ['id' => $id]);

    return true;
}

// -----------------------------------------------------------------------
// Branding helpers
// -----------------------------------------------------------------------

/**
 * Tri-state feature toggle shared by Chat/Break/SOS (and any future per-activity
 * on/off feature that should follow the same pattern): NULL on the activity means
 * "inherit the site-wide default" and stays in sync if that setting is changed later;
 * an explicit 0/1 on the activity is a per-activity override that WINS over the site
 * default in EITHER direction — an activity can enable a feature the site default has
 * off, or disable one the site default has on. Used identically by view.php (building
 * the client config) and classes/external.php (server-side enforcement on every call),
 * so the two can never disagree with each other.
 *
 * @param int|null $activityvalue the activity's own stored value (null = not set)
 * @param string   $globalkey     the admin setting name to fall back to
 * @param bool     $globaldefault used only if the admin setting itself was never saved
 */
function learnguard_feature_enabled(?int $activityvalue, string $globalkey, bool $globaldefault): bool {
    if ($activityvalue !== null) {
        return (bool)$activityvalue;
    }
    $v = get_config('learnguard', $globalkey);
    return ($v === false || $v === '') ? $globaldefault : (bool)$v;
}

// ==========================================================================
// EMAIL HELPERS
// ==========================================================================

/**
 * A grader who was assigned via admin.php/siteadmin.php but has no other role in the
 * course (or is only a plain student) gets blocked by Moodle's own
 * require_login($course, false, $cm) the moment the activity is hidden — it needs
 * moodle/course:viewhiddenactivities, which a bare enrolment never grants. The
 * "assign them the Non-editing Teacher role" fix that was tried first is WRONG: that
 * archetype is also granted mod/learnguard:liveproctor by default (see db/access.php),
 * which would silently turn every grader into a de facto proctor too — explicitly the
 * opposite of what was asked ("a grader without proctor access should not have access
 * to student attempt history"). This dedicated role has exactly one capability
 * (moodle/course:viewhiddenactivities) and nothing else — created once, on first use,
 * and reused from then on.
 *
 * @return int the role id (0 if the role could not be created).
 */
function learnguard_ensure_grader_access_role(): int {
    global $DB;
    $shortname = 'learnguard_grader_access';
    $existing = $DB->get_record('role', ['shortname' => $shortname]);
    if ($existing) {
        // Catch-up for a role created before viewhiddencourses was added below — reported
        // live: a grader with only this role still couldn't enter the course at all (not
        // just the activity), forcing a manual "Non-editing Teacher" workaround exactly
        // like the one this role was built to avoid. assign_capability() is idempotent
        // (re-allowing an already-allowed capability is a no-op), so this is safe to run
        // on every call, not just when the role is first created.
        assign_capability('moodle/course:viewhiddencourses', CAP_ALLOW, (int)$existing->id, context_system::instance()->id);
        return (int)$existing->id;
    }

    $roleid = create_role(
        'LearnGuard Grader Access',
        $shortname,
        'Grants only the ability to view a hidden course/activity — assigned automatically ' .
        'to a user made a LearnGuard grader who has no other role in the course. Carries no ' .
        'LearnGuard capability of its own (a grader is not automatically a proctor).',
        ''
    );
    if (!$roleid) { return 0; }
    set_role_contextlevels($roleid, [CONTEXT_COURSE, CONTEXT_MODULE]);
    assign_capability('moodle/course:viewhiddenactivities', CAP_ALLOW, $roleid, context_system::instance()->id);
    // moodle/course:viewhiddenactivities alone only covers a hidden ACTIVITY inside an
    // otherwise-visible course. Michael's exam courses are commonly hidden entirely —
    // entering a hidden COURSE needs this separate capability (the one stock Moodle's
    // Non-editing Teacher archetype happens to include by default, which is exactly why
    // that workaround "worked" and this minimal role initially didn't).
    assign_capability('moodle/course:viewhiddencourses', CAP_ALLOW, $roleid, context_system::instance()->id);
    return $roleid;
}

/**
 * Send a LearnGuard notification email using Moodle's outgoing mail (SMTP).
 * Configure SMTP at: Site admin → Server → Email → Outgoing mail configuration.
 *
 * @param stdClass $toUser   Moodle user object (must have firstname, lastname, email).
 * @param string   $subject  Plain-text subject line.
 * @param string   $body     Plain-text email body.
 */
function learnguard_send_email(stdClass $toUser, string $subject, string $body): bool {
    global $CFG;
    require_once($CFG->libdir . '/moodlelib.php');

    // Use a configured "from" address or fall back to the Moodle support user.
    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';

    if ($fromEmail) {
        $from = (object)[
            'email'     => $fromEmail,
            'firstname' => $fromName,
            'lastname'  => '',
            'maildisplay' => 1,
        ];
    } else {
        $from = \core_user::get_support_user();
    }

    // Build HTML version of the plain-text body.
    $htmlBody = '<pre style="font-family:sans-serif;font-size:14px;line-height:1.6;">' .
                nl2br(htmlspecialchars($body)) . '</pre>';

    return email_to_user($toUser, $from, $subject, $body, $htmlBody);
}

/**
 * Fill an email template with key → value replacements.
 *
 * @param string $template   Template string with {placeholders}.
 * @param array  $values     Map of placeholder → value.
 * @return string
 */
function learnguard_fill_email_template(string $template, array $values): string {
    return str_replace(array_keys($values), array_values($values), $template);
}

/**
 * Get all proctors assigned to a LearnGuard assessment (per-assessment + global + course-level).
 * Returns an array of user objects.
 *
 * @param int $lgid  learnguard instance id
 * @param int $cmid  course module id
 * @return array of stdClass user objects
 */
function learnguard_get_all_proctors(int $lgid, int $cmid): array {
    global $DB;

    $instance = $DB->get_record('learnguard', ['id' => $lgid], '*', IGNORE_MISSING);
    $courseid = $instance ? (int)$instance->course : 0;

    // Global proctors + per-assessment proctors + course-level proctors
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username,
                   u.mailformat, u.auth, u.confirmed, u.deleted, u.suspended
              FROM {learnguard_proctors} p
              JOIN {user} u ON u.id = p.userid AND u.deleted = 0 AND u.suspended = 0
             WHERE p.is_global = 1
                OR p.learnguard = :lgid
                OR (p.courseid = :courseid AND p.courseid > 0 AND p.learnguard = 0 AND p.is_global = 0)";

    return array_values($DB->get_records_sql($sql, ['lgid' => $lgid, 'courseid' => $courseid]));
}

/**
 * Whether a user is allowed to proctor a given LearnGuard activity — site admin, global
 * proctor, or assigned per-course/per-activity via learnguard_proctors. Used by site-wide
 * pages (proctorview.php) and their AJAX endpoints, where a Moodle per-module capability
 * check would wrongly reject a global proctor who isn't enrolled in every course.
 *
 * @param int $userid
 * @param int $learnguardid
 * @return bool
 */
function learnguard_is_proctor_for(int $userid, int $learnguardid): bool {
    if (has_capability('moodle/site:config', \context_system::instance())) {
        return true;
    }
    $proctors = learnguard_get_all_proctors($learnguardid, 0);
    foreach ($proctors as $p) {
        if ((int)$p->id === $userid) { return true; }
    }
    return false;
}

/**
 * Force-end any approved breaks whose deadline has already passed.
 *
 * The exam timer normally auto-calls end_break from the student's own browser when
 * its local countdown reaches zero (amd/src/assessment.js showBreakOverlay()). If that
 * tab is backgrounded/throttled or the call never lands, break_end is never set and the
 * attempt shows as "on break" indefinitely on proctor dashboards. Called defensively
 * wherever break state is read so it self-heals without needing the student's tab.
 *
 * @param int[] $learnguardids restrict to these activity IDs; empty = every activity
 */
function learnguard_expire_breaks(array $learnguardids = []): void {
    global $DB;
    $now = time();
    if (empty($learnguardids)) {
        $DB->execute(
            "UPDATE {learnguard_attempts} SET break_end = :now
              WHERE break_approved > 0 AND break_end = 0 AND break_deadline > 0 AND break_deadline <= :now2",
            ['now' => $now, 'now2' => $now]
        );
        return;
    }
    list($insql, $inparams) = $DB->get_in_or_equal($learnguardids, SQL_PARAMS_NAMED);
    $inparams['now']  = $now;
    $inparams['now2'] = $now;
    $DB->execute(
        "UPDATE {learnguard_attempts} SET break_end = :now
          WHERE learnguard $insql AND break_approved > 0 AND break_end = 0 AND break_deadline > 0 AND break_deadline <= :now2",
        $inparams
    );
}

/**
 * Notify all assigned proctors about an event on an attempt.
 *
 * @param int    $attemptid   attempt ID
 * @param string $eventType   short event label
 * @param string $detail      extra context for the email body
 */
function learnguard_notify_proctors_event(int $attemptid, string $eventType, string $detail = ''): void {
    global $DB;

    $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) { return; }

    $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, IGNORE_MISSING);
    if (!$cm)  { return; }

    $student  = $DB->get_record('user',       ['id' => $attempt->userid],      '*', IGNORE_MISSING);
    $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard],  '*', IGNORE_MISSING);
    if (!$student || !$instance) { return; }

    $proctors   = learnguard_get_all_proctors($attempt->learnguard, $cm->id);
    if (empty($proctors)) { return; }

    $detailUrl  = (new moodle_url('/mod/learnguard/attemptdetail.php',
                    ['id' => $cm->id, 'attempt' => $attempt->id]))->out(false);
    $dashUrl    = (new moodle_url('/mod/learnguard/proctordashboard.php',
                    ['id' => $cm->id]))->out(false);

    $subject = '⚠️ LearnGuard Alert: ' . str_replace('_', ' ', strtoupper($eventType)) .
               ' — ' . fullname($student);

    $body = 'LearnGuard Proctoring Alert' . "\n" .
            str_repeat('=', 40) . "\n\n" .
            'Event     : ' . str_replace('_', ' ', strtoupper($eventType)) . "\n" .
            'Student   : ' . fullname($student) . ' <' . $student->email . '>' . "\n" .
            'Assessment: ' . format_string($instance->name) . "\n" .
            'Time      : ' . userdate(time(), '%d/%m/%Y %H:%M:%S') . "\n" .
            ($detail ? 'Detail    : ' . $detail . "\n" : '') . "\n" .
            'Attempt detail: ' . $detailUrl . "\n" .
            'Live dashboard: ' . $dashUrl . "\n\n" .
            'LearnGuard — Secure Assessment Platform';

    foreach ($proctors as $proctor) {
        learnguard_send_email($proctor, $subject, $body);
    }
}

/**
 * Email all global proctors a one-day-ahead warning that a batch of evidence images
 * and/or audio clips is about to be permanently deleted by the daily GDPR retention
 * cleanup task, so they have a chance to download anything they still need first.
 * Gated by the 'learnguard/media_deletion_reminder_enabled' setting (default on).
 *
 * @param int $snapCount  number of evidence images due for deletion tomorrow
 * @param int $audioCount number of audio clips due for deletion tomorrow
 * @param int $retainDays the retention period (days) that is about to be crossed
 */
function learnguard_notify_media_deletion_reminder(int $snapCount, int $audioCount, int $retainDays): void {
    global $DB;

    $globalProctors = $DB->get_records('learnguard_proctors', ['is_global' => 1]);
    if (empty($globalProctors)) { return; }

    $storageUrl = (new moodle_url('/mod/learnguard/storage.php'))->out(false);

    $items = [];
    if ($snapCount > 0)  { $items[] = "$snapCount evidence image(s)"; }
    if ($audioCount > 0) { $items[] = "$audioCount audio clip(s)"; }

    $subject = '⚠️ LearnGuard: evidence media being deleted tomorrow';
    $body = 'LearnGuard Data Retention Notice' . "\n" .
            str_repeat('=', 40) . "\n\n" .
            'In approximately 24 hours, the following proctoring evidence will reach the ' .
            $retainDays . '-day retention limit and be PERMANENTLY deleted to free up storage:' . "\n\n" .
            '  - ' . implode("\n  - ", $items) . "\n\n" .
            'If you need to keep any of this evidence, please review and download it before ' .
            'then from the Storage Management page:' . "\n" .
            $storageUrl . "\n\n" .
            'This deletion cannot be undone once it runs.' . "\n\n" .
            'LearnGuard — Secure Assessment Platform';

    $notified = [];
    foreach ($globalProctors as $assignment) {
        if (isset($notified[$assignment->userid])) { continue; }
        $notified[$assignment->userid] = true;
        $user = $DB->get_record('user', ['id' => $assignment->userid, 'deleted' => 0, 'suspended' => 0], '*', IGNORE_MISSING);
        if ($user) {
            learnguard_send_email($user, $subject, $body);
        }
    }
}

/**
 * Bundle selected snapshot/audio-clip evidence into a ZIP for an email attachment.
 * Shared by learnguard_notify_student_reinstated() and
 * learnguard_notify_student_suspension_confirmed() so both notice types attach
 * evidence the exact same way. email_to_user() requires the attachment path to be
 * RELATIVE to $CFG->dataroot; make_request_directory() creates a dir under
 * $CFG->tempdir (which lives under dataroot on a standard install) that Moodle
 * auto-cleans at the end of this request.
 *
 * @param int   $attemptid attempt ID (evidence is scoped to this attempt only)
 * @param array $snapIds   learnguard_snapshots IDs to attach (may be empty)
 * @param array $audioIds  learnguard_audio_clips IDs to attach (may be empty)
 * @return array [string $attachRel, string $attachName, int $snapCount, int $audioCount]
 */
function learnguard_build_evidence_zip(int $attemptid, array $snapIds, array $audioIds): array {
    global $DB, $CFG;

    $attachRel  = '';
    $attachName = '';
    $snapCount  = 0;
    $audioCount = 0;
    if ((empty($snapIds) && empty($audioIds)) || !class_exists('ZipArchive')) {
        return [$attachRel, $attachName, $snapCount, $audioCount];
    }

    $zipPath = make_request_directory() . '/evidence.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        return [$attachRel, $attachName, $snapCount, $audioCount];
    }

    $i = 0;
    if (!empty($snapIds)) {
        [$insql, $inparams] = $DB->get_in_or_equal($snapIds, SQL_PARAMS_NAMED);
        $inparams['aid'] = $attemptid;
        $snaps = $DB->get_records_select('learnguard_snapshots', "id $insql AND attemptid = :aid", $inparams);
        foreach ($snaps as $snap) {
            $binary = base64_decode($snap->snapshot_url, true);
            if ($binary === false || strlen($binary) < 10) { continue; }
            $i++;
            $ext = ($snap->snapshot_type === '360_video') ? 'webm' : 'jpg';
            $zip->addFromString('evidence_' . $i . '_' . $snap->snapshot_type . '.' . $ext, $binary);
            $snapCount++;
        }
    }
    if (!empty($audioIds) && $DB->get_manager()->table_exists('learnguard_audio_clips')) {
        [$insql, $inparams] = $DB->get_in_or_equal($audioIds, SQL_PARAMS_NAMED);
        $inparams['aid'] = $attemptid;
        $clips = $DB->get_records_select('learnguard_audio_clips', "id $insql AND attemptid = :aid", $inparams);
        foreach ($clips as $clip) {
            $binary = base64_decode($clip->audiodata, true);
            if ($binary === false || strlen($binary) < 10) { continue; }
            $i++;
            $zip->addFromString('evidence_audio_' . $i . '.webm', $binary);
            $audioCount++;
        }
    }
    $zip->close();
    if ($i > 0 && file_exists($zipPath) && strpos(realpath($zipPath), $CFG->dataroot) === 0) {
        $attachRel  = ltrim(str_replace($CFG->dataroot, '', realpath($zipPath)), '/\\');
        $attachName = 'evidence.zip';
    }
    return [$attachRel, $attachName, $snapCount, $audioCount];
}

/**
 * Email the student directly when a proctor reinstates their suspended attempt.
 * Gated by the 'notify_reinstate_enabled' admin setting (default on). Two call shapes:
 * the plain reinstate buttons (report.php, proctorview.php, admin.php) call this with
 * just $attemptid — a generic reinstated message goes out, no evidence attached; the
 * richer Reinstate & Notify flow on report.php passes a proctor-written $message plus
 * any ticked snapshot/audio evidence, mirroring the Confirm Suspension flow. Every call
 * (including a skipped one, when the setting is off) is logged to
 * learnguard_suspension_notices (noticetype='reinstate') so proctors/admins can review
 * what was sent later, via suspensionnotices.php.
 *
 * @param int    $attemptid attempt ID
 * @param string $message   optional proctor-written message, sent to the student verbatim
 * @param array  $snapIds   learnguard_snapshots IDs to attach as evidence (may be empty)
 * @param array  $audioIds  learnguard_audio_clips IDs to attach as evidence (may be empty)
 * @return bool whether the email was sent successfully
 */
function learnguard_notify_student_reinstated(
    int $attemptid, string $message = '', array $snapIds = [], array $audioIds = []
): bool {
    global $DB, $USER;

    $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) { return false; }
    $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, IGNORE_MISSING);
    if (!$cm)  { return false; }
    $student  = $DB->get_record('user',       ['id' => $attempt->userid],     '*', IGNORE_MISSING);
    $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', IGNORE_MISSING);
    if (!$student || !$instance) { return false; }

    $enabledCfg = get_config('learnguard', 'notify_reinstate_enabled');
    $enabled    = ($enabledCfg === false || $enabledCfg === '') ? true : (bool)$enabledCfg;
    if (!$enabled) {
        $DB->insert_record('learnguard_suspension_notices', (object)[
            'learnguard'  => $attempt->learnguard,
            'attemptid'   => $attemptid,
            'userid'      => $attempt->userid,
            'proctorid'   => $USER->id,
            'noticetype'  => 'reinstate',
            'reason'      => $message !== '' ? $message : '(reinstatement email disabled site-wide)',
            'snapcount'   => 0,
            'audiocount'  => 0,
            'mail_status' => 'skipped',
            'mail_error'  => 'Reinstatement email disabled via LearnGuard settings.',
            'timecreated' => time(),
        ]);
        return false;
    }

    $examUrl = (new moodle_url('/mod/learnguard/view.php', ['id' => $cm->id]))->out(false);
    [$attachRel, $attachName, $snapCount, $audioCount] = learnguard_build_evidence_zip($attemptid, $snapIds, $audioIds);
    $hasEvidence = ($snapCount + $audioCount) > 0;

    $subject = '✅ Your assessment attempt has been reinstated — ' . format_string($instance->name);
    $body    = 'Hello ' . fullname($student) . ',' . "\n\n" .
        'Your suspended attempt for "' . format_string($instance->name) . '" has been reviewed and reinstated ' .
        'by your proctor. You may return to the assessment and continue from where you left off.' . "\n\n" .
        'You will be asked to complete a short security check again before continuing.' . "\n\n" .
        ($message !== '' ? 'Message from your proctor:' . "\n" . $message . "\n\n" : '') .
        ($hasEvidence ? 'Supporting evidence from your session is attached to this email.' . "\n\n" : '') .
        'Return to the assessment: ' . $examUrl . "\n\n" .
        'LearnGuard — Secure Assessment Platform';

    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';
    $from      = $fromEmail
        ? (object)['email' => $fromEmail, 'firstname' => $fromName, 'lastname' => '', 'maildisplay' => 1]
        : \core_user::get_support_user();
    $htmlBody  = '<pre style="font-family:sans-serif;font-size:14px;line-height:1.6;">' .
        nl2br(htmlspecialchars($body)) . '</pre>';

    $mailError = null;
    try {
        $sent = email_to_user($student, $from, $subject, $body, $htmlBody, $attachRel, $attachName);
    } catch (\Throwable $e) {
        $sent      = false;
        $mailError = $e->getMessage();
    }

    $DB->insert_record('learnguard_suspension_notices', (object)[
        'learnguard'  => $attempt->learnguard,
        'attemptid'   => $attemptid,
        'userid'      => $attempt->userid,
        'proctorid'   => $USER->id,
        'noticetype'  => 'reinstate',
        'reason'      => $message !== '' ? $message : '(no message — standard reinstatement notice)',
        'snapcount'   => $snapCount,
        'audiocount'  => $audioCount,
        'mail_status' => $sent ? 'sent' : 'failed',
        'mail_error'  => $mailError,
        'timecreated' => time(),
    ]);

    return (bool)$sent;
}

/**
 * Email the student when a proctor confirms their suspension via attemptdetail.php's
 * "Confirm Suspension (Notify Student)" modal — includes the proctor's typed reason and,
 * if any snapshot/audio IDs were ticked in the Webcam Snapshots / Screen Captures / Audio
 * Clips sections, a ZIP of that evidence attached to the email. Gated by the
 * 'notify_suspension_confirmed_enabled' admin setting (default on). Every call
 * (including a skipped one) is logged to learnguard_suspension_notices
 * (noticetype='suspend') so proctors/admins can review what was sent later, via
 * suspensionnotices.php.
 *
 * @param int    $attemptid attempt ID
 * @param string $reason    proctor-entered reason, sent to the student verbatim
 * @param array  $snapIds   learnguard_snapshots IDs to attach as evidence (may be empty)
 * @param array  $audioIds  learnguard_audio_clips IDs to attach as evidence (may be empty)
 * @return bool whether the email was sent successfully
 */
function learnguard_notify_student_suspension_confirmed(
    int $attemptid, string $reason, array $snapIds = [], array $audioIds = []
): bool {
    global $DB, $USER;

    $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) { return false; }
    $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, IGNORE_MISSING);
    if (!$cm)  { return false; }
    $student  = $DB->get_record('user',       ['id' => $attempt->userid],     '*', IGNORE_MISSING);
    $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', IGNORE_MISSING);
    if (!$student || !$instance) { return false; }

    $enabledCfg = get_config('learnguard', 'notify_suspension_confirmed_enabled');
    $enabled    = ($enabledCfg === false || $enabledCfg === '') ? true : (bool)$enabledCfg;
    if (!$enabled) {
        $DB->insert_record('learnguard_suspension_notices', (object)[
            'learnguard'  => $attempt->learnguard,
            'attemptid'   => $attemptid,
            'userid'      => $attempt->userid,
            'proctorid'   => $USER->id,
            'noticetype'  => 'suspend',
            'reason'      => $reason,
            'snapcount'   => 0,
            'audiocount'  => 0,
            'mail_status' => 'skipped',
            'mail_error'  => 'Confirmed-suspension email disabled via LearnGuard settings.',
            'timecreated' => time(),
        ]);
        return false;
    }

    [$attachRel, $attachName, $snapCount, $audioCount] = learnguard_build_evidence_zip($attemptid, $snapIds, $audioIds);
    $hasEvidence = ($snapCount + $audioCount) > 0;

    $subject = '🚫 Your assessment attempt has been suspended — ' . format_string($instance->name);
    $body    = 'Hello ' . fullname($student) . ',' . "\n\n" .
        'Your attempt for "' . format_string($instance->name) . '" has been reviewed and your suspension has ' .
        'been confirmed due to a violation of exam rules.' . "\n\n" .
        'Reason:' . "\n" . $reason . "\n\n" .
        ($hasEvidence ? 'Supporting evidence is attached to this email.' . "\n\n" : '') .
        'If you believe this decision was made in error, please contact your instructor.' . "\n\n" .
        'LearnGuard — Secure Assessment Platform';

    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';
    $from      = $fromEmail
        ? (object)['email' => $fromEmail, 'firstname' => $fromName, 'lastname' => '', 'maildisplay' => 1]
        : \core_user::get_support_user();
    $htmlBody  = '<pre style="font-family:sans-serif;font-size:14px;line-height:1.6;">' .
        nl2br(htmlspecialchars($body)) . '</pre>';

    $mailError = null;
    try {
        $sent = email_to_user($student, $from, $subject, $body, $htmlBody, $attachRel, $attachName);
    } catch (\Throwable $e) {
        $sent      = false;
        $mailError = $e->getMessage();
    }

    $DB->insert_record('learnguard_suspension_notices', (object)[
        'learnguard'  => $attempt->learnguard,
        'attemptid'   => $attemptid,
        'userid'      => $attempt->userid,
        'proctorid'   => $USER->id,
        'noticetype'  => 'suspend',
        'reason'      => $reason,
        'snapcount'   => $snapCount,
        'audiocount'  => $audioCount,
        'mail_status' => $sent ? 'sent' : 'failed',
        'mail_error'  => $mailError,
        'timecreated' => time(),
    ]);

    return (bool)$sent;
}

/**
 * Email a student who has not yet attempted an assessment — triggered from clicking the
 * "N/A" cell on scorematrix.php (single) or its "Send to All" bulk action (batched
 * client-side, 5 at a time, to avoid overloading the server). No attempt exists yet, so
 * this takes the instance + userid directly rather than an attemptid. States the
 * assessment's effective window close time for this specific learner (personal
 * schedule override, if any) so the reminder is actionable, not just "please attempt
 * this." Gated by 'notify_reminder_enabled' (default on). Logged to
 * learnguard_suspension_notices (noticetype='reminder') so it shows in Mail History
 * alongside every other student-facing notice.
 *
 * @param int $lgid   learnguard instance id
 * @param int $userid the student to remind
 * @return bool whether the email was sent successfully
 */
function learnguard_notify_student_attempt_reminder(int $lgid, int $userid): bool {
    global $DB, $USER;

    $instance = $DB->get_record('learnguard', ['id' => $lgid], '*', IGNORE_MISSING);
    if (!$instance) { return false; }
    $cm       = get_coursemodule_from_instance('learnguard', $lgid, 0, false, IGNORE_MISSING);
    if (!$cm)  { return false; }
    $student  = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
    if (!$student) { return false; }

    $enabledCfg = get_config('learnguard', 'notify_reminder_enabled');
    $enabled    = ($enabledCfg === false || $enabledCfg === '') ? true : (bool)$enabledCfg;
    if (!$enabled) {
        $DB->insert_record('learnguard_suspension_notices', (object)[
            'learnguard'  => $lgid,
            'attemptid'   => 0,
            'userid'      => $userid,
            'proctorid'   => $USER->id,
            'noticetype'  => 'reminder',
            'reason'      => '(attempt reminder email disabled site-wide)',
            'snapcount'   => 0,
            'audiocount'  => 0,
            'mail_status' => 'skipped',
            'mail_error'  => 'Attempt reminder email disabled via LearnGuard settings.',
            'timecreated' => time(),
        ]);
        return false;
    }

    [, $timeclose] = learnguard_effective_window($instance, $userid);
    $examUrl  = (new moodle_url('/mod/learnguard/view.php', ['id' => $cm->id]))->out(false);
    $deadline = $timeclose > 0 ? userdate($timeclose) : null;

    $subject = '⏰ Reminder: "' . format_string($instance->name) . '" is yet to be attempted';
    $body    = 'Hello ' . fullname($student) . ',' . "\n\n" .
        'Our records show you have not yet attempted "' . format_string($instance->name) . '".' . "\n\n" .
        ($deadline ? 'This assessment closes on ' . $deadline . '. Please complete it before then.' . "\n\n"
                   : 'Please complete it as soon as possible.' . "\n\n") .
        'Start the assessment: ' . $examUrl . "\n\n" .
        'LearnGuard — Secure Assessment Platform';

    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';
    $from      = $fromEmail
        ? (object)['email' => $fromEmail, 'firstname' => $fromName, 'lastname' => '', 'maildisplay' => 1]
        : \core_user::get_support_user();
    $htmlBody  = '<pre style="font-family:sans-serif;font-size:14px;line-height:1.6;">' .
        nl2br(htmlspecialchars($body)) . '</pre>';

    $mailError = null;
    try {
        $sent = email_to_user($student, $from, $subject, $body, $htmlBody);
    } catch (\Throwable $e) {
        $sent      = false;
        $mailError = $e->getMessage();
    }

    $DB->insert_record('learnguard_suspension_notices', (object)[
        'learnguard'  => $lgid,
        'attemptid'   => 0,
        'userid'      => $userid,
        'proctorid'   => $USER->id,
        'noticetype'  => 'reminder',
        'reason'      => 'Not yet attempted' . ($deadline ? ' — window closes ' . $deadline : ''),
        'snapcount'   => 0,
        'audiocount'  => 0,
        'mail_status' => $sent ? 'sent' : 'failed',
        'mail_error'  => $mailError,
        'timecreated' => time(),
    ]);

    return (bool)$sent;
}

/**
 * Email a student when a proctor grants Continue & Review from the Score Matrix score
 * cell's "Grant Continue & Review" action. Explicitly states the review must be
 * completed within the assessment's own window (personal schedule override, if any) —
 * a Continue & Review session's extra_time/review_deadline is a SEPARATE, narrower
 * limit set by assessmentmanager.php, but the outer assessment window is the hard
 * ceiling this notice is about. Gated by 'notify_review_granted_enabled' (default on).
 * Logged to learnguard_suspension_notices (noticetype='reviewgranted').
 *
 * @param int $attemptid the attempt being reopened for review
 * @return bool whether the email was sent successfully
 */
function learnguard_notify_student_review_granted(int $attemptid): bool {
    global $DB, $USER;

    $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) { return false; }
    $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, IGNORE_MISSING);
    if (!$cm)  { return false; }
    $student  = $DB->get_record('user',       ['id' => $attempt->userid],     '*', IGNORE_MISSING);
    $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', IGNORE_MISSING);
    if (!$student || !$instance) { return false; }

    $enabledCfg = get_config('learnguard', 'notify_review_granted_enabled');
    $enabled    = ($enabledCfg === false || $enabledCfg === '') ? true : (bool)$enabledCfg;
    if (!$enabled) {
        $DB->insert_record('learnguard_suspension_notices', (object)[
            'learnguard'  => $attempt->learnguard,
            'attemptid'   => $attemptid,
            'userid'      => $attempt->userid,
            'proctorid'   => $USER->id,
            'noticetype'  => 'reviewgranted',
            'reason'      => '(review-granted email disabled site-wide)',
            'snapcount'   => 0,
            'audiocount'  => 0,
            'mail_status' => 'skipped',
            'mail_error'  => 'Review-granted email disabled via LearnGuard settings.',
            'timecreated' => time(),
        ]);
        return false;
    }

    [, $timeclose] = learnguard_effective_window($instance, $attempt->userid);
    $examUrl  = (new moodle_url('/mod/learnguard/view.php', ['id' => $cm->id]))->out(false);
    $deadline = $timeclose > 0 ? userdate($timeclose) : null;

    $subject = '🔄 You have been granted a review — ' . format_string($instance->name);
    $body    = 'Hello ' . fullname($student) . ',' . "\n\n" .
        'Your proctor has granted you a Continue & Review session for "' . format_string($instance->name) . '" — ' .
        'you may revisit and revise your answers before final resubmission.' . "\n\n" .
        'IMPORTANT: this review must be completed within the assessment window' .
        ($deadline ? ', which closes on ' . $deadline . '.' : '.') . "\n\n" .
        'Return to the assessment: ' . $examUrl . "\n\n" .
        'LearnGuard — Secure Assessment Platform';

    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';
    $from      = $fromEmail
        ? (object)['email' => $fromEmail, 'firstname' => $fromName, 'lastname' => '', 'maildisplay' => 1]
        : \core_user::get_support_user();
    $htmlBody  = '<pre style="font-family:sans-serif;font-size:14px;line-height:1.6;">' .
        nl2br(htmlspecialchars($body)) . '</pre>';

    $mailError = null;
    try {
        $sent = email_to_user($student, $from, $subject, $body, $htmlBody);
    } catch (\Throwable $e) {
        $sent      = false;
        $mailError = $e->getMessage();
    }

    $DB->insert_record('learnguard_suspension_notices', (object)[
        'learnguard'  => $attempt->learnguard,
        'attemptid'   => $attemptid,
        'userid'      => $attempt->userid,
        'proctorid'   => $USER->id,
        'noticetype'  => 'reviewgranted',
        'reason'      => 'Continue & Review granted' . ($deadline ? ' — window closes ' . $deadline : ''),
        'snapcount'   => 0,
        'audiocount'  => 0,
        'mail_status' => $sent ? 'sent' : 'failed',
        'mail_error'  => $mailError,
        'timecreated' => time(),
    ]);

    return (bool)$sent;
}

/**
 * Return the configured brand colours and name as a stdClass.
 * Always returns valid hex strings, never null.
 */
function learnguard_brand(): stdClass {
    static $brand = null;
    if ($brand !== null) { return $brand; }
    $primary = get_config('learnguard', 'brand_primary') ?: '#0f6cbf';
    $brand   = (object)[
        'primary'   => $primary,
        'accent'    => get_config('learnguard', 'brand_accent')    ?: '#28a745',
        'name'      => get_config('learnguard', 'brand_name')      ?: 'LearnGuard',
        'textcolor' => get_config('learnguard', 'brand_textcolor') ?: '#ffffff',
        'dark'      => learnguard_darken($primary, 30),
    ];
    return $brand;
}

/**
 * Return a compact navigation/breadcrumb bar for LearnGuard pages.
 *
 * @param int    $cmid     Course-module ID (0 = site-level page)
 * @param string $current  Label for the current page (shown as last breadcrumb)
 * @param bool   $back     Whether to show a ← Back button as the first item
 * @param string $backurl  Override the back URL (defaults to assessment view)
 * @return string HTML
 */
function learnguard_nav_bar(int $cmid = 0, string $current = '', bool $back = true, string $backurl = ''): string {
    $b = learnguard_brand();
    $style = 'background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;' .
             'padding:6px 14px;margin-bottom:14px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:13px;';
    $sep   = html_writer::tag('span', '›', ['style' => 'color:#adb5bd;margin:0 2px;']);
    $ls    = 'color:' . $b->primary . ';text-decoration:none;font-weight:600;';
    $html  = html_writer::start_div('', ['style' => $style]);

    if ($back) {
        $burl = $backurl ?: ($cmid > 0
            ? (new moodle_url('/mod/learnguard/view.php', ['id' => $cmid]))->out(false)
            : (new moodle_url('/mod/learnguard/siteadmin.php'))->out(false));
        $html .= html_writer::link($burl, '← Back', ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:2px 10px;margin-right:6px;']);
    }

    if ($cmid > 0) {
        $html .= html_writer::link(
            new moodle_url('/mod/learnguard/view.php', ['id' => $cmid]),
            '🏠 Assessment', ['style' => $ls]
        );
        $html .= $sep;
        $html .= html_writer::link(
            new moodle_url('/mod/learnguard/admin.php', ['id' => $cmid]),
            '🛡 Admin', ['style' => $ls]
        );
    } else {
        $html .= html_writer::link(
            new moodle_url('/mod/learnguard/siteadmin.php'),
            '🌐 LearnGuard Site Admin', ['style' => $ls]
        );
    }

    if ($current) {
        $html .= $sep;
        $html .= html_writer::tag('span', $current, ['style' => 'color:#495057;font-weight:600;']);
    }

    $html .= html_writer::end_div();
    return $html;
}

// ── Background media cleanup (Storage tab) ───────────────────────────────────────────
// FROM/WHERE for one media type's rows matching a job's criteria. Never touches media from
// an attempt that is still in progress (a live exam); orphan rows whose attempt no longer
// exists are included (LEFT JOIN) since nothing else would ever clean them up.
// Non-LearnGuard tables the Storage tab may purge by age. A deliberately short whitelist of
// tables that only hold disposable, re-creatable data. Everything else — question attempts, response
// history, grades, users, course data — is protected and never offered for deletion.
function learnguard_purge_specs(): array {
    return [
        'notifications' => ['label' => 'Old notifications (bell messages)'],
        'message_popup_notifications' => ['label' => 'Notification popup markers (deleted alongside old notifications)'],
        'logstore_standard_log' => ['label' => 'Moodle event log (audit history — older entries)'],
    ];
}

function learnguard_cleanup_table_for(string $type, \stdClass $job): string {
    if ($type === 'table') { return (string)$job->tablename; }
    return $type === 'audio' ? 'learnguard_audio_clips' : 'learnguard_snapshots';
}

function learnguard_cleanup_query(string $type, \stdClass $job): array {
    global $DB;
    if ($type === 'table') {
        if (!isset(learnguard_purge_specs()[$job->tablename])) {
            throw new \coding_exception('Table not allowed for purge: ' . $job->tablename);
        }
        $cut = time() - max(1, (int)$job->olderthandays) * 86400;
        if ($job->tablename === 'message_popup_notifications') {
            return ["FROM {message_popup_notifications} t LEFT JOIN {notifications} n ON n.id = t.notificationid
                     WHERE (n.id IS NULL OR n.timecreated < :cut)", ['cut' => $cut]];
        }
        return ["FROM {" . $job->tablename . "} t WHERE t.timecreated < :cut", ['cut' => $cut]];
    }
    $tbl = $type === 'audio' ? 'learnguard_audio_clips' : 'learnguard_snapshots';
    $col = $type === 'audio' ? 't.timecreated' : 't.timemodified';
    $params = [];
    $hasScore = !empty($job->scoreop) && isset($job->scoreval) && $job->scoreval !== '' && $job->scoreval !== null;

    $from = "FROM {" . $tbl . "} t LEFT JOIN {learnguard_attempts} a ON a.id = t.attemptid";
    if ($hasScore) { $from .= ' JOIN {learnguard} lgs ON lgs.id = a.learnguard'; }
    $from .= " WHERE (a.id IS NULL OR a.state <> 'inprogress')";

    if ((int)($job->olderthandays ?? 0) > 0) {
        $from .= " AND $col < :cut";
        $params['cut'] = time() - (int)$job->olderthandays * 86400;
    }
    if ((int)($job->datefrom ?? 0) > 0) { $from .= " AND $col >= :dfrom"; $params['dfrom'] = (int)$job->datefrom; }
    if ((int)($job->dateto ?? 0) > 0)   { $from .= " AND $col <= :dto";   $params['dto']   = (int)$job->dateto; }

    // Assessment scope: the new multi-select list, falling back to the older single id.
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($job->lgids ?? '')))));
    if (empty($ids) && (int)($job->lgid ?? 0) > 0) { $ids = [(int)$job->lgid]; }
    if (!empty($ids)) {
        [$inSql, $inP] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'lgi');
        $from .= " AND a.learnguard $inSql";
        $params = array_merge($params, $inP);
    }
    if ($type !== 'audio') {
        $st = array_values(array_filter(array_map('trim', explode(',', (string)($job->snaptypes ?? '')))));
        if (!empty($job->snaptype)) { $st[] = $job->snaptype; }
        $st = array_values(array_unique($st));
        if (!empty($st)) {
            [$stSql, $stP] = $DB->get_in_or_equal($st, SQL_PARAMS_NAMED, 'sty');
            $from .= " AND t.snapshot_type $stSql";
            $params = array_merge($params, $stP);
        }
    }

    // Score filter: the attempt's score as a percentage of the assessment's maximum grade. An
    // attempt with no score yet (essays still being graded) never matches, so evidence for work
    // that hasn't been graded is never deleted by a score rule.
    if ($hasScore) {
        $ops = ['gt' => '>', 'ge' => '>=', 'lt' => '<', 'le' => '<='];
        $op = $ops[$job->scoreop] ?? '>';
        $from .= " AND a.sumgrades IS NOT NULL AND lgs.grade > 0 AND (a.sumgrades * 100.0 / lgs.grade) $op :sval";
        $params['sval'] = (float)$job->scoreval;
    }
    return [$from, $params];
}

// Human-readable summary of a job's criteria (used in the jobs table and preview).
function learnguard_cleanup_describe(\stdClass $j): string {
    global $DB;
    $labels = ['snapshots' => 'Snapshots', 'audio' => 'Audio', 'both' => 'Snapshots + Audio', 'table' => 'Table'];
    $stList = trim((string)($j->snaptypes ?? '') . (empty($j->snaptype) ? '' : ',' . $j->snaptype), ',');
    $bits = [($j->mediatype === 'table' ? 'Table ' . $j->tablename : ($labels[$j->mediatype] ?? $j->mediatype)) .
        ($stList !== '' ? ' [' . $stList . ']' : '')];
    if ((int)($j->olderthandays ?? 0) > 0) { $bits[] = 'older than ' . (int)$j->olderthandays . ' days'; }
    if ((int)($j->datefrom ?? 0) > 0 || (int)($j->dateto ?? 0) > 0) {
        $bits[] = 'captured ' . ((int)$j->datefrom > 0 ? 'from ' . userdate((int)$j->datefrom, '%d %b %Y') : '') .
            ((int)$j->dateto > 0 ? ' to ' . userdate((int)$j->dateto, '%d %b %Y') : '');
    }
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($j->lgids ?? '')))));
    if (empty($ids) && (int)($j->lgid ?? 0) > 0) { $ids = [(int)$j->lgid]; }
    if (!empty($ids)) {
        $names = $DB->get_records_list('learnguard', 'id', $ids, '', 'id, name');
        $bits[] = 'in ' . implode(', ', array_map(fn($n) => format_string($n->name), $names));
    }
    if (!empty($j->scoreop) && isset($j->scoreval) && $j->scoreval !== null && $j->scoreval !== '') {
        $bits[] = 'score ' . ['gt' => 'above', 'ge' => 'at or above', 'lt' => 'below', 'le' => 'at or below'][$j->scoreop] . ' ' . (float)$j->scoreval . '%';
    }
    return implode(' · ', $bits);
}

// What a set of criteria WOULD delete — row counts per type and an estimated size — without
// deleting anything.
function learnguard_cleanup_preview(\stdClass $job): array {
    global $DB;
    $stats = learnguard_snapshot_type_stats();
    $rows = 0; $bytes = 0.0; $bytype = [];
    foreach (learnguard_cleanup_types($job) as $type) {
        [$from, $params] = learnguard_cleanup_query($type, $job);
        if ($type === 'audio') {
            $c = (int)$DB->count_records_sql("SELECT COUNT(*) $from", $params);
            $avg = (float)$DB->get_field_sql("SELECT COALESCE(AVG(l), 0) FROM (SELECT LENGTH(audiodata) AS l FROM {learnguard_audio_clips} ORDER BY id DESC LIMIT 50) x");
            $bytype['audio'] = ['count' => $c, 'bytes' => $c * $avg];
            $rows += $c; $bytes += $c * $avg;
            continue;
        }
        foreach ($DB->get_records_sql("SELECT t.snapshot_type AS k, COUNT(*) AS c $from GROUP BY t.snapshot_type", $params) as $r) {
            $b = (int)$r->c * (float)($stats[$r->k]['avg'] ?? 0);
            $bytype[$r->k] = ['count' => (int)$r->c, 'bytes' => $b];
            $rows += (int)$r->c; $bytes += $b;
        }
    }
    return ['rows' => $rows, 'bytes' => $bytes, 'bytype' => $bytype];
}

// Which assessments hold the most media: snapshots grouped by (assessment, type), sized with the
// per-type sampled average, plus audio. Runs only on request (it walks the whole snapshots table).
function learnguard_media_by_assessment(int $limit = 25): array {
    global $DB;
    $stats = learnguard_snapshot_type_stats();
    $cat = ['periodic' => 'webcam', '360_video' => 'video', 'photo_id' => 'photoid',
        'screen_capture' => 'screen', 'random_screenshot' => 'screen'];
    $agg = [];
    foreach ($DB->get_records_sql(
        "SELECT CONCAT(COALESCE(a.learnguard, 0), ':', t.snapshot_type) AS k, COALESCE(a.learnguard, 0) AS lgid,
                t.snapshot_type AS st, COUNT(*) AS c
           FROM {learnguard_snapshots} t LEFT JOIN {learnguard_attempts} a ON a.id = t.attemptid
       GROUP BY COALESCE(a.learnguard, 0), t.snapshot_type") as $r) {
        $c = $cat[$r->st] ?? 'other';
        $agg[$r->lgid][$c] = ($agg[$r->lgid][$c] ?? 0) + (int)$r->c;
        $agg[$r->lgid]['bytes'] = ($agg[$r->lgid]['bytes'] ?? 0) + (int)$r->c * (float)($stats[$r->st]['avg'] ?? 0);
    }
    foreach ($DB->get_records_sql(
        "SELECT COALESCE(a.learnguard, 0) AS lgid, COUNT(*) AS c, COALESCE(SUM(LENGTH(t.audiodata)), 0) AS b
           FROM {learnguard_audio_clips} t LEFT JOIN {learnguard_attempts} a ON a.id = t.attemptid
       GROUP BY COALESCE(a.learnguard, 0)") as $r) {
        $agg[$r->lgid]['audio'] = (int)$r->c;
        $agg[$r->lgid]['bytes'] = ($agg[$r->lgid]['bytes'] ?? 0) + (float)$r->b;
    }
    uasort($agg, fn($x, $y) => ($y['bytes'] ?? 0) <=> ($x['bytes'] ?? 0));
    return array_slice($agg, 0, $limit, true);
}

function learnguard_render_media_by_assessment(): string {
    global $DB;
    $rows = learnguard_media_by_assessment();
    if (empty($rows)) { return '<p style="font-size:13px;">No media found.</p>'; }
    $ids = array_filter(array_keys($rows));
    $lgs = $ids ? $DB->get_records_list('learnguard', 'id', $ids, '', 'id, name, course') : [];
    $courses = $lgs ? $DB->get_records_list('course', 'id', array_unique(array_map(fn($l) => $l->course, $lgs)), '', 'id, fullname') : [];
    $html = '<table class="table table-sm table-bordered" style="font-size:13px;background:#fff;"><thead class="table-dark"><tr>' .
        '<th>Assessment</th><th>Course</th><th>Webcam</th><th>360° video</th><th>Screens</th><th>Photo ID</th><th>Other</th><th>Audio</th><th>≈ Total size</th></tr></thead><tbody>';
    foreach ($rows as $lgid => $r) {
        $lg = $lgs[$lgid] ?? null;
        $html .= '<tr><td>' . ($lg ? s(format_string($lg->name)) : '<em>(attempt no longer exists)</em>') . '</td>' .
            '<td>' . ($lg && isset($courses[$lg->course]) ? s(format_string($courses[$lg->course]->fullname)) : '—') . '</td>';
        foreach (['webcam', 'video', 'screen', 'photoid', 'other', 'audio'] as $k) { $html .= '<td>' . number_format($r[$k] ?? 0) . '</td>'; }
        $html .= '<td><strong>' . s(display_size((float)($r['bytes'] ?? 0))) . '</strong></td></tr>';
    }
    return $html . '</tbody></table><p style="font-size:12px;color:#6c757d;">Sizes are estimates (per-type sampled average × count); audio is exact. Top 25 shown.</p>';
}

// Automatic retention: what the daily cleanup task deletes on its own, and whether it is running.
function learnguard_render_retention_panel(): string {
    global $DB;
    $snap = (int)(get_config('learnguard', 'retention_snapshots') ?: 14);
    $v360 = (int)(get_config('learnguard', 'retention_360') ?: 7);
    $media = (int)(get_config('learnguard', 'retention_media_days') ?: 30);
    $html = '<h6 style="margin:14px 0 6px;">Automatic retention (daily cleanup)</h6><ul style="font-size:13px;">' .
        '<li>Webcam snapshots: deleted after <strong>' . $snap . ' days</strong></li>' .
        '<li>360° recordings: deleted after <strong>' . $v360 . ' days</strong></li>' .
        '<li>Photo IDs: deleted after <strong>30 days</strong></li>' .
        '<li>Screen captures, violation/evidence images <strong>and audio clips</strong>: deleted after <strong>' . $media . ' days</strong> ' .
        '— this is why older audio is no longer in the LMS: it is removed automatically once past this age and cannot be recovered. ' .
        'Raise it under Site administration → LearnGuard settings if you need audio kept longer.</li></ul>';
    try {
        $t = $DB->get_record_select('task_scheduled', $DB->sql_like('classname', ':c'), ['c' => '%cleanup_proctoring_data']);
        if ($t) {
            $html .= '<p style="font-size:13px;">Daily cleanup task: last ran <strong>' . ((int)$t->lastruntime > 0 ? s(userdate((int)$t->lastruntime)) : 'never') .
                '</strong>' . ((int)$t->disabled ? ' — <span style="color:#dc3545;">DISABLED</span>' : '') .
                ((int)$t->faildelay > 0 ? ' — <span style="color:#dc3545;">currently failing (retry delay ' . (int)$t->faildelay . 's)</span>' : '') . '.</p>';
        }
    } catch (Throwable $e) {}
    return $html;
}

// Snapshot counts per snapshot_type, with an estimated size per type from a small sample of that
// type's recent rows (measuring every row would mean reading the whole 40 GB+ table). Uses the
// type_time index, so it stays quick. Returns [type => ['count' => n, 'avg' => bytes, 'est' => bytes]].
function learnguard_snapshot_type_stats(): array {
    global $DB;
    $out = [];
    try {
        foreach ($DB->get_records_sql("SELECT snapshot_type AS t, COUNT(*) AS c FROM {learnguard_snapshots} GROUP BY snapshot_type ORDER BY c DESC") as $r) {
            $avg = (float)$DB->get_field_sql(
                "SELECT AVG(l) FROM (SELECT LENGTH(snapshot_url) AS l FROM {learnguard_snapshots}
                  WHERE snapshot_type = ? ORDER BY timemodified DESC LIMIT 200) x", [$r->t]);
            $out[$r->t] = ['count' => (int)$r->c, 'avg' => $avg, 'est' => $avg * (int)$r->c];
        }
    } catch (Throwable $e) {}
    return $out;
}

// Top disk consumers under a path (du, one level deep) — used to find what ELSE is filling the disk.
// Returns [path => bytes] or ['error' => message] when the server won't let PHP run du.
function learnguard_du(string $path, int $timeout = 40): array {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
        return ['error' => 'shell_exec is disabled on this server, so the disk cannot be scanned from here.'];
    }
    $out = @shell_exec('timeout ' . (int)$timeout . ' du -xk --max-depth=1 ' . escapeshellarg($path) . ' 2>/dev/null | sort -rn | head -14');
    $rows = [];
    foreach (preg_split('/\R/', trim((string)$out)) as $line) {
        if (preg_match('/^(\d+)\s+(.+)$/', $line, $m)) { $rows[$m[2]] = (float)$m[1] * 1024; }
    }
    return $rows ?: ['error' => 'No result (permission denied or the scan timed out).'];
}

function learnguard_cleanup_types(\stdClass $job): array {
    if ($job->mediatype === 'table') { return ['table']; }
    return $job->mediatype === 'both' ? ['snapshots', 'audio'] : [$job->mediatype === 'audio' ? 'audio' : 'snapshots'];
}

// Tables a job touches — what the follow-up disk reclaim should rebuild.
function learnguard_cleanup_tables(\stdClass $job): array {
    return array_values(array_unique(array_map(fn($t) => learnguard_cleanup_table_for($t, $job), learnguard_cleanup_types($job))));
}

function learnguard_cleanup_count(\stdClass $job): int {
    global $DB;
    $n = 0;
    foreach (learnguard_cleanup_types($job) as $type) {
        [$from, $params] = learnguard_cleanup_query($type, $job);
        $n += (int)$DB->count_records_sql("SELECT COUNT(*) $from", $params);
    }
    return $n;
}

function learnguard_cleanup_batch_ids(string $type, \stdClass $job, int $limit): array {
    global $DB;
    [$from, $params] = learnguard_cleanup_query($type, $job);
    return array_keys($DB->get_records_sql("SELECT t.id $from", $params, 0, $limit));
}

function learnguard_disk_used_bytes(): ?int {
    global $CFG;
    $t = @disk_total_space($CFG->dataroot);
    $f = @disk_free_space($CFG->dataroot);
    return ($t && $f !== false) ? (int)($t - $f) : null;
}

// Queues one Reclaim Disk Space run (history row + ad-hoc task); optionally tied to a cleanup job
// so its before/after disk figures get filled in when the reclaim finishes.
function learnguard_queue_reclaim(int $userid, int $jobid = 0, ?array $tables = null): int {
    global $DB;
    $tables = $tables ?: ['learnguard_snapshots', 'learnguard_audio_clips'];
    $tables = array_values(array_filter($tables, fn($t) => $DB->get_manager()->table_exists($t)));
    $hid = $DB->insert_record('learnguard_reclaim_history', (object)[
        'status' => 'queued', 'tablesinvolved' => substr(implode(', ', $tables), 0, 255), 'queuedby' => $userid,
        'timequeued' => time(), 'timestarted' => 0, 'timefinished' => 0,
    ]);
    $task = new \mod_learnguard\task\reclaim_disk_space();
    $task->set_custom_data(['historyid' => $hid, 'jobid' => $jobid, 'tables' => $tables]);
    \core\task\manager::queue_adhoc_task($task);
    return $hid;
}

function learnguard_render_cleanup_jobs(): string {
    global $DB;
    try { $jobs = $DB->get_records('learnguard_cleanup_jobs', null, 'id DESC', '*', 0, 20); }
    catch (Exception $e) { return ''; }
    if (empty($jobs)) { return '<p style="font-size:13px;color:#6c757d;">No cleanup jobs yet.</p>'; }
    $colors = ['queued' => '#6c757d', 'running' => '#0a4d8c', 'reclaiming' => '#0a4d8c', 'done' => '#28a745',
        'error' => '#dc3545', 'cancelled' => '#e0a800'];
    $labels = ['snapshots' => 'Snapshots', 'audio' => 'Audio', 'both' => 'Snapshots + Audio'];
    $html = '<table class="table table-sm table-bordered" style="font-size:13px;background:#fff;"><thead class="table-dark"><tr>' .
        '<th>Queued</th><th>What</th><th>Status</th><th>Progress</th><th>Disk used before</th><th>Disk used after</th><th>Freed</th><th></th></tr></thead><tbody>';
    foreach ($jobs as $j) {
        $scope = learnguard_cleanup_describe($j);
        $pct = (int)$j->totalrows > 0 ? min(100, (int)round(((int)$j->deletedrows / (int)$j->totalrows) * 100)) : ($j->status === 'done' ? 100 : 0);
        $before = $j->diskusedbefore !== null ? display_size((float)$j->diskusedbefore) : '—';
        $after = $j->diskusedafter !== null ? display_size((float)$j->diskusedafter) : '—';
        $freed = ($j->diskusedbefore !== null && $j->diskusedafter !== null)
            ? display_size((float)max(0, $j->diskusedbefore - $j->diskusedafter)) : '—';
        $active = in_array($j->status, ['queued', 'running'], true);
        $cancel = $active
            ? '<form method="post" style="margin:0;"><input type="hidden" name="sesskey" value="' . sesskey() . '">' .
              '<input type="hidden" name="postaction" value="cancelcleanup"><input type="hidden" name="jobid" value="' . (int)$j->id . '">' .
              '<button type="submit" class="btn btn-sm btn-outline-danger py-0">Cancel</button></form>' : '';
        $note = $j->errormessage ? '<div style="font-size:11px;color:#6c757d;max-width:340px;">' . s($j->errormessage) . '</div>' : '';
        $html .= '<tr><td>' . s(userdate((int)$j->timequeued, '%d %b %y, %H:%M')) . '</td><td>' . s($scope) . '</td>' .
            '<td style="color:' . ($colors[$j->status] ?? '#495057') . ';font-weight:600;">' . s(ucfirst($j->status)) . $note . '</td>' .
            '<td style="min-width:150px;"><div style="background:#e9ecef;border-radius:4px;height:10px;"><div style="width:' . $pct .
            '%;background:#198754;height:10px;border-radius:4px;"></div></div><small>' . number_format((int)$j->deletedrows) . ' / ' .
            number_format((int)$j->totalrows) . ' rows (' . $pct . '%)</small></td>' .
            '<td>' . s($before) . '</td><td>' . s($after) . '</td><td><strong>' . s($freed) . '</strong></td><td>' . $cancel . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/**
 * Full "where is the disk space going" panel for siteadmin.php's Storage tab. Snapshots and
 * audio clips are stored INSIDE the database (base64 in text columns), and InnoDB never
 * gives disk back after DELETE — freed rows just become slack inside the table's own file
 * (information_schema DATA_FREE) that new rows reuse. The file only shrinks after OPTIMIZE
 * TABLE, which needs roughly the table's size again in free space while it rebuilds. So
 * "I deleted everything but disk usage didn't drop" is expected until a reclaim actually
 * runs — this panel shows the numbers that explain it, plus whether the queued reclaim task
 * is even being picked up by cron.
 */
function learnguard_render_storage_panel(): string {
    global $DB, $CFG;
    $mb = fn($b) => display_size((float)$b);
    $html = '';

    $total = @disk_total_space($CFG->dataroot);
    $free  = @disk_free_space($CFG->dataroot);
    if ($total && $free !== false) {
        $usedPct = round((($total - $free) / $total) * 100);
        $html .= '<p style="font-size:13px;margin-bottom:10px;"><strong>Disk (moodledata volume):</strong> ' .
            s($mb($total - $free)) . ' used of ' . s($mb($total)) . ' (' . $usedPct . '%) — <strong>' . s($mb($free)) . ' free</strong>.</p>';
    }

    $lgTotalBytes = 0; $lgSlackBytes = 0; $largestLg = 0;
    if ($DB->get_dbfamily() === 'mysql') {
        $prefix = $DB->get_prefix();
        try {
            $lgRows = $DB->get_records_sql(
                "SELECT TABLE_NAME AS tname, TABLE_ROWS AS trows, DATA_LENGTH AS dl, INDEX_LENGTH AS il, DATA_FREE AS df
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)",
                [$prefix . 'learnguard_snapshots', $prefix . 'learnguard_audio_clips']);
            $html .= '<h6 style="margin:14px 0 6px;">LearnGuard media tables</h6>' .
                '<table class="table table-sm table-bordered" style="font-size:13px;background:#fff;max-width:900px;"><thead class="table-dark"><tr>' .
                '<th>Table</th><th>Rows</th><th>Live data + indexes</th><th>Reclaimable slack</th><th>File on disk</th><th>Free needed to shrink</th></tr></thead><tbody>';
            foreach ($lgRows as $r) {
                $size = (float)$r->dl + (float)$r->il;
                $lgTotalBytes += $size; $lgSlackBytes += (float)$r->df; $largestLg = max($largestLg, $size);
                // The batched shrink only has to hold a copy of the SURVIVING rows — not the whole file.
                $need = (int)($size * 1.15);
                $ok = ($free === false) ? true : ($free >= $need);
                $html .= '<tr><td>' . s(preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $r->tname)) . '</td>' .
                    '<td>' . number_format((float)$r->trows) . '</td><td>' . s($mb($size)) . '</td>' .
                    '<td><strong>' . s($mb($r->df)) . '</strong></td><td>' . s($mb($size + (float)$r->df)) . '</td>' .
                    '<td style="color:' . ($ok ? '#198754' : '#dc3545') . ';">' . s($mb($need)) . ($ok ? ' ✔ enough free' : ' ✘ NOT enough free') . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p style="font-size:12px;color:#6c757d;">"Reclaimable slack" is space already freed inside the table by deletions. ' .
                'The reclaim step copies only the surviving rows into a fresh table in small batches, swaps it in, then drops the old file — ' .
                'that drop is what returns the disk space.</p>';

            // Reasons a successful cleanup can still leave the disk figure unchanged.
            try {
                $fpt = (string)$DB->get_field_sql('SELECT @@innodb_file_per_table');
                $datadir = (string)$DB->get_field_sql('SELECT @@datadir');
                $dbFree = $datadir !== '' ? @disk_free_space($datadir) : false;
                $diag = '';
                if ($fpt === '0') {
                    $diag .= '<li><strong style="color:#dc3545;">innodb_file_per_table is OFF</strong> — every table lives inside one shared file (ibdata1) that can never shrink. ' .
                        'Deleting and reclaiming will free space inside it but the disk figure will not drop; this needs a database administrator to dump/restore.</li>';
                }
                if ($datadir !== '') {
                    $diag .= '<li>Database files are in <code>' . s($datadir) . '</code>' .
                        ($dbFree !== false ? ' — ' . s($mb($dbFree)) . ' free on that volume.' :
                        ' — not visible from the web server (database is on another machine/volume), so the moodledata disk figure above may not move even when the database shrinks.') . '</li>';
                }
                try {
                    $logs = $DB->get_records_sql('SHOW BINARY LOGS');
                    $logBytes = 0;
                    foreach ($logs as $lg) { $logBytes += (float)($lg->file_size ?? $lg->File_size ?? 0); }
                    if ($logBytes > 0) {
                        $diag .= '<li>Binary logs are using <strong>' . s($mb($logBytes)) . '</strong>. Bulk deletes and table rebuilds are written to them, so ' .
                            'heavy cleanups can grow this while shrinking the tables. Ask your database admin to lower the binlog retention or purge old logs if this is large.</li>';
                    }
                } catch (Throwable $e) { /* no privilege / binlog off — nothing to report */ }
                if ($diag !== '') { $html .= '<h6 style="margin:12px 0 6px;">Why disk usage may not drop</h6><ul style="font-size:13px;">' . $diag . '</ul>'; }
            } catch (Throwable $e) {}

            $top = $DB->get_records_sql(
                "SELECT TABLE_NAME AS tname, TABLE_ROWS AS trows, (DATA_LENGTH + INDEX_LENGTH) AS sz, DATA_FREE AS df
                   FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
               ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC", [], 0, 8);
            $specs = learnguard_purge_specs();
            $pjs = "return prompt('PERMANENTLY delete %WHAT%? This cannot be undone. Type DELETE to confirm.')==='DELETE';";
            $html .= '<h6 style="margin:14px 0 6px;">Largest tables in the whole database</h6>' .
                '<form method="post" id="lg-tables-form"><input type="hidden" name="sesskey" value="' . sesskey() . '">' .
                '<input type="hidden" name="postaction" value="queuetablepurge">' .
                '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:8px;font-size:13px;">' .
                '<label style="margin:0;">Delete entries older than (days) <input type="number" name="purge_olderthandays" min="1" value="30" class="form-control form-control-sm" style="display:inline-block;width:90px;"></label>' .
                '<label style="margin:0;"><input type="checkbox" name="reclaimafter" value="1" checked> Reclaim disk space afterwards</label>' .
                '<button type="submit" class="btn btn-sm btn-danger" onclick="var n=document.querySelectorAll(\'.lg-purge-cb:checked\').length; ' .
                'if(!n){alert(\'Tick at least one table first.\');return false;} ' .
                str_replace('%WHAT%', "old entries from ' + n + ' selected table(s)", $pjs) . '">🗑 Delete Selected (+ reclaim)</button></div>' .
                '<table class="table table-sm table-bordered" style="font-size:13px;background:#fff;max-width:900px;"><thead class="table-dark"><tr>' .
                '<th style="width:30px;"></th><th>Table</th><th>Rows</th><th>Size</th><th>Slack</th><th>Action</th></tr></thead><tbody>';
            foreach ($top as $t) {
                $short = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $t->tname);
                $cb = ''; $action = '';
                if (isset($specs[$short])) {
                    $cb = '<input type="checkbox" class="lg-purge-cb" name="purge_tables[]" value="' . s($short) . '">';
                    $action = '<button type="submit" name="purge_one" value="' . s($short) . '" class="btn btn-sm btn-outline-danger py-0" title="' .
                        s($specs[$short]['label']) . '" onclick="' . str_replace('%WHAT%', 'old entries from ' . s($short), $pjs) . '">Delete</button>';
                } else if ($short === 'learnguard_snapshots') {
                    $action = '<span style="font-size:12px;color:#6c757d;">use the snapshot types table above</span>';
                } else {
                    $action = '<span style="font-size:12px;color:#6c757d;">🔒 protected (assessment / course data)</span>';
                }
                $html .= '<tr><td style="text-align:center;">' . $cb . '</td><td>' . s($t->tname) . '</td><td>' . number_format((float)$t->trows) . '</td><td>' .
                    s($mb($t->sz)) . '</td><td>' . s($mb($t->df)) . '</td><td>' . $action . '</td></tr>';
            }
            $html .= '</tbody></table></form><p style="font-size:12px;color:#6c757d;">Only disposable tables can be purged here (old notifications, ' .
                'notification popup markers, the Moodle event log) and only entries older than the number of days you set. Everything else is ' .
                'protected. If the biggest table is not a LearnGuard one, reclaiming LearnGuard tables will not fix the growth.</p>';
        } catch (Throwable $e) {
            $html .= '<p style="font-size:12px;color:#dc3545;">Could not read table sizes: ' . s($e->getMessage()) . '</p>';
        }
    } else {
        $html .= '<p style="font-size:12px;color:#6c757d;">Per-table sizes are only shown for MySQL/MariaDB.</p>';
    }

    if ($free !== false && $largestLg > 0) {
        $need = (int)($largestLg * 1.15);
        if ($free < $need) {
            // The shrink has to build a copy of the SURVIVING data next to the old file, so free
            // disk must exceed the live data. Tell the admin exactly how much has to go first.
            $maxLive = (int)($free / 1.15);
            $mustDelete = max(0, $largestLg - $maxLive);
            $html .= '<div class="alert alert-danger" style="font-size:13px;"><strong>The biggest table is mostly real data, not slack.</strong> ' .
                'It holds ' . s($mb($largestLg)) . ' of live data, so shrinking it needs about ' . s($mb($need)) . ' free — only ' . s($mb($free)) .
                ' is. Reclaiming can only return the slack left by past deletions (' . s($mb($lgSlackBytes)) . ' right now). To free the rest you must ' .
                '<strong>delete data</strong>: to make the shrink possible the table has to get down to about <strong>' . s($mb($maxLive)) .
                '</strong>, i.e. delete roughly <strong>' . s($mb($mustDelete)) . '</strong> (' . round(($mustDelete / max(1, $largestLg)) * 100) .
                '% of it). Alternatively free that space elsewhere — see the disk scan below for what else is using the disk.</div>';
        }
    }

    // What the snapshot table is actually made of, by type — so the biggest categories can be cleared first.
    $types = learnguard_snapshot_type_stats();
    if (!empty($types)) {
        // One form: per-row Delete buttons and a bulk "Delete Selected" both post the same
        // criteria, queue a background batch-delete, and (if ticked) reclaim the disk after.
        $confirmJs = "return prompt('PERMANENTLY delete %WHAT%? This cannot be undone. Type DELETE to confirm.')==='DELETE';";
        $html .= '<h6 style="margin:14px 0 6px;">Snapshots by type (sizes estimated from a sample)</h6>' .
            '<form method="post" id="lg-types-form"><input type="hidden" name="sesskey" value="' . sesskey() . '">' .
            '<input type="hidden" name="postaction" value="queuetypecleanup">' .
            '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:8px;font-size:13px;">' .
            '<label style="margin:0;">Older than (days, 0 = all ages) <input type="number" name="types_olderthandays" min="0" value="0" class="form-control form-control-sm" style="display:inline-block;width:90px;"></label>' .
            '<label style="margin:0;"><input type="checkbox" name="reclaimafter" value="1" checked> Reclaim disk space afterwards</label>' .
            '<button type="submit" class="btn btn-sm btn-danger" onclick="var n=document.querySelectorAll(\'.lg-type-cb:checked\').length; ' .
            'if(!n){alert(\'Tick at least one type first.\');return false;} ' .
            str_replace('%WHAT%', "' + n + ' selected snapshot type(s)", $confirmJs) . '">🗑 Delete Selected (+ reclaim)</button></div>' .
            '<table class="table table-sm table-bordered" style="font-size:13px;background:#fff;max-width:760px;"><thead class="table-dark"><tr>' .
            '<th style="width:30px;text-align:center;"><input type="checkbox" title="Select all" onchange="document.querySelectorAll(\'.lg-type-cb\').forEach(function(c){c.checked=this.checked;}.bind(this));"></th>' .
            '<th>Type</th><th>Count</th><th>Avg each</th><th>≈ Total</th><th></th></tr></thead><tbody>';
        foreach ($types as $tname => $st) {
            $html .= '<tr><td style="text-align:center;"><input type="checkbox" class="lg-type-cb" name="snaptypes[]" value="' . s($tname) . '"></td>' .
                '<td>' . s($tname) . '</td><td>' . number_format($st['count']) . '</td><td>' . s($mb($st['avg'])) .
                '</td><td><strong>' . s($mb($st['est'])) . '</strong></td><td>' .
                '<button type="submit" name="type_one" value="' . s($tname) . '" class="btn btn-sm btn-outline-danger py-0" onclick="' .
                str_replace('%WHAT%', 'all ' . s($tname) . ' snapshots', $confirmJs) . '">Delete</button></td></tr>';
        }
        $html .= '</tbody></table></form>' .
            '<p style="font-size:12px;color:#6c757d;">Never touches media from an exam still in progress. Set "Older than" to keep recent ones; ' .
            'use the form above for score / date / assessment rules.</p>';
    }

    // On-demand disk scan (can be slow on a big server, so it only runs when asked).
    global $PAGE;
    $scanUrl = new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'storage', 'scandisk' => 1]);
    $html .= '<h6 style="margin:14px 0 6px;">What else is using the disk?</h6>';
    if (optional_param('scandisk', 0, PARAM_INT)) {
        $dirs = [];
        $dirs['Moodle data folder (' . $CFG->dataroot . ')'] = learnguard_du($CFG->dataroot);
        try {
            $dd = (string)$DB->get_field_sql('SELECT @@datadir');
            if ($dd !== '') { $dirs['Database files (' . $dd . ')'] = learnguard_du($dd); }
        } catch (Throwable $e) {}
        $dirs['Whole server, top level (/)'] = learnguard_du('/', 60);
        foreach ($dirs as $label => $rows) {
            $html .= '<div style="font-size:13px;font-weight:600;margin-top:8px;">' . s($label) . '</div>';
            if (isset($rows['error'])) { $html .= '<p style="font-size:12px;color:#6c757d;">' . s($rows['error']) . '</p>'; continue; }
            $html .= '<table class="table table-sm table-bordered" style="font-size:12px;background:#fff;max-width:640px;"><tbody>';
            foreach ($rows as $p => $b) { $html .= '<tr><td>' . s($p) . '</td><td style="text-align:right;">' . s($mb($b)) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
    } else {
        $html .= '<p style="font-size:13px;">The database tables above are only part of the disk. Scan the Moodle data folder, the database ' .
            'files (including binary logs) and the whole server to see the rest: <a class="btn btn-sm btn-outline-secondary" href="' .
            $scanUrl->out(false) . '">🔍 Scan disk usage</a> <span style="font-size:12px;color:#6c757d;">(can take up to a minute)</span></p>';
    }

    // Is the queued reclaim actually being picked up? An ad-hoc task only runs when Moodle's
    // cron is running; "queued" forever means cron isn't processing it.
    try {
        $adhoc = $DB->get_records_select('task_adhoc', $DB->sql_like('classname', ':c'), ['c' => '%reclaim_disk_space']);
        $html .= '<h6 style="margin:14px 0 6px;">Background task status</h6>';
        if (empty($adhoc)) {
            $html .= '<p style="font-size:13px;">No reclaim task is waiting in the cron queue.</p>';
        } else {
            $html .= '<table class="table table-sm table-bordered" style="font-size:13px;background:#fff;max-width:900px;"><thead class="table-dark"><tr>' .
                '<th>Task #</th><th>Next run</th><th>Started</th><th>Failures (delay)</th></tr></thead><tbody>';
            foreach ($adhoc as $a) {
                $html .= '<tr><td>' . (int)$a->id . '</td><td>' . s(userdate((int)$a->nextruntime)) . '</td><td>' .
                    ((int)$a->timestarted > 0 ? s(userdate((int)$a->timestarted)) . ' (still running / stuck)' : 'not started') .
                    '</td><td>' . (int)$a->faildelay . 's</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<div class="alert alert-warning" style="font-size:13px;">A task stays "queued" until Moodle cron processes it. If it has been waiting ' .
                'more than a few minutes, cron is not running (or is not running ad-hoc tasks). Ask your server admin to confirm the cron job ' .
                '(<code>php admin/cli/cron.php</code> every minute), or run the task immediately from the server with:<br>' .
                '<code>php admin/cli/adhoc_task.php --execute</code><br>Then refresh this page.</div>';
        }
    } catch (Throwable $e) {}

    return $html;
}

/**
 * Full, always-visible reclaim history table (one row per run: how long, size before/after,
 * how much was reclaimed, and the reason if refused/failed).
 */
function learnguard_render_reclaim_history_table(int $limit = 50): string {
    global $DB;
    try {
        $rows = $DB->get_records('learnguard_reclaim_history', null, 'id DESC', '*', 0, $limit);
    } catch (Exception $e) { return ''; }
    if (empty($rows)) { return '<p style="font-size:13px;color:#6c757d;">No reclaim has been queued yet.</p>'; }
    $colors = ['queued' => '#6c757d', 'running' => '#0a4d8c', 'done' => '#28a745', 'refused' => '#e0a800', 'error' => '#dc3545'];
    $html = '<table class="table table-sm table-bordered" style="font-size:13px;background:#fff;"><thead class="table-dark"><tr>' .
        '<th>Queued</th><th>Status</th><th>Started</th><th>Finished</th><th>Before</th><th>After</th><th>Reclaimed</th><th>Note</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $fmt = fn($t) => $t > 0 ? s(userdate((int)$t, '%d %b %y, %H:%M')) : '—';
        $sz = fn($b) => $b !== null ? s(display_size((float)$b)) : '—';
        $html .= '<tr><td>' . $fmt($r->timequeued) . '</td>' .
            '<td style="color:' . ($colors[$r->status] ?? '#495057') . ';font-weight:600;">' . s(ucfirst($r->status)) . '</td>' .
            '<td>' . $fmt($r->timestarted) . '</td><td>' . $fmt($r->timefinished) . '</td>' .
            '<td>' . $sz($r->bytesbefore) . '</td><td>' . $sz($r->bytesafter) . '</td>' .
            '<td><strong>' . $sz($r->bytesreclaimed) . '</strong></td>' .
            '<td style="max-width:360px;">' . s((string)$r->errormessage) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/**
 * Render the "Reclaim Disk Space" live-status line + a collapsible history table, shared
 * by siteadmin.php's Media and Audio Clips tabs. Reads learnguard_reclaim_history —
 * previously only the MOST RECENT run's status was ever visible (3 plain config values,
 * silently overwritten by the next run), so there was no way to see past runs, whether
 * they succeeded/failed/were refused for insufficient free space, or how much was
 * actually reclaimed. Falls back gracefully (no crash) if the table doesn't exist yet
 * (mid-upgrade) — same defensive pattern used elsewhere in this codebase.
 *
 * @param string $tab Which tab this is rendered on ('media' or 'audio') — cosmetic only,
 *                     the history itself is site-wide (both tables are reclaimed together).
 * @return string HTML
 */
function learnguard_render_reclaim_status(string $tab): string {
    global $DB;

    $rows = [];
    try {
        $rows = $DB->get_records('learnguard_reclaim_history', null, 'id DESC', '*', 0, 20);
    } catch (Exception $e) {
        return ''; // Table not created yet (mid-upgrade) — nothing to show.
    }

    $latest = $rows ? reset($rows) : null;
    $html = '';
    if ($latest) {
        if ($latest->status === 'queued') {
            $html .= '<span style="font-size:12px;color:#6c757d;">⏳ Queued — waiting for cron to pick it up.</span>';
        } else if ($latest->status === 'running') {
            $html .= '<span style="font-size:12px;color:#0a4d8c;">⏳ Reclaim running in the background — refresh to check.</span>';
        } else if ($latest->status === 'done') {
            $reclaimedTxt = $latest->bytesreclaimed !== null ? display_size((float)$latest->bytesreclaimed) : 'unknown';
            $html .= '<span style="font-size:12px;color:#28a745;">✅ Last reclaim finished ' .
                s(userdate((int)$latest->timefinished)) . ' — reclaimed ' . s($reclaimedTxt) . '</span>';
        } else if ($latest->status === 'refused') {
            $html .= '<span style="font-size:12px;color:#e0a800;">⚠️ Last reclaim refused: ' .
                s($latest->errormessage) . '</span>';
        } else if ($latest->status === 'error') {
            $html .= '<span style="font-size:12px;color:#dc3545;">⚠️ Last reclaim failed: ' .
                s($latest->errormessage) . '</span>';
        }
    }

    if (!empty($rows)) {
        $html .= '<details style="font-size:12px;margin-left:6px;">' .
            '<summary style="cursor:pointer;color:#0f6cbf;display:inline;">📋 Reclaim History (' . count($rows) . ')</summary>' .
            '<table class="table table-sm" style="margin-top:6px;background:#fff;">' .
            '<thead><tr><th>Queued</th><th>Status</th><th>Finished</th><th>Reclaimed</th><th>Tables</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $statusColor = ['queued' => '#6c757d', 'running' => '#0a4d8c', 'done' => '#28a745',
                'refused' => '#e0a800', 'error' => '#dc3545'][$r->status] ?? '#495057';
            $reclaimedCell = $r->bytesreclaimed !== null ? display_size((float)$r->bytesreclaimed) : '—';
            $finishedCell = $r->timefinished > 0 ? userdate((int)$r->timefinished, '%d %b %y, %H:%M') : '—';
            $html .= '<tr>' .
                '<td>' . s(userdate((int)$r->timequeued, '%d %b %y, %H:%M')) . '</td>' .
                '<td style="color:' . $statusColor . ';font-weight:600;">' . s(ucfirst($r->status)) .
                    ($r->errormessage ? ' <span title="' . s($r->errormessage) . '" style="cursor:help;">ℹ️</span>' : '') . '</td>' .
                '<td>' . s($finishedCell) . '</td>' .
                '<td>' . s($reclaimedCell) . '</td>' .
                '<td>' . s($r->tablesinvolved) . '</td>' .
                '</tr>';
        }
        $html .= '</tbody></table></details>';
    }

    return $html;
}

/**
 * Render the full-width subsection tab strip for LearnGuard per-assessment pages.
 * Shows: Overview | Proctor | Grader | Report | Settings | Brand
 *
 * @param int    $cmid     Course-module ID
 * @param string $active   Current tab key (overview|proctor|grader|report|settings|brand)
 * @return string HTML
 */
function learnguard_activity_tabs(int $cmid, string $active = 'overview'): string {
    global $DB, $USER;
    $b = learnguard_brand();

    // Permissions.
    $context   = context_module::instance($cmid);
    $canManage = has_capability('mod/learnguard:manage', $context);
    $canProctor = $canManage || has_capability('mod/learnguard:liveproctor', $context);
    $isGrader  = $canManage || $DB->record_exists_sql(
        "SELECT id FROM {learnguard_graders} WHERE userid = :uid AND learnguard IN (
             SELECT instance FROM {course_modules} WHERE id = :cmid
         )", ['uid' => $USER->id, 'cmid' => $cmid]
    );

    $tabs = [];
    $tabs['overview'] = ['🏠 Overview',  '/mod/learnguard/view.php',        ['id' => $cmid], true];
    if ($canProctor) {
        $tabs['proctor']  = ['🛡 Proctor',  '/mod/learnguard/proctorview.php', [],              true];
        $tabs['report']   = ['📊 Reports',  '/mod/learnguard/admin.php',       ['id' => $cmid, 'tab' => 'attempts'], true];
        $tabs['scores']   = ['🏆 Scores',   '/mod/learnguard/scores.php',      ['id' => $cmid], true];
    }
    if ($isGrader) {
        $tabs['grader']   = ['✏️ Grader',   '/mod/learnguard/grader.php',      ['id' => $cmid], true];
    }
    if ($canManage) {
        $tabs['schedule'] = ['📅 Schedule', '/mod/learnguard/schedule.php',     ['id' => $cmid], true];
        $tabs['settings'] = ['⚙️ Settings', '/mod/learnguard/admin.php',       ['id' => $cmid, 'tab' => 'settings'], true];
        $tabs['brand']    = ['🎨 Brand',    '/mod/learnguard/admin.php',       ['id' => $cmid, 'tab' => 'brand'],    true];
    }

    $stripStyle = 'display:flex;background:#fff;border-bottom:2px solid #dee2e6;' .
        'overflow-x:auto;scrollbar-width:none;margin-bottom:16px;';
    $html = html_writer::start_div('', ['style' => $stripStyle, 'class' => 'lg-activity-tabs']);

    foreach ($tabs as $key => [$label, $path, $params, $show]) {
        if (!$show) { continue; }
        $isActive = ($key === $active);
        $url      = new moodle_url($path, $params);
        $tabStyle = 'display:flex;align-items:center;gap:5px;padding:0 18px;height:44px;font-size:13px;' .
            'font-weight:' . ($isActive ? '700' : '600') . ';' .
            'color:' . ($isActive ? $b->primary : '#6c757d') . ';' .
            'text-decoration:none;white-space:nowrap;flex-shrink:0;' .
            'border-bottom:3px solid ' . ($isActive ? $b->primary : 'transparent') . ';' .
            'transition:all .15s;';
        $html .= html_writer::link($url, $label, ['style' => $tabStyle]);
    }

    $html .= html_writer::end_div();
    return $html;
}

/**
 * Check if a user is an assigned proctor for a LearnGuard activity.
 *
 * @param int $learnguardid  learnguard.id
 * @param int $userid        defaults to $USER->id
 * @return bool
 */
function learnguard_is_proctor(int $learnguardid, int $userid = 0): bool {
    global $DB, $USER;
    if (!$userid) { $userid = $USER->id; }
    // Per-assessment assignment.
    if ($DB->record_exists('learnguard_proctors', ['learnguard' => $learnguardid, 'userid' => $userid])) {
        return true;
    }
    // Global proctor (is_global = 1 means they proctor ALL assessments site-wide).
    return $DB->record_exists_select(
        'learnguard_proctors',
        'userid = :uid AND is_global = 1',
        ['uid' => $userid]
    );
}

/**
 * Darken a hex color by a given amount (0-255).
 */
/**
 * Push an event to the Socket.IO server so all connected clients in a room
 * receive it instantly (milliseconds) instead of waiting for the 15-second poll.
 *
 * Falls back silently if the socket server is not configured or unreachable.
 *
 * @param string $event        Socket.IO event name (e.g. 'assessment:paused').
 * @param array  $data         Payload to send with the event.
 * @param int    $learnguardid Assessment ID — used to derive the room.
 * @param int    $attemptid    Optional: send to a specific student's room.
 */
function learnguard_socket_emit(string $event, array $data = [], int $learnguardid = 0, int $attemptid = 0): void {
    $socketUrl = get_config('learnguard', 'ai_socket_url');
    $secret    = get_config('learnguard', 'ai_gateway_key') ?: '';
    if (empty($socketUrl)) { return; }

    $room = $attemptid > 0 ? "attempt:{$attemptid}" : "assessment:{$learnguardid}";
    $payload = json_encode(['room' => $room, 'event' => $event, 'data' => $data]);

    $curl = new \curl();
    $curl->setHeader(['Content-Type: application/json', 'X-API-Key: ' . $secret]);
    $curl->post(rtrim($socketUrl, '/') . '/emit', $payload);
    // Errors are silently ignored — socket is an enhancement, not a requirement.
}

/**
 * Call the AI Gateway and return decoded JSON or null on failure.
 *
 * @param string $endpoint  e.g. '/api/v1/face/detect'
 * @param array  $data      Request body (JSON-encoded).
 * @return array|null
 */
function learnguard_ai_call(string $endpoint, array $data): ?array {
    $gatewayUrl = get_config('learnguard', 'ai_gateway_url');
    $apiKey     = get_config('learnguard', 'ai_gateway_key') ?: '';
    if (empty($gatewayUrl)) { return null; }

    $curl = new \curl();
    $curl->setHeader(['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
    $resp = $curl->post(rtrim($gatewayUrl, '/') . $endpoint, json_encode($data));
    if (!$resp) { return null; }
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : null;
}

function learnguard_darken(string $hex, int $amount = 30): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) { return '#0a4d8c'; }
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $r = max(0, $r - $amount);
    $g = max(0, $g - $amount);
    $b = max(0, $b - $amount);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Return a styled banner div using the configured brand color.
 *
 * @param string $title   Main heading
 * @param string $sub     Sub-heading / description (optional)
 * @param string $icon    Emoji icon (optional)
 * @return string HTML
 */
function learnguard_banner(string $title, string $sub = '', string $icon = '🛡'): string {
    $b  = learnguard_brand();
    $tc = $b->textcolor;
    return html_writer::start_div('lg-banner', ['style' =>
        'background:linear-gradient(135deg,' . $b->primary . ',' . $b->dark . ');' .
        'color:' . $tc . ';border-radius:8px;padding:20px 24px;margin-bottom:20px;text-align:center;']) .
        html_writer::tag('h2', $icon . ' ' . $title, ['style' => 'margin:0 0 4px;font-size:20px;text-align:center;color:' . $tc . ';']) .
        ($sub ? html_writer::tag('p', $sub, ['style' => 'margin:0;opacity:0.85;font-size:14px;text-align:center;color:' . $tc . ';']) : '') .
        html_writer::end_div();
}

// -----------------------------------------------------------------------
// Availability
// -----------------------------------------------------------------------

/**
 * Check if an assessment is currently within its availability window.
 *
 * @param stdClass $instance  learnguard row
 * @return bool
 */
function learnguard_is_available(stdClass $instance): bool {
    $now = time();
    if (!empty($instance->timeopen) && $now < (int)$instance->timeopen) {
        return false;
    }
    if (!empty($instance->timeclose) && $now > (int)$instance->timeclose) {
        return false;
    }
    return true;
}

/**
 * Human-readable availability status string.
 *
 * @param stdClass $instance
 * @return string
 */
function learnguard_availability_message(stdClass $instance): string {
    $now = time();
    if (!empty($instance->timeopen) && $now < (int)$instance->timeopen) {
        return get_string('assessmentnotopen', 'learnguard', userdate($instance->timeopen));
    }
    if (!empty($instance->timeclose) && $now > (int)$instance->timeclose) {
        return get_string('assessmentclosed', 'learnguard', userdate($instance->timeclose));
    }
    return '';
}

/**
 * Fetch a learner's personal schedule for an assessment, if one was assigned.
 *
 * @param int $learnguardid learnguard.id
 * @param int $userid
 * @return stdClass|null
 */
function learnguard_get_schedule(int $learnguardid, int $userid): ?stdClass {
    global $DB;
    $record = $DB->get_record('learnguard_schedules', ['learnguard' => $learnguardid, 'userid' => $userid]);
    return $record ?: null;
}

/**
 * Make sure a student can actually get into the COURSE, not just the assessment.
 *
 * LearnGuard's own window (learnguard_schedules) and Continue & Review's own deadline
 * are entirely separate from Moodle's own course-enrolment mechanics — a proctor
 * granting extra time or a review window here has no effect on the student's
 * {user_enrolments} row, which can independently be suspended or already expired.
 * Without this, a proctor's grant is silently pointless: the student can't even reach
 * the activity to use the extra time they were just given.
 *
 * Reactivates every enabled enrolment method this user has in the course (manual,
 * self, cohort sync, etc.) and, when $minuntil is given, pushes out any enrolment
 * that would otherwise expire before that date. Never shortens an enrolment — only
 * ever activates/extends, never suspends/shortens.
 *
 * @param int $userid
 * @param int $courseid
 * @param int $minuntil unix timestamp the enrolment must remain valid until (0 = don't
 *                       touch the expiry date, only fix suspended status)
 */
function learnguard_ensure_course_access(int $userid, int $courseid, int $minuntil = 0): void {
    global $DB;

    $instances = $DB->get_records('enrol', ['courseid' => $courseid, 'status' => ENROL_INSTANCE_ENABLED]);
    if (!$instances) {
        return;
    }

    foreach ($instances as $instance) {
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if (!$ue) {
            continue; // Not enrolled via this particular method.
        }
        $plugin = enrol_get_plugin($instance->enrol);
        if (!$plugin) {
            continue;
        }

        $newstatus  = ($ue->status != ENROL_USER_ACTIVE) ? ENROL_USER_ACTIVE : $ue->status;
        $newtimeend = $ue->timeend;
        if ($minuntil > 0 && $ue->timeend > 0 && $ue->timeend < $minuntil) {
            $newtimeend = $minuntil;
        }

        if ($newstatus != $ue->status || $newtimeend != $ue->timeend) {
            try {
                $plugin->update_user_enrol($instance, $userid, $newstatus, $ue->timestart, $newtimeend);
            } catch (\Throwable $e) {
                debugging('learnguard_ensure_course_access: could not update enrolment ' .
                    $ue->id . ' for user ' . $userid . ' in course ' . $courseid . ': ' .
                    $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}

/**
 * Resolve the effective open/close window for a specific learner: a personal
 * schedule (learnguard_schedules) overrides the instance-level timeopen/timeclose.
 *
 * @param stdClass $instance learnguard row
 * @param int      $userid
 * @return array{0:int,1:int,2:bool} [timeopen, timeclose, hasschedule]
 */
function learnguard_effective_window(stdClass $instance, int $userid): array {
    $schedule = learnguard_get_schedule((int)$instance->id, $userid);
    if ($schedule) {
        return [(int)$schedule->timeopen, (int)$schedule->timeclose, true];
    }
    return [(int)($instance->timeopen ?? 0), (int)($instance->timeclose ?? 0), false];
}

/**
 * Check if an assessment is currently within its availability window for a
 * specific learner, taking any personal schedule into account.
 *
 * @param stdClass $instance learnguard row
 * @param int      $userid
 * @return bool
 */
function learnguard_is_available_for_user(stdClass $instance, int $userid): bool {
    [$timeopen, $timeclose] = learnguard_effective_window($instance, $userid);
    $now = time();
    if ($timeopen && $now < $timeopen) {
        return false;
    }
    if ($timeclose && $now > $timeclose) {
        return false;
    }
    return true;
}

/**
 * The effective time limit (seconds) for a specific attempt — mirrors exactly what the
 * client computes for its own countdown (initTimer() in assessment.js), so this can be
 * used as a server-side backstop that agrees with what the learner sees on screen:
 *  - Continue & Review attempts: the granted review budget (extra_time) IS the whole
 *    limit for this session, not added on top of the activity's normal time limit.
 *  - Normal attempts: the activity's timelimit, or a per-user timelimit_override from
 *    learnguard_schedules when set, plus any extra_time granted (proctor bonus minutes /
 *    break time-back).
 * Returns 0 for "no time limit" (untimed) — callers must treat 0 as never-expires.
 *
 * @param stdClass $instance learnguard row
 * @param stdClass $attempt  learnguard_attempts row
 * @return int seconds, or 0 if untimed
 */
function learnguard_effective_timelimit_seconds(stdClass $instance, stdClass $attempt): int {
    if (!empty($attempt->reopened_for_review)) {
        return (int)($attempt->extra_time ?? 0);
    }
    $base = (int)$instance->timelimit;
    $schedule = learnguard_get_schedule((int)$instance->id, (int)$attempt->userid);
    if ($schedule && !empty($schedule->timelimit_override)) {
        $base = (int)$schedule->timelimit_override;
    }
    if ($base <= 0) {
        return 0;
    }
    return $base + (int)($attempt->extra_time ?? 0);
}

/**
 * Whether an in-progress attempt's OWN time limit has been exceeded — independent of
 * learnguard_is_available_for_user()'s personal/activity WINDOW check (the exam being
 * open at all vs. this specific attempt having run out of allotted minutes). Always
 * false for an untimed activity/session.
 *
 * Approved-break time is excluded from the elapsed calculation: unlike a suspension
 * (extend_attempt_time() shifts timestart forward once resolved), end_break() never
 * shifts timestart — the client's on-screen timer only LOOKS frozen during a break via
 * a local trick that doesn't survive a reload. Without this, a student legitimately on
 * (or just back from) an approved break could be force-finished for time that was never
 * really theirs to lose.
 *
 * @param stdClass $instance learnguard row
 * @param stdClass $attempt  learnguard_attempts row
 * @return bool
 */
function learnguard_attempt_timelimit_expired(stdClass $instance, stdClass $attempt): bool {
    // Clock hasn't started yet (attempt_manager::start_clock() leaves timestart=0 until
    // the client is actually about to show the student content — see start_or_resume_locked()
    // and external.php::start_review()). There's nothing to measure elapsed time against
    // yet, and treating 0 as a real timestamp here would make "now - 0" look like decades
    // of elapsed time, instantly tripping the 48-hour safety net below and force-finishing
    // an attempt the student hasn't even started. learnguard_finish_expired_attempts()
    // handles a genuinely-abandoned pre-start attempt separately (deletes, doesn't finish
    // — nothing was ever shown or answered, so it was never a real graded attempt).
    if ((int)$attempt->timestart === 0) {
        return false;
    }
    // An explicit absolute deadline set on a Continue & Review grant (review_deadline) is
    // checked independently of the minutes-based extra_time limit below — whichever is
    // reached first ends the review. Unlike extra_time, this doesn't depend on timestart
    // ever advancing, so a review that's granted but never actually started (the student
    // never redoes the precheck, so start_review() never runs and timestart stays at
    // whatever it was on the ORIGINAL attempt) still gets a hard, predictable cutoff
    // instead of drifting on indefinitely.
    if (!empty($attempt->reopened_for_review) && !empty($attempt->review_deadline)
            && time() > (int)$attempt->review_deadline) {
        return true;
    }
    $now = time();

    // Absolute safety net, independent of every calculation above: no attempt should
    // EVER be able to sit 'inprogress' indefinitely regardless of which specific edge
    // case (a misconfigured limit, an unresolved break, extra_time somehow left at 0 on
    // a reopened_for_review attempt, etc.) might otherwise let it slip through every
    // check above forever. No legitimate exam/review session — breaks included — should
    // ever genuinely need more than 48 hours.
    if ($now - (int)$attempt->timestart > 48 * HOURSECS) {
        return true;
    }

    $limit = learnguard_effective_timelimit_seconds($instance, $attempt);
    if ($limit <= 0) {
        return false;
    }
    // Never expire while an approved break is still GENUINELY active — i.e. its own
    // deadline hasn't passed yet. end_break() normally sets break_end when the student
    // clicks "end break", but a student who closes the tab during (or right after) an
    // approved break never sends that call, leaving break_approved set with break_end
    // still empty permanently. Blocking expiry forever in that case — as this used to —
    // left an abandoned break-taker stuck 'inprogress' no matter how many days passed.
    // Once the break's own deadline has passed, treat it as effectively over at that
    // deadline instead, and keep evaluating the time limit normally from there.
    if (!empty($attempt->break_approved) && empty($attempt->break_end)) {
        if (empty($attempt->break_deadline) || $now < (int)$attempt->break_deadline) {
            return false; // still genuinely on break (or no deadline recorded to fall back on).
        }
    }
    $effectiveBreakEnd = !empty($attempt->break_end) ? (int)$attempt->break_end
        : (!empty($attempt->break_approved) ? (int)$attempt->break_deadline : 0);
    $breakSeconds = (!empty($attempt->break_approved) && $effectiveBreakEnd > (int)$attempt->break_approved)
                    ? ($effectiveBreakEnd - (int)$attempt->break_approved) : 0;
    $elapsed = $now - (int)$attempt->timestart - $breakSeconds;
    return $elapsed > $limit;
}

/**
 * Force-finish every 'inprogress' attempt site-wide whose personal/activity window has
 * closed or whose own time limit (including a Continue & Review review_deadline) has run
 * out. Shared by the auto_finish_expired_attempts scheduled task (runs every 5 minutes)
 * and a manual "run now" trigger for admins who don't want to wait on cron, or whose
 * site's cron isn't confirmed to be running.
 *
 * @return int number of attempts finished
 */
function learnguard_finish_expired_attempts(): int {
    global $DB;

    $attempts = $DB->get_records('learnguard_attempts', ['state' => 'inprogress']);
    if (empty($attempts)) {
        return 0;
    }

    $lgids = array_unique(array_map(fn($a) => (int)$a->learnguard, $attempts));
    [$insql, $inparams] = $DB->get_in_or_equal($lgids, SQL_PARAMS_NAMED);
    $instances = $DB->get_records_select('learnguard', "id $insql", $inparams);

    $finished = 0;
    foreach ($attempts as $attempt) {
        $instance = $instances[$attempt->learnguard] ?? null;
        if (!$instance) {
            continue; // orphaned attempt (activity deleted without cascade) — leave alone.
        }

        // Clock never started: the student either abandoned precheck/loading before ever
        // seeing a question, or preload is genuinely still running right now (which keeps
        // touching timemodified via its own retries, so a truly live one never looks
        // idle). Never "finish" one of these — finishing implies a real graded attempt,
        // but nothing was ever shown or answered. Only clean up ones clearly abandoned
        // for a long time, and DELETE rather than finish so it never counts as a real
        // attempt or shows up as a phantom 0-score result.
        if ((int)$attempt->timestart === 0) {
            if ((time() - (int)$attempt->timemodified) > 24 * HOURSECS) {
                $DB->delete_records('learnguard_attempt_questions', ['attemptid' => $attempt->id]);
                $DB->delete_records('learnguard_events',            ['attemptid' => $attempt->id]);
                $DB->delete_records('learnguard_snapshots',         ['attemptid' => $attempt->id]);
                $DB->delete_records('learnguard_slots',             ['attemptid' => $attempt->id]);
                $DB->delete_records('learnguard_attempts',          ['id'        => $attempt->id]);
            }
            continue;
        }

        $windowClosed  = empty($attempt->reopened_for_review)
                         && !learnguard_is_available_for_user($instance, (int)$attempt->userid);
        $timelimitOver = learnguard_attempt_timelimit_expired($instance, $attempt);
        if (!$windowClosed && !$timelimitOver) {
            continue;
        }

        \mod_learnguard\attempt_manager::finish($attempt, $instance);
        $finished++;
    }

    return $finished;
}

/**
 * Human-readable availability status string for a specific learner, taking
 * any personal schedule into account.
 *
 * @param stdClass $instance learnguard row
 * @param int      $userid
 * @return string
 */
function learnguard_availability_message_for_user(stdClass $instance, int $userid): string {
    [$timeopen, $timeclose] = learnguard_effective_window($instance, $userid);
    $now = time();
    if ($timeopen && $now < $timeopen) {
        return get_string('assessmentnotopen', 'learnguard', userdate($timeopen));
    }
    if ($timeclose && $now > $timeclose) {
        return get_string('assessmentclosed', 'learnguard', userdate($timeclose));
    }
    return '';
}

// -----------------------------------------------------------------------
// Gradebook integration
// -----------------------------------------------------------------------

/**
 * Create or update the grade item in the gradebook.
 *
 * @param stdClass $instance
 * @param mixed    $grades
 */
function learnguard_grade_item_update(stdClass $instance, $grades = null, ?float $gradepass = null): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname'  => $instance->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => (float)($instance->grade ?? 100),
        'grademin'  => 0,
    ];

    if ($gradepass !== null) {
        $params['gradepass'] = $gradepass;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
        $instance->id, 0, $grades, $params);
}

/**
 * Delete the grade item from the gradebook.
 *
 * @param stdClass $instance
 */
function learnguard_grade_item_delete(stdClass $instance): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
        $instance->id, 0, null, ['deleted' => 1]);
}

/**
 * Update grades in the gradebook.
 *
 * @param stdClass $instance
 * @param int      $userid  0 = all users.
 * @param bool     $nullifnone
 */
function learnguard_update_grades(stdClass $instance, int $userid = 0, bool $nullifnone = true): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    // TODO: retrieve the best grade for each user and update.
    // For now, refresh the grade item.
    learnguard_grade_item_update($instance);
}

// -----------------------------------------------------------------------
// Completion
// -----------------------------------------------------------------------

/**
 * Check custom completion rules for a user.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param int      $userid
 * @param bool     $type
 * @return bool
 */
function learnguard_get_completion_state($course, $cm, $userid, $type): bool {
    global $DB;
    $instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);
    $finished = $DB->record_exists('learnguard_attempts', [
        'learnguard' => $instance->id, 'userid' => $userid, 'state' => 'finished',
    ]);
    return $type == COMPLETION_AND ? $finished : ($finished ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE);
}

// -----------------------------------------------------------------------
// File serving
// -----------------------------------------------------------------------

/**
 * Serve plugin files.
 */
function learnguard_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): void {
    if ($context->contextlevel != CONTEXT_MODULE) { send_file_not_found(); }
    require_login($course, true, $cm);

    $itemid   = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs   = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_learnguard', $filearea, $itemid, $filepath, $filename);
    if (!$file) { send_file_not_found(); }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

// -----------------------------------------------------------------------
// Reset (used by course reset)
// -----------------------------------------------------------------------

/**
 * Return items that can be reset.
 *
 * @param stdClass $mform
 */
function learnguard_reset_course_form_definition(stdClass $mform): void {
    $mform->addElement('checkbox', 'reset_learnguard_attempts', get_string('allAttempts', 'learnguard'));
}

/**
 * Reset the module data.
 *
 * @param stdClass $data
 * @return array
 */
function learnguard_reset_userdata(stdClass $data): array {
    global $DB;
    $results = [];

    if (empty($data->reset_learnguard_attempts)) {
        return $results;
    }

    $instances = $DB->get_records('learnguard', ['course' => $data->courseid]);
    foreach ($instances as $instance) {
        $attempts = $DB->get_records('learnguard_attempts', ['learnguard' => $instance->id]);
        foreach ($attempts as $attempt) {
            if ($attempt->uniqueid) {
                question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
            }
            $DB->delete_records('learnguard_snapshots', ['attemptid' => $attempt->id]);
            $DB->delete_records('learnguard_events',    ['attemptid' => $attempt->id]);
        }
        $DB->delete_records('learnguard_attempts', ['learnguard' => $instance->id]);
    }

    $results[] = [
        'component' => get_string('modulename', 'learnguard'),
        'item'      => get_string('allAttempts', 'learnguard'),
        'error'     => false,
    ];

    return $results;
}

// Every unit-tagged slot in this instance, once — reused across every attempt's resolution
// instead of re-querying learnguard_slots/learnguard_qbank per attempt.
function learnguard_unit_tagged_slots(int $learnguardid): array {
    global $DB;
    try {
        return array_values($DB->get_records_sql(
            "SELECT s.id AS slotid, q.id AS questionid, q.qtype, q.unitid
               FROM {learnguard_slots} s
               JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE s.learnguard = :lgid AND (q.unitid > 0 OR q.qtype = 'choiceessay')",
            ['lgid' => $learnguardid]
        ));
    } catch (Exception $e) { return []; }
}

// Which Grading Unit ids actually have gradable content in ONE specific attempt — the basis
// for scoping Distribution per-unit instead of treating every attempt as needing every unit's
// questions. A plain (non-Choice Essay) unit-tagged slot is instance-wide (every attempt that
// answered it counts); a Choice Essay slot's unit depends entirely on which prompt the student
// actually picked, resolved the exact same way grader.php's own slot-pruning
// (lg_grader_unit_allowed()) does, so Distribution and the actual grading view never disagree
// about which unit an attempt's answer belongs to.
function learnguard_attempt_unit_ids(int $attemptid, array $unitTaggedSlots): array {
    global $DB;
    $units = [];
    foreach ($unitTaggedSlots as $s) {
        if ($s->qtype === 'choiceessay') {
            try {
                $resp = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $s->slotid]);
            } catch (Exception $e) { $resp = false; }
            if ($resp && $resp->responsedata) {
                $dec = json_decode($resp->responsedata, true) ?: [];
                foreach ($dec as $k => $v) {
                    if (strpos((string)$k, '_answer') === false) { continue; }
                    $ceDec = json_decode((string)$v, true);
                    if (is_array($ceDec) && (int)($ceDec['promptid'] ?? 0) > 0) {
                        try {
                            $u = (int)($DB->get_field('learnguard_ce_prompts', 'unitid', ['id' => (int)$ceDec['promptid']]) ?: 0);
                        } catch (Exception $e) { $u = 0; }
                        if ($u > 0) { $units[$u] = true; }
                    }
                }
            }
        } else if ((int)$s->unitid > 0) {
            try {
                $hasResponse = $DB->record_exists('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $s->slotid]);
            } catch (Exception $e) { $hasResponse = false; }
            if ($hasResponse) { $units[(int)$s->unitid] = true; }
        }
    }
    return array_keys($units);
}

// attemptid => [graderids] for assignments the grader has already STARTED (has at least one
// essay grade of their own on that attempt). See learnguard_distribution_plan().
function learnguard_locked_assignments(int $learnguardid): array {
    global $DB;
    $out = [];
    try {
        $rows = $DB->get_records_sql(
            "SELECT ga.id, ga.attemptid, ga.graderid
               FROM {learnguard_grader_assignments} ga
              WHERE ga.learnguard = :lg
                AND EXISTS (SELECT 1 FROM {learnguard_essay_grades} eg
                             WHERE eg.attemptid = ga.attemptid AND eg.graderid = ga.graderid)",
            ['lg' => $learnguardid]);
    } catch (Exception $e) { return []; }
    foreach ($rows as $r) { $out[(int)$r->attemptid][] = (int)$r->graderid; }
    return $out;
}

// Deletes only the assignments nobody has started; returns how many were removed. Started
// ones (see learnguard_locked_assignments()) are left exactly as they are.
function learnguard_clear_unstarted_assignments(int $learnguardid): int {
    global $DB;
    $ids = $DB->get_fieldset_sql(
        "SELECT ga.id FROM {learnguard_grader_assignments} ga
          WHERE ga.learnguard = :lg
            AND NOT EXISTS (SELECT 1 FROM {learnguard_essay_grades} eg
                             WHERE eg.attemptid = ga.attemptid AND eg.graderid = ga.graderid)",
        ['lg' => $learnguardid]);
    if (!empty($ids)) { $DB->delete_records_list('learnguard_grader_assignments', 'id', $ids); }
    return count($ids);
}

// Computes, but does NOT write, how Distribution WOULD split this instance's currently
// ungraded attempts across its eligible Layer-1 (+ optionally global) grader pool, unit by
// unit. Shared by the actual siteadmin.php distribution run (which then performs the writes
// from this same plan) and the read-only "coverage gap" display shown on every page load —
// so what the admin sees BEFORE clicking "Run Distribution" always matches what running it
// would actually do, with no separate copy of this logic to drift out of sync.
//
// A Grading Unit that has content in the ungraded pool but no grader currently restricted to
// it shows up in 'gaps' (keyed -1 for "no unit tag at all" if that bucket has no generalist)
// rather than being silently handed to a mismatched grader — see the actual POST handler's
// own comment for why.
function learnguard_distribution_plan(int $learnguardid): array {
    global $DB;

    $attemptIds = $DB->get_fieldset_sql(
        "SELECT id FROM {learnguard_attempts} WHERE learnguard = :lg AND state = 'finished' AND sumgrades IS NULL",
        ['lg' => $learnguardid]
    );
    $includeGlobal = learnguard_should_include_global_graders($learnguardid);
    $graderRows = $DB->get_records_sql(
        "SELECT DISTINCT g.userid FROM {learnguard_graders} g
          WHERE (g.learnguard = :lg AND g.gradelayer = 1)" . ($includeGlobal ? " OR g.is_global = 1" : ""),
        ['lg' => $learnguardid]
    );
    $graderIds = array_map(fn($r) => (int)$r->userid, $graderRows);

    $unitTaggedSlots = learnguard_unit_tagged_slots($learnguardid);
    $restrictByUser = [];
    if (!empty($unitTaggedSlots) && !empty($graderIds)) {
        [$uidInSql, $uidInParams] = $DB->get_in_or_equal($graderIds, SQL_PARAMS_NAMED, 'gu');
        $unitRows = $DB->get_records_sql(
            "SELECT id, userid, unitid FROM {learnguard_grader_units} WHERE userid $uidInSql", $uidInParams);
        foreach ($unitRows as $ur) { $restrictByUser[(int)$ur->userid][] = (int)$ur->unitid; }
    }
    $generalistIds = array_values(array_filter($graderIds, fn($uid) => empty($restrictByUser[$uid])));

    // Assignments a grader has already started (graded any slot of) are LOCKED — a
    // re-distribution never moves them, so an attempt someone is mid-way through (or has
    // finished) stays theirs. A locked assignment also counts as covering that attempt (for
    // that unit, or entirely if the locked grader has no unit restriction), so it isn't
    // handed out a second time. Only genuinely untouched attempts stay in the pools below.
    $locked = learnguard_locked_assignments($learnguardid);
    $lockedUnits = [];
    if (!empty($unitTaggedSlots) && !empty($locked)) {
        $lockedUids = array_values(array_unique(array_merge(...array_values($locked))));
        [$lSql, $lP] = $DB->get_in_or_equal($lockedUids, SQL_PARAMS_NAMED, 'lk');
        foreach ($DB->get_records_sql("SELECT id, userid, unitid FROM {learnguard_grader_units} WHERE userid $lSql", $lP) as $lur) {
            $lockedUnits[(int)$lur->userid][] = (int)$lur->unitid;
        }
    }
    $coveredByLocked = function(int $aid, ?int $unit) use ($locked, $lockedUnits): bool {
        foreach ($locked[$aid] ?? [] as $g) {
            if ($unit === null) { return true; }
            if (empty($lockedUnits[$g]) || in_array($unit, $lockedUnits[$g], true)) { return true; }
        }
        return false;
    };

    $unitPools = [];
    $noUnitAttempts = [];
    foreach ($attemptIds as $aid) {
        $units = empty($unitTaggedSlots) ? [] : learnguard_attempt_unit_ids((int)$aid, $unitTaggedSlots);
        if (empty($units)) {
            if (!$coveredByLocked((int)$aid, null)) { $noUnitAttempts[] = (int)$aid; }
            continue;
        }
        foreach ($units as $u) {
            if (!$coveredByLocked((int)$aid, (int)$u)) { $unitPools[$u][] = (int)$aid; }
        }
    }

    $specialistsByUnit = [];
    $gaps = [];
    foreach ($unitPools as $unitId => $poolAttemptIds) {
        $specialists = array_values(array_filter($graderIds,
            fn($uid) => in_array($unitId, $restrictByUser[$uid] ?? [], true)));
        $specialistsByUnit[$unitId] = $specialists;
        if (empty($specialists)) { $gaps[$unitId] = count($poolAttemptIds); }
    }
    if (!empty($noUnitAttempts) && empty($generalistIds)) {
        $gaps[-1] = count($noUnitAttempts);
    }

    return [
        'attemptIds' => array_map('intval', $attemptIds),
        'graderIds' => $graderIds,
        'unitPools' => $unitPools,
        'noUnitAttempts' => $noUnitAttempts,
        'specialistsByUnit' => $specialistsByUnit,
        'generalistIds' => $generalistIds,
        'gaps' => $gaps,
    ];
}

/**
 * Distribute finished attempts with essay questions among Layer-1 graders.
 *
 * Replaces all existing learnguard_grader_assignments rows for this activity
 * with a fresh distribution. Layer-2 graders and managers always see every
 * finished attempt regardless of this table (see grader.php) — distribution
 * only affects which attempts each Layer-1 grader sees in their queue.
 *
 * @param int    $learnguardid Activity instance ID.
 * @param string $mode         'split' (even round-robin) or 'random' (capped random sample per grader).
 * @param int    $cap          Max attempts per grader for 'random' mode (0 = even share of the pool).
 */
// Resolves whether Global Graders should be treated as part of an assessment's assigned
// grading workforce — counted into Grading Distribution's split/random share, and eligible
// to be listed/restricted on the Grading Units panel — or left out entirely (in which case
// they simply keep their pre-existing unrestricted view/grade access, untouched by either
// mechanism, exactly as a Global Grader always could regardless of Distribution or Units).
// learnguard_grading_config.include_global_graders is a per-assessment override: -1 means
// "no override, use the site-wide default" (settings.php: include_global_graders_default,
// defaulting to included — today's pre-existing behaviour, so nothing changes for anyone
// until this is deliberately touched); 0/1 means the admin explicitly chose exclude/include
// for this one assessment regardless of the site default.
function learnguard_should_include_global_graders(int $learnguardid): bool {
    global $DB;
    $override = -1;
    try {
        // get_field() returns false (NOT null) when no learnguard_grading_config row exists
        // for this instance yet — the common case for any assessment whose admin has never
        // touched Grading Distribution — so a plain `?? -1` null-coalesce would never catch
        // it and would instead cast false to 0 ("explicitly exclude"), silently flipping the
        // default for most assessments. Both false and null are treated as "no override" here.
        $raw = $DB->get_field('learnguard_grading_config', 'include_global_graders', ['learnguard' => $learnguardid]);
        $override = ($raw === false || $raw === null) ? -1 : (int)$raw;
    } catch (Exception $e) { $override = -1; }
    if ($override !== -1) { return $override === 1; }
    // Same false-vs-unset caveat as above: get_config() can return false (not the setting's
    // declared default of 1) for the brief window between this upgrade installing and an
    // admin ever opening Site Administration, since that's what actually persists a
    // checkbox's default into config_plugins on this Moodle version. Treated as "not yet
    // set" → the intended default (include), not as an explicit off.
    $siteDefault = get_config('learnguard', 'include_global_graders_default');
    return $siteDefault === false ? true : (bool)$siteDefault;
}

// Per-grader workload for an instance WHEN Distribution is active (assignment rows exist):
// userid => ['assigned' => distinct attempts assigned to them, 'graded' => distinct attempts
// among THOSE they've actually graded]. Returns null when nothing is distributed, in which
// case every grader sees every finished attempt and callers should keep using the
// activity-wide totals. Reported live: the grader tables showed "Left: 208" for a grader
// whose own share was 52, because "left" was always total-finished minus graded, ignoring
// that Distribution had already narrowed what's actually theirs to grade.
function learnguard_distribution_workloads(int $learnguardid): ?array {
    global $DB;
    try {
        if (!$DB->record_exists('learnguard_grader_assignments', ['learnguard' => $learnguardid])) { return null; }
        $assigned = $DB->get_records_sql(
            "SELECT graderid, COUNT(DISTINCT attemptid) AS cnt
               FROM {learnguard_grader_assignments} WHERE learnguard = :lg GROUP BY graderid",
            ['lg' => $learnguardid]);
        $graded = $DB->get_records_sql(
            "SELECT eg.graderid, COUNT(DISTINCT eg.attemptid) AS cnt
               FROM {learnguard_essay_grades} eg
               JOIN {learnguard_grader_assignments} ga
                    ON ga.attemptid = eg.attemptid AND ga.learnguard = :lg AND ga.graderid = eg.graderid
           GROUP BY eg.graderid",
            ['lg' => $learnguardid]);
    } catch (Exception $e) { return null; }
    $out = [];
    foreach ($assigned as $a) { $out[(int)$a->graderid] = ['assigned' => (int)$a->cnt, 'graded' => 0]; }
    foreach ($graded as $g) {
        if (isset($out[(int)$g->graderid])) { $out[(int)$g->graderid]['graded'] = (int)$g->cnt; }
    }
    return $out;
}

// Sends one grader a "you still have N to grade" reminder for an assessment. Shared by
// siteadmin.php's single Nudge button and its bulk "Nudge Selected" so both send exactly the
// same email. Uses learnguard_distribution_workloads() when Distribution is active (the
// grader's own share), else the activity-wide finished count (grader.php's own "no
// assignments yet -> show everything" fallback — using the distribution-only count
// unconditionally once left "remaining" stuck at 0 for every non-distributed grader).
// Returns [bool sent, string message].
function learnguard_nudge_grader(int $learnguardid, int $userid, int $deadline = 0): array {
    global $DB;
    $instance   = $DB->get_record('learnguard', ['id' => $learnguardid]);
    $graderUser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
    if (!$instance || !$graderUser) { return [false, 'Grader or assessment not found.']; }

    $wl = learnguard_distribution_workloads($learnguardid);
    if ($wl !== null) {
        $assigned = $wl[$userid]['assigned'] ?? 0;
        $graded   = $wl[$userid]['graded'] ?? 0;
    } else {
        $assigned = $DB->count_records('learnguard_attempts', ['learnguard' => $learnguardid, 'state' => 'finished']);
        $graded   = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT eg.attemptid)
               FROM {learnguard_essay_grades} eg
               JOIN {learnguard_attempts} a ON a.id = eg.attemptid AND a.learnguard = :lg
              WHERE eg.graderid = :uid",
            ['lg' => $learnguardid, 'uid' => $userid]
        );
    }
    $remaining = max(0, $assigned - $graded);
    $dlStr     = $deadline > 0 ? userdate($deadline, '%d %B %Y') : 'No deadline set';
    $cm        = get_coursemodule_from_instance('learnguard', $learnguardid, 0, false, MUST_EXIST);
    $graderUrl = (new moodle_url('/mod/learnguard/grader.php', ['id' => $cm->id]))->out(false);

    learnguard_send_email(
        $graderUser,
        '⏰ Reminder: ' . $remaining . ' attempt(s) pending — ' . format_string($instance->name),
        'Hi ' . fullname($graderUser) . ',<br><br>' .
        'This is a reminder that you have <strong>' . $remaining . ' attempt(s)</strong> still to grade for:<br>' .
        '<strong>' . format_string($instance->name) . '</strong><br><br>' .
        'Assigned: ' . $assigned . ' &nbsp;|&nbsp; Graded: ' . $graded .
        ' &nbsp;|&nbsp; Remaining: <strong>' . $remaining . '</strong><br>' .
        'Deadline: <strong>' . $dlStr . '</strong><br><br>' .
        'Open your grading queue: <a href="' . $graderUrl . '">' . $graderUrl . '</a><br><br>' .
        'LearnGuard — Secure Assessment Platform'
    );
    return [true, 'Reminder sent to ' . fullname($graderUser) . ' (' . $remaining . ' pending).'];
}

// Sets ONE grader's total assigned count for an assessment to $target — lowers or raises
// their share without re-running (and reshuffling) the whole distribution. Requested live:
// e.g. cut Ukpong to ~20, hold the rest back, and hand the remainder to her (or someone
// else) later.
//  - Lowering: only attempts they have NOT graded any slot of are ever taken back (never
//    yanks work out from under them); those attempts simply lose their assignment row and
//    fall back into the unassigned pool. Can't go below what they've already graded.
//  - Raising: draws only from the genuinely unassigned pool and, unit-aware like the main
//    distribution, only attempts that need one of THEIR units and aren't already covered
//    for that unit by another grader (a grader with no unit restriction draws from
//    attempts with no unit-tagged content, not already covered by another generalist).
// Returns [bool ok, string message].
function learnguard_adjust_grader_load(int $learnguardid, int $userid, int $target): array {
    global $DB;
    $target = max(0, $target);

    $rows = $DB->get_records('learnguard_grader_assignments', ['learnguard' => $learnguardid]);
    $byAttempt = [];   // attemptid => [graderids]
    $mine = [];        // attemptid => assignment row id (this grader's)
    foreach ($rows as $r) {
        $byAttempt[(int)$r->attemptid][] = (int)$r->graderid;
        if ((int)$r->graderid === $userid) { $mine[(int)$r->attemptid] = (int)$r->id; }
    }
    $gradedMine = [];
    if (!empty($mine)) {
        [$inSql, $inP] = $DB->get_in_or_equal(array_keys($mine), SQL_PARAMS_NAMED, 'am');
        $gradedMine = array_flip(array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT attemptid FROM {learnguard_essay_grades} WHERE graderid = :uid AND attemptid $inSql",
            array_merge(['uid' => $userid], $inP))));
    }
    $current = count($mine);
    $note = '';
    if ($target < count($gradedMine)) {
        $target = count($gradedMine);
        $note = ' (can\'t go below the ' . count($gradedMine) . ' already graded)';
    }

    if ($target < $current) {
        $removable = array_values(array_diff(array_keys($mine), array_keys($gradedMine)));
        shuffle($removable);
        $toRemove = array_slice($removable, 0, $current - $target);
        foreach ($toRemove as $aid) { $DB->delete_records('learnguard_grader_assignments', ['id' => $mine[$aid]]); }
        return [true, 'Now ' . ($current - count($toRemove)) . ' assigned; ' . count($toRemove) .
            ' attempt(s) returned to the unassigned pool' . $note . '.'];
    }
    if ($target === $current) { return [true, 'No change — already ' . $current . ' assigned' . $note . '.']; }

    $need = $target - $current;
    $plan = learnguard_distribution_plan($learnguardid);
    $unitsOf = [];
    if (!empty($plan['unitPools'])) {
        $uids = array_values(array_unique(array_merge([$userid], ...array_values($byAttempt ?: [[]]))));
        [$uSql, $uP] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'gu');
        foreach ($DB->get_records_sql("SELECT id, userid, unitid FROM {learnguard_grader_units} WHERE userid $uSql", $uP) as $ur) {
            $unitsOf[(int)$ur->userid][] = (int)$ur->unitid;
        }
    }
    $myUnits = $unitsOf[$userid] ?? [];
    $cand = [];
    if (empty($myUnits)) {
        foreach ($plan['noUnitAttempts'] as $aid) {
            if (isset($mine[$aid])) { continue; }
            $covered = false;
            foreach ($byAttempt[$aid] ?? [] as $g) {
                if ($g !== $userid && empty($unitsOf[$g])) { $covered = true; break; }
            }
            if (!$covered) { $cand[$aid] = true; }
        }
    } else {
        foreach ($myUnits as $u) {
            foreach ($plan['unitPools'][$u] ?? [] as $aid) {
                if (isset($mine[$aid]) || isset($cand[$aid])) { continue; }
                $covered = false;
                foreach ($byAttempt[$aid] ?? [] as $g) {
                    if ($g !== $userid && in_array($u, $unitsOf[$g] ?? [], true)) { $covered = true; break; }
                }
                if (!$covered) { $cand[$aid] = true; }
            }
        }
    }
    $candIds = array_keys($cand);
    shuffle($candIds);
    $take = array_slice($candIds, 0, $need);
    $now = time();
    foreach ($take as $aid) {
        $DB->insert_record('learnguard_grader_assignments', (object)[
            'learnguard' => $learnguardid, 'attemptid' => (int)$aid, 'graderid' => $userid, 'timecreated' => $now,
        ]);
    }
    if (!empty($take)) {
        $mode = $DB->get_field('learnguard_grading_config', 'gradingmode', ['learnguard' => $learnguardid]);
        if (!in_array($mode, ['split', 'random'], true)) { learnguard_set_grading_mode($learnguardid, 'split'); }
    }
    $msg = 'Now ' . ($current + count($take)) . ' assigned';
    if (count($take) < $need) {
        $msg .= ' — only ' . count($take) . ' eligible unassigned attempt(s) were available (asked for ' . $need . ' more)';
    }
    return [true, $msg . '.'];
}

// Automation for the above: when enabled for an assessment (site config autotopup_<id> =
// batch size, 0/unset = off), a grader who finishes everything currently assigned to them is
// automatically topped up with another batch from the unassigned pool. Called after each grade
// save (grader.php); silent no-op unless Distribution is active and they're actually done.
function learnguard_auto_topup(int $learnguardid, int $userid): void {
    $batch = (int)get_config('learnguard', 'autotopup_' . $learnguardid);
    if ($batch <= 0) { return; }
    $wl = learnguard_distribution_workloads($learnguardid);
    if ($wl === null || !isset($wl[$userid])) { return; }
    if ($wl[$userid]['assigned'] - $wl[$userid]['graded'] > 0) { return; }
    learnguard_adjust_grader_load($learnguardid, $userid, $wl[$userid]['assigned'] + $batch);
}

// Persists ONLY gradingmode into learnguard_grading_config, preserving every other field on
// an existing row (or defaulting them sanely for a brand-new one). Both grader.php's own
// $distributed check and block_learnguard.php's dashboard recompute treat gradingmode being
// 'split'/'random' as THE signal that Distribution is actually active for an instance — not
// "do learnguard_grader_assignments rows exist" — specifically so a stale set of assignment
// rows left over after Distribution was turned back off doesn't keep wrongly restricting
// someone (see grader.php's own $distributed fix). siteadmin.php's distribution widget writes
// assignment rows directly without ever going through admin.php's "Save & Distribute" form
// (the only other place this got persisted before), so without this call, every consumer of
// that signal never recognized Distribution as active and fell through to "show everything"
// for every grader — reported live as both the dashboard block and grader.php itself showing
// totals instead of each grader's own assigned share.
function learnguard_set_grading_mode(int $learnguardid, string $gradingmode): void {
    global $DB;
    try {
        $existing = $DB->get_record('learnguard_grading_config', ['learnguard' => $learnguardid]);
        if ($existing) {
            $DB->update_record('learnguard_grading_config',
                (object)['id' => $existing->id, 'gradingmode' => $gradingmode, 'timemodified' => time()]);
        } else {
            $DB->insert_record('learnguard_grading_config', (object)[
                'learnguard' => $learnguardid, 'gradingmode' => $gradingmode, 'gradingcap' => 0,
                'include_layer2' => 0, 'include_global_graders' => -1, 'timemodified' => time(),
            ]);
        }
    } catch (Exception $e) {}
}

function learnguard_distribute_grading(int $learnguardid, string $mode, int $cap, bool $includeLayer2 = false): void {
    global $DB;

    // Now routed through the exact same learnguard_distribution_plan() as siteadmin.php's
    // own distribution widget — this function used to have its own separate, older
    // pool-building logic (blind to Grading Units entirely, and scoped to ALL finished
    // attempts rather than just ungraded ones), which is exactly the kind of two-copies-
    // drifting-apart bug that caused live confusion elsewhere on this feature. One deliberate
    // behaviour change from that old logic: this now only (re)distributes UNGRADED attempts,
    // matching every other part of this feature and the status displays around it — an
    // already-graded attempt is never reshuffled onto someone else by a later distribution
    // run.
    $plan = learnguard_distribution_plan($learnguardid);
    // Started assignments stay locked with their graders (the plan already excludes them
    // from the pools); only untouched ones are cleared and redone.
    learnguard_clear_unstarted_assignments($learnguardid);

    foreach ($plan['unitPools'] as $unitId => $poolAttemptIds) {
        $specialistIds = $plan['specialistsByUnit'][$unitId] ?? [];
        if (empty($specialistIds)) { continue; } // gap — left unassigned on purpose, see plan()
        $poolAttempts = array_map(fn($aid) => (object)['id' => $aid], $poolAttemptIds);
        $poolGraders  = array_map(fn($uid) => (object)['userid' => $uid], $specialistIds);
        learnguard_distribute_pool($learnguardid, $poolGraders, $poolAttempts, $mode, $cap);
    }
    if (!empty($plan['noUnitAttempts']) && !empty($plan['generalistIds'])) {
        $poolAttempts = array_map(fn($aid) => (object)['id' => $aid], $plan['noUnitAttempts']);
        $poolGraders  = array_map(fn($uid) => (object)['userid' => $uid], $plan['generalistIds']);
        learnguard_distribute_pool($learnguardid, $poolGraders, $poolAttempts, $mode, $cap);
    }

    // Layer 2 — only when explicitly opted in (learnguard_grading_config.include_layer2).
    // An INDEPENDENT exclusive split of the same UNGRADED attempt pool (its own fresh
    // shuffle/draw, not unit-scoped — Layer 2 is a full second-pass reviewer role), so no
    // two Layer-2 graders overlap either. Default behaviour (opted out) is unchanged: every
    // Layer-2 grader still sees every finished attempt, as a full second-pass reviewer.
    if ($includeLayer2) {
        $layer2Graders = array_values($DB->get_records('learnguard_graders',
            ['learnguard' => $learnguardid, 'gradelayer' => 2], 'id ASC'));
        $ungradedAttempts = array_map(fn($aid) => (object)['id' => $aid], $plan['attemptIds']);
        if (!empty($layer2Graders) && !empty($ungradedAttempts)) {
            learnguard_distribute_pool($learnguardid, $layer2Graders, $ungradedAttempts, $mode, $cap);
        }
    }
}

/**
 * One distribution pass: assigns $attempts to $graders with no overlap among them,
 * either splitting round-robin ('split') or capping a random share each ('random').
 * Shared by learnguard_distribute_grading()'s Layer-1(+global) and optional Layer-2
 * passes so both use identical, consistently-tested assignment logic.
 */
function learnguard_distribute_pool(int $learnguardid, array $graders, array $attempts, string $mode, int $cap): void {
    global $DB;
    $pool = $attempts;
    shuffle($pool);
    $now = time();

    if ($mode === 'split') {
        $numgraders = count($graders);
        foreach ($pool as $i => $attempt) {
            $grader = $graders[$i % $numgraders];
            $DB->insert_record('learnguard_grader_assignments', (object)[
                'learnguard'  => $learnguardid,
                'attemptid'   => $attempt->id,
                'graderid'    => $grader->userid,
                'timecreated' => $now,
            ]);
        }
        return;
    }

    // 'random' — each grader gets up to $cap random attempts (no overlap).
    // A cap of 0 falls back to an even share so every grader gets work.
    $effectivecap = $cap > 0 ? $cap : (int)ceil(count($pool) / count($graders));
    foreach ($graders as $grader) {
        if (empty($pool)) {
            break;
        }
        $take = min($effectivecap, count($pool));
        for ($i = 0; $i < $take; $i++) {
            $attempt = array_shift($pool);
            $DB->insert_record('learnguard_grader_assignments', (object)[
                'learnguard'  => $learnguardid,
                'attemptid'   => $attempt->id,
                'graderid'    => $grader->userid,
                'timecreated' => $now,
            ]);
        }
    }
}
