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
 * Site-level administration for mod_learnguard.
 *
 * Accessible only to Moodle site administrators. Shows ALL LearnGuard
 * assessments across the entire site — statistics, high-risk flags,
 * proctor assignments, media gallery, and navigation to each course-level
 * admin page.
 *
 * URL: /mod/learnguard/siteadmin.php
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// SITE ADMIN ONLY.
require_login();
require_capability('moodle/site:config', context_system::instance());

$tab = optional_param('tab', 'overview', PARAM_ALPHA);

$PAGE->set_url('/mod/learnguard/siteadmin.php', ['tab' => $tab]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Site Administration');
$PAGE->set_heading('LearnGuard — Site Administration');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/mod/learnguard/styles.css');

// -----------------------------------------------------------------------
// POST ACTIONS (proctors)
// -----------------------------------------------------------------------
if (data_submitted() && confirm_sesskey()) {

    $postaction = optional_param('postaction', '', PARAM_ALPHA);

    // Add a global proctor.
    if ($postaction === 'addglobalproctor') {
        $search = trim(required_param('usersearch', PARAM_RAW));
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) {
            $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]);
        }
        if ($user) {
            if (!$DB->record_exists_select('learnguard_proctors', 'userid = :uid AND is_global = 1', ['uid' => $user->id])) {
                $DB->insert_record('learnguard_proctors', (object)[
                    'learnguard'   => 0,
                    'userid'       => $user->id,
                    'role'         => 'global_proctor',
                    'is_global'    => 1,
                    'timemodified' => time(),
                ]);
            }
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
                fullname($user) . ' added as a Global Proctor.', null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Remove a global proctor.
    if ($postaction === 'removeglobalproctor') {
        $proctorid = required_param('proctorid', PARAM_INT);
        $DB->delete_records('learnguard_proctors', ['id' => $proctorid, 'is_global' => 1]);
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
            'Global proctor removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Add a per-assessment proctor from site admin.
    if ($postaction === 'addassessmentproctor') {
        $lgid   = required_param('lgid', PARAM_INT);
        $search = trim(required_param('usersearch', PARAM_RAW));
        $instance = $DB->get_record('learnguard', ['id' => $lgid], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) {
            $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]);
        }
        if ($user) {
            if (!$DB->record_exists('learnguard_proctors', ['learnguard' => $lgid, 'userid' => $user->id])) {
                $DB->insert_record('learnguard_proctors', (object)[
                    'learnguard'   => $lgid,
                    'userid'       => $user->id,
                    'role'         => 'proctor',
                    'is_global'    => 0,
                    'timemodified' => time(),
                ]);
            }
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors', 'lgid' => $lgid]),
                fullname($user) . ' assigned as proctor for ' . format_string($instance->name) . '.',
                null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors', 'lgid' => $lgid]),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Remove a per-assessment proctor from site admin.
    if ($postaction === 'removeassessmentproctor') {
        $proctorid = required_param('proctorid', PARAM_INT);
        $lgid      = required_param('lgid', PARAM_INT);
        $DB->delete_records('learnguard_proctors', ['id' => $proctorid, 'learnguard' => $lgid, 'is_global' => 0]);
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors', 'lgid' => $lgid]),
            'Proctor removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Add a course-level proctor (covers all LearnGuard assessments in a course).
    if ($postaction === 'addcourseproctor') {
        $courseid = required_param('courseid', PARAM_INT);
        $search   = trim(required_param('usersearch', PARAM_RAW));
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) { $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]); }
        if ($user) {
            if (!$DB->record_exists_select('learnguard_proctors',
                    'userid = :uid AND courseid = :cid AND is_global = 0 AND learnguard = 0',
                    ['uid' => $user->id, 'cid' => $courseid])) {
                $DB->insert_record('learnguard_proctors', (object)[
                    'learnguard'   => 0,
                    'courseid'     => $courseid,
                    'userid'       => $user->id,
                    'role'         => 'course_proctor',
                    'is_global'    => 0,
                    'timemodified' => time(),
                ]);
            }
            $cname = $DB->get_field('course', 'fullname', ['id' => $courseid]);
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
                fullname($user) . ' assigned as course proctor for ' . format_string($cname) . '.',
                null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Remove a course-level proctor.
    if ($postaction === 'removecourseproctor') {
        $proctorid = required_param('proctorid', PARAM_INT);
        $DB->delete_records('learnguard_proctors', ['id' => $proctorid, 'is_global' => 0, 'learnguard' => 0]);
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
            'Course proctor removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Delete selected snapshots / recordings.
    if ($postaction === 'deletemedia') {
        require_capability('moodle/site:config', context_system::instance());
        // Single-item delete (submitted via JS).
        $singleId = optional_param('single_delete_id', 0, PARAM_INT);
        if ($singleId > 0) {
            $DB->delete_records('learnguard_snapshots', ['id' => $singleId]);
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
                '1 item deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        // Bulk delete.
        $ids = optional_param_array('snapids', [], PARAM_INT);
        $ids = array_filter(array_map('intval', $ids));
        if (!empty($ids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('learnguard_snapshots', "id $insql", $inparams);
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
            count($ids) . ' item(s) deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Delete all snapshots for one type (bulk by type).
    if ($postaction === 'deletemediatype') {
        require_capability('moodle/site:config', context_system::instance());
        $dtype   = required_param('dtype', PARAM_ALPHA);
        $dlgid   = optional_param('dlgid', 0, PARAM_INT);
        $allowed = ['periodic', 'photo_id', '360_video', 'face'];
        if (in_array($dtype, $allowed)) {
            $where  = 'snapshot_type = :stype';
            $params = ['stype' => $dtype];
            if ($dlgid > 0) {
                $where .= ' AND attemptid IN (SELECT id FROM {learnguard_attempts} WHERE learnguard = :lgid)';
                $params['lgid'] = $dlgid;
            }
            $count = $DB->count_records_select('learnguard_snapshots', $where, $params);
            $DB->delete_records_select('learnguard_snapshots', $where, $params);
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
                $count . ' ' . $dtype . ' item(s) deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }

    // Branding save.
    if ($postaction === '' && $tab === 'branding') {
        $newprimary = optional_param('brand_primary', '#0f6cbf', PARAM_TEXT);
        $newaccent  = optional_param('brand_accent',  '#28a745', PARAM_TEXT);
        $newname    = optional_param('brand_name',    '',        PARAM_TEXT);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $newprimary)) { set_config('brand_primary', $newprimary, 'learnguard'); }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $newaccent))  { set_config('brand_accent',  $newaccent,  'learnguard'); }
        set_config('brand_name', clean_param($newname, PARAM_TEXT), 'learnguard');
        purge_all_caches();
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'branding']),
            'Branding saved. Theme cache purged.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();

$brand = learnguard_brand();

// ---- Site-wide stats ----
$totalassessments = $DB->count_records('learnguard');
$totalattempts    = $DB->count_records('learnguard_attempts');
$inprogress       = $DB->count_records('learnguard_attempts', ['state' => 'inprogress']);
$finished         = $DB->count_records('learnguard_attempts', ['state' => 'finished']);
$suspended        = $DB->count_records('learnguard_attempts', ['state' => 'suspended']);
$highrisk         = $DB->count_records('learnguard_attempts', ['risk_level' => 'high']);

echo learnguard_banner('LearnGuard — Site Administration', 'Site-wide overview of all LearnGuard assessments', '🌐');

// ── Tab definitions (label, icon, badge count) ───────────────────────────
$tabs = [
    'overview'    => ['Overview',        '📊', null],
    'assessments' => ['Assessments',     '📋', $totalassessments],
    'inprogress'  => ['Live Exams',      '🟢', $inprogress  ?: null],
    'highrisk'    => ['High Risk',       '⚠️', $highrisk    ?: null],
    'suspended'   => ['Suspended',       '🚫', $suspended   ?: null],
    'proctors'    => ['Proctors',        '👤', null],
    'media'       => ['Media Gallery',   '🖼',  null],
    'questions'   => ['Question Bank',   '🏦', null],
    'branding'    => ['Branding',        '🎨', null],
];

?>
<style>
/* ── LearnGuard siteadmin navigation ────────────────────── */
.lg-sa-wrap   { display:flex; flex-direction:column; gap:0; margin-bottom:24px; }

/* Action toolbar */
.lg-sa-actions {
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    padding:10px 16px;
    background:#f8f9fa;
    border:1px solid #dee2e6;
    border-radius:10px 10px 0 0;
}
.lg-sa-actions .lg-action-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 14px; border-radius:6px; font-size:13px; font-weight:600;
    text-decoration:none; border:1px solid transparent; white-space:nowrap;
    transition:all .15s;
}
.lg-sa-actions .lg-action-btn:hover { filter:brightness(.94); }
.lg-action-primary   { background:<?php echo $brand->primary; ?>;  color:#fff; }
.lg-action-secondary { background:#fff; color:#495057; border-color:#dee2e6; }
.lg-action-green     { background:#198754; color:#fff; }
.lg-action-teal      { background:#0dcaf0; color:#000; }
.lg-action-warning   { background:#ffc107; color:#000; }

/* Tab strip */
.lg-sa-tabs {
    display:flex; align-items:stretch; overflow-x:auto;
    background:#fff; border:1px solid #dee2e6; border-top:none;
    border-radius:0 0 0 0;
    scrollbar-width:none; /* Firefox */
}
.lg-sa-tabs::-webkit-scrollbar { display:none; }

.lg-sa-tab {
    display:inline-flex; align-items:center; gap:6px;
    padding:0 18px; height:48px; white-space:nowrap;
    font-size:13px; font-weight:600; color:#6c757d;
    text-decoration:none; border-right:1px solid #dee2e6;
    border-bottom:3px solid transparent;
    transition:all .15s; flex-shrink:0;
}
.lg-sa-tab:last-child { border-right:none; }
.lg-sa-tab:hover { background:#f8f9fa; color:#212529; }
.lg-sa-tab.active {
    color:<?php echo $brand->primary; ?>;
    border-bottom:3px solid <?php echo $brand->primary; ?>;
    background:#fff; font-weight:700;
}
.lg-sa-tab .lg-tab-icon { font-size:15px; }
.lg-sa-badge {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:20px; height:20px; padding:0 5px;
    border-radius:10px; font-size:10px; font-weight:800; color:#fff;
    background:#dc3545; line-height:1;
}
.lg-sa-badge.badge-orange { background:#fd7e14; }
.lg-sa-badge.badge-green  { background:#28a745; }
.lg-sa-badge.badge-grey   { background:#6c757d; }

/* Content area */
.lg-sa-content { padding:24px 0 0; }
</style>

<div class="lg-sa-wrap">

  <!-- ── Action toolbar ──────────────────────────────────── -->
  <div class="lg-sa-actions">
    <span style="font-size:12px;font-weight:700;color:#6c757d;letter-spacing:.5px;margin-right:4px;">QUICK LINKS</span>

    <a href="<?php echo new moodle_url('/mod/learnguard/results.php'); ?>" class="lg-action-btn lg-action-green">
      📊 All Results
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/scorematrix.php'); ?>" class="lg-action-btn lg-action-teal">
      🏆 Score Matrix
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'inprogress']); ?>" class="lg-action-btn lg-action-primary">
      🔴 Live Monitor
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/proctorview.php'); ?>" class="lg-action-btn" style="background:#6f42c1;color:#fff;">
      👤 Proctor View
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/overview.php'); ?>" class="lg-action-btn lg-action-secondary">
      🗺 Plugin Overview
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/instructions_editor.php'); ?>" class="lg-action-btn lg-action-secondary">
      📄 Edit Instructions
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/backup_manager.php'); ?>" class="lg-action-btn lg-action-secondary">
      💾 Backup &amp; Restore
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/face_approvals.php'); ?>" class="lg-action-btn lg-action-warning">
      👤 Face Approvals
      <?php
      $pendingFaceCount = 0;
      try { $pendingFaceCount = $DB->count_records('learnguard_face_approvals', ['status' => 'pending']); } catch (Exception $e) {}
      if ($pendingFaceCount > 0) {
          echo '<span class="lg-sa-badge">' . $pendingFaceCount . '</span>';
      }
      ?>
    </a>
  </div>

  <!-- ── Tab strip ────────────────────────────────────────── -->
  <div class="lg-sa-tabs">
    <?php
    foreach ($tabs as $key => [$label, $icon, $badge]) {
        $active    = ($tab === $key);
        $badgeHtml = '';
        if ($badge !== null) {
            $badgeClass = 'lg-sa-badge';
            if ($key === 'inprogress') { $badgeClass .= ' badge-green'; }
            elseif ($key === 'highrisk') { $badgeClass .= ''; }
            elseif ($key === 'suspended') { $badgeClass .= ''; }
            else { $badgeClass .= ' badge-grey'; }
            $badgeHtml = '<span class="' . $badgeClass . '">' . (int)$badge . '</span>';
        }
        echo html_writer::link(
            new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => $key]),
            '<span class="lg-tab-icon">' . $icon . '</span>' .
            '<span>' . $label . '</span>' .
            $badgeHtml,
            ['class' => 'lg-sa-tab' . ($active ? ' active' : '')]
        );
    }
    ?>
  </div>

</div><!-- /lg-sa-wrap -->

<div class="lg-sa-content">
<?php

// =====================================================================
// TAB: Overview
// =====================================================================
if ($tab === 'overview') {

    // ── Upgrade check: detect if new DB tables from recent versions are missing ──
    $missingTables = [];
    $tablesToCheck = [
        'learnguard_face_approvals' => 'v1.3.9 — Face Match Approval system',
        'learnguard_fa_messages'    => 'v1.3.9 — Proctor-student precheck chat',
        'learnguard_graders'        => 'v1.3.4 — Essay Grader assignments',
        'learnguard_incidents'      => 'v1.2.1 — Incident Management',
        'learnguard_audio_clips'    => 'v1.3.2 — Audio Clip Recording',
        'learnguard_essay_grades'   => 'v1.2.1 — Essay Grade storage',
        'learnguard_messages'       => 'v1.1.6 — Proctor chat',
    ];
    foreach ($tablesToCheck as $table => $sinceVersion) {
        try {
            $dbman = $DB->get_manager();
            if (!$dbman->table_exists(new xmldb_table($table))) {
                $missingTables[$table] = $sinceVersion;
            }
        } catch (Exception $e) { $missingTables[$table] = $sinceVersion; }
    }
    if (!empty($missingTables)) {
        echo '<div style="background:#fff3cd;border:2px solid #ffc107;border-radius:10px;' .
             'padding:16px 20px;margin-bottom:20px;">';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">';
        echo '<span style="font-size:24px;">⚠️</span>';
        echo '<div><strong style="font-size:15px;color:#856404;">Moodle Upgrade Required</strong>' .
             '<p style="margin:3px 0 0;font-size:13px;color:#6c757d;">' . count($missingTables) .
             ' new database table(s) need to be created. Features using these tables will show errors until the upgrade runs.</p></div>';
        echo html_writer::link(
            new moodle_url('/mod/learnguard/fix_db.php', ['sesskey' => sesskey()]),
            '▶ Fix Database Now (one click)',
            ['class' => 'btn btn-warning btn-sm', 'style' => 'margin-left:auto;font-weight:700;font-size:14px;',
             'title' => 'Creates all missing LearnGuard tables directly — no Moodle upgrade confirmation needed']
        );
        echo '</div>';
        echo '<table style="font-size:12px;width:100%;border-collapse:collapse;">';
        echo '<tr style="background:#fff8e1;"><th style="padding:4px 10px;text-align:left;">Missing Table</th><th style="padding:4px 10px;text-align:left;">Required since</th></tr>';
        foreach ($missingTables as $t => $v) {
            echo '<tr><td style="padding:4px 10px;font-family:monospace;color:#495057;">' . s($t) . '</td>' .
                 '<td style="padding:4px 10px;color:#6c757d;">' . s($v) . '</td></tr>';
        }
        echo '</table></div>';
    }

    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
    foreach ([
        ['Assessments',   $totalassessments, $brand->primary],
        ['Total Attempts', $totalattempts,   '#495057'],
        ['In Progress',   $inprogress,       '#fd7e14'],
        ['Completed',     $finished,         '#28a745'],
        ['Suspended',     $suspended,        '#dc3545'],
        ['High Risk',     $highrisk,         '#6f42c1'],
    ] as [$label, $count, $colour]) {
        echo html_writer::start_div('', ['style' =>
            'background:' . $colour . ';color:#fff;border-radius:8px;padding:16px 24px;' .
            'min-width:120px;text-align:center;']);
        echo html_writer::tag('div', $count, ['style' => 'font-size:28px;font-weight:700;']);
        echo html_writer::tag('div', $label, ['style' => 'font-size:12px;opacity:0.85;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    echo html_writer::tag('h4', '🔴 Currently In-Progress Attempts (all courses)', ['class' => 'mb-3 mt-2']);
    $active = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.risk_score, a.risk_level, a.timestart,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}       u   ON u.id   = a.userid
           JOIN {learnguard} lg  ON lg.id  = a.learnguard
           JOIN {course}     c   ON c.id   = lg.course
           JOIN {modules} mdl ON mdl.name = 'learnguard'
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = mdl.id
          WHERE a.state = 'inprogress'
       ORDER BY a.risk_score DESC, a.timestart DESC
          LIMIT 40", []
    );

    if (empty($active)) {
        echo html_writer::div('No active attempts right now.', 'alert alert-info');
    } else {
        $riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Student', 'Assessment', 'Course', 'Risk', 'Started', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($active as $a) {
            $rc = $riskcolours[$a->risk_level] ?? '#6c757d';
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', fullname($a));
            echo html_writer::tag('td', format_string($a->assessmentname));
            echo html_writer::tag('td', format_string($a->coursename));
            echo html_writer::tag('td', html_writer::tag('span', $a->risk_score . ' ' . strtoupper($a->risk_level), [
                'class' => 'lg-badge-risk', 'style' => 'background:' . $rc . ';',
            ]));
            echo html_writer::tag('td', userdate($a->timestart, '%H:%M'));
            echo html_writer::start_tag('td');
            echo html_writer::link(
                new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $a->cmid, 'attempt' => $a->id]),
                '🔍 Review', ['class' => 'btn btn-sm btn-outline-primary']
            );
            echo ' ';
            echo html_writer::link(
                new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid]),
                'Admin →', ['class' => 'btn btn-sm btn-outline-secondary']
            );
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: All Assessments
// =====================================================================
if ($tab === 'assessments') {

    // Handle bulk delete of assessments.
    if (data_submitted() && confirm_sesskey() && optional_param('bulkaction', '', PARAM_ALPHA) === 'deleteassessments') {
        $cmids = optional_param_array('cmids', [], PARAM_INT);
        $deleted = 0;
        foreach (array_filter(array_map('intval', $cmids)) as $delcmid) {
            $delcm = get_coursemodule_from_id('learnguard', $delcmid, 0, false, MUST_EXIST);
            // Do NOT call require_login() here — page-level require_login() already ran.
            // Calling it again with a course/cm param re-initialises the theme and crashes.
            if (has_capability('mod/learnguard:manage', context_module::instance($delcm->id))) {
                course_delete_module($delcm->id);
                $deleted++;
            }
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'assessments']),
            $deleted . ' assessment(s) deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    echo html_writer::tag('h4', 'All LearnGuard Assessments (' . $totalassessments . ')', ['class' => 'mb-3']);

    $assessments = $DB->get_records_sql(
        "SELECT lg.id, lg.name, lg.proctoring, lg.timelimit, lg.grade, lg.timecreated,
                COALESCE(lg.show_results, 1) AS show_results,
                COALESCE(lg.results_released, 0) AS results_released,
                c.id AS courseid, c.fullname AS coursename, cm.id AS cmid
           FROM {learnguard} lg
           JOIN {course} c ON c.id = lg.course
           JOIN {modules} mdl ON mdl.name = 'learnguard'
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = mdl.id
       ORDER BY c.fullname, lg.name", []
    );

    // Attempt counts per assessment, computed via 3 grouped aggregate queries
    // instead of 3 correlated subqueries per assessment row (was very slow
    // with many assessments).
    if (!empty($assessments)) {
        $totalsByLg   = $DB->get_records_sql_menu(
            "SELECT learnguard, COUNT(*) FROM {learnguard_attempts} GROUP BY learnguard");
        $activeByLg   = $DB->get_records_sql_menu(
            "SELECT learnguard, COUNT(*) FROM {learnguard_attempts} WHERE state = 'inprogress' GROUP BY learnguard");
        $highriskByLg = $DB->get_records_sql_menu(
            "SELECT learnguard, COUNT(*) FROM {learnguard_attempts} WHERE risk_level = 'high' GROUP BY learnguard");
        foreach ($assessments as $a) {
            $a->totalattempts = $totalsByLg[$a->id]   ?? 0;
            $a->active        = $activeByLg[$a->id]   ?? 0;
            $a->highrisk      = $highriskByLg[$a->id] ?? 0;
        }
    }

    if (empty($assessments)) {
        echo html_writer::div('No LearnGuard assessments found on this site.', 'alert alert-info');
    } else {
        // Bulk delete toolbar.
        echo '<form method="post" id="lg-asmt-form" style="margin-bottom:8px;">';
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',        'value' => 'assessments']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulkaction', 'value' => 'deleteassessments']);
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f8f9fa;' .
             'padding:10px 14px;border-radius:8px;border:1px solid #dee2e6;margin-bottom:10px;">';
        echo '<strong style="font-size:13px;">Actions:</strong>';
        echo '<button type="button" onclick="lgAsmtSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ Select All</button>';
        echo '<button type="button" onclick="lgAsmtSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ Deselect All</button>';
        echo '<button type="submit" class="btn btn-sm btn-danger" ' .
             'onclick="return lgAsmtConfirmDelete()">🗑 Delete Selected</button>';
        echo '<span id="lg-asmt-count" style="font-size:12px;color:#6c757d;">0 selected</span>';
        echo '<small style="color:#adb5bd;margin-left:6px;">⚠️ Permanently removes the assessment and all its attempts</small>';
        echo '</div>';

        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['☑', 'Assessment', 'Course', 'Proctoring', 'Grade', 'Attempts', 'Active', 'High Risk', 'Results', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;white-space:nowrap;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($assessments as $a) {
            $resultStatus = !isset($a->show_results) || $a->show_results == 1
                ? '✅ Immediate'
                : ($a->results_released > 0 ? '📬 Released ' . userdate($a->results_released, '%d/%m/%y') : '⏳ Held');
            echo html_writer::start_tag('tr', ['id' => 'asmt-row-' . $a->cmid]);
            // Checkbox column.
            echo html_writer::tag('td',
                '<input type="checkbox" name="cmids[]" value="' . $a->cmid . '" ' .
                'onchange="lgAsmtUpdateCount(this,\'asmt-row-' . $a->cmid . '\')" ' .
                'style="width:16px;height:16px;cursor:pointer;">');
            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/mod/learnguard/view.php', ['id' => $a->cmid]), format_string($a->name), ['target' => '_blank']
            ));
            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/course/view.php', ['id' => $a->courseid]), format_string($a->coursename)
            ));
            echo html_writer::tag('td', $a->proctoring ? '🛡 On' : 'Off');
            echo html_writer::tag('td', (int)$a->grade);
            echo html_writer::tag('td', (int)$a->totalattempts, ['style' => 'text-align:center;']);
            echo html_writer::tag('td',
                $a->active > 0 ? html_writer::tag('span', $a->active . ' 🔴', ['style' => 'color:#dc3545;font-weight:700;']) : '0',
                ['style' => 'text-align:center;']
            );
            echo html_writer::tag('td',
                $a->highrisk > 0 ? html_writer::tag('span', $a->highrisk, ['style' => 'color:#dc3545;font-weight:700;']) : '0',
                ['style' => 'text-align:center;']
            );
            echo html_writer::tag('td', $resultStatus, ['style' => 'white-space:nowrap;font-size:13px;']);
            echo html_writer::start_tag('td');
            echo html_writer::link(new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid]), '⚙️ Admin',
                ['class' => 'btn btn-sm btn-outline-primary']);
            echo ' ';
            echo html_writer::link(new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid, 'tab' => 'graders']), '👥 Graders',
                ['class' => 'btn btn-sm btn-outline-success']);
            echo ' ';
            echo html_writer::link(new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $a->cmid]), '🔴 Live',
                ['class' => 'btn btn-sm btn-outline-danger']);
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo '</form>'; // end lg-asmt-form
        ?>
        <script>
        function lgAsmtUpdateCount(chk, rowId) {
            var row = document.getElementById(rowId);
            if (row) row.style.background = chk.checked ? '#fff3cd' : '';
            var c = document.querySelectorAll('#lg-asmt-form input[name="cmids[]"]:checked').length;
            var b = document.getElementById('lg-asmt-count');
            if (b) b.textContent = c + ' selected';
        }
        function lgAsmtSelectAll(sel) {
            document.querySelectorAll('#lg-asmt-form input[name="cmids[]"]').forEach(function(chk) {
                chk.checked = sel;
                var row = chk.closest('tr');
                if (row) row.style.background = sel ? '#fff3cd' : '';
            });
            var c = sel ? document.querySelectorAll('#lg-asmt-form input[name="cmids[]"]').length : 0;
            var b = document.getElementById('lg-asmt-count');
            if (b) b.textContent = c + ' selected';
        }
        function lgAsmtConfirmDelete() {
            var c = document.querySelectorAll('#lg-asmt-form input[name="cmids[]"]:checked').length;
            if (c === 0) { alert('Please select at least one assessment.'); return false; }
            return confirm('PERMANENTLY DELETE ' + c + ' assessment(s)?\n\nThis removes the activity, all attempts, all snapshots, and all grades. This CANNOT be undone.');
        }
        </script>
        <?php
    }

    echo html_writer::start_div('alert alert-info mt-3');
    echo html_writer::tag('strong', 'Creating new assessments: ');
    echo 'Go to the course, click "Add an activity or resource", and select "LearnGuard Assessment". ';
    echo html_writer::link(new moodle_url('/course/index.php'), 'Browse Courses →', ['class' => 'btn btn-sm btn-primary']);
    echo ' ';
    echo html_writer::link(new moodle_url('/mod/learnguard/demo_setup.php'), '🎓 Create Demo Course',
        ['class' => 'btn btn-sm btn-outline-success']);
    echo html_writer::end_div();
}

