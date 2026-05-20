<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * One-click database fix for mod_learnguard.
 *
 * Creates any missing LearnGuard tables directly without requiring
 * the Moodle upgrade workflow. Site admin only.
 *
 * URL: /mod/learnguard/fix_db.php
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

$PAGE->set_url('/mod/learnguard/fix_db.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Fix Database');

$dbman = $DB->get_manager();
$results = [];

/**
 * Create a table from an array definition if it doesn't already exist.
 * Returns ['table' => name, 'status' => 'created'|'exists', 'error' => string|null]
 */
function lg_ensure_table(string $tableName, callable $definer): array {
    global $dbman;
    $table = new xmldb_table($tableName);
    if ($dbman->table_exists($table)) {
        return ['table' => $tableName, 'status' => 'exists', 'error' => null];
    }
    try {
        $definer($table);
        $dbman->create_table($table);
        return ['table' => $tableName, 'status' => 'created', 'error' => null];
    } catch (Exception $e) {
        return ['table' => $tableName, 'status' => 'error', 'error' => $e->getMessage()];
    }
}

// ── Table definitions ──────────────────────────────────────────────────────

$results[] = lg_ensure_table('learnguard_face_approvals', function(xmldb_table $t) {
    $t->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('cmid',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
    $t->add_field('status',       XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'pending');
    $t->add_field('passportdata', XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
    $t->add_field('matchpct',     XMLDB_TYPE_INTEGER, '3',   null, XMLDB_NOTNULL, null, '0');
    $t->add_field('reviewedby',   XMLDB_TYPE_INTEGER, '10',  null, false,         null, null);
    $t->add_field('reviewnote',   XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
    $t->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
    $t->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('cmid_userid', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'userid']);
});

$results[] = lg_ensure_table('learnguard_fa_messages', function(xmldb_table $t) {
    $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('approvalid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('sendertype',  XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'student');
    $t->add_field('message',     XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
    $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('approvalid', XMLDB_INDEX_NOTUNIQUE, ['approvalid']);
});

$results[] = lg_ensure_table('learnguard_graders', function(xmldb_table $t) {
    $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('learnguard',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('anonymous',   XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
    $t->add_field('gradelayer',  XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '1');
    $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('learnguard', XMLDB_INDEX_NOTUNIQUE, ['learnguard']);
});

$results[] = lg_ensure_table('learnguard_precheck_sessions', function(xmldb_table $t) {
    $t->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('cmid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('current_step', XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, 'welcome');
    $t->add_field('step_number',  XMLDB_TYPE_INTEGER, '3',  null, XMLDB_NOTNULL, null, '0');
    $t->add_field('total_steps',  XMLDB_TYPE_INTEGER, '3',  null, XMLDB_NOTNULL, null, '0');
    $t->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('cmid_userid', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'userid']);
});

$results[] = lg_ensure_table('learnguard_audio_clips', function(xmldb_table $t) {
    $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('audiodata',   XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
    $t->add_field('duration',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('noise_level', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
});

$results[] = lg_ensure_table('learnguard_incidents', function(xmldb_table $t) {
    $t->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('attemptid',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
    $t->add_field('createdby',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
    $t->add_field('title',        XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, '');
    $t->add_field('description',  XMLDB_TYPE_TEXT,    null,  null, false,         null, null);
    $t->add_field('severity',     XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'medium');
    $t->add_field('status',       XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'open');
    $t->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
    $t->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
});

$results[] = lg_ensure_table('learnguard_essay_grades', function(xmldb_table $t) {
    $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('slotid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('graderid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('grade',       XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', 5);
    $t->add_field('feedback',    XMLDB_TYPE_TEXT,    null, null, false,         null, null);
    $t->add_field('timegraded',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
});

$results[] = lg_ensure_table('learnguard_messages', function(xmldb_table $t) {
    $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('sendertype',  XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'student');
    $t->add_field('message',     XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
    $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
});

// Also add any missing COLUMNS to existing tables.
$colResults = [];

// courseid on learnguard_proctors.
$prt = new xmldb_table('learnguard_proctors');
if ($dbman->table_exists($prt)) {
    $fld = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'is_global');
    if (!$dbman->field_exists($prt, $fld)) {
        try { $dbman->add_field($prt, $fld); $colResults[] = '✅ Added learnguard_proctors.courseid'; }
        catch (Exception $e) { $colResults[] = '❌ learnguard_proctors.courseid: ' . $e->getMessage(); }
    }
}

// notification_hours on learnguard.
$lgt = new xmldb_table('learnguard');
if ($dbman->table_exists($lgt)) {
    foreach ([
        ['notification_hours', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'access_pin'],
        ['show_selfcheck',     XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '1', 'notification_hours'],
    ] as [$fname, $ftype, $flen, $unsigned, $notnull, $seq, $default, $prev]) {
        $fld = new xmldb_field($fname, $ftype, $flen, $unsigned, $notnull, $seq, $default, $prev);
        if (!$dbman->field_exists($lgt, $fld)) {
            try { $dbman->add_field($lgt, $fld); $colResults[] = '✅ Added learnguard.' . $fname; }
            catch (Exception $e) { $colResults[] = '❌ learnguard.' . $fname . ': ' . $e->getMessage(); }
        }
    }
}

// Update the plugin version in the DB to current so upgrade.php doesn't try to re-run.
$currentVersion = 2025052005;
$DB->set_field('config_plugins', 'value', $currentVersion, ['plugin' => 'mod_learnguard', 'name' => 'version']);

// Count results.
$created = count(array_filter($results, fn($r) => $r['status'] === 'created'));
$existed = count(array_filter($results, fn($r) => $r['status'] === 'exists'));
$errors  = count(array_filter($results, fn($r) => $r['status'] === 'error'));

// Redirect back to siteadmin with a success notification.
$redirectUrl = new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'overview']);
if ($errors === 0) {
    redirect($redirectUrl,
        '✅ Database fixed! ' . $created . ' table(s) created, ' . $existed . ' already existed. ' .
        ($colResults ? implode(', ', $colResults) : ''),
        null, \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    // Show detailed results if there were errors.
    echo $OUTPUT->header();
    echo $OUTPUT->heading('LearnGuard — Database Fix Results');
    echo '<div style="font-family:system-ui,sans-serif;max-width:700px;">';
    echo '<div class="alert ' . ($errors > 0 ? 'alert-warning' : 'alert-success') . '">';
    echo '<strong>' . $created . ' table(s) created</strong>, ' . $existed . ' already existed, ' . $errors . ' error(s).';
    echo '</div>';
    echo '<table class="generaltable w-100">';
    echo '<tr><th>Table</th><th>Status</th><th>Detail</th></tr>';
    foreach ($results as $r) {
        $icon = $r['status'] === 'created' ? '✅' : ($r['status'] === 'exists' ? '☑️' : '❌');
        echo '<tr><td><code>' . s($r['table']) . '</code></td>' .
             '<td>' . $icon . ' ' . ucfirst($r['status']) . '</td>' .
             '<td style="font-size:12px;color:#6c757d;">' . s($r['error'] ?? '') . '</td></tr>';
    }
    echo '</table>';
    foreach ($colResults as $cr) { echo '<div style="font-size:13px;margin-top:6px;">' . $cr . '</div>'; }
    echo '<br><a href="' . $redirectUrl->out(false) . '" class="btn btn-primary">← Back to Admin</a>';
    echo '</div>';
    echo $OUTPUT->footer();
}
