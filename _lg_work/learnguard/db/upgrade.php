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
 * Upgrade steps for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_learnguard_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // -----------------------------------------------------------------------
    // v1.0.5 — Add all columns introduced after v1.0.0, plus own question bank
    // -----------------------------------------------------------------------
    if ($oldversion < 2025051805) {

        // ---- learnguard table: add missing columns ----
        $table = new xmldb_table('learnguard');

        $cols = [
            ['gradepass',        'number', null, null, XMLDB_NOTNULL, null, '0'],
            ['timeopen',         'int',    10,   null, XMLDB_NOTNULL, null, '0'],
            ['timeclose',        'int',    10,   null, XMLDB_NOTNULL, null, '0'],
            ['show_results',     'int',    1,    null, XMLDB_NOTNULL, null, '1'],
            ['results_released', 'int',    10,   null, XMLDB_NOTNULL, null, '0'],
        ];

        foreach ($cols as [$name, $type, $precision, $scale, $notnull, $sequence, $default]) {
            if (!$dbman->field_exists($table, $name)) {
                if ($type === 'number') {
                    $field = new xmldb_field($name, XMLDB_TYPE_NUMBER, '10,5', null, $notnull, $sequence, $default);
                } else {
                    $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, $precision, null, $notnull, $sequence, $default);
                }
                $dbman->add_field($table, $field);
            }
        }

        // ---- learnguard_qbank_cats ----
        if (!$dbman->table_exists('learnguard_qbank_cats')) {
            $t = new xmldb_table('learnguard_qbank_cats');
            $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('name',        XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL, null, null);
            $t->add_field('description', XMLDB_TYPE_TEXT,   null,  null, null, null, null);
            $t->add_field('sortorder',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timemodified',XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($t);
        }

        // ---- learnguard_qbank ----
        if (!$dbman->table_exists('learnguard_qbank')) {
            $t = new xmldb_table('learnguard_qbank');
            $t->add_field('id',                  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('categoryid',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('name',                 XMLDB_TYPE_CHAR,   '255',null, XMLDB_NOTNULL, null, null);
            $t->add_field('questiontext',         XMLDB_TYPE_TEXT,   null,  null, null, null, null);
            $t->add_field('questiontextformat',   XMLDB_TYPE_INTEGER, '4',  null, XMLDB_NOTNULL, null, '1');
            $t->add_field('qtype',                XMLDB_TYPE_CHAR,   '20', null, XMLDB_NOTNULL, null, 'multichoice');
            $t->add_field('defaultmark',          XMLDB_TYPE_NUMBER, '10,5',null, XMLDB_NOTNULL, null, '1');
            $t->add_field('shuffleanswers',       XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '1');
            $t->add_field('generalfeedback',      XMLDB_TYPE_TEXT,   null,  null, null, null, null);
            $t->add_field('generalfeedbackformat',XMLDB_TYPE_INTEGER, '4',  null, XMLDB_NOTNULL, null, '1');
            $t->add_field('timecreated',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timemodified',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('createdby',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t->add_index('categoryid', XMLDB_INDEX_NOTUNIQUE, ['categoryid']);
            $dbman->create_table($t);
        }

        // ---- learnguard_qbank_answers ----
        if (!$dbman->table_exists('learnguard_qbank_answers')) {
            $t = new xmldb_table('learnguard_qbank_answers');
            $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('questionid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('answertext',  XMLDB_TYPE_TEXT,   null,  null, null, null, null);
            $t->add_field('answertextformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
            $t->add_field('fraction',    XMLDB_TYPE_NUMBER, '10,5',null, XMLDB_NOTNULL, null, '0');
            $t->add_field('feedback',    XMLDB_TYPE_TEXT,   null,  null, null, null, null);
            $t->add_field('sortorder',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t->add_index('questionid', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
            $dbman->create_table($t);
        }

        // ---- learnguard_responses ----
        if (!$dbman->table_exists('learnguard_responses')) {
            $t = new xmldb_table('learnguard_responses');
            $t->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('attemptid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('slotid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('questionid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('responsedata', XMLDB_TYPE_TEXT,   null,  null, null, null, null);
            $t->add_field('fraction',     XMLDB_TYPE_NUMBER, '10,5',null, XMLDB_NOTNULL, null, '0');
            $t->add_field('state',        XMLDB_TYPE_CHAR,   '20', null, XMLDB_NOTNULL, null, 'todo');
            $t->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $t->add_index('slotid',    XMLDB_INDEX_NOTUNIQUE, ['slotid']);
            $dbman->create_table($t);
        }

        // ---- learnguard_qbank_cats: seed a default category ----
        if (!$DB->record_exists('learnguard_qbank_cats', [])) {
            $DB->insert_record('learnguard_qbank_cats', (object)[
                'name'         => 'General',
                'description'  => 'Default question category',
                'sortorder'    => 0,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }

        upgrade_mod_savepoint(true, 2025051805, 'learnguard');
    }

    // -----------------------------------------------------------------------
    // v1.0.6 — Randomization support + per-attempt question ordering
    // -----------------------------------------------------------------------
    if ($oldversion < 2025051806) {

        // Add randomize / num_questions to learnguard table.
        $table = new xmldb_table('learnguard');
        foreach ([
            ['randomize',     'int',  1],
            ['num_questions', 'int', 10],
        ] as [$name, $type, $len]) {
            if (!$dbman->field_exists($table, $name)) {
                $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, $len, null, XMLDB_NOTNULL, null, '0');
                $dbman->add_field($table, $field);
            }
        }

        // learnguard_attempt_questions table.
        if (!$dbman->table_exists('learnguard_attempt_questions')) {
            $t = new xmldb_table('learnguard_attempt_questions');
            $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('slotid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('questionid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('slotnum',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $dbman->create_table($t);
        }

        upgrade_mod_savepoint(true, 2025051806, 'learnguard');
    }

    // v1.1.4 — add is_global flag to learnguard_proctors so a user can proctor
    //           ALL assessments site-wide without per-activity assignment.
    if ($oldversion < 2025051814) {
        $table = new xmldb_table('learnguard_proctors');
        $field = new xmldb_field('is_global', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'role');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2025051814, 'learnguard');
    }

    // v1.1.6 — Phase 3: chat messages, exam PIN, break management, extended time.
    if ($oldversion < 2025051816) {

        // ── learnguard_messages (proctor ↔ student chat) ──────────────────
        $t = new xmldb_table('learnguard_messages');
        if (!$dbman->table_exists($t)) {
            $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('learnguard',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('senderid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('sendertype',  XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'student');
            $t->add_field('message',     XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
            $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('isseen',      XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary',   XMLDB_KEY_PRIMARY,  ['id']);
            $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $dbman->create_table($t);
        }

        // ── learnguard: access_pin field ──────────────────────────────────
        $lt  = new xmldb_table('learnguard');
        $fld = new xmldb_field('access_pin', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, '', 'instructions');
        if (!$dbman->field_exists($lt, $fld)) { $dbman->add_field($lt, $fld); }

        // ── learnguard_attempts: break + extended time fields ─────────────
        $at = new xmldb_table('learnguard_attempts');
        foreach ([
            ['extra_time',      XMLDB_TYPE_INTEGER, '10', '0'],  // extra seconds granted by proctor
            ['break_requested', XMLDB_TYPE_INTEGER, '10', '0'],  // unix ts of break request
            ['break_approved',  XMLDB_TYPE_INTEGER, '10', '0'],  // unix ts proctor approved
            ['break_end',       XMLDB_TYPE_INTEGER, '10', '0'],  // unix ts student ended break
        ] as [$fname, $type, $len, $default]) {
            $f = new xmldb_field($fname, $type, $len, null, XMLDB_NOTNULL, null, $default, 'timemodified');
            if (!$dbman->field_exists($at, $f)) { $dbman->add_field($at, $f); }
        }

        upgrade_mod_savepoint(true, 2025051816, 'learnguard');
    }

    // v1.2.1 — Incident management table + notification_hours field.
    if ($oldversion < 2025051821) {

        // learnguard_incidents — incident management for proctors.
        $t = new xmldb_table('learnguard_incidents');
        if (!$dbman->table_exists($t)) {
            $t->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('learnguard',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('attemptid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('proctoriid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('severity',     XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL, null, 'medium');
            $t->add_field('title',        XMLDB_TYPE_CHAR,    '255',null, XMLDB_NOTNULL, null, null);
            $t->add_field('description',  XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
            $t->add_field('status',       XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'open');
            $t->add_field('resolution',   XMLDB_TYPE_TEXT,    null, null, false,         null, null);
            $t->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timeresolved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary',    XMLDB_KEY_PRIMARY,   ['id']);
            $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $dbman->create_table($t);
        }

        // learnguard_essay_grades — manual essay grade overrides.
        $t2 = new xmldb_table('learnguard_essay_grades');
        if (!$dbman->table_exists($t2)) {
            $t2->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t2->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t2->add_field('slotid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t2->add_field('graderid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t2->add_field('grade',       XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', 5);
            $t2->add_field('feedback',    XMLDB_TYPE_TEXT,    null, null, false,         null, null);
            $t2->add_field('timegraded',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($t2);
        }

        // notification_hours on learnguard table — for pre-exam emails.
        $lt  = new xmldb_table('learnguard');
        $fld = new xmldb_field('notification_hours', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'access_pin');
        if (!$dbman->field_exists($lt, $fld)) { $dbman->add_field($lt, $fld); }

        upgrade_mod_savepoint(true, 2025051821, 'learnguard');
    }

    // v1.3.2 — Audio clips table for noise-triggered recordings.
    if ($oldversion < 2025051832) {
        $t = new xmldb_table('learnguard_audio_clips');
        if (!$dbman->table_exists($t)) {
            $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('audiodata',   XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
            $t->add_field('duration',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('noise_level', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary',    XMLDB_KEY_PRIMARY,   ['id']);
            $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $dbman->create_table($t);
        }
        upgrade_mod_savepoint(true, 2025051832, 'learnguard');
    }

    // v1.3.4 — Add courseid to learnguard_proctors + learnguard_graders table.
    if ($oldversion < 2025051834) {
        // courseid on proctors for course-level proctor assignment.
        $pt  = new xmldb_table('learnguard_proctors');
        $fld = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'is_global');
        if (!$dbman->field_exists($pt, $fld)) {
            $dbman->add_field($pt, $fld);
        }

        // learnguard_graders — essay grader assignments with anonymous + layer support.
        $gt = new xmldb_table('learnguard_graders');
        if (!$dbman->table_exists($gt)) {
            $gt->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $gt->add_field('learnguard',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gt->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gt->add_field('anonymous',   XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
            $gt->add_field('gradelayer',  XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '1');
            $gt->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $gt->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $gt->add_index('learnguard', XMLDB_INDEX_NOTUNIQUE, ['learnguard']);
            $dbman->create_table($gt);
        }

        upgrade_mod_savepoint(true, 2025051834, 'learnguard');
    }

    // v1.3.9 — Face approval requests + messaging tables.
    // Stores pre-exam face match approval requests (keyed by cmid+userid, no attemptid needed).
    if ($oldversion < 2025051839) {
        $fat = new xmldb_table('learnguard_face_approvals');
        if (!$dbman->table_exists($fat)) {
            $fat->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $fat->add_field('cmid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $fat->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $fat->add_field('status',       XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'pending');
            $fat->add_field('passportdata', XMLDB_TYPE_TEXT,    null, null, false,         null, null);
            $fat->add_field('matchpct',     XMLDB_TYPE_INTEGER, '3',  null, XMLDB_NOTNULL, null, '0');
            $fat->add_field('reviewedby',   XMLDB_TYPE_INTEGER, '10', null, false,         null, null);
            $fat->add_field('reviewnote',   XMLDB_TYPE_TEXT,    null, null, false,         null, null);
            $fat->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $fat->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $fat->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $fat->add_index('cmid_userid',  XMLDB_INDEX_NOTUNIQUE, ['cmid', 'userid']);
            $dbman->create_table($fat);
        }

        // learnguard_fa_messages — chat messages tied to face approval requests.
        $fmt = new xmldb_table('learnguard_fa_messages');
        if (!$dbman->table_exists($fmt)) {
            $fmt->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $fmt->add_field('approvalid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $fmt->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $fmt->add_field('sendertype',  XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'student');
            $fmt->add_field('message',     XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
            $fmt->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $fmt->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $fmt->add_index('approvalid', XMLDB_INDEX_NOTUNIQUE, ['approvalid']);
            $dbman->create_table($fmt);
        }

        upgrade_mod_savepoint(true, 2025051839, 'learnguard');
    }

    // v1.4.3a — Precheck session tracking table.
    if ($oldversion < 2025052004) {
        $pct = new xmldb_table('learnguard_precheck_sessions');
        if (!$dbman->table_exists($pct)) {
            $pct->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $pct->add_field('cmid',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $pct->add_field('userid',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $pct->add_field('current_step', XMLDB_TYPE_CHAR,    '50',  null, XMLDB_NOTNULL, null, 'welcome');
            $pct->add_field('step_number',  XMLDB_TYPE_INTEGER, '3',   null, XMLDB_NOTNULL, null, '0');
            $pct->add_field('total_steps',  XMLDB_TYPE_INTEGER, '3',   null, XMLDB_NOTNULL, null, '0');
            $pct->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $pct->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $pct->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $pct->add_index('cmid_userid', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'userid']);
            $dbman->create_table($pct);
        }
        upgrade_mod_savepoint(true, 2025052004, 'learnguard');
    }

    // v1.4.3 — Timed results release + question timing per response.
    if ($oldversion < 2025052003) {
        // timeresults on learnguard table: when students can see their results.
        $lt  = new xmldb_table('learnguard');
        $fld = new xmldb_field('timeresults', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'show_results');
        if (!$dbman->field_exists($lt, $fld)) { $dbman->add_field($lt, $fld); }

        // show_selfcheck on learnguard table: show risk summary to student after finishing.
        $fld2 = new xmldb_field('show_selfcheck', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'timeresults');
        if (!$dbman->field_exists($lt, $fld2)) { $dbman->add_field($lt, $fld2); }

        // timespent on learnguard_responses: track seconds spent on each question.
        $rt  = new xmldb_table('learnguard_responses');
        if ($dbman->table_exists($rt)) {
            $rfld = new xmldb_field('timespent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'response_data');
            if (!$dbman->field_exists($rt, $rfld)) { $dbman->add_field($rt, $rfld); }
        }

        upgrade_mod_savepoint(true, 2025052003, 'learnguard');
    }

    // v1.6.0 — Per-learner exam scheduling (schedule.php + dual countdown).
    if ($oldversion < 2025052021) {
        $table = new xmldb_table('learnguard_schedules');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('learnguard',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timeopen',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeclose',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('learnguard_userid', XMLDB_INDEX_UNIQUE, ['learnguard', 'userid']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2025052021, 'learnguard');
    }

    // -----------------------------------------------------------------------
    // v1.6.5 — fix snapshot_type values mangled by send_snapshot()'s old
    // PARAM_ALPHA validation (it stripped underscores/digits, e.g.
    // 'screen_capture' was stored as 'screencapture'), which made the
    // Gallery's type filter never match those rows.
    // -----------------------------------------------------------------------
    if ($oldversion < 2025052026) {
        $map = [
            'photoid'          => 'photo_id',
            'screencapture'    => 'screen_capture',
            'randomscreenshot' => 'random_screenshot',
            'multiplefaces'    => 'multiple_faces',
            'multiplepersons'  => 'multiple_persons',
            'backgroundchange' => 'background_change',
            'identitymismatch' => 'identity_mismatch',
        ];
        foreach ($map as $mangled => $correct) {
            $DB->set_field('learnguard_snapshots', 'snapshot_type', $correct, ['snapshot_type' => $mangled]);
        }
        upgrade_mod_savepoint(true, 2025052026, 'learnguard');
    }

    // -----------------------------------------------------------------------
    // v1.6.7 — Per-activity branding overrides (Brand tab on admin.php).
    // -----------------------------------------------------------------------
    if ($oldversion < 2025052028) {
        $table = new xmldb_table('learnguard_brand_overrides');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',              XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('learnguard',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('brand_name',      XMLDB_TYPE_CHAR,    '255', null, false,         null, null);
            $table->add_field('brand_primary',   XMLDB_TYPE_CHAR,    '20',  null, false,         null, null);
            $table->add_field('brand_accent',    XMLDB_TYPE_CHAR,    '20',  null, false,         null, null);
            $table->add_field('brand_textcolor', XMLDB_TYPE_CHAR,    '20',  null, false,         null, null);
            $table->add_field('timemodified',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('learnguard', XMLDB_INDEX_UNIQUE, ['learnguard']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2025052028, 'learnguard');
    }

    // -----------------------------------------------------------------------
    // v1.6.7 — Essay grading distribution (Cap+Randomise / Even Split /
    // Manual) for Layer-1 graders, configured on the Graders tab.
    // -----------------------------------------------------------------------
    if ($oldversion < 2025052029) {
        $table = new xmldb_table('learnguard_grading_config');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('learnguard',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('gradingmode',   XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'all');
            $table->add_field('gradingcap',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('learnguard', XMLDB_INDEX_UNIQUE, ['learnguard']);
            $dbman->create_table($table);
        }

        $table2 = new xmldb_table('learnguard_grader_assignments');
        if (!$dbman->table_exists($table2)) {
            $table2->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table2->add_field('learnguard',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table2->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table2->add_field('graderid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table2->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table2->add_index('learnguard_attempt', XMLDB_INDEX_UNIQUE, ['learnguard', 'attemptid']);
            $dbman->create_table($table2);
        }

        upgrade_mod_savepoint(true, 2025052029, 'learnguard');
    }

    return true;
}