// =====================================================================
// TAB: Live Exams — all currently in-progress attempts (site-wide)
// =====================================================================
if ($tab === 'inprogress') {

    // Force-submit a live attempt from this page.
    if (data_submitted() && confirm_sesskey()) {
        $fsattemptid = optional_param('fsattemptid', 0, PARAM_INT);
        if ($fsattemptid > 0) {
            $fsat = $DB->get_record('learnguard_attempts', ['id' => $fsattemptid, 'state' => 'inprogress'], '*', IGNORE_MISSING);
            if ($fsat) {
                // Grade all responses and close the attempt.
                $DB->set_field('learnguard_attempts', 'state',      'finished', ['id' => $fsattemptid]);
                $DB->set_field('learnguard_attempts', 'timefinish',  time(),    ['id' => $fsattemptid]);
                redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'inprogress']),
                    'Attempt #' . $fsattemptid . ' force-submitted.', null, \core\output\notification::NOTIFY_SUCCESS);
            }
        }
    }

    $liveAttempts = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.attempt, a.risk_score, a.risk_level, a.timestart, a.timemodified,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}           u   ON u.id = a.userid
           JOIN {learnguard}     lg  ON lg.id = a.learnguard
           JOIN {course}         c   ON c.id = lg.course
           JOIN {course_modules} cm  ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE a.state = 'inprogress'
       ORDER BY a.timestart DESC", []
    );

    echo html_writer::tag('h4', '🟢 Live Exams in Progress (' . count($liveAttempts) . ')', ['class' => 'mb-1']);
    echo html_writer::tag('p', 'All assessments currently being taken across the site. Refreshes on page reload.', ['class' => 'text-muted mb-3', 'style' => 'font-size:13px;']);

    if (empty($liveAttempts)) {
        echo html_writer::div('No exams are in progress right now.', 'alert alert-info');
    } else {
        $rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">';
        foreach ($liveAttempts as $live) {
            $rc      = $rcolours[$live->risk_level ?? 'low'] ?? '#6c757d';
            $elapsed = time() - (int)$live->timestart;
            $emin    = floor($elapsed / 60);
            $esec    = $elapsed % 60;
            $lastact = time() - (int)$live->timemodified;
            $actcol  = $lastact < 120 ? '#28a745' : ($lastact < 600 ? '#fd7e14' : '#dc3545');
            $actlbl  = $lastact < 60 ? 'Active now' : ($lastact < 120 ? '~1 min ago' : floor($lastact/60) . ' min ago');

            echo '<div style="background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.08);padding:16px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">';
            echo '<div>';
            echo '<div style="font-weight:700;font-size:15px;">' . htmlspecialchars(fullname($live)) . '</div>';
            echo '<div style="font-size:12px;color:#6c757d;">' . htmlspecialchars($live->email) . '</div>';
            echo '</div>';
            echo '<span style="background:' . $rc . ';color:#fff;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700;">'
                . ($live->risk_score ?? 0) . ' ' . strtoupper($live->risk_level ?? 'low') . '</span>';
            echo '</div>';
            echo '<div style="font-size:13px;color:#495057;margin-bottom:4px;">📋 ' . htmlspecialchars(format_string($live->assessmentname)) . '</div>';
            echo '<div style="font-size:12px;color:#6c757d;margin-bottom:8px;">📚 ' . htmlspecialchars(format_string($live->coursename)) . '</div>';
            echo '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">';
            echo '<span style="font-size:12px;">⏱ ' . $emin . 'm ' . $esec . 's</span>';
            echo '<span style="font-size:12px;color:' . $actcol . ';">● ' . $actlbl . '</span>';
            echo '</div>';
            // Actions
            echo '<div style="display:flex;gap:6px;">';
            echo html_writer::link(
                new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $live->cmid, 'attempt' => $live->id]),
                '🔍 Detail', ['class' => 'btn btn-sm btn-outline-primary']
            );
            echo html_writer::link(
                new moodle_url('/mod/learnguard/admin.php', ['id' => $live->cmid]),
                '⚙️ Admin', ['class' => 'btn btn-sm btn-outline-secondary']
            );
            // Force submit button
            $furl = new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'inprogress', 'sesskey' => sesskey(), 'fsattemptid' => $live->id]);
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => $furl->out(false), 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'fsattemptid', 'value' => $live->id]);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '⏹ End',
                'class' => 'btn btn-sm btn-danger',
                'onclick' => 'return confirm("Force-submit this attempt? The student\'s exam will end immediately.")']);
            echo html_writer::end_tag('form');
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
}

