<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * One-click database fix for quizaccess_learnshield.
 *
 * Creates any missing LearnShield tables directly without requiring
 * the Moodle upgrade workflow. Site admin only.
 *
 * URL: /mod/quiz/accessrule/learnshield/fix_db.php
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

$PAGE->set_url('/mod/quiz/accessrule/learnshield/fix_db.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnShield — Fix Database');

$dbman   = $DB->get_manager();
$results = [];

function ls_ensure_table(string $tableName, callable $definer): array {
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

$results[] = ls_ensure_table('quizaccess_learnshield_face_approvals', function(xmldb_table $t) {
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

$results[] = ls_ensure_table('quizaccess_learnshield_fa_messages', function(xmldb_table $t) {
    $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('approvalid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('sendertype',  XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'student');
    $t->add_field('message',     XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
    $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('approvalid', XMLDB_INDEX_NOTUNIQUE, ['approvalid']);
});

$results[] = ls_ensure_table('quizaccess_learnshield_precheck_sessions', function(xmldb_table $t) {
    $t->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('cmid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('current_step', XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, 'closetabs');
    $t->add_field('step_number',  XMLDB_TYPE_INTEGER, '3',  null, XMLDB_NOTNULL, null, '0');
    $t->add_field('total_steps',  XMLDB_TYPE_INTEGER, '3',  null, XMLDB_NOTNULL, null, '0');
    $t->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('cmid_userid', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'userid']);
});

$results[] = ls_ensure_table('quizaccess_learnshield_audio_clips', function(xmldb_table $t) {
    $t->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $t->add_field('sessionid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $t->add_field('audiodata',   XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
    $t->add_field('duration',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('noise_level', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('sessionid', XMLDB_INDEX_NOTUNIQUE, ['sessionid']);
});

// Update version in DB so upgrade.php doesn't re-prompt.
$currentVersion = 2025051826;
$DB->set_field('config_plugins', 'value', $currentVersion,
    ['plugin' => 'quizaccess_learnshield', 'name' => 'version']);

// Count results.
$created = count(array_filter($results, fn($r) => $r['status'] === 'created'));
$existed = count(array_filter($results, fn($r) => $r['status'] === 'exists'));
$errors  = count(array_filter($results, fn($r) => $r['status'] === 'error'));

$redirectUrl = new moodle_url('/mod/quiz/accessrule/learnshield/admin.php', ['tab' => 'overview']);
if ($errors === 0) {
    redirect($redirectUrl,
        '✅ Database fixed! ' . $created . ' table(s) created, ' . $existed . ' already existed.',
        null, \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading('LearnShield — Database Fix Results');
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
    echo '<br><a href="' . $redirectUrl->out(false) . '" class="btn btn-primary">← Back to Admin</a>';
    echo '</div>';
    echo $OUTPUT->footer();
}
