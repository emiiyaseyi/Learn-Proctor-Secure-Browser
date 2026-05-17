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

    return true;
}