// =====================================================================
// TAB: High Risk
// =====================================================================
if ($tab === 'highrisk') {
    echo html_writer::tag('h4', '⚠️ All High-Risk & Suspicious Attempts (site-wide)', ['class' => 'mb-3']);

    $highriskAttempts = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.attempt, a.state, a.risk_score, a.risk_level,
                a.timestart, a.timefinish,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}           u   ON u.id = a.userid
           JOIN {learnguard}     lg  ON lg.id = a.learnguard
           JOIN {course}         c   ON c.id = lg.course
           JOIN {course_modules} cm  ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE a.risk_level IN ('high', 'medium')
       ORDER BY a.risk_score DESC
          LIMIT 200", []
    );

    if (empty($highriskAttempts)) {
        echo html_writer::div('No medium or high risk attempts found.', 'alert alert-success');
    } else {
        $riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Student', 'Assessment', 'Course', 'State', 'Risk Score', 'Level', 'Started', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($highriskAttempts as $a) {
            $rc = $riskcolours[$a->risk_level] ?? '#6c757d';
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', fullname($a));
            echo html_writer::tag('td', format_string($a->assessmentname));
            echo html_writer::tag('td', format_string($a->coursename));
            echo html_writer::tag('td', ucfirst($a->state));
            echo html_writer::tag('td', html_writer::tag('strong', $a->risk_score));
            echo html_writer::tag('td', html_writer::tag('span', strtoupper($a->risk_level),
                ['class' => 'lg-badge-risk', 'style' => 'background:' . $rc . ';']));
            echo html_writer::tag('td', userdate($a->timestart, '%d/%m/%y %H:%M'));
            echo html_writer::start_tag('td', ['style' => 'white-space:nowrap;']);
            echo html_writer::link(
                new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $a->cmid, 'attempt' => $a->id]),
                '🔍 Review', ['class' => 'btn btn-sm btn-outline-danger me-1']
            );
            echo html_writer::link(
                new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $a->cmid]),
                '🔴 Live', ['class' => 'btn btn-sm btn-danger me-1']
            );
            // Suspend from high-risk tab
            $hrbaseurl = new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'suspended', 'sesskey' => sesskey()]);
            if ($a->state === 'inprogress') {
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',      'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'susattemptid', 'value' => $a->id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'susaction',    'value' => 'unsuspend']);
                // Abuse unsuspend form but change action to suspend via separate siteadmin action
                echo html_writer::end_tag('form');
                // Simpler: link to admin page with suspend action
                echo html_writer::link(
                    new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid, 'action' => 'suspend', 'attemptid' => $a->id, 'sesskey' => sesskey()]),
                    '⛔ Suspend', ['class' => 'btn btn-sm btn-outline-warning']
                );
            } elseif ($a->state === 'suspended') {
                echo html_writer::tag('span', '🚫 Suspended', ['class' => 'badge bg-danger']);
            }
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: Suspended Users — site-wide list with unsuspend/delete actions
// =====================================================================
if ($tab === 'suspended') {

    // Handle unsuspend action
    if (data_submitted() && confirm_sesskey()) {
        $susaction   = optional_param('susaction', '', PARAM_ALPHA);
        $susattemptid = optional_param('susattemptid', 0, PARAM_INT);
        if ($susattemptid > 0) {
            $susat = $DB->get_record('learnguard_attempts', ['id' => $susattemptid, 'state' => 'suspended'], '*', IGNORE_MISSING);
            if ($susat) {
                // Site admin is already required for this page — just check siteconfig capability.
                if ($susaction === 'unsuspend' && has_capability('moodle/site:config', context_system::instance())) {
                    $DB->set_field('learnguard_attempts', 'state', 'inprogress', ['id' => $susattemptid]);
                    redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'suspended']),
                        'Attempt reinstated — student can now continue.', null, \core\output\notification::NOTIFY_SUCCESS);
                } elseif ($susaction === 'delete' && has_capability('moodle/site:config', context_system::instance())) {
                    $DB->delete_records('learnguard_attempts', ['id' => $susattemptid]);
                    redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'suspended']),
                        'Attempt deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
                }
            }
        }
    }

    $suspendedAttempts = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.attempt, a.risk_score, a.risk_level, a.timestart,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}           u   ON u.id = a.userid
           JOIN {learnguard}     lg  ON lg.id = a.learnguard
           JOIN {course}         c   ON c.id = lg.course
           JOIN {course_modules} cm  ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE a.state = 'suspended'
       ORDER BY a.timestart DESC", []
    );

    echo html_writer::tag('h4', '🚫 All Suspended Users (' . count($suspendedAttempts) . ')', ['class' => 'mb-1']);
    echo html_writer::tag('p', 'These students have been suspended mid-exam due to proctoring violations. Proctor or admin can reinstate or remove the attempt.', ['class' => 'text-muted mb-3', 'style' => 'font-size:13px;']);

    if (empty($suspendedAttempts)) {
        echo html_writer::div('No suspended attempts — all clear.', 'alert alert-success');
    } else {
        $rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Student', 'Email', 'Assessment', 'Course', 'Risk Score', 'Started', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;font-size:13px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($suspendedAttempts as $sus) {
            $rc = $rcolours[$sus->risk_level ?? 'low'] ?? '#6c757d';
            $baseurl = new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'suspended', 'sesskey' => sesskey()]);
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', fullname($sus)));
            echo html_writer::tag('td', html_writer::tag('small', s($sus->email), ['class' => 'text-muted']));
            echo html_writer::tag('td', format_string($sus->assessmentname));
            echo html_writer::tag('td', format_string($sus->coursename));
            echo html_writer::tag('td',
                html_writer::tag('span', ($sus->risk_score ?? 0) . ' — ' . strtoupper($sus->risk_level ?? 'low'),
                    ['style' => 'background:' . $rc . ';color:#fff;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;']));
            echo html_writer::tag('td', html_writer::tag('small', userdate($sus->timestart, '%d/%m/%y %H:%M')));
            echo html_writer::start_tag('td', ['style' => 'white-space:nowrap;']);
            // Detail + Dashboard links
            echo html_writer::link(
                new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $sus->cmid, 'attempt' => $sus->id]),
                '🔍', ['class' => 'btn btn-sm btn-outline-secondary me-1', 'title' => 'View detail']
            );
            echo html_writer::link(
                new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $sus->cmid]),
                '🔴', ['class' => 'btn btn-sm btn-outline-danger me-1', 'title' => 'Live Dashboard']
            );
            // Unsuspend form
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',      'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'susattemptid', 'value' => $sus->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'susaction',    'value' => 'unsuspend']);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '▶ Reinstate',
                'class' => 'btn btn-sm btn-success me-1',
                'onclick' => 'return confirm("Reinstate this student? They will be able to continue the exam.")']);
            echo html_writer::end_tag('form');
            // Delete form
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',      'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'susattemptid', 'value' => $sus->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'susaction',    'value' => 'delete']);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🗑 Delete',
                'class' => 'btn btn-sm btn-danger',
                'onclick' => 'return confirm("Permanently delete this attempt? The student will need to start fresh.")']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: Proctors — Global + Per-Assessment
