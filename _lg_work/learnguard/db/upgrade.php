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
    global $DB, $CFG;
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

    // -----------------------------------------------------------------------
    // v1.6.5 — catch-up step. The companion quizaccess_learnshield plugin's
    // site-recorded version (2026053105) was higher than every savepoint
    // above, so on a site that received the same out-of-band build, the
    // 2025052021/2025052026/2025052028/2025052029 steps could be silently
    // skipped on upgrade. Re-apply them all here (idempotent — no-ops if
    // already applied).
    // -----------------------------------------------------------------------
    if ($oldversion < 2026053106) {
        // learnguard_schedules (2025052021).
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

        // snapshot_type mangled value fix (2025052026).
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

        // learnguard_brand_overrides (2025052028).
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

        // learnguard_grading_config + learnguard_grader_assignments (2025052029).
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

        upgrade_mod_savepoint(true, 2026053106, 'learnguard');
    }

    // Essay "marking guide" field on learnguard_qbank (N12).
    if ($oldversion < 2026053107) {
        $table = new xmldb_table('learnguard_qbank');

        $field = new xmldb_field('markingguidance', XMLDB_TYPE_TEXT, null, null, null, null, null, 'generalfeedbackformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('markingguidanceformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'markingguidance');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026053107, 'learnguard');
    }

    // R6/R4: casesensitive on learnguard_qbank + deadline on learnguard_graders.
    if ($oldversion < 2026061603) {
        $qbank = new xmldb_table('learnguard_qbank');
        $field = new xmldb_field('casesensitive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'markingguidanceformat');
        if (!$dbman->field_exists($qbank, $field)) {
            $dbman->add_field($qbank, $field);
        }

        $graders = new xmldb_table('learnguard_graders');
        $field = new xmldb_field('deadline', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'gradelayer');
        if (!$dbman->field_exists($graders, $field)) {
            $dbman->add_field($graders, $field);
        }

        upgrade_mod_savepoint(true, 2026061603, 'learnguard');
    }

    // Mandatory questions: is_mandatory flag on learnguard_qbank.
    if ($oldversion < 2026061604) {
        $qbank = new xmldb_table('learnguard_qbank');
        $field = new xmldb_field('is_mandatory', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'casesensitive');
        if (!$dbman->field_exists($qbank, $field)) {
            $dbman->add_field($qbank, $field);
        }
        upgrade_mod_savepoint(true, 2026061604, 'learnguard');
    }

    // Timer suspend/resume: record when the attempt was suspended so the timer
    // can be restored to the exact remaining time on reinstatement.
    if ($oldversion < 2026061612) {
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('timesuspended', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026061612, 'learnguard');
    }

    // Catch-up: ensure all learnguard_qbank columns exist. Some sites have these
    // steps skipped when their recorded plugin version was already above individual
    // savepoints (2026053107, 2026061603, 2026061604). All checks are idempotent.
    if ($oldversion < 2026061900) {
        $qbanktable = new xmldb_table('learnguard_qbank');

        $field = new xmldb_field('markingguidance', XMLDB_TYPE_TEXT, null, null, null, null, null, 'generalfeedbackformat');
        if (!$dbman->field_exists($qbanktable, $field)) {
            $dbman->add_field($qbanktable, $field);
        }

        $field = new xmldb_field('markingguidanceformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'markingguidance');
        if (!$dbman->field_exists($qbanktable, $field)) {
            $dbman->add_field($qbanktable, $field);
        }

        $field = new xmldb_field('casesensitive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'markingguidanceformat');
        if (!$dbman->field_exists($qbanktable, $field)) {
            $dbman->add_field($qbanktable, $field);
        }

        $field = new xmldb_field('is_mandatory', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'casesensitive');
        if (!$dbman->field_exists($qbanktable, $field)) {
            $dbman->add_field($qbanktable, $field);
        }

        upgrade_mod_savepoint(true, 2026061900, 'learnguard');
    }

    if ($oldversion < 2026061907) {
        // Add violations_suspended to learnguard_attempts so proctors can
        // pause all violation scoring for a specific learner mid-attempt.
        $atttable = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('violations_suspended', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'is_flagged');
        if (!$dbman->field_exists($atttable, $field)) {
            $dbman->add_field($atttable, $field);
        }

        // Add overridden to learnguard_events so proctors can dismiss a
        // false-positive event and deduct its score_impact from the learner's
        // running risk score.
        $evttable = new xmldb_table('learnguard_events');
        $field = new xmldb_field('overridden', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'score_impact');
        if (!$dbman->field_exists($evttable, $field)) {
            $dbman->add_field($evttable, $field);
        }

        upgrade_mod_savepoint(true, 2026061907, 'learnguard');
    }

    // v2.0.8 — fix decimal essay grades + Moodle-proper response state for essays.
    if ($oldversion < 2026062004) {
        // Ensure learnguard_essay_grades.grade is DECIMAL(10,5) not INT.
        // Sites installed before this column was properly typed would silently truncate
        // decimal marks (e.g. 0.5 stored as 0).
        $table = new xmldb_table('learnguard_essay_grades');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('grade', XMLDB_TYPE_NUMBER, '10', null, XMLDB_NOTNULL, null, '0');
            $field->setDecimals(5);
            $dbman->change_field_type($table, $field);
        }

        // Migrate essay learnguard_responses.state to Moodle-proper values:
        //   'graded'       = essay has been manually graded (grade row in learnguard_essay_grades)
        //   'needsgrading' = essay was submitted but not yet manually graded
        // Previously save_grade set state='complete' for both auto-graded MCQ and essays,
        // making it impossible to distinguish without querying learnguard_essay_grades.
        $essayRespRows = $DB->get_recordset_sql(
            "SELECT r.id, r.attemptid, r.slotid FROM {learnguard_responses} r
               JOIN {learnguard_slots} s ON s.id = r.slotid
               JOIN {learnguard_qbank} q ON q.id = s.questionid AND q.qtype = 'essay'
              WHERE r.state = 'complete'"
        );
        foreach ($essayRespRows as $row) {
            $hasGrade = $DB->record_exists('learnguard_essay_grades',
                ['attemptid' => $row->attemptid, 'slotid' => $row->slotid]);
            $DB->set_field('learnguard_responses', 'state',
                $hasGrade ? 'graded' : 'needsgrading', ['id' => $row->id]);
        }
        $essayRespRows->close();

        upgrade_mod_savepoint(true, 2026062004, 'learnguard');
    }

    if ($oldversion < 2026062005) {
        // v2.0.9: No DB schema changes. CASE WHEN in scores/scorematrix now uses
        // state != 'graded' so old state='complete' essays are hidden until re-graded.
        upgrade_mod_savepoint(true, 2026062005, 'learnguard');
    }

    if ($oldversion < 2026062702) {
        // v2.0.13: Add is_global and courseid columns to learnguard_graders to support
        // course-level and site-wide global grader assignments (mirrors learnguard_proctors).
        $table = new xmldb_table('learnguard_graders');
        $fieldGlobal = new xmldb_field('is_global', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $fieldGlobal)) {
            $dbman->add_field($table, $fieldGlobal);
        }
        $fieldCourse = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $fieldCourse)) {
            $dbman->add_field($table, $fieldCourse);
        }
        upgrade_mod_savepoint(true, 2026062702, 'learnguard');
    }

    if ($oldversion < 2026063002) {
        // v2.0.22: Add break_duration + break_deadline to learnguard_attempts.
        // break_duration  = minutes selected by student when requesting break.
        // break_deadline  = unix ts when break auto-expires (break_approved + break_duration*60).
        $tattempts = new xmldb_table('learnguard_attempts');
        $fDur = new xmldb_field('break_duration', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '5');
        if (!$dbman->field_exists($tattempts, $fDur)) {
            $dbman->add_field($tattempts, $fDur);
        }
        $fDead = new xmldb_field('break_deadline', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($tattempts, $fDead)) {
            $dbman->add_field($tattempts, $fDead);
        }

        // v2.0.22: Add extra_attempts + timelimit_override to learnguard_schedules.
        // extra_attempts      = additional attempts granted to this user beyond the activity limit.
        // timelimit_override  = per-user time limit in seconds (0 = use activity default).
        $tsched = new xmldb_table('learnguard_schedules');
        $fExtra = new xmldb_field('extra_attempts', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($tsched, $fExtra)) {
            $dbman->add_field($tsched, $fExtra);
        }
        $fTime = new xmldb_field('timelimit_override', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($tsched, $fTime)) {
            $dbman->add_field($tsched, $fTime);
        }

        upgrade_mod_savepoint(true, 2026063002, 'learnguard');
    }

    if ($oldversion < 2026072301) {
        // Add attemptid to learnguard_slots: 0=fixed slot, non-zero=pool-drawn for that attempt.
        $tslots   = new xmldb_table('learnguard_slots');
        $fattmid  = new xmldb_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($tslots, $fattmid)) {
            $dbman->add_field($tslots, $fattmid);
        }

        // Create learnguard_pools table for multi-bank pool rules.
        $tpools = new xmldb_table('learnguard_pools');
        if (!$dbman->table_exists($tpools)) {
            $tpools->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $tpools->add_field('learnguard',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $tpools->add_field('catid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $tpools->add_field('drawcount',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $tpools->add_field('maxmark',     XMLDB_TYPE_NUMBER,  '10', 5,    XMLDB_NOTNULL, null, '1.0');
            $tpools->add_field('sortorder',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $tpools->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $tpools->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $tpools->add_index('learnguard', XMLDB_INDEX_NOTUNIQUE, ['learnguard']);
            $dbman->create_table($tpools);
        }

        upgrade_mod_savepoint(true, 2026072301, 'learnguard');
    }

    if ($oldversion < 2026072302) {
        // Add skip_precheck to learnguard_schedules so admins can bypass the
        // pre-exam wizard for a specific user on a specific assessment.
        $tsched = new xmldb_table('learnguard_schedules');
        $fSkip  = new xmldb_field('skip_precheck', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($tsched, $fSkip)) {
            $dbman->add_field($tsched, $fSkip);
        }
        upgrade_mod_savepoint(true, 2026072302, 'learnguard');
    }

    if ($oldversion < 2026072305) {
        // Add exclude_violations to learnguard_schedules so admins can exempt a
        // specific user from selected violation categories (camera, face, audio, etc.).
        // Stored as a comma-separated list, e.g. "camera,audio".
        $tsched = new xmldb_table('learnguard_schedules');
        $fExcl  = new xmldb_field('exclude_violations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($tsched, $fExcl)) {
            $dbman->add_field($tsched, $fExcl);
        }
        upgrade_mod_savepoint(true, 2026072305, 'learnguard');
    }

    if ($oldversion < 2026073002) {
        // Create answer history table — logs the previous state of a response row
        // before each overwrite, enabling recovery of accidentally lost answers.
        if (!$dbman->table_exists('learnguard_response_history')) {
            $t = new xmldb_table('learnguard_response_history');
            $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('responseid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('slotid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('questionid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('responsedata',XMLDB_TYPE_TEXT,    null,  null, null,          null, null);
            $t->add_field('fraction',    XMLDB_TYPE_NUMBER,  '10',  null, XMLDB_NOTNULL, null, '0', '5');
            $t->add_field('state',       XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, '');
            $t->add_field('savedbefore', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $t->add_index('slotid',    XMLDB_INDEX_NOTUNIQUE, ['slotid']);
            $dbman->create_table($t);
        }
        upgrade_mod_savepoint(true, 2026073002, 'learnguard');
    }

    if ($oldversion < 2026073003) {
        // Add per-section time limit to learnguard_pools.
        // 0 = uses the total exam timer; >0 = independent countdown for this pool/section.
        $tpools  = new xmldb_table('learnguard_pools');
        $fTlimit = new xmldb_field('timelimit', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sortorder');
        if (!$dbman->field_exists($tpools, $fTlimit)) {
            $dbman->add_field($tpools, $fTlimit);
        }
        upgrade_mod_savepoint(true, 2026073003, 'learnguard');
    }

    if ($oldversion < 2026073004) {
        // Create learnguard_attempt_sections: records when each section started and its server-side
        // deadline, enabling submit_answer and autosave to reject saves after the section expires.
        if (!$dbman->table_exists('learnguard_attempt_sections')) {
            $t = new xmldb_table('learnguard_attempt_sections');
            $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('catid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('deadline',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t->add_index('attemptid',   XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $t->add_index('attempt_cat', XMLDB_INDEX_UNIQUE,    ['attemptid', 'catid']);
            $dbman->create_table($t);
        }
        upgrade_mod_savepoint(true, 2026073004, 'learnguard');
    }

    if ($oldversion < 2026073005) {
        // Add show_section_breaks toggle to the activity table.
        // 1 = show named section intro overlays and nav dividers (default for pool-based assessments).
        // 0 = show all questions seamlessly with no section UI.
        $tlg  = new xmldb_table('learnguard');
        $fSSB = new xmldb_field('show_section_breaks', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'num_questions');
        if (!$dbman->field_exists($tlg, $fSSB)) {
            $dbman->add_field($tlg, $fSSB);
        }
        upgrade_mod_savepoint(true, 2026073005, 'learnguard');
    }

    if ($oldversion < 2026073006) {
        // Add show_prompts_count to learnguard_qbank for the Choice Essay question type.
        $tqb  = new xmldb_table('learnguard_qbank');
        $fSPC = new xmldb_field('show_prompts_count', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'createdby');
        if (!$dbman->field_exists($tqb, $fSPC)) {
            $dbman->add_field($tqb, $fSPC);
        }
        upgrade_mod_savepoint(true, 2026073006, 'learnguard');
    }

    if ($oldversion < 2026073007) {
        // Create learnguard_ce_prompts table for Choice Essay question type.
        if (!$dbman->table_exists('learnguard_ce_prompts')) {
            $t = new xmldb_table('learnguard_ce_prompts');
            $t->add_field('id',               XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('questionid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('prompttext',       XMLDB_TYPE_TEXT,    null, null, null,          null, null);
            $t->add_field('prompttextformat', XMLDB_TYPE_INTEGER, '4',  null, XMLDB_NOTNULL, null, '1');
            $t->add_field('sortorder',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t->add_index('questionid', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
            $dbman->create_table($t);
        }
        upgrade_mod_savepoint(true, 2026073007, 'learnguard');
    }

    if ($oldversion < 2026080707) {
        $table = new xmldb_table('learnguard');
        $field = new xmldb_field('passmark', XMLDB_TYPE_NUMBER, '5,2', null, XMLDB_NOTNULL, null, '0.00', 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026080707, 'learnguard');
    }

    if ($oldversion < 2026080711) {
        $table = new xmldb_table('learnguard_graders');
        $field = new xmldb_field('lg_enrolled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026080711, 'learnguard');
    }

    if ($oldversion < 2026080809) {
        // Option A: add cohort_name to learnguard_schedules (per-schedule label).
        $table = new xmldb_table('learnguard_schedules');
        $field = new xmldb_field('cohort_name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Option B: create learnguard_learner_cohorts table (course-level, independent of schedule rows).
        $table = new xmldb_table('learnguard_learner_cohorts');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('cohort_name', XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_courseid', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026080809, 'learnguard');
    }

    if ($oldversion < 2026082802) {
        // Snapshot of risk_score at the moment an attempt was suspended — risk_score
        // itself can later drop when a proctor dismisses flagged events, which made
        // the Suspended Students list show a current score below the autosuspend
        // threshold and look like a false suspension.
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('risk_score_at_suspend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'risk_level');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026082802, 'learnguard');
    }

    if ($oldversion < 2026082807) {
        // Per-activity Chat / Break feature toggles.
        $table = new xmldb_table('learnguard');
        $field = new xmldb_field('enable_chat', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'autosuspend_score');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('enable_break', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'enable_chat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026082807, 'learnguard');
    }

    if ($oldversion < 2026082809) {
        // Per-activity SOS (report technical difficulty) feature toggle.
        $table = new xmldb_table('learnguard');
        $field = new xmldb_field('enable_sos', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'enable_break');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026082809, 'learnguard');
    }

    if ($oldversion < 2026082812) {
        // autosuspend_score was NOTNULL DEFAULT 80, with view.php using "!= 80" as a
        // magic-number sentinel for "this activity has no override, use the site
        // default". That silently broke whenever an activity's stored value happened to
        // be anything other than 80 (however it got that way) — the site-wide setting
        // was then ignored for that activity with no visible indication why, and the
        // admin's global threshold change appeared to do nothing for that assessment.
        // Made the column nullable so "inherit the site default" is an explicit,
        // unambiguous NULL instead of a magic number. Rows still sitting at the old
        // default (80) are migrated to NULL — behaviourally identical to before (they
        // already inherited the site setting under the old "!= 80" check); any activity
        // with a genuinely different stored value keeps that exact value unchanged.
        $table = new xmldb_table('learnguard');
        $field = new xmldb_field('autosuspend_score', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'block_mobile');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
            $dbman->change_field_default($table, $field);
        }
        $DB->execute("UPDATE {learnguard} SET autosuspend_score = NULL WHERE autosuspend_score = 80");
        upgrade_mod_savepoint(true, 2026082812, 'learnguard');
    }

    if ($oldversion < 2026083001) {
        // Persistent "last suspended at" timestamp — separate from timesuspended, which
        // gets cleared on reinstate so the post-reinstate-threshold logic works. This one
        // is never cleared, so suspension history survives reinstate/confirm and can be
        // shown after the fact even once an attempt is no longer in state='suspended'.
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('time_last_suspended', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'risk_score_at_suspend');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026083001, 'learnguard');
    }

    if ($oldversion < 2026083003) {
        // Marks a suspended attempt as a voluntary self-pause (via the check-in dialog's
        // "Pause Assessment & Leave" button) rather than a proctor/violation suspension —
        // lets the learner resume it themselves after redoing the precheck, instead of
        // being stuck waiting for a proctor to manually reinstate a suspension they chose.
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('self_paused', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'time_last_suspended');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026083003, 'learnguard');
    }

    if ($oldversion < 2026083008) {
        // Marks an attempt reopened via Assessment Manager's "Continue & Review" override
        // — grants extra time to go back into an already-finished attempt, review/change
        // answers, and resubmit. See learnguard/review_autosuspend_score in settings.php.
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('reopened_for_review', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'self_paused');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026083008, 'learnguard');
    }

    if ($oldversion < 2026083011) {
        // Snapshot of the prior scored attempt's sumgrades, taken when a new/reopened
        // attempt is created for a student who already has a scored attempt (Bulk
        // Reattempt or Continue & Review). Lets scores.php show a rise/fall arrow once
        // this attempt is (re)finished, without re-querying other attempt rows.
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('prev_sumgrades', XMLDB_TYPE_NUMBER, '10,5', null, null, null, null, 'sumgrades');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026083011, 'learnguard');
    }

    if ($oldversion < 2026083021) {
        // Marks whether a snapshot was actually captured from the screen stream,
        // independent of snapshot_type (a reason/evidence tag like 'periodic' or
        // 'violation_face_missing' — NOT an image-source indicator; when the webcam is
        // unavailable, those captures silently fall back to a screen frame but keep
        // their original tag, causing screen images to be miscategorised as webcam
        // snapshots on attemptdetail.php).
        $table = new xmldb_table('learnguard_snapshots');
        $field = new xmldb_field('is_screen', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'snapshot_type');
        if (!$dbman->field_exists($table, $field)) {
            try {
                $dbman->add_field($table, $field);
            } catch (ddl_change_structure_exception $e) {
                // Column already exists on the live DB even though field_exists() just
                // reported it missing (seen on this site's DB replica setup — a schema
                // check can be answered by a replica that hasn't caught up with an ALTER
                // TABLE from an earlier, interrupted upgrade attempt yet; MySQL DDL
                // auto-commits and isn't rolled back by Moodle's upgrade transaction).
                // The desired end state — column present — is already true, so continue
                // instead of failing the whole upgrade; only re-throw anything else.
                $msg = $e->getMessage() . ' ' . ($e->debuginfo ?? '');
                if (stripos($msg, 'duplicate column') === false) {
                    throw $e;
                }
            }
        }
        upgrade_mod_savepoint(true, 2026083021, 'learnguard');
    }

    if ($oldversion < 2026090101) {
        // Audit log of "Confirm Suspension (Notify Student)" emails sent from
        // attemptdetail.php, so proctors/admins can review what reason and evidence
        // were sent to a student, and whether the email actually delivered.
        $table = new xmldb_table('learnguard_suspension_notices');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('learnguard',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('proctorid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('reason',      XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('snapcount',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('audiocount',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('mail_status', XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'sent');
            $table->add_field('mail_error',  XMLDB_TYPE_TEXT,    null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $table->add_index('learnguard_time', XMLDB_INDEX_NOTUNIQUE, ['learnguard', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026090101, 'learnguard');
    }

    if ($oldversion < 2026090105) {
        // Score Matrix access as its own independent grant — not tied to being a proctor
        // or grader, so someone can see scores without also getting proctoring/grading
        // powers. Mirrors learnguard_graders' is_global/courseid scoping.
        if (!$dbman->table_exists('learnguard_score_viewers')) {
            $table = new xmldb_table('learnguard_score_viewers');
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('is_global',   XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026090105, 'learnguard');
    }

    if ($oldversion < 2026090112) {
        // Optional absolute deadline on a Continue & Review grant — checked independently
        // of the extra_time minutes-based limit, so a review that's granted but never
        // actually started (timestart never advances until the learner redoes the
        // precheck) still gets a hard, predictable cutoff.
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('review_deadline', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'reopened_for_review');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026090112, 'learnguard');
    }

    if ($oldversion < 2026090119) {
        // Reinstatement notices (with optional evidence + a custom proctor message) now
        // log to the same table as Confirm Suspension notices — noticetype tells them
        // apart on suspensionnotices.php. Existing rows are all suspension confirmations.
        $table = new xmldb_table('learnguard_suspension_notices');
        $field = new xmldb_field('noticetype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'suspend', 'proctorid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026090119, 'learnguard');
    }

    if ($oldversion < 2026090121) {
        // Chat/Break/SOS become a tri-state (NULL=inherit site default; 0/1=explicit
        // per-activity override that wins over the site default either way) instead of
        // a plain NOT NULL checkbox that always silently overrode the site setting.
        // Existing rows keep their current stored 0/1 value — none are touched here, so
        // an activity that already has an explicit choice keeps behaving exactly as it
        // does today. Only going forward can an admin explicitly pick "Site default" on
        // an activity's own settings form to make it inherit and follow the site-wide
        // setting live.
        $table = new xmldb_table('learnguard');
        // XMLDB's change_field_notnull() needs the field's full current definition
        // (type/length) to match what's already installed — declare each explicitly,
        // with the NEW (nullable) notnull state, then apply it.
        $chatfield  = new xmldb_field('enable_chat',  XMLDB_TYPE_INTEGER, '1', null, null, null, null);
        $breakfield = new xmldb_field('enable_break', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
        $sosfield   = new xmldb_field('enable_sos',   XMLDB_TYPE_INTEGER, '1', null, null, null, null);
        if ($dbman->field_exists($table, $chatfield)) {
            $dbman->change_field_notnull($table, $chatfield);
        }
        if ($dbman->field_exists($table, $breakfield)) {
            $dbman->change_field_notnull($table, $breakfield);
        }
        if ($dbman->field_exists($table, $sosfield)) {
            $dbman->change_field_notnull($table, $sosfield);
        }
        upgrade_mod_savepoint(true, 2026090121, 'learnguard');
    }

    if ($oldversion < 2026090127) {
        // Flags an attempt whose precheck face-detection step fell all the way through
        // to a student self-tick with no automatic or proctor verification — surfaced
        // as a loud banner on attemptdetail.php so it's impossible to miss on review.
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('identity_unverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'is_flagged');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026090127, 'learnguard');
    }

    if ($oldversion < 2026090131) {
        // Confirming a suspension (is_flagged=1) is a final proctor decision, but
        // previously only ever set that flag — it never actually finished the attempt,
        // so a confirmed attempt could sit at 'suspended' (or drift to 'inprogress' if
        // something else later reinstated it) indefinitely, looking identical to an
        // unresolved case on the Suspended tab and Suspension History — reported live
        // as "Suspension Confirmed" next to "now: Inprogress" instead of "now: Finished".
        // classes/attempt_manager.php::finish() and the two live actions that call it
        // (attemptdetail.php's confirmsuspendnotify, proctorview.php's bulk confirm) are
        // fixed going forward as of this version — this step retroactively finishes
        // every ALREADY-confirmed attempt still sitting unfinished, so upgrading alone
        // fixes the ones already worked on rather than requiring them to be re-touched.
        $unfinished = $DB->get_records_select('learnguard_attempts',
            "is_flagged = 1 AND state NOT IN ('finished', 'voided')");
        foreach ($unfinished as $ua) {
            $uaInstance = $DB->get_record('learnguard', ['id' => $ua->learnguard], '*', IGNORE_MISSING);
            if ($uaInstance) {
                try {
                    \mod_learnguard\attempt_manager::finish($ua, $uaInstance);
                } catch (\Throwable $e) {
                    debugging('LearnGuard upgrade 2026090131: could not finish attempt ' .
                        $ua->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
        upgrade_mod_savepoint(true, 2026090131, 'learnguard');
    }

    if ($oldversion < 2026090134) {
        // A schedule/review grant (per-user window close date, or a Continue & Review
        // deadline) only ever touched learnguard_schedules/learnguard_attempts — never
        // Moodle's own {user_enrolments}, which can independently be suspended or
        // already expired, silently locking the student out of the COURSE regardless
        // of whatever window/deadline was just granted them. Fixed going forward as of
        // this version (learnguard_ensure_course_access(), called from
        // assessmentmanager.php's save_user_overrides/continue_review handlers and
        // scorematrix.php's quick-grant) — this step retroactively fixes every
        // currently-relevant grant already made, so upgrading alone covers ones already
        // worked on rather than requiring them to be re-touched one by one.
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');

        // Personal window-close dates still in effect (a past close date needs no fix —
        // that window is already over).
        $activeSchedules = $DB->get_records_select('learnguard_schedules',
            'timeclose > 0 AND timeclose > :now', ['now' => time()]);
        foreach ($activeSchedules as $sched) {
            $schedLg = $DB->get_record('learnguard', ['id' => $sched->learnguard], '*', IGNORE_MISSING);
            if ($schedLg) {
                try {
                    learnguard_ensure_course_access((int)$sched->userid, (int)$schedLg->course, (int)$sched->timeclose);
                } catch (\Throwable $e) {
                    debugging('LearnGuard upgrade 2026090134 (schedule ' . $sched->id . '): ' .
                        $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        // Reviews still pending (granted but not yet resubmitted).
        $pendingReviews = $DB->get_records_select('learnguard_attempts',
            "reopened_for_review = 1 AND state = 'inprogress'");
        foreach ($pendingReviews as $ra) {
            $raLg = $DB->get_record('learnguard', ['id' => $ra->learnguard], '*', IGNORE_MISSING);
            if ($raLg) {
                $raMinuntil = (!empty($ra->review_deadline) && $ra->review_deadline > time()) ? (int)$ra->review_deadline : 0;
                try {
                    learnguard_ensure_course_access((int)$ra->userid, (int)$raLg->course, $raMinuntil);
                } catch (\Throwable $e) {
                    debugging('LearnGuard upgrade 2026090134 (attempt ' . $ra->id . '): ' .
                        $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        upgrade_mod_savepoint(true, 2026090134, 'learnguard');
    }

    if ($oldversion < 2026090135) {
        // Boundary timestamp for the before/after answer diff on grader_user_report.php —
        // splits learnguard_response_history into "edited before this review" vs "edited
        // during this review" for a given attempt. 0 = never granted, or granted before
        // this column existed (those older reviews fall back to a best-effort
        // reconstruction using the single most recent history entry instead).
        $table = new xmldb_table('learnguard_attempts');
        $field = new xmldb_field('review_granted_time', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'review_deadline');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026090135, 'learnguard');
    }

    if ($oldversion < 2026090139) {
        // Root cause of storage.php timing out / going super slow once these tables grew
        // large enough to matter: learnguard_snapshots only ever had composite indexes
        // LEADING with attemptid (attemptid_type, attemptid_time) — useless for a
        // site-wide query filtered ONLY by snapshot_type or date, which is exactly what
        // storage.php's stats, bulk-delete-by-type, and the daily retention cleanup task
        // all do. Every one of those was silently falling back to a full table scan over
        // every row's actual TEXT/blob content (snapshot_url, audiodata) — the likely
        // real explanation behind a live storage crisis (LMS went down overnight from a
        // full disk; deleting old data through storage.php was itself too slow to use).
        // learnguard_audio_clips had the identical gap (attemptid-only index, no
        // date-based one at all).
        $table = new xmldb_table('learnguard_snapshots');
        $index = new xmldb_index('type_time', XMLDB_INDEX_NOTUNIQUE, ['snapshot_type', 'timemodified']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $audiotable = new xmldb_table('learnguard_audio_clips');
        $audioindex = new xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if ($dbman->table_exists($audiotable) && !$dbman->index_exists($audiotable, $audioindex)) {
            $dbman->add_index($audiotable, $audioindex);
        }

        upgrade_mod_savepoint(true, 2026090139, 'learnguard');
    }

    if ($oldversion < 2026090148) {
        // Choice Essay's "instructions shown to the student above the prompt list" text
        // was a hardcoded string with no way for an admin to customise it per question —
        // reported directly. New per-question override; empty means "use the existing
        // default text", so no existing Choice Essay question's behaviour changes until
        // someone deliberately edits it.
        $tqb = new xmldb_table('learnguard_qbank');
        $fCEI = new xmldb_field('ce_instructions', XMLDB_TYPE_TEXT, null, null, null, null, null, 'show_prompts_count');
        if (!$dbman->field_exists($tqb, $fCEI)) {
            $dbman->add_field($tqb, $fCEI);
        }
        upgrade_mod_savepoint(true, 2026090148, 'learnguard');
    }

    if ($oldversion < 2026090149) {
        // Grading units — lets a grader be restricted to specific questions/prompts
        // (e.g. "Strategy" only), independent of the existing learnguard_qbank_cats
        // categories (which drive random pool draws, a separate concern). Admin-named,
        // no hardcoded values.
        if (!$dbman->table_exists('learnguard_grading_units')) {
            $t = new xmldb_table('learnguard_grading_units');
            $t->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('name',         XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $t->add_field('sortorder',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($t);
        }

        // Keyed by userid, not a specific learnguard_graders row, so one person's subject
        // restriction applies consistently across every activity/global assignment they
        // hold. No rows for a grader = unrestricted (today's unchanged default behaviour).
        if (!$dbman->table_exists('learnguard_grader_units')) {
            $t2 = new xmldb_table('learnguard_grader_units');
            $t2->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t2->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t2->add_field('unitid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t2->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t2->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($t2);
        }

        $tqb2 = new xmldb_table('learnguard_qbank');
        $fUnit = new xmldb_field('unitid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'ce_instructions');
        if (!$dbman->field_exists($tqb2, $fUnit)) {
            $dbman->add_field($tqb2, $fUnit);
        }

        $tcep = new xmldb_table('learnguard_ce_prompts');
        $fUnit2 = new xmldb_field('unitid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sortorder');
        if (!$dbman->field_exists($tcep, $fUnit2)) {
            $dbman->add_field($tcep, $fUnit2);
        }

        upgrade_mod_savepoint(true, 2026090149, 'learnguard');
    }

    if ($oldversion < 2026090151) {
        // Choice Essay word limit — flags the answer box (and the grader view) once a
        // student's written answer exceeds this many words. 0 = no limit, so no existing
        // question's behaviour changes until an admin sets one explicitly.
        $tqb3 = new xmldb_table('learnguard_qbank');
        $fWl  = new xmldb_field('word_limit', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'unitid');
        if (!$dbman->field_exists($tqb3, $fWl)) {
            $dbman->add_field($tqb3, $fWl);
        }

        upgrade_mod_savepoint(true, 2026090151, 'learnguard');
    }

    if ($oldversion < 2026090152) {
        // Pathway / role-based question selection (Choice Essay only) — a student picks
        // a Grading Unit as their pathway before the exam clock starts, and the system
        // randomly draws Choice Essay questions from that unit plus an admin-selected
        // "other units" pool. Default 0/disabled, so no existing assessment changes
        // behaviour until an admin explicitly enables it.
        $tlg = new xmldb_table('learnguard');
        $fPe = new xmldb_field('pathway_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'instructionsformat');
        if (!$dbman->field_exists($tlg, $fPe)) {
            $dbman->add_field($tlg, $fPe);
        }
        $fPo = new xmldb_field('pathway_own_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pathway_enabled');
        if (!$dbman->field_exists($tlg, $fPo)) {
            $dbman->add_field($tlg, $fPo);
        }
        $fPc = new xmldb_field('pathway_other_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pathway_own_count');
        if (!$dbman->field_exists($tlg, $fPc)) {
            $dbman->add_field($tlg, $fPc);
        }

        $tatt = new xmldb_table('learnguard_attempts');
        $fPu  = new xmldb_field('pathway_unitid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'break_deadline');
        if (!$dbman->field_exists($tatt, $fPu)) {
            $dbman->add_field($tatt, $fPu);
        }

        if (!$dbman->table_exists('learnguard_pathway_other_units')) {
            $t = new xmldb_table('learnguard_pathway_other_units');
            $t->add_field('id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t->add_field('learnguard', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_field('unitid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t->add_index('learnguard', XMLDB_INDEX_NOTUNIQUE, ['learnguard']);
            $dbman->create_table($t);
        }

        upgrade_mod_savepoint(true, 2026090152, 'learnguard');
    }

    if ($oldversion < 2026090155) {
        // Choice Essay scoring rubric — one Michael-defined rubric per assessment, shared
        // by every Choice Essay question in it. Defaults off/no schema-changing behaviour
        // until an admin actually builds one.
        $tlg2 = new xmldb_table('learnguard');
        $fRe  = new xmldb_field('rubric_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'pathway_other_count');
        if (!$dbman->field_exists($tlg2, $fRe)) {
            $dbman->add_field($tlg2, $fRe);
        }
        $fRsl = new xmldb_field('rubric_show_learner', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'rubric_enabled');
        if (!$dbman->field_exists($tlg2, $fRsl)) {
            $dbman->add_field($tlg2, $fRsl);
        }
        $fRsg = new xmldb_field('rubric_show_total_grader', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'rubric_show_learner');
        if (!$dbman->field_exists($tlg2, $fRsg)) {
            $dbman->add_field($tlg2, $fRsg);
        }

        if (!$dbman->table_exists('learnguard_rubric_criteria')) {
            $t2 = new xmldb_table('learnguard_rubric_criteria');
            $t2->add_field('id',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t2->add_field('learnguard', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $t2->add_field('name',       XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $t2->add_field('weight',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $t2->add_field('focus',      XMLDB_TYPE_TEXT,    null,  null, null, null, null);
            $t2->add_field('sortorder',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $t2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t2->add_index('learnguard', XMLDB_INDEX_NOTUNIQUE, ['learnguard']);
            $dbman->create_table($t2);
        }

        if (!$dbman->table_exists('learnguard_rubric_scores')) {
            $t3 = new xmldb_table('learnguard_rubric_scores');
            $t3->add_field('id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $t3->add_field('attemptid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t3->add_field('slotid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t3->add_field('criteriaid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t3->add_field('graderid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $t3->add_field('score',      XMLDB_TYPE_NUMBER,  '10', 5,    XMLDB_NOTNULL, null, '0');
            $t3->add_field('timegraded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t3->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $t3->add_index('attemptid_slotid', XMLDB_INDEX_NOTUNIQUE, ['attemptid', 'slotid']);
            $dbman->create_table($t3);
        }

        upgrade_mod_savepoint(true, 2026090155, 'learnguard');
    }

    if ($oldversion < 2026090157) {
        // Distribution: let an admin opt Layer-2 graders into their own mutually-exclusive
        // split too, instead of the existing default (every Layer-2 grader sees every
        // finished attempt, as a full second-pass reviewer). Default 0 = unchanged.
        $tgc = new xmldb_table('learnguard_grading_config');
        $fIl2 = new xmldb_field('include_layer2', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'gradingcap');
        if ($dbman->table_exists($tgc) && !$dbman->field_exists($tgc, $fIl2)) {
            $dbman->add_field($tgc, $fIl2);
        }

        upgrade_mod_savepoint(true, 2026090157, 'learnguard');
    }

    if ($oldversion < 2026090160) {
        // Choice Essay's "prompts per student" / "word limit" / "instructions" move from
        // per-question (learnguard_qbank) to per-assessment (learnguard) — one setting
        // shared by every Choice Essay question in an assessment instead of re-entering it
        // on each question's own form.
        $tlg3 = new xmldb_table('learnguard');
        $fCpc = new xmldb_field('ce_prompts_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'rubric_show_total_grader');
        if (!$dbman->field_exists($tlg3, $fCpc)) {
            $dbman->add_field($tlg3, $fCpc);
        }
        $fCwl = new xmldb_field('ce_word_limit', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'ce_prompts_count');
        if (!$dbman->field_exists($tlg3, $fCwl)) {
            $dbman->add_field($tlg3, $fCwl);
        }
        $fCin = new xmldb_field('ce_instructions', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ce_word_limit');
        if (!$dbman->field_exists($tlg3, $fCin)) {
            $dbman->add_field($tlg3, $fCin);
        }

        // Auto-migrate: for every assessment with existing Choice Essay questions, seed the
        // new assessment-level fields from the first such question that actually has a
        // non-default value set, so nothing looks blank/reset after upgrading.
        if ($dbman->table_exists('learnguard_qbank') && $dbman->table_exists('learnguard_slots')) {
            $activities = $DB->get_records('learnguard', null, '', 'id');
            foreach ($activities as $act) {
                $ceq = $DB->get_records_sql(
                    "SELECT q.show_prompts_count, q.word_limit, q.ce_instructions
                       FROM {learnguard_slots} s
                       JOIN {learnguard_qbank} q ON q.id = s.questionid
                      WHERE s.learnguard = ? AND s.attemptid = 0 AND q.qtype = 'choiceessay'
                   ORDER BY s.slot ASC",
                    [$act->id]
                );
                if (empty($ceq)) { continue; }
                $update = (object)['id' => $act->id];
                $changed = false;
                foreach ($ceq as $row) {
                    if (!empty($row->show_prompts_count) && empty($update->ce_prompts_count)) {
                        $update->ce_prompts_count = (int)$row->show_prompts_count;
                        $changed = true;
                    }
                    if (!empty($row->word_limit) && empty($update->ce_word_limit)) {
                        $update->ce_word_limit = (int)$row->word_limit;
                        $changed = true;
                    }
                    if (!empty(trim((string)$row->ce_instructions)) && empty($update->ce_instructions)) {
                        $update->ce_instructions = $row->ce_instructions;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $DB->update_record('learnguard', $update);
                }
            }
        }

        upgrade_mod_savepoint(true, 2026090160, 'learnguard');
    }

    if ($oldversion < 2026090171) {
        // Choice Essay: back-navigation controls for a question with more than one
        // prompt (a standalone single-prompt question never shows a back link at all,
        // so these are only ever relevant for a manually-built multi-prompt question).
        $tlg4 = new xmldb_table('learnguard');
        $fAb = new xmldb_field('ce_allow_back', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'ce_instructions');
        if (!$dbman->field_exists($tlg4, $fAb)) {
            $dbman->add_field($tlg4, $fAb);
        }
        $fBl = new xmldb_field('ce_back_limit', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'ce_allow_back');
        if (!$dbman->field_exists($tlg4, $fBl)) {
            $dbman->add_field($tlg4, $fBl);
        }

        // Mirrored onto learnguard_qbank too, same pattern as show_prompts_count/
        // word_limit/ce_instructions — learnguard_sync_ce_settings_to_questions() keeps
        // these in sync with the activity-wide setting above, and render_choiceessay()
        // reads them straight off the question record like every other CE setting.
        $tqb4 = new xmldb_table('learnguard_qbank');
        $fQAb = new xmldb_field('ce_allow_back', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'word_limit');
        if (!$dbman->field_exists($tqb4, $fQAb)) {
            $dbman->add_field($tqb4, $fQAb);
        }
        $fQBl = new xmldb_field('ce_back_limit', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'ce_allow_back');
        if (!$dbman->field_exists($tqb4, $fQBl)) {
            $dbman->add_field($tqb4, $fQBl);
        }

        upgrade_mod_savepoint(true, 2026090171, 'learnguard');
    }

    if ($oldversion < 2026090172) {
        // Typing Pattern Baseline (item 1) — the precheck step's recorded value, now
        // actually persisted (previously never sent to the server at all — its
        // logProctoringEvent() call was gated on an attemptid that doesn't exist yet
        // during precheck) and used as the reference for a live in-exam comparison.
        $tlg5 = new xmldb_table('learnguard_attempts');
        $fTb  = new xmldb_field('typing_baseline_avg', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'identity_unverified');
        if (!$dbman->field_exists($tlg5, $fTb)) {
            $dbman->add_field($tlg5, $fTb);
        }

        upgrade_mod_savepoint(true, 2026090172, 'learnguard');
    }

    if ($oldversion < 2026090175) {
        // Data repair: v2.0.198 fixed the JS bug that saved a Choice Essay prompt's text
        // as raw, never-decoded base64 (a WAF-bypass hidden field named with brackets in
        // the wrong place, so PHP silently read the still-encoded value straight into
        // prompttext) — but that fix only stops NEW corruption. Reported live again on
        // 2026-09-13: a prompt created/edited before that fix shipped still shows the
        // literal base64 string to students and graders, since nothing ever re-saved it.
        // This repairs every already-corrupted row in one pass on upgrade.
        $corruptPrompts = $DB->get_records('learnguard_ce_prompts');
        foreach ($corruptPrompts as $cp) {
            $text = (string)$cp->prompttext;
            $trimmed = trim($text);
            // Heuristic, not a guess: genuine prompt text almost never satisfies the
            // strict base64 alphabet (letters/digits/+//=, no spaces or punctuation) at
            // any real length — real English text always contains spaces. Require a
            // reasonable minimum length too, so a short legitimate word/code isn't
            // mistaken for base64.
            if (strlen($trimmed) >= 40 && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $trimmed)) {
                $decoded = base64_decode($trimmed, true);
                if ($decoded !== false && strpos($decoded, ' ') !== false) {
                    // Only accept the decode if the result looks like real text — mostly
                    // printable characters, not further binary/garbage.
                    $printable = preg_match_all('/[\x20-\x7E]/', $decoded);
                    if ($printable / max(1, strlen($decoded)) > 0.85) {
                        $DB->set_field('learnguard_ce_prompts', 'prompttext', $decoded, ['id' => $cp->id]);
                    }
                }
            }
        }

        upgrade_mod_savepoint(true, 2026090175, 'learnguard');
    }

    if ($oldversion < 2026090177) {
        // Reported live, confirmed as the same attempt: a student typed a long answer,
        // submitted, and the grader only ever saw a short earlier fragment. Root cause —
        // the periodic autosave (every 5s while typing) fires a new save request on top
        // of any still in flight, with no ordering guarantee: a slow EARLIER request
        // (short content) can complete AFTER a later one (fuller content) and silently
        // overwrite it, since save_response() was a plain last-write-wins upsert with no
        // way to tell which request was actually captured more recently. This column
        // lets the client tell the server exactly when the content was read from the DOM
        // (not when the request happened to arrive) so a genuinely older capture can
        // never clobber a newer one, regardless of network arrival order.
        $trsp = new xmldb_table('learnguard_responses');
        $frsp = new xmldb_field('lastcaptureat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($trsp, $frsp)) {
            $dbman->add_field($trsp, $frsp);
        }

        upgrade_mod_savepoint(true, 2026090177, 'learnguard');
    }

    if ($oldversion < 2026090180) {
        // Grade History Status/Regraded feature: learnguard_essay_grades stores at most
        // one row per (attemptid, slotid, graderid) and is updated IN PLACE on a regrade
        // — the previous value is silently discarded with zero audit trail. This new
        // table logs every save (first grade AND every regrade) so grader.php's "Grade
        // History" popup can show whether a question was regraded, what the previous
        // score was, and when/by whom the change happened.
        $tgh = new xmldb_table('learnguard_grade_history');
        if (!$dbman->table_exists($tgh)) {
            $tgh->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $tgh->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $tgh->add_field('slotid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $tgh->add_field('graderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $tgh->add_field('oldgrade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $tgh->add_field('newgrade', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $tgh->add_field('timegraded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $tgh->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $tgh->add_index('attemptid_slotid', XMLDB_INDEX_NOTUNIQUE, ['attemptid', 'slotid']);
            $dbman->create_table($tgh);
        }

        upgrade_mod_savepoint(true, 2026090180, 'learnguard');
    }

    if ($oldversion < 2026090185) {
        // Reported live: "Void This Assessment" (learnguard_void_assessment_attempts())
        // was clicked believing it did something else, wiping out 408 legitimate finished
        // attempts in one action with no way back — voiding has always been a plain
        // state='voided' write with zero memory of what the attempt was before. These two
        // columns let a void be reversed exactly (learnguard_reverse_void_attempt()) —
        // prevstate records what it was, timevoided records when. Attempts already voided
        // before this column existed have prevstate='' — reversal falls back to a
        // best-effort guess in that case (see the function's own docblock) rather than
        // refusing to restore them at all.
        $tatt = new xmldb_table('learnguard_attempts');
        $fprev = new xmldb_field('prevstate', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '', 'pathway_unitid');
        if (!$dbman->field_exists($tatt, $fprev)) {
            $dbman->add_field($tatt, $fprev);
        }
        $ftv = new xmldb_field('timevoided', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'prevstate');
        if (!$dbman->field_exists($tatt, $ftv)) {
            $dbman->add_field($tatt, $ftv);
        }

        upgrade_mod_savepoint(true, 2026090185, 'learnguard');
    }

    return true;
}