// =====================================================================
if ($tab === 'proctors') {

    $selectedLgid = optional_param('lgid', 0, PARAM_INT);

    // ── Section 1: Global Proctors ────────────────────────────────────
    echo html_writer::start_div('', ['style' =>
        'border:2px solid #6f42c1;border-radius:10px;padding:20px;margin-bottom:28px;background:#fdf8ff;']);
    echo html_writer::tag('h4', '🌐 Global Proctors', ['style' => 'color:#6f42c1;margin-bottom:6px;']);
    echo html_writer::tag('p',
        'Global proctors can view and monitor <strong>every</strong> LearnGuard assessment on this site ' .
        'without needing per-assessment assignment. Assign sparingly — typically senior invigilators only.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:16px;']);

    $globalProctors = $DB->get_records_sql(
        "SELECT p.id AS pid, p.timemodified, u.id AS userid, u.firstname, u.lastname, u.email
           FROM {learnguard_proctors} p
           JOIN {user} u ON u.id = p.userid
          WHERE p.is_global = 1
       ORDER BY u.lastname, u.firstname", []
    );

    if (empty($globalProctors)) {
        echo html_writer::div('No global proctors assigned yet.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'margin-bottom:16px;']);
        echo html_writer::start_tag('tr');
        foreach (['Proctor', 'Email', 'Assigned', 'Remove'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($globalProctors as $p) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', fullname($p)));
            echo html_writer::tag('td', $p->email);
            echo html_writer::tag('td', userdate($p->timemodified, '%d/%m/%Y'));
            echo html_writer::start_tag('td');
            echo html_writer::start_tag('form', ['method' => 'post']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',     'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction',  'value' => 'removeglobalproctor']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'proctorid',   'value' => $p->pid]);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '× Remove',
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => 'return confirm("Remove ' . s(fullname($p)) . ' as global proctor?")']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }

    // Add global proctor form.
    echo html_writer::tag('h6', 'Add a Global Proctor', ['style' => 'margin-bottom:8px;']);
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addglobalproctor']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'usersearch', 'class' => 'form-control', 'style' => 'max-width:280px;',
        'placeholder' => 'Email, username or user ID', 'required' => 'required',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Add Global Proctor',
        'class' => 'btn btn-primary', 'style' => 'background:#6f42c1;border-color:#6f42c1;']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    // ── Section 2: Course-Level Proctors ─────────────────────────────
    echo html_writer::start_div('', ['style' =>
        'border:2px solid #17a2b8;border-radius:10px;padding:20px;margin-bottom:28px;background:#f0fbff;']);
    echo html_writer::tag('h4', '📚 Course Proctors', ['style' => 'color:#17a2b8;margin-bottom:6px;']);
    echo html_writer::tag('p',
        'Course proctors can monitor <strong>all</strong> LearnGuard assessments within a specific course.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:16px;']);

    $courseProctors = $DB->get_records_sql(
        "SELECT p.id AS pid, p.courseid, p.timemodified, u.id AS userid, u.firstname, u.lastname, u.email,
                c.fullname AS coursename
           FROM {learnguard_proctors} p
           JOIN {user}   u ON u.id = p.userid
           JOIN {course} c ON c.id = p.courseid
          WHERE p.is_global = 0 AND p.learnguard = 0 AND p.courseid > 0
       ORDER BY c.fullname, u.lastname", []
    );

    if (empty($courseProctors)) {
        echo html_writer::div('No course proctors assigned yet.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'margin-bottom:16px;']);
        echo html_writer::start_tag('tr');
        foreach (['Proctor', 'Email', 'Course', 'Assigned', 'Remove'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($courseProctors as $p) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', fullname($p)));
            echo html_writer::tag('td', s($p->email));
            echo html_writer::tag('td', format_string($p->coursename));
            echo html_writer::tag('td', userdate($p->timemodified, '%d/%m/%Y'));
            echo html_writer::start_tag('td');
            echo html_writer::start_tag('form', ['method' => 'post']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'removecourseproctor']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'proctorid',  'value' => $p->pid]);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '× Remove',
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => 'return confirm("Remove ' . s(fullname($p)) . ' as course proctor?")']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }

    // Add course proctor form.
    $allCourses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname FROM {course} c
           JOIN {learnguard} lg ON lg.course = c.id
          WHERE c.id > 1 ORDER BY c.fullname", []
    );
    echo html_writer::tag('h6', 'Assign a Course Proctor', ['style' => 'margin-bottom:8px;']);
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addcourseproctor']);
    echo html_writer::start_tag('select', ['name' => 'courseid', 'class' => 'form-control', 'style' => 'max-width:300px;', 'required' => 'required']);
    echo html_writer::tag('option', '— Select course —', ['value' => '']);
    foreach ($allCourses as $c) {
        echo html_writer::tag('option', format_string($c->fullname), ['value' => $c->id]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'usersearch', 'class' => 'form-control', 'style' => 'max-width:240px;',
        'placeholder' => 'Email, username or user ID', 'required' => 'required',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Assign',
        'class' => 'btn btn-primary', 'style' => 'background:#17a2b8;border-color:#17a2b8;']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    // ── Section 3: Per-Assessment Proctors ───────────────────────────
    echo html_writer::tag('h4', '📋 Per-Assessment Proctor Assignment', ['class' => 'mb-2']);
    echo html_writer::tag('p', 'Select an assessment below to view or change its proctor list.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:14px;']);

    // Assessment picker form.
    $allAssessments = $DB->get_records_sql(
        "SELECT lg.id, lg.name, c.fullname AS coursename
           FROM {learnguard} lg
           JOIN {course} c ON c.id = lg.course
       ORDER BY c.fullname, lg.name", []
    );

    echo html_writer::start_tag('form', ['method' => 'get', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:20px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'proctors']);
    echo html_writer::start_tag('select', ['name' => 'lgid', 'class' => 'form-control', 'style' => 'max-width:420px;',
        'onchange' => 'this.form.submit()']);
    echo html_writer::tag('option', '— Choose an assessment —', ['value' => '0']);
    foreach ($allAssessments as $a) {
        echo html_writer::tag('option', '[' . format_string($a->coursename) . '] ' . format_string($a->name),
            ['value' => $a->id, 'selected' => ($selectedLgid === (int)$a->id) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'View', 'class' => 'btn btn-outline-secondary']);
    echo html_writer::end_tag('form');

    if ($selectedLgid > 0) {
        $selInstance = $DB->get_record('learnguard', ['id' => $selectedLgid]);
        $selCm = get_coursemodule_from_instance('learnguard', $selectedLgid, 0, false, MUST_EXIST);

        echo html_writer::start_div('', ['style' =>
            'border:1px solid #dee2e6;border-radius:8px;padding:20px;background:#fff;']);
        echo html_writer::tag('h5', format_string($selInstance->name),
            ['style' => 'border-left:4px solid ' . $brand->primary . ';padding-left:10px;margin-bottom:14px;']);

        $asmtProctors = $DB->get_records_sql(
            "SELECT p.id AS pid, p.timemodified, u.id AS userid, u.firstname, u.lastname, u.email
               FROM {learnguard_proctors} p
               JOIN {user} u ON u.id = p.userid
              WHERE p.learnguard = :lgid AND p.is_global = 0
           ORDER BY u.lastname, u.firstname",
            ['lgid' => $selectedLgid]
        );

        if (empty($asmtProctors)) {
            echo html_writer::div('No proctors assigned to this assessment yet.', 'alert alert-info');
        } else {
            echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'margin-bottom:14px;']);
            echo html_writer::start_tag('tr');
            foreach (['Proctor', 'Email', 'Assigned', 'Actions'] as $h) {
                echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
            }
            echo html_writer::end_tag('tr');
            foreach ($asmtProctors as $p) {
                echo html_writer::start_tag('tr');
                echo html_writer::tag('td', fullname($p));
                echo html_writer::tag('td', $p->email);
                echo html_writer::tag('td', userdate($p->timemodified, '%d/%m/%Y'));
                echo html_writer::start_tag('td');
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'removeassessmentproctor']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'proctorid',  'value' => $p->pid]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $selectedLgid]);
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '× Remove',
                    'class' => 'btn btn-sm btn-outline-danger',
                    'onclick' => 'return confirm("Remove ' . s(fullname($p)) . ' from this assessment?")']);
                echo html_writer::end_tag('form');
                echo ' ';
                echo html_writer::link(
                    new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $selCm->id]),
                    '🔴 Dashboard', ['class' => 'btn btn-sm btn-outline-primary']
                );
                echo html_writer::end_tag('td');
                echo html_writer::end_tag('tr');
            }
            echo html_writer::end_tag('table');
        }

        // Add proctor form for selected assessment.
        echo html_writer::tag('h6', 'Add Proctor to this Assessment', ['style' => 'margin-bottom:8px;']);
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addassessmentproctor']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $selectedLgid]);
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'usersearch', 'class' => 'form-control', 'style' => 'max-width:280px;',
            'placeholder' => 'Email, username or user ID', 'required' => 'required',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Assign Proctor', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
    }

    // Global proctors also cover this assessment — info note.
    if (count($globalProctors) > 0) {
        echo html_writer::start_div('alert alert-info mt-3');
        echo html_writer::tag('strong', '🌐 Note: ');
        echo count($globalProctors) . ' Global Proctor' . (count($globalProctors) > 1 ? 's are' : ' is') .
             ' active — they already cover ALL assessments automatically.';
        echo html_writer::end_div();
    }
}

// =====================================================================
// TAB: Media Gallery
// =====================================================================
if ($tab === 'media') {

    echo html_writer::tag('h4', '🖼 Media Gallery — All Snapshots & Recordings', ['class' => 'mb-1']);
    echo html_writer::tag('p',
        'Webcam snapshots and Photo ID captures stored across all assessments.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:16px;']);

    // Total counts.
    $totalSnaps = $DB->count_records('learnguard_snapshots');
    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
    foreach ([
        ['Total',        $totalSnaps, $brand->primary],
        ['📸 Webcam',    $DB->count_records('learnguard_snapshots', ['snapshot_type' => 'periodic']), '#0d6efd'],
        ['🪪 Photo ID',  $DB->count_records('learnguard_snapshots', ['snapshot_type' => 'photo_id']), '#6f42c1'],
        ['🎥 360°',      $DB->count_records('learnguard_snapshots', ['snapshot_type' => '360_video']), '#dc3545'],
        ['👤 Face',      $DB->count_records('learnguard_snapshots', ['snapshot_type' => 'face']), '#fd7e14'],
    ] as [$label, $count, $colour]) {
        echo html_writer::start_div('', ['style' =>
            'background:' . $colour . ';color:#fff;border-radius:8px;padding:12px 20px;min-width:110px;text-align:center;']);
        echo html_writer::tag('div', $count, ['style' => 'font-size:24px;font-weight:700;']);
        echo html_writer::tag('div', $label, ['style' => 'font-size:11px;opacity:0.85;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    // ── Filter form ───────────────────────────────────────────────────
    $filterLgid    = optional_param('filter_lgid',    0,  PARAM_INT);
    $filterStudent = optional_param('filter_student', '', PARAM_TEXT);
    $filterType    = optional_param('filter_type',    '', PARAM_ALPHANUMEXT);
    $mediaPage     = optional_param('mediapage',      0,  PARAM_INT);
    $perPage       = 48;

    $allAssessments2 = $DB->get_records_sql(
        "SELECT lg.id, lg.name, c.fullname AS coursename
           FROM {learnguard} lg JOIN {course} c ON c.id = lg.course
       ORDER BY c.fullname, lg.name", []
    );

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'style'  => 'background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:16px;margin-bottom:20px;',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'media']);
    echo html_writer::start_div('d-flex gap-3 flex-wrap align-items-end');

    // Assessment filter.
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Assessment', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::start_tag('select', ['name' => 'filter_lgid', 'class' => 'form-control', 'style' => 'max-width:260px;']);
    echo html_writer::tag('option', '— All assessments —', ['value' => '0']);
    foreach ($allAssessments2 as $a) {
        echo html_writer::tag('option',
            '[' . format_string($a->coursename) . '] ' . format_string($a->name),
            ['value' => $a->id, 'selected' => ($filterLgid === (int)$a->id) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_div();

    // Student filter.
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Student (name/email)', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'filter_student', 'class' => 'form-control', 'style' => 'max-width:220px;',
        'value' => s($filterStudent), 'placeholder' => 'Search name or email…',
    ]);
    echo html_writer::end_div();

    // Type filter.
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Type', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::start_tag('select', ['name' => 'filter_type', 'class' => 'form-control', 'style' => 'max-width:160px;']);
    $typeOptions = [
        ''              => 'All types',
        'periodic'      => 'Webcam Snapshots (during exam)',
        'photo_id'      => 'Photo ID (precheck)',
        '360_video'     => '360° Environment Recordings',
        'face'          => 'Face Check (precheck)',
        'object_cell_phone' => 'Phone Detected Captures',
        'identity_mismatch' => 'Identity Mismatch Captures',
        'background_change' => 'Background Change Captures',
        'multiple_persons'      => 'Multiple Persons Captures',
        'random_screenshot'     => 'Random Screenshots (Webcam)',
        'screen_capture'        => '🖥 Screen Captures (Desktop)',
    ];
    foreach ($typeOptions as $val => $lbl) {
        echo html_writer::tag('option', $lbl, ['value' => $val, 'selected' => ($filterType === $val) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🔍 Filter', 'class' => 'btn btn-primary', 'style' => 'align-self:flex-end;']);
    if ($filterLgid || $filterStudent || $filterType) {
        echo ' ';
        echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
            'Clear', ['class' => 'btn btn-outline-secondary', 'style' => 'align-self:flex-end;']);
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    // ── Build WHERE clause ────────────────────────────────────────────
    $where  = '1=1';
    $params = [];

    if ($filterLgid > 0) {
        $where   .= ' AND a.learnguard = :filter_lgid';
        $params['filter_lgid'] = $filterLgid;
    }
    if ($filterType !== '') {
        $where   .= ' AND s.snapshot_type = :filter_type';
        $params['filter_type'] = $filterType;
    }
    if ($filterStudent !== '') {
        $like = $DB->sql_like_escape($filterStudent);
        $where .= ' AND (' .
            $DB->sql_like('u.firstname', ':fsn', false) . ' OR ' .
            $DB->sql_like('u.lastname',  ':lsn', false) . ' OR ' .
            $DB->sql_like('u.email',     ':esn', false) . ')';
        $params['fsn'] = '%' . $like . '%';
        $params['lsn'] = '%' . $like . '%';
        $params['esn'] = '%' . $like . '%';
    }

    $baseSql = "FROM {learnguard_snapshots} s
                JOIN {learnguard_attempts} a  ON a.id = s.attemptid
                JOIN {user}                u  ON u.id = s.userid
                JOIN {learnguard}          lg ON lg.id = a.learnguard
                JOIN {course}              c  ON c.id  = lg.course
               WHERE $where";

    $countSql   = "SELECT COUNT(*) $baseSql";
    $totalCount = $DB->count_records_sql($countSql, $params);
    $totalPages = $totalCount > 0 ? ceil($totalCount / $perPage) : 1;
    $mediaPage  = max(0, min($mediaPage, $totalPages - 1));
    $offset     = $mediaPage * $perPage;

    // Single query — joins {modules}+{course_modules} to get cmid for the "View attempt" links.
    // Do NOT select snapshot_url here — it can be megabytes per row.
    // The gallery uses /mod/learnguard/snapshot.php?id=X to serve binaries efficiently.
    $snapshots2 = $DB->get_records_sql(
        "SELECT s.id, s.snapshot_type, s.facefound, s.timemodified,
                u.firstname, u.lastname, u.email, u.id AS userid,
                lg.name AS assessmentname, c.fullname AS coursename, a.id AS attemptid,
                cm.id AS cmid
           FROM {learnguard_snapshots} s
           JOIN {learnguard_attempts} a  ON a.id = s.attemptid
           JOIN {user}                u  ON u.id = s.userid
           JOIN {learnguard}          lg ON lg.id = a.learnguard
           JOIN {course}              c  ON c.id  = lg.course
           JOIN {modules}             md ON md.name = 'learnguard'
           JOIN {course_modules}      cm ON cm.instance = lg.id AND cm.module = md.id
          WHERE $where
       ORDER BY s.timemodified DESC",
        $params, $offset, $perPage
    );

    echo html_writer::tag('p',
        'Showing ' . ($totalCount === 0 ? '0' : ($offset + 1)) . '–' .
        min($offset + $perPage, $totalCount) . ' of ' . $totalCount . ' snapshots.',
        ['style' => 'font-size:13px;color:#6c757d;margin-bottom:12px;']
    );

    $typeLabels = [
        'periodic'            => ['📸 Webcam',     '#0d6efd', false],
        'photo_id'            => ['🪪 Photo ID',   '#6f42c1', false],
        '360_video'           => ['🎥 360°',        '#dc3545', true],   // true = video
        'face'                => ['👤 Face',         '#fd7e14', false],
        'object_cell_phone'   => ['📱 Phone',        '#dc3545', false],
        'identity_mismatch'   => ['⚠️ ID Mismatch', '#dc3545', false],
        'background_change'   => ['🖼 BG Change',   '#856404', false],
        'multiple_persons'    => ['👥 Multi-Person','#dc3545', false],
        'random_screenshot'   => ['🎲 Rnd Shot',    '#20c997', false],
        'screen_capture'      => ['🖥 Screen',       '#495057', false],
    ];

    // ── Hidden form for individual + bulk deletes (single form, no nesting) ──
    // Individual deletes submit via JS setting the hidden snapids[] value.
    echo '<form method="post" id="lg-media-form">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'deletemedia']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',        'value' => 'media']);
    // Single-item delete field — JS sets this and submits the form.
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'single_delete_id', 'id' => 'lg-single-del-id', 'value' => '']);

    echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;' .
         'background:#f8f9fa;padding:10px 14px;border-radius:8px;border:1px solid #dee2e6;">';
    echo '<strong style="font-size:13px;">Actions:</strong>';
    echo '<button type="button" onclick="lgSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ Select All</button>';
    echo '<button type="button" onclick="lgSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ Deselect All</button>';
    echo '<button type="submit" class="btn btn-sm btn-danger" ' .
         'onclick="return lgConfirmDelete()">' .
         '🗑 Delete Selected</button>';
    echo '<span id="lg-sel-count" style="font-size:12px;color:#6c757d;margin-left:4px;">0 selected</span>';
    echo '<div style="border-left:1px solid #dee2e6;margin:0 4px;height:24px;"></div>';
    // Quick bulk-delete by type.
    foreach (['periodic' => 'All Webcam', 'photo_id' => 'All Photo IDs', '360_video' => 'All 360°'] as $dtype => $dlabel) {
        $cnt = $DB->count_records('learnguard_snapshots', ['snapshot_type' => $dtype]);
        if ($cnt > 0) {
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete ALL ' .
                 $dlabel . ' (' . $cnt . ' items)? This cannot be undone.\');">';
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'deletemediatype']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'dtype',      'value' => $dtype]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',        'value' => 'media']);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🗑 ' . $dlabel . ' (' . $cnt . ')',
                'class' => 'btn btn-sm btn-outline-danger']);
            echo '</form>';
        }
    }
    echo '</div>';

    // ── Thumbnail grid with checkboxes ────────────────────────────────
    if (empty($snapshots2)) {
        echo html_writer::div('No snapshots match the current filter.', 'alert alert-info');
    } else {
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin-bottom:20px;">';
        foreach ($snapshots2 as $s) {
            $tdata    = $typeLabels[$s->snapshot_type] ?? [$s->snapshot_type, '#6c757d', false];
            $isVideo  = ($tdata[2] === true);
            // Use snapshot.php endpoint — avoids sending megabytes of base64 in the HTML page.
            $mediaSrc = (new moodle_url('/mod/learnguard/snapshot.php',
                ['id' => $s->id, 'sesskey' => sesskey()]))->out(false);
            $student  = htmlspecialchars(fullname($s), ENT_QUOTES);
            $asmt     = htmlspecialchars(format_string($s->assessmentname), ENT_QUOTES);
            $ts       = userdate($s->timemodified, '%d/%m/%y %H:%M');
            $detailUrl = (new moodle_url('/mod/learnguard/attemptdetail.php',
                ['id' => $s->cmid, 'attempt' => $s->attemptid]))->out(false);

            echo '<div class="lg-media-card" style="border:2px solid #dee2e6;border-radius:8px;overflow:hidden;' .
                 'background:#fff;box-shadow:0 2px 6px rgba(0,0,0,0.07);position:relative;" id="card-' . $s->id . '">';

            // Checkbox overlay top-left — inside the main form, no nesting needed.
            echo '<label style="position:absolute;top:6px;left:6px;z-index:2;cursor:pointer;' .
                 'background:rgba(255,255,255,0.92);border-radius:4px;padding:3px 5px;box-shadow:0 1px 4px rgba(0,0,0,0.2);">' .
                 '<input type="checkbox" name="snapids[]" value="' . $s->id . '" ' .
                 'onchange="lgUpdateCount(this,\'card-' . $s->id . '\')" ' .
                 'style="width:16px;height:16px;cursor:pointer;vertical-align:middle;"></label>';

            // Media thumbnail.
            if ($isVideo) {
                echo '<video src="' . htmlspecialchars($mediaSrc, ENT_QUOTES) . '" ' .
                     'style="width:100%;height:140px;object-fit:cover;background:#111;cursor:pointer;display:block;" ' .
                     'onclick="lgMediaLightbox(this,true)" controls preload="none"></video>';
            } else {
                echo '<div onclick="lgMediaLightbox(this,false)" ' .
                     'data-src="' . htmlspecialchars($mediaSrc, ENT_QUOTES) . '" ' .
                     'data-student="' . $student . '" data-asmt="' . $asmt . '" data-ts="' . $ts . '" ' .
                     'style="cursor:zoom-in;height:140px;overflow:hidden;background:#111;">';
                echo '<img src="' . htmlspecialchars($mediaSrc, ENT_QUOTES) . '" ' .
                     'style="width:100%;height:140px;object-fit:cover;" loading="lazy" alt="Snapshot"/>';
                echo '</div>';
            }

            // Caption.
            echo '<div style="padding:8px 10px;">';
            echo '<div style="background:' . $tdata[1] . ';color:#fff;display:inline-block;padding:2px 8px;' .
                 'border-radius:10px;font-size:10px;font-weight:700;margin-bottom:5px;">' . htmlspecialchars($tdata[0]) . '</div>';
            echo '<div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' .
                 $student . '</div>';
            echo '<div style="font-size:11px;color:#6c757d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' .
                 format_string($s->assessmentname) . '</div>';
            echo '<div style="font-size:11px;color:#adb5bd;">' . $ts . '</div>';
            // Individual delete — uses JS to set hidden field and submit the SAME form (no nesting).
            echo '<div style="display:flex;gap:6px;margin-top:5px;align-items:center;">';
            echo '<a href="' . $detailUrl . '" style="font-size:11px;color:' . $brand->primary . ';">View →</a>';
            echo '<button type="button" onclick="lgDeleteOne(' . $s->id . ')" ' .
                 'style="background:none;border:none;color:#dc3545;font-size:11px;cursor:pointer;padding:0;margin-left:auto;">🗑</button>';
            echo '</div></div></div>';
        }
        echo '</div>';

        // ── Pagination ────────────────────────────────────────────────
        if ($totalPages > 1) {
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">';
            for ($p = 0; $p < $totalPages; $p++) {
                $active  = ($p === $mediaPage);
                $pageUrl = new moodle_url('/mod/learnguard/siteadmin.php', [
                    'tab' => 'media', 'mediapage' => $p,
                    'filter_lgid' => $filterLgid, 'filter_student' => $filterStudent, 'filter_type' => $filterType,
                ]);
                echo html_writer::link($pageUrl, ($p + 1), [
                    'style' => 'padding:6px 12px;border-radius:4px;text-decoration:none;font-size:13px;' .
                        ($active ? 'background:' . $brand->primary . ';color:#fff;' : 'background:#f8f9fa;border:1px solid #dee2e6;color:#333;'),
                ]);
            }
            echo '</div>';
        }
    }

    echo '</form>'; // end lg-media-form

    // ── Lightbox script ───────────────────────────────────────────────
    ?>
    <div id="lg-media-lightbox" onclick="this.style.display='none'"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:999999;
                align-items:center;justify-content:center;flex-direction:column;font-family:system-ui,sans-serif;">
        <div style="position:relative;max-width:90vw;max-height:80vh;" onclick="event.stopPropagation()">
            <img id="lg-lb-img" src="" style="max-width:90vw;max-height:80vh;border-radius:4px;object-fit:contain;display:none;" alt=""/>
            <video id="lg-lb-video" controls style="max-width:90vw;max-height:80vh;border-radius:4px;display:none;"></video>
        </div>
        <div id="lg-lb-caption" style="color:#fff;margin-top:10px;text-align:center;font-size:14px;"></div>
        <div style="color:#aaa;font-size:12px;margin-top:4px;">Click outside to close</div>
    </div>
    <script>
    // Lightbox — supports both images and 360° videos.
    function lgMediaLightbox(el, isVideo) {
        var lb    = document.getElementById('lg-media-lightbox');
        var img   = document.getElementById('lg-lb-img');
        var vid   = document.getElementById('lg-lb-video');
        var cap   = document.getElementById('lg-lb-caption');
        lb.style.display = 'flex';
        if (isVideo) {
            img.style.display = 'none'; vid.style.display = 'block';
            vid.src = el.src || el.querySelector('source')?.src || '';
            if (cap) cap.textContent = '360° Environment Recording';
        } else {
            vid.style.display = 'none'; img.style.display = 'block';
            img.src = el.dataset.src;
            if (cap) cap.textContent =
                (el.dataset.student || '') + ' — ' + (el.dataset.asmt || '') + ' — ' + (el.dataset.ts || '');
        }
    }

    // ── Checkbox + selection helpers ──────────────────────────────────────────
    function lgUpdateCount(chk, cardId) {
        var card = document.getElementById(cardId);
        if (card) { card.style.borderColor = chk.checked ? '#dc3545' : '#dee2e6'; }
        var count = document.querySelectorAll('input[name="snapids[]"]:checked').length;
        var badge = document.getElementById('lg-sel-count');
        if (badge) badge.textContent = count + ' selected';
    }

    function lgSelectAll(select) {
        // Select/deselect every checkbox on the page (including those outside the media form grid).
        var boxes = document.querySelectorAll('input[name="snapids[]"]');
        boxes.forEach(function(chk) {
            chk.checked = select;
            var card = chk.closest('.lg-media-card');
            if (card) card.style.borderColor = select ? '#dc3545' : '#dee2e6';
        });
        var badge = document.getElementById('lg-sel-count');
        if (badge) badge.textContent = (select ? boxes.length : 0) + ' selected';
    }

    function lgConfirmDelete() {
        var boxes   = document.querySelectorAll('input[name="snapids[]"]:checked');
        var count   = boxes.length;
        if (count === 0) {
            alert('Please tick at least one item to delete.\nUse the checkboxes on the top-left of each card.');
            return false;
        }
        return confirm('Delete ' + count + ' selected item(s)?\nThis cannot be undone.');
    }

    // Individual delete — sets the hidden single-delete field and submits the main form.
    function lgDeleteOne(id) {
        if (!confirm('Delete this item? This cannot be undone.')) { return; }
        var field = document.getElementById('lg-single-del-id');
        if (field) { field.value = id; }
        // Uncheck all multi-select boxes so they don't delete too.
        document.querySelectorAll('input[name="snapids[]"]').forEach(function(c) { c.checked = false; });
        document.getElementById('lg-media-form').submit();
    }
    </script>
    <?php
}

// =====================================================================
// TAB: Question Bank (site-wide)
// =====================================================================
if ($tab === 'questions') {
    $totalquestions = $DB->count_records('learnguard_qbank');
    $totalcats      = $DB->count_records('learnguard_qbank_cats');

    echo learnguard_banner('LearnGuard Question Bank', 'All questions across all assessments on this site.', '🏦');

    echo html_writer::start_div('d-flex gap-2 mb-3 flex-wrap');
    echo html_writer::link(new moodle_url('/mod/learnguard/questionbank.php'), '+ Browse / Manage All Questions', ['class' => 'btn btn-primary']);
    echo html_writer::link(new moodle_url('/mod/learnguard/questionimport.php'), '📥 Import Questions', ['class' => 'btn btn-outline-secondary']);
    echo '<div class="btn-group">';
    foreach (['csv' => '⬇ CSV', 'xml' => '📄 Moodle XML', 'gift' => '🎁 GIFT', 'json' => '{ } JSON'] as $fmt => $lbl) {
        $cls = in_array($fmt, ['csv','xml','gift']) ? 'btn-outline-success' : 'btn-outline-secondary';
        echo html_writer::link(
            new moodle_url('/mod/learnguard/export_questions.php', ['format' => $fmt, 'sesskey' => sesskey()]),
            $lbl, ['class' => 'btn btn-sm ' . $cls]
        );
    }
    echo '</div>';
    echo html_writer::end_div();

    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
    foreach ([['Total Questions', $totalquestions, $brand->primary], ['Categories', $totalcats, '#20c997']] as [$label, $count, $colour]) {
        echo html_writer::start_div('', ['style' =>
            'background:' . $colour . ';color:#fff;border-radius:8px;padding:16px 24px;min-width:120px;text-align:center;']);
        echo html_writer::tag('div', $count, ['style' => 'font-size:28px;font-weight:700;']);
        echo html_writer::tag('div', $label, ['style' => 'font-size:12px;opacity:0.85;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    $breakdown = $DB->get_records_sql('SELECT qtype, COUNT(*) AS cnt FROM {learnguard_qbank} GROUP BY qtype ORDER BY cnt DESC');
    if ($breakdown) {
        $colours = ['multichoice' => '#0f6cbf', 'multicheck' => '#6f42c1', 'truefalse' => '#20c997',
                    'shortanswer' => '#fd7e14', 'essay' => '#795548', 'matching' => '#17a2b8'];
        echo html_writer::tag('h5', 'Question Types', ['class' => 'mb-2']);
        echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4');
        foreach ($breakdown as $row) {
            $col = $colours[$row->qtype] ?? '#6c757d';
            echo html_writer::tag('div', html_writer::tag('strong', $row->cnt) . ' ' . ucfirst($row->qtype),
                ['style' => 'background:' . $col . ';color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;']);
        }
        echo html_writer::end_div();
    }

    $recent = $DB->get_records_sql(
        "SELECT q.id, q.name, q.qtype, c.name AS catname, q.timemodified
           FROM {learnguard_qbank} q LEFT JOIN {learnguard_qbank_cats} c ON c.id = q.categoryid
          ORDER BY q.timemodified DESC LIMIT 20"
    );
    if ($recent) {
        $typecolors = ['multichoice' => '#0f6cbf', 'multicheck' => '#6f42c1', 'truefalse' => '#20c997',
                       'shortanswer' => '#fd7e14', 'essay' => '#795548', 'matching' => '#17a2b8'];
        echo html_writer::tag('h5', 'Recently Modified (last 20)', ['class' => 'mb-2']);
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('tr');
        foreach (['Question', 'Type', 'Category', 'Modified'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:6px 10px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($recent as $q) {
            $col = $typecolors[$q->qtype] ?? '#6c757d';
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', format_string($q->name), ['style' => 'font-weight:600;max-width:320px;']);
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($q->qtype),
                ['style' => 'background:' . $col . ';color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;']));
            echo html_writer::tag('td', format_string($q->catname ?? 'General'));
            echo html_writer::tag('td', userdate($q->timemodified, '%d/%m/%y'));
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: Branding
// =====================================================================
if ($tab === 'branding') {
    $brandprimary = get_config('learnguard', 'brand_primary') ?: '#0f6cbf';
    $brandaccent  = get_config('learnguard', 'brand_accent')  ?: '#28a745';
    $brandname    = get_config('learnguard', 'brand_name')    ?: '';

    echo html_writer::tag('h4', '🎨 Branding & Appearance', ['class' => 'mb-1']);
    echo html_writer::tag('p',
        'These settings are applied site-wide across all LearnGuard assessments.',
        ['style' => 'color:#6c757d;margin-bottom:24px;']);

    echo html_writer::start_div('', ['style' =>
        'border:1px solid #dee2e6;border-radius:8px;overflow:hidden;margin-bottom:24px;max-width:700px;']);
    echo html_writer::start_div('', ['id' => 'lg-brand-preview-bar', 'style' =>
        'background:' . $brandprimary . ';color:#fff;padding:12px 20px;' .
        'display:flex;align-items:center;justify-content:space-between;']);
    echo html_writer::tag('span', '🛡 ' . (trim($brandname) ?: 'LearnGuard') . ' Assessment',
        ['id' => 'lg-brand-preview-name', 'style' => 'font-weight:700;font-size:16px;']);
    echo html_writer::start_div('', ['style' => 'display:flex;gap:12px;align-items:center;']);
    echo html_writer::tag('span', 'Q 1 of 20', ['style' => 'font-size:14px;']);
    echo html_writer::tag('span', '⏱ 44:58',   ['style' => 'font-size:14px;font-weight:700;']);
    echo html_writer::tag('span', '🛡 Risk: 0 (low)', ['id' => 'lg-brand-preview-badge', 'style' =>
        'background:' . $brandaccent . ';color:#fff;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('', ['style' => 'padding:20px;background:#f8f9fa;']);
    echo html_writer::tag('p', '📋 Your question content will appear here.', ['style' => 'color:#6c757d;']);
    echo html_writer::start_div('', ['style' => 'display:flex;gap:8px;margin-top:8px;']);
    echo html_writer::tag('span', '← Previous',
        ['style' => 'background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;']);
    echo html_writer::tag('span', 'Save & Next →',
        ['id' => 'lg-brand-preview-btn', 'style' => 'background:' . $brandprimary . ';color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'max-width:500px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => '']);

    echo html_writer::start_div('mb-3');
    echo html_writer::tag('label', 'Plugin display name',
        ['for' => 'brand_name', 'style' => 'font-weight:600;display:block;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'id' => 'brand_name', 'name' => 'brand_name', 'value' => $brandname,
        'class' => 'form-control', 'placeholder' => 'e.g. My University Proctor',
        'oninput' => 'document.getElementById("lg-brand-preview-name").textContent = "🛡 " + (this.value || "LearnGuard") + " Assessment";',
    ]);
    echo html_writer::end_div();

    foreach ([
        ['brand_primary', 'Primary colour (banners, buttons)', $brandprimary,
            'lg-brand-preview-bar', 'lg-brand-preview-btn'],
        ['brand_accent', 'Accent colour (success badges)', $brandaccent, 'lg-brand-preview-badge', ''],
    ] as [$name, $label, $value, $previewEl, $previewEl2]) {
        $updateJs = 'document.getElementById("' . $previewEl . '").style.background=this.value;' .
            ($previewEl2 ? 'document.getElementById("' . $previewEl2 . '").style.background=this.value;' : '');
        echo html_writer::start_div('mb-3');
        echo html_writer::tag('label', $label, ['for' => $name . '_picker', 'style' => 'font-weight:600;display:block;margin-bottom:4px;']);
        echo html_writer::start_div('', ['style' => 'display:flex;gap:8px;align-items:center;']);
        echo html_writer::empty_tag('input', [
            'type' => 'color', 'id' => $name . '_picker', 'value' => $value,
            'style' => 'width:48px;height:40px;border:1px solid #ccc;border-radius:4px;cursor:pointer;padding:2px;',
            'oninput' => 'document.getElementById("' . $name . '").value=this.value;' . $updateJs,
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'id' => $name, 'name' => $name, 'value' => $value,
            'class' => 'form-control', 'style' => 'max-width:120px;font-family:monospace;',
            'pattern' => '#[0-9a-fA-F]{6}',
            'oninput' => 'if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById("' . $name . '_picker").value=this.value;' . $updateJs . '}',
        ]);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    $presets = [
        ['🔵 Default Blue', '#0f6cbf', '#28a745'], ['🟣 Royal Purple', '#6f42c1', '#fd7e14'],
        ['🔴 University Red', '#dc3545', '#0f6cbf'], ['⚫ Midnight Dark', '#212529', '#17a2b8'],
        ['🟤 Warm Brown', '#795548', '#ff9800'], ['🟢 Forest Green', '#2e7d32', '#1565c0'],
    ];
    echo html_writer::tag('h6', 'Quick Presets', ['class' => 'mb-2 mt-3']);
    echo html_writer::start_div('d-flex gap-2 flex-wrap mb-3');
    foreach ($presets as $preset) {
        echo html_writer::tag('button', $preset[0], [
            'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary',
            'onclick' => 'applyPreset("' . $preset[1] . '","' . $preset[2] . '")',
            'style' => 'border-left:4px solid ' . $preset[1] . ';',
        ]);
    }
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '💾 Save Branding', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    ?>
    <script>
    function applyPreset(primary, accent) {
        document.getElementById('brand_primary').value = primary;
        document.getElementById('brand_primary_picker').value = primary;
        document.getElementById('brand_accent').value = accent;
        document.getElementById('brand_accent_picker').value = accent;
        document.getElementById('lg-brand-preview-bar').style.background = primary;
        document.getElementById('lg-brand-preview-btn').style.background = primary;
        document.getElementById('lg-brand-preview-badge').style.background = accent;
    }
    </script>
    <?php
}
?>
</div><!-- /lg-sa-content -->
<?php echo $OUTPUT->footer();
