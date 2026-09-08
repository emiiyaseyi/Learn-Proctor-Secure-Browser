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
// Lazy size fetch for the Media Gallery — called via GET after the page has
// already rendered, so the slow LENGTH(snapshot_url) computation over a large
// blob column never blocks the main page load (same reasoning as storage.php's
// own deferred getstats call). Only computes for the batch of IDs actually
// visible on the current page (at most 48).
// -----------------------------------------------------------------------
if ($tab === 'media' && optional_param('ajaxsizes', 0, PARAM_INT) && confirm_sesskey()) {
    $sizeIds = optional_param_array('ids', [], PARAM_INT);
    $sizeIds = array_values(array_filter(array_map('intval', $sizeIds)));
    $out = [];
    if (!empty($sizeIds)) {
        list($sinsql, $sinparams) = $DB->get_in_or_equal($sizeIds, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT id, LENGTH(snapshot_url) AS bytelen FROM {learnguard_snapshots} WHERE id $sinsql",
            $sinparams
        );
        foreach ($rows as $r) {
            $out[$r->id] = (int)$r->bytelen;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    die;
}

// -----------------------------------------------------------------------
// POST ACTIONS (proctors)
// -----------------------------------------------------------------------
if (data_submitted() && confirm_sesskey()) {

    $postaction = optional_param('postaction', '', PARAM_ALPHANUMEXT);

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

    // Remove a grader from a specific assessment (site-admin shortcut).
    if ($postaction === 'removesitegrader') {
        $graderid  = required_param('graderid', PARAM_INT);
        $lgid      = required_param('lgid',     PARAM_INT);
        $graderRec = $DB->get_record('learnguard_graders', ['id' => $graderid, 'learnguard' => $lgid]);
        $DB->delete_records('learnguard_graders', ['id' => $graderid, 'learnguard' => $lgid]);

        // Revoke course role_assign if LearnGuard added it and no other assignments remain.
        if ($graderRec && !empty($graderRec->lg_enrolled)) {
            $lgRec = $DB->get_record('learnguard', ['id' => $lgid]);
            if ($lgRec) {
                $otherCount = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {learnguard_graders} g
                       JOIN {learnguard} lg ON lg.id = g.learnguard
                      WHERE g.userid = :uid AND lg.course = :cid",
                    ['uid' => $graderRec->userid, 'cid' => $lgRec->course]
                );
                if ($otherCount == 0) {
                    require_once($CFG->libdir . '/enrollib.php');
                    $lgManualPlugin = enrol_get_plugin('manual');
                    $lgManualInst   = $lgManualPlugin
                        ? $DB->get_record('enrol', ['courseid' => $lgRec->course, 'enrol' => 'manual'])
                        : null;
                    if ($lgManualPlugin && $lgManualInst) {
                        $lgManualPlugin->unenrol_user($lgManualInst, $graderRec->userid);
                    }
                }
            }
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders', 'lgid' => $lgid]),
            'Grader removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Edit an existing grader assignment (layer, anonymous, deadline).
    if ($postaction === 'editsitegrader') {
        $graderid = required_param('graderid',  PARAM_INT);
        $lgid     = required_param('lgid',      PARAM_INT);
        $layer    = optional_param('gradelayer', 1, PARAM_INT);
        $anon     = optional_param('grader_anonymous', 0, PARAM_INT);
        $ddl      = trim(optional_param('grader_deadline', '', PARAM_RAW));
        $deadline = ($ddl !== '') ? strtotime($ddl) : 0;
        $graderRec = $DB->get_record('learnguard_graders', ['id' => $graderid, 'learnguard' => $lgid]);
        if ($graderRec) {
            $DB->update_record('learnguard_graders', (object)[
                'id'         => $graderid,
                'gradelayer' => min(2, max(1, (int)$layer)),
                'anonymous'  => (int)$anon,
                'deadline'   => $deadline,
            ]);
            // If there's a new deadline and we managed the enrollment, update timeend.
            if (!empty($graderRec->lg_enrolled)) {
                $lgRec = $DB->get_record('learnguard', ['id' => $lgid]);
                if ($lgRec) {
                    require_once($CFG->libdir . '/enrollib.php');
                    $lgEnrolPlugin   = enrol_get_plugin('manual');
                    $lgEnrolInstance = $lgEnrolPlugin
                        ? $DB->get_record('enrol', ['courseid' => $lgRec->course, 'enrol' => 'manual'])
                        : null;
                    if ($lgEnrolPlugin && $lgEnrolInstance) {
                        $lgEnrolPlugin->update_user_enrol(
                            $lgEnrolInstance, $graderRec->userid,
                            ENROL_USER_ACTIVE, 0, $deadline > 0 ? $deadline : 0
                        );
                    }
                }
            }
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders', 'lgid' => $lgid]),
            'Grader updated.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Add a global grader (sees all activities site-wide).
    if ($postaction === 'addglobalgrader') {
        $search = trim(required_param('usersearch', PARAM_RAW));
        $anon   = optional_param('grader_anonymous', 0, PARAM_INT);
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) { $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]); }
        if ($user) {
            try {
                if (!$DB->record_exists_select('learnguard_graders', 'userid = :uid AND is_global = 1 AND courseid = 0', ['uid' => $user->id])) {
                    $DB->insert_record('learnguard_graders', (object)[
                        'learnguard'  => 0,
                        'userid'      => $user->id,
                        'anonymous'   => (int)$anon,
                        'gradelayer'  => 1,
                        'deadline'    => 0,
                        'timecreated' => time(),
                        'is_global'   => 1,
                        'courseid'    => 0,
                    ]);
                }
            } catch (Exception $e) {}
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders']),
                fullname($user) . ' added as Global Grader.', null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders']),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Remove a global grader.
    if ($postaction === 'removeglobalgrader') {
        $graderid = required_param('graderid', PARAM_INT);
        try { $DB->delete_records('learnguard_graders', ['id' => $graderid, 'is_global' => 1]); } catch (Exception $e) {}
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders']),
            'Global grader removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Edit a global grader (toggle anonymous).
    if ($postaction === 'editglobalgrader') {
        $graderid = required_param('graderid', PARAM_INT);
        $anon     = optional_param('grader_anonymous', 0, PARAM_INT);
        $rec = $DB->get_record('learnguard_graders', ['id' => $graderid, 'is_global' => 1]);
        if ($rec) {
            $DB->set_field('learnguard_graders', 'anonymous', (int)$anon, ['id' => $graderid]);
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders']),
            'Global grader updated.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Edit a site-wide overview grader (same as editsitegrader but redirects back to overview).
    if ($postaction === 'editoverviewgrader') {
        $graderid = required_param('graderid', PARAM_INT);
        $lgid     = required_param('lgid',     PARAM_INT);
        $layer    = optional_param('gradelayer', 1, PARAM_INT);
        $anon     = optional_param('grader_anonymous', 0, PARAM_INT);
        $ddl      = trim(optional_param('grader_deadline', '', PARAM_RAW));
        $deadline = ($ddl !== '') ? strtotime($ddl) : 0;
        $graderRec = $DB->get_record('learnguard_graders', ['id' => $graderid, 'learnguard' => $lgid]);
        if ($graderRec) {
            $DB->update_record('learnguard_graders', (object)[
                'id'         => $graderid,
                'gradelayer' => min(2, max(1, (int)$layer)),
                'anonymous'  => (int)$anon,
                'deadline'   => $deadline ?: 0,
            ]);
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders', 'editovid' => $graderid]),
            'Grader updated.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // ── Score Matrix access — its own independent grant, not tied to Proctor/Grader ──
    if ($postaction === 'addglobalscoreviewer') {
        $search = trim(required_param('usersearch', PARAM_RAW));
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) { $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]); }
        if ($user) {
            if (!$DB->record_exists_select('learnguard_score_viewers',
                'userid = :uid AND is_global = 1', ['uid' => $user->id])) {
                $DB->insert_record('learnguard_score_viewers', (object)[
                    'userid' => $user->id, 'is_global' => 1, 'courseid' => 0, 'timecreated' => time(),
                ]);
            }
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'scoreaccess']),
                fullname($user) . ' can now view the Score Matrix for every course.', null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'scoreaccess']),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
    if ($postaction === 'removescoreviewer') {
        $viewerid = required_param('viewerid', PARAM_INT);
        $DB->delete_records('learnguard_score_viewers', ['id' => $viewerid]);
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'scoreaccess']),
            'Score Matrix access removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($postaction === 'addcoursescoreviewer') {
        $search   = trim(required_param('usersearch', PARAM_RAW));
        $courseid = required_param('sv_courseid', PARAM_INT);
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) { $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]); }
        if ($user && $DB->record_exists('course', ['id' => $courseid])) {
            if (!$DB->record_exists_select('learnguard_score_viewers',
                'userid = :uid AND is_global = 0 AND courseid = :cid', ['uid' => $user->id, 'cid' => $courseid])) {
                $DB->insert_record('learnguard_score_viewers', (object)[
                    'userid' => $user->id, 'is_global' => 0, 'courseid' => $courseid, 'timecreated' => time(),
                ]);
            }
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'scoreaccess']),
                fullname($user) . ' can now view the Score Matrix for that course.', null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'scoreaccess']),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Assign a grader to one or more assessments in the same course + auto-enroll.
    if ($postaction === 'addsitegrader' || $postaction === 'addsitegrader_multi') {
        $lgid     = required_param('lgid', PARAM_INT);
        $search   = trim(required_param('usersearch', PARAM_RAW));
        $layer    = optional_param('gradelayer', 1, PARAM_INT);
        $anon     = optional_param('grader_anonymous', 0, PARAM_INT);
        $ddl      = trim(optional_param('grader_deadline', '', PARAM_RAW));
        $deadline = ($ddl !== '') ? strtotime($ddl) : 0;
        $instance = $DB->get_record('learnguard', ['id' => $lgid], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) {
            $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]);
        }
        if ($user) {
            // Build list of lgids to assign to (current + any checked extras).
            $extraLgIds = optional_param_array('extra_lgids', [], PARAM_INT);
            $allLgIds   = array_unique(array_merge([$lgid], array_map('intval', $extraLgIds ?? [])));

            // Auto-enroll the grader using Moodle's manual enrollment plugin.
            // enrol_try_internal_enrol() creates a proper {user_enrolments} record which
            // satisfies require_login()'s is_enrolled() check on Moodle 4.5+ / 5.x.
            // It also creates the manual enrollment instance for the course if needed.
            require_once($CFG->libdir . '/enrollib.php');
            $lgCourseCtx = context_course::instance($instance->course);
            $lgRoleId    = (int)($DB->get_field('role', 'id', ['shortname' => 'teacher'])
                           ?: $DB->get_field('role', 'id', ['shortname' => 'editingteacher'])
                           ?: 0);
            $lgDidEnroll = false;
            if (!is_enrolled($lgCourseCtx, $user->id)) {
                $lgDidEnroll = (bool) enrol_try_internal_enrol($instance->course, $user->id, $lgRoleId ?: null);
            }

            $addedNames = [];
            foreach ($allLgIds as $thisLgid) {
                $thisLgid = (int)$thisLgid;
                $thisLg = $DB->get_record('learnguard', ['id' => $thisLgid, 'course' => $instance->course]);
                if (!$thisLg) { continue; }
                if (!$DB->record_exists('learnguard_graders', ['learnguard' => $thisLgid, 'userid' => $user->id])) {
                    $DB->insert_record('learnguard_graders', (object)[
                        'learnguard'  => $thisLgid,
                        'userid'      => $user->id,
                        'gradelayer'  => min(2, max(1, (int)$layer)),
                        'anonymous'   => (int)$anon,
                        'deadline'    => $deadline ?: 0,
                        'timecreated' => time(),
                        'lg_enrolled' => $lgDidEnroll ? 1 : 0,
                    ]);
                    $addedNames[] = format_string($thisLg->name);
                }
            }

            // Send notification email for the primary assessment.
            if (!empty($addedNames) && get_config('learnguard', 'notify_proctors_enabled')) {
                $cm = get_coursemodule_from_instance('learnguard', $lgid, 0, false, MUST_EXIST);
                $gradersUrl = (new moodle_url('/mod/learnguard/grader.php', ['id' => $cm->id]))->out(false);
                learnguard_send_email(
                    $user,
                    'You have been assigned as a grader for ' . format_string($instance->name),
                    'You have been assigned as a Layer-' . $layer . ' grader for: <strong>' .
                    implode(', ', $addedNames) . '</strong>.<br>' .
                    'Visit your grading page: <a href="' . $gradersUrl . '">' . $gradersUrl . '</a>' .
                    ($deadline ? '<br>Deadline: ' . userdate($deadline) : '')
                );
            }

            $msg = empty($addedNames)
                ? fullname($user) . ' is already assigned to all selected assessments.'
                : fullname($user) . ' assigned to: ' . implode(', ', $addedNames) . '. Course access granted.';
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders', 'lgid' => $lgid]),
                $msg, null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders', 'lgid' => $lgid]),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Bulk-remove multiple non-global graders from learnguard_graders.
    if ($postaction === 'bulkremovesitegraders') {
        $rawIds  = optional_param_array('graderids', [], PARAM_INT);
        $rawIds  = array_values(array_filter(array_map('intval', $rawIds)));
        $lgidRet = optional_param('lgid', 0, PARAM_INT);
        require_once($CFG->libdir . '/enrollib.php');
        foreach ($rawIds as $gid) {
            $graderRec = $DB->get_record('learnguard_graders', ['id' => $gid]);
            if (!$graderRec || !empty($graderRec->is_global)) { continue; }
            $DB->delete_records('learnguard_graders', ['id' => $gid]);
            if (!empty($graderRec->lg_enrolled)) {
                $lgRec = $DB->get_record('learnguard', ['id' => $graderRec->learnguard]);
                if ($lgRec) {
                    $otherCnt = $DB->count_records_sql(
                        "SELECT COUNT(*) FROM {learnguard_graders} g
                           JOIN {learnguard} lg ON lg.id = g.learnguard
                          WHERE g.userid = :uid AND lg.course = :cid",
                        ['uid' => $graderRec->userid, 'cid' => $lgRec->course]
                    );
                    if ($otherCnt == 0) {
                        $lgMp = enrol_get_plugin('manual');
                        $lgMi = $lgMp ? $DB->get_record('enrol', ['courseid' => $lgRec->course, 'enrol' => 'manual']) : null;
                        if ($lgMp && $lgMi) { $lgMp->unenrol_user($lgMi, $graderRec->userid); }
                    }
                }
            }
        }
        $rparams = ['tab' => 'graders'];
        if ($lgidRet > 0) { $rparams['lgid'] = $lgidRet; }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', $rparams),
            count($rawIds) . ' grader(s) removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Bulk-remove multiple global graders.
    if ($postaction === 'bulkremoveglobalgraders') {
        $rawGlbIds = optional_param_array('graderids', [], PARAM_INT);
        $rawGlbIds = array_values(array_filter(array_map('intval', $rawGlbIds)));
        foreach ($rawGlbIds as $gid) {
            $DB->delete_records('learnguard_graders', ['id' => $gid, 'is_global' => 1]);
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders']),
            count($rawGlbIds) . ' global grader(s) removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Distribute finished attempts among Layer-1 graders for an assessment.
    if ($postaction === 'distribsitegrader') {
        $lgid = required_param('lgid', PARAM_INT);
        $mode = optional_param('distmode', 'equal', PARAM_ALPHA);
        require_capability('moodle/site:config', context_system::instance());

        // Only distribute UNGRADED attempts (sumgrades IS NULL = not yet fully graded).
        $attemptIds = $DB->get_fieldset_sql(
            "SELECT id FROM {learnguard_attempts} WHERE learnguard = :lg AND state = 'finished' AND sumgrades IS NULL",
            ['lg' => $lgid]
        );
        $graderRows = $DB->get_records_sql(
            "SELECT g.userid FROM {learnguard_graders} g WHERE g.learnguard = :lg AND g.gradelayer = 1 ORDER BY g.id",
            ['lg' => $lgid]
        );
        $graderIds = [];
        foreach ($graderRows as $gr) { $graderIds[] = (int)$gr->userid; }

        if (!empty($attemptIds) && !empty($graderIds)) {
            $DB->delete_records('learnguard_grader_assignments', ['learnguard' => $lgid]);
            $now = time();
            shuffle($attemptIds);

            if ($mode === 'equal') {
                $total = count($graderIds);
                foreach ($attemptIds as $i => $aid) {
                    $DB->insert_record('learnguard_grader_assignments', (object)[
                        'learnguard'  => $lgid,
                        'attemptid'   => (int)$aid,
                        'graderid'    => (int)$graderIds[$i % $total],
                        'timecreated' => $now,
                    ]);
                }
                $distMsg = 'Distributed ' . count($attemptIds) . ' attempt(s) equally among ' . $total . ' grader(s).';
            } else {
                // Custom: read per-grader counts, assign sequentially from shuffled pool.
                $perGraderCounts = optional_param_array('dist_custom', [], PARAM_INT);
                $offset = 0;
                $distTotal = 0;
                foreach ($graderIds as $gid) {
                    $n     = max(0, (int)($perGraderCounts[$gid] ?? 0));
                    $slice = array_slice($attemptIds, $offset, $n);
                    foreach ($slice as $aid) {
                        $DB->insert_record('learnguard_grader_assignments', (object)[
                            'learnguard'  => $lgid,
                            'attemptid'   => (int)$aid,
                            'graderid'    => (int)$gid,
                            'timecreated' => $now,
                        ]);
                        $distTotal++;
                    }
                    $offset += $n;
                }
                $distMsg = 'Assigned ' . $distTotal . ' attempt(s) to grader(s).';
            }
        } else {
            $distMsg = 'Nothing to distribute (no Layer-1 graders or no finished attempts).';
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders', 'lgid' => $lgid]),
            $distMsg, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Clear all grader assignments for an assessment (graders revert to seeing all attempts).
    if ($postaction === 'cleardistrib') {
        $lgid = required_param('lgid', PARAM_INT);
        require_capability('moodle/site:config', context_system::instance());
        $DB->delete_records('learnguard_grader_assignments', ['learnguard' => $lgid]);
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders', 'lgid' => $lgid]),
            'All attempt assignments cleared. Graders will now see all finished attempts.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Nudge a grader: send a reminder email about their pending attempts.
    if ($postaction === 'nudgesitegrader') {
        $graderid = required_param('graderid', PARAM_INT);
        $lgid     = required_param('lgid', PARAM_INT);
        require_capability('moodle/site:config', context_system::instance());

        $graderRec  = $DB->get_record('learnguard_graders', ['id' => $graderid, 'learnguard' => $lgid]);
        $instance   = $DB->get_record('learnguard', ['id' => $lgid]);
        if ($graderRec && $instance) {
            $graderUser = $DB->get_record('user', ['id' => $graderRec->userid]);
            $assigned   = $DB->count_records('learnguard_grader_assignments',
                              ['learnguard' => $lgid, 'graderid' => $graderRec->userid]);
            $graded     = (int)$DB->get_field_sql(
                "SELECT COUNT(DISTINCT eg.attemptid)
                   FROM {learnguard_essay_grades} eg
                   JOIN {learnguard_grader_assignments} ga
                        ON ga.attemptid = eg.attemptid AND ga.learnguard = :lg AND ga.graderid = :uid
                  WHERE eg.graderid = :uid2",
                ['lg' => $lgid, 'uid' => $graderRec->userid, 'uid2' => $graderRec->userid]
            );
            $remaining  = max(0, $assigned - $graded);
            $dlStr      = $graderRec->deadline > 0 ? userdate($graderRec->deadline, '%d %B %Y') : 'No deadline set';
            $nudgeCm    = get_coursemodule_from_instance('learnguard', $lgid, 0, false, MUST_EXIST);
            $graderUrl  = (new moodle_url('/mod/learnguard/grader.php', ['id' => $nudgeCm->id]))->out(false);

            if ($graderUser) {
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
                $nudgeMsg = 'Reminder sent to ' . fullname($graderUser) . ' (' . $remaining . ' pending).';
            } else {
                $nudgeMsg = 'Grader user not found.';
            }
        } else {
            $nudgeMsg = 'Grader record not found.';
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'graders', 'lgid' => $lgid]),
            $nudgeMsg, null, \core\output\notification::NOTIFY_SUCCESS);
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

    // Delete every snapshot matching the current Media Gallery filter (assessment,
    // student, type, and/or date range) — lets an admin filter to "older than X" and
    // clear it in one click instead of deleting types wholesale or paging through items.
    if ($postaction === 'deletemediafiltered') {
        require_capability('moodle/site:config', context_system::instance());

        $fLgid    = optional_param('filter_lgid',    0,  PARAM_INT);
        $fStudent = optional_param('filter_student', '', PARAM_TEXT);
        $fType    = optional_param('filter_type',    '', PARAM_ALPHANUMEXT);
        $fBefore  = optional_param('filter_before',  '', PARAM_TEXT);
        $fAfter   = optional_param('filter_after',   '', PARAM_TEXT);

        $fWhere  = '1=1';
        $fParams = [];
        if ($fLgid > 0) {
            $fWhere .= ' AND a.learnguard = :flgid';
            $fParams['flgid'] = $fLgid;
        }
        if ($fType !== '') {
            $fWhere .= ' AND s.snapshot_type = :ftype';
            $fParams['ftype'] = $fType;
        }
        if ($fStudent !== '') {
            $flike = $DB->sql_like_escape($fStudent);
            $fWhere .= ' AND (' .
                $DB->sql_like('u.firstname', ':ffsn', false) . ' OR ' .
                $DB->sql_like('u.lastname',  ':flsn', false) . ' OR ' .
                $DB->sql_like('u.email',     ':fesn', false) . ')';
            $fParams['ffsn'] = '%' . $flike . '%';
            $fParams['flsn'] = '%' . $flike . '%';
            $fParams['fesn'] = '%' . $flike . '%';
        }
        $fAfterTs = $fAfter !== '' ? strtotime($fAfter . ' 00:00:00') : false;
        if ($fAfterTs !== false) {
            $fWhere .= ' AND s.timemodified >= :fafterts';
            $fParams['fafterts'] = $fAfterTs;
        }
        $fBeforeTs = $fBefore !== '' ? strtotime($fBefore . ' 00:00:00') : false;
        if ($fBeforeTs !== false) {
            $fWhere .= ' AND s.timemodified < :fbeforets';
            $fParams['fbeforets'] = $fBeforeTs;
        }

        // Refuse an unfiltered "delete everything" via this action — use the per-type
        // or per-item tools for that, so a blank/expired filter can't wipe the gallery.
        if ($fWhere === '1=1') {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
                'No filter was applied — nothing deleted.', null, \core\output\notification::NOTIFY_WARNING);
        }

        // A live storage crisis is exactly when this can be the largest — protect
        // against PHP's own default execution-time cutoff on top of the type_time /
        // timecreated indexes added to fix the underlying full-table-scan slowness.
        core_php_time_limit::raise(300);
        $matchIds = $DB->get_records_sql(
            "SELECT s.id
               FROM {learnguard_snapshots} s
               JOIN {learnguard_attempts} a ON a.id = s.attemptid
               JOIN {user}                u ON u.id = s.userid
              WHERE $fWhere",
            $fParams
        );
        $ids = array_keys($matchIds);
        if (!empty($ids)) {
            list($fInsql, $fInparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('learnguard_snapshots', "id $fInsql", $fInparams);
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
            core_php_time_limit::raise(300);
            $count = $DB->count_records_select('learnguard_snapshots', $where, $params);
            $DB->delete_records_select('learnguard_snapshots', $where, $params);
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
                $count . ' ' . $dtype . ' item(s) deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }

    // Delete selected audio clips.
    if ($postaction === 'deleteaudio') {
        require_capability('moodle/site:config', context_system::instance());
        $singleId = optional_param('single_delete_id', 0, PARAM_INT);
        if ($singleId > 0) {
            $DB->delete_records('learnguard_audio_clips', ['id' => $singleId]);
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'audio']),
                '1 audio clip deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        $ids = optional_param_array('audioids', [], PARAM_INT);
        $ids = array_filter(array_map('intval', $ids));
        if (!empty($ids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('learnguard_audio_clips', "id $insql", $inparams);
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'audio']),
            count($ids) . ' audio clip(s) deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Delete every audio clip matching the current Audio Clips filter (assessment,
    // student, and/or date range) — same pattern as deletemediafiltered.
    if ($postaction === 'deleteaudiofiltered') {
        require_capability('moodle/site:config', context_system::instance());

        $fLgid    = optional_param('filter_lgid',    0,  PARAM_INT);
        $fStudent = optional_param('filter_student', '', PARAM_TEXT);
        $fBefore  = optional_param('filter_before',  '', PARAM_TEXT);
        $fAfter   = optional_param('filter_after',   '', PARAM_TEXT);

        $fWhere  = '1=1';
        $fParams = [];
        if ($fLgid > 0) {
            $fWhere .= ' AND a.learnguard = :flgid';
            $fParams['flgid'] = $fLgid;
        }
        if ($fStudent !== '') {
            $flike = $DB->sql_like_escape($fStudent);
            $fWhere .= ' AND (' .
                $DB->sql_like('u.firstname', ':ffsn', false) . ' OR ' .
                $DB->sql_like('u.lastname',  ':flsn', false) . ' OR ' .
                $DB->sql_like('u.email',     ':fesn', false) . ')';
            $fParams['ffsn'] = '%' . $flike . '%';
            $fParams['flsn'] = '%' . $flike . '%';
            $fParams['fesn'] = '%' . $flike . '%';
        }
        $fAfterTs = $fAfter !== '' ? strtotime($fAfter . ' 00:00:00') : false;
        if ($fAfterTs !== false) {
            $fWhere .= ' AND ac.timecreated >= :fafterts';
            $fParams['fafterts'] = $fAfterTs;
        }
        $fBeforeTs = $fBefore !== '' ? strtotime($fBefore . ' 00:00:00') : false;
        if ($fBeforeTs !== false) {
            $fWhere .= ' AND ac.timecreated < :fbeforets';
            $fParams['fbeforets'] = $fBeforeTs;
        }

        if ($fWhere === '1=1') {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'audio']),
                'No filter was applied — nothing deleted.', null, \core\output\notification::NOTIFY_WARNING);
        }

        core_php_time_limit::raise(300);
        $matchIds = $DB->get_records_sql(
            "SELECT ac.id
               FROM {learnguard_audio_clips} ac
               JOIN {learnguard_attempts} a ON a.id = ac.attemptid
               JOIN {user}                u ON u.id = ac.userid
              WHERE $fWhere",
            $fParams
        );
        $ids = array_keys($matchIds);
        if (!empty($ids)) {
            list($fInsql, $fInparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('learnguard_audio_clips', "id $fInsql", $fInparams);
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'audio']),
            count($ids) . ' audio clip(s) deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Download selected snapshots/recordings as a ZIP before deleting them. Built
    // against a temp file (not held in memory) and decoded one item at a time so this
    // stays safe on a memory-constrained server even for a few hundred items.
    if ($postaction === 'downloadmedia') {
        require_capability('moodle/site:config', context_system::instance());
        $ids = optional_param_array('snapids', [], PARAM_INT);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
                'No items selected to download.', null, \core\output\notification::NOTIFY_WARNING);
        }
        if (count($ids) > 300) {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
                'Please select 300 items or fewer per download.', null, \core\output\notification::NOTIFY_WARNING);
        }

        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT s.id, s.snapshot_type, s.snapshot_url, s.timemodified,
                    u.firstname, u.lastname
               FROM {learnguard_snapshots} s
               JOIN {user} u ON u.id = s.userid
              WHERE s.id $insql",
            $inparams
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'lgmedia_');
        $zip = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::OVERWRITE);
        foreach ($rows as $r) {
            $ext = (in_array($r->snapshot_type, ['360_video'], true) || strpos($r->snapshot_type, 'screen') !== false)
                ? 'webm' : 'jpg';
            $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $r->firstname . '_' . $r->lastname);
            $fname = $r->id . '_' . $r->snapshot_type . '_' . $safeName . '_' .
                userdate($r->timemodified, '%Y%m%d_%H%M%S') . '.' . $ext;
            $binary = base64_decode($r->snapshot_url);
            $zip->addFromString($fname, $binary);
            unset($binary); // free before decoding the next item.
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="learnguard_media_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($tmpPath));
        readfile($tmpPath);
        unlink($tmpPath);
        die;
    }

    // Download selected audio clips as a ZIP before deleting them — same streamed,
    // temp-file-backed approach as downloadmedia.
    if ($postaction === 'downloadaudio') {
        require_capability('moodle/site:config', context_system::instance());
        $ids = optional_param_array('audioids', [], PARAM_INT);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'audio']),
                'No items selected to download.', null, \core\output\notification::NOTIFY_WARNING);
        }
        if (count($ids) > 300) {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'audio']),
                'Please select 300 items or fewer per download.', null, \core\output\notification::NOTIFY_WARNING);
        }

        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT ac.id, ac.audiodata, ac.timecreated, u.firstname, u.lastname
               FROM {learnguard_audio_clips} ac
               JOIN {user} u ON u.id = ac.userid
              WHERE ac.id $insql",
            $inparams
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'lgaudio_');
        $zip = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::OVERWRITE);
        foreach ($rows as $r) {
            $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $r->firstname . '_' . $r->lastname);
            $fname = $r->id . '_' . $safeName . '_' . userdate($r->timecreated, '%Y%m%d_%H%M%S') . '.webm';
            $binary = base64_decode($r->audiodata);
            $zip->addFromString($fname, $binary);
            unset($binary);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="learnguard_audio_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($tmpPath));
        readfile($tmpPath);
        unlink($tmpPath);
        die;
    }

    // Reclaim disk space after deleting media/audio. Deleting rows frees space inside
    // the table for MySQL/MariaDB to reuse, but the physical file on disk doesn't shrink
    // until the table is rebuilt — this is why "Disk Usage" can look unchanged right after
    // a bulk delete.
    // Queued as a background ad-hoc task, not run synchronously — confirmed live that
    // running OPTIMIZE TABLE directly in this request hung indefinitely with the browser
    // showing zero feedback: a full rebuild of a 700,000+ row table with large BLOB
    // content can run far longer than any web request/gateway timeout allows, the exact
    // same problem already fixed for bulk ZIP export in v2.0.175 (see
    // classes/task/reclaim_disk_space.php). Status tracked via 3 small config values
    // (reclaim_status/reclaim_started/reclaim_finished/reclaim_error) — no new table
    // needed for a single, site-wide, one-at-a-time operation like this.
    if ($postaction === 'reclaimdiskspace') {
        require_capability('moodle/site:config', context_system::instance());
        $reclaimedFrom = optional_param('reclaimtab', 'media', PARAM_ALPHA);
        \core\task\manager::queue_adhoc_task(new \mod_learnguard\task\reclaim_disk_space());
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => $reclaimedFrom]),
            'Disk space reclaim queued — this runs in the background via cron, so it is not ' .
            'limited by how long a page load can run. Refresh this page in a few minutes to check on it.',
            null, \core\output\notification::NOTIFY_SUCCESS);
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
    'graders'     => ['Graders',         '✍️', null],
    'scoreaccess' => ['Score Access',    '📈', null],
    'media'       => ['Media Gallery',   '🖼',  null],
    'audio'       => ['Audio Clips',     '🎤',  null],
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
    <a href="<?php echo new moodle_url('/mod/learnguard/bulkschedule.php'); ?>" class="lg-action-btn" style="background:#6c757d;color:#fff;">
      📅 Bulk Schedule
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/assessmentmanager.php'); ?>" class="lg-action-btn" style="background:#17a2b8;color:#fff;">
      📋 Assessment Manager
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
            echo html_writer::tag('td', $a->timestart ? userdate($a->timestart, '%H:%M') : '—');
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

    // Handle single/bulk void of assessments — voids every finished/suspended attempt
    // under the selected assessment(s), keeps the activity and history intact.
    if (data_submitted() && confirm_sesskey() &&
        in_array(optional_param('bulkaction', '', PARAM_ALPHA), ['voidassessments', 'voidassessment'], true)) {
        $cmids = optional_param_array('cmids', [], PARAM_INT);
        $singlecmid = optional_param('cmid', 0, PARAM_INT);
        if ($singlecmid) { $cmids = [$singlecmid]; }
        $voidedAttempts = 0;
        foreach (array_filter(array_map('intval', $cmids)) as $vcmid) {
            $vcm = get_coursemodule_from_id('learnguard', $vcmid, 0, false, MUST_EXIST);
            if (has_capability('mod/learnguard:manage', context_module::instance($vcm->id))) {
                $voidedAttempts += learnguard_void_assessment_attempts((int)$vcm->instance);
            }
        }
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'assessments']),
            $voidedAttempts . ' attempt(s) voided across the selected assessment(s).', null,
            \core\output\notification::NOTIFY_SUCCESS);
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
        // Bulk delete/void toolbar. bulkaction is set by whichever button was clicked
        // (see lgAsmtSubmit() below) — the hidden field default doesn't matter.
        echo '<form method="post" id="lg-asmt-form" style="margin-bottom:8px;">';
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',        'value' => 'assessments']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulkaction', 'id' => 'lg-asmt-bulkaction', 'value' => '']);
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f8f9fa;' .
             'padding:10px 14px;border-radius:8px;border:1px solid #dee2e6;margin-bottom:10px;">';
        echo '<strong style="font-size:13px;">Actions:</strong>';
        echo '<button type="button" onclick="lgAsmtSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ Select All</button>';
        echo '<button type="button" onclick="lgAsmtSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ Deselect All</button>';
        echo '<button type="button" class="btn btn-sm btn-warning" ' .
             'onclick="return lgAsmtSubmit(\'voidassessments\')">🚫 Void Selected</button>';
        echo '<button type="button" class="btn btn-sm btn-danger" ' .
             'onclick="return lgAsmtSubmit(\'deleteassessments\')">🗑 Delete Selected</button>';
        echo '<span id="lg-asmt-count" style="font-size:12px;color:#6c757d;">0 selected</span>';
        echo '<small style="color:#adb5bd;margin-left:6px;">Void keeps the assessment and history, just excludes finished attempts from grading/scores. Delete is permanent.</small>';
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
            echo html_writer::start_tag('td', ['style' => 'white-space:nowrap;']);
            echo html_writer::link(new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid]), '⚙️ Admin',
                ['class' => 'btn btn-sm btn-outline-primary']);
            echo ' ';
            echo html_writer::link(new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid, 'tab' => 'graders']), '👥 Graders',
                ['class' => 'btn btn-sm btn-outline-success']);
            echo ' ';
            echo html_writer::link(new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $a->cmid]), '🔴 Live',
                ['class' => 'btn btn-sm btn-outline-danger']);
            echo ' ';
            echo '<button type="button" class="btn btn-sm btn-outline-warning" ' .
                 'onclick="lgAsmtSingleVoid(' . (int)$a->cmid . ', ' . json_encode(format_string($a->name)) . ')">🚫 Void</button>';
            echo ' ';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" style="color:#dc3545;border-color:#dc3545;" ' .
                 'onclick="lgAsmtSingleDelete(' . (int)$a->cmid . ', ' . json_encode(format_string($a->name)) . ')">🗑 Delete</button>';
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
        function lgAsmtSubmit(action) {
            var c = document.querySelectorAll('#lg-asmt-form input[name="cmids[]"]:checked').length;
            if (c === 0) { alert('Please select at least one assessment.'); return false; }
            var ok = (action === 'deleteassessments')
                ? confirm('PERMANENTLY DELETE ' + c + ' assessment(s)?\n\nThis removes the activity, all attempts, all snapshots, and all grades. This CANNOT be undone.')
                : confirm('Void every finished attempt under ' + c + ' assessment(s)?\n\nThe assessment(s) and their history stay intact — voided attempts are just excluded from grading/scores. This can be reversed per-attempt from the Score Matrix.');
            if (!ok) { return false; }
            document.getElementById('lg-asmt-bulkaction').value = action;
            document.getElementById('lg-asmt-form').submit();
            return false;
        }
        function lgAsmtSingleVoid(cmid, name) {
            if (!confirm('Void every finished attempt under "' + name + '"?\n\nThe assessment and its history stay intact — voided attempts are just excluded from grading/scores.')) { return; }
            var f = document.createElement('form');
            f.method = 'post'; f.action = '';
            f.innerHTML =
                '<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">' +
                '<input type="hidden" name="tab" value="assessments">' +
                '<input type="hidden" name="bulkaction" value="voidassessment">' +
                '<input type="hidden" name="cmid" value="' + cmid + '">';
            document.body.appendChild(f);
            f.submit();
        }
        function lgAsmtSingleDelete(cmid, name) {
            var typed = prompt('This permanently deletes "' + name + '" and ALL its data ' +
                '(every attempt, score, chat, snapshot, audio clip, incident). This cannot be undone.\n\n' +
                'Type the assessment name exactly to confirm:');
            if (typed === null) { return; }
            if (typed !== name) { alert('Name did not match — nothing was deleted.'); return; }
            var f = document.createElement('form');
            f.method = 'post'; f.action = '';
            f.innerHTML =
                '<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">' +
                '<input type="hidden" name="tab" value="assessments">' +
                '<input type="hidden" name="bulkaction" value="deleteassessments">' +
                '<input type="hidden" name="cmids[]" value="' + cmid + '">';
            document.body.appendChild(f);
            f.submit();
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
            $notstarted = ((int)$live->timestart === 0);
            $elapsed = $notstarted ? 0 : time() - (int)$live->timestart;
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
            echo '<span style="font-size:12px;">⏱ ' . ($notstarted ? 'Preparing…' : ($emin . 'm ' . $esec . 's')) . '</span>';
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
            echo html_writer::tag('td', $a->timestart ? userdate($a->timestart, '%d/%m/%y %H:%M') : '—');
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
                    // Clear timesuspended — otherwise every future resume of this attempt is
                    // treated as "just reinstated" and gets the lower post-reinstate threshold
                    // (risk_score + reinstate_threshold_delta) instead of the real autosuspend_score.
                    $DB->set_field('learnguard_attempts', 'timesuspended', 0, ['id' => $susattemptid]);
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
        "SELECT a.id, a.userid, a.attempt, a.risk_score, a.risk_level, a.risk_score_at_suspend, a.timestart,
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
        foreach (['Student', 'Email', 'Assessment', 'Course', 'Risk at suspension', 'Started', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;font-size:13px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($suspendedAttempts as $sus) {
            $rc = $rcolours[$sus->risk_level ?? 'low'] ?? '#6c757d';
            $atSuspend = (int)($sus->risk_score_at_suspend ?? 0);
            $current   = (int)($sus->risk_score ?? 0);
            // Pre-upgrade rows have no snapshot (0) — fall back to showing the current score alone.
            $shownScore = $atSuspend > 0 ? $atSuspend : $current;
            $baseurl = new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'suspended', 'sesskey' => sesskey()]);
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', fullname($sus)));
            echo html_writer::tag('td', html_writer::tag('small', s($sus->email), ['class' => 'text-muted']));
            echo html_writer::tag('td', format_string($sus->assessmentname));
            echo html_writer::tag('td', format_string($sus->coursename));
            $riskcell = html_writer::tag('span', $shownScore . ' — ' . strtoupper($sus->risk_level ?? 'low'),
                    ['style' => 'background:' . $rc . ';color:#fff;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;']);
            if ($atSuspend > 0 && $atSuspend !== $current) {
                $riskcell .= html_writer::tag('div', 'now: ' . $current,
                    ['class' => 'text-muted', 'style' => 'font-size:11px;margin-top:2px;',
                     'title' => 'Score changed after suspension — a proctor likely dismissed some flagged events.']);
            }
            echo html_writer::tag('td', $riskcell);
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
// TAB: Graders — Site-wide grader assignment with course/assessment selector
// =====================================================================
if ($tab === 'graders') {

    $selectedLgid  = optional_param('lgid',      0, PARAM_INT);
    $filterCourse  = optional_param('courseid',  0, PARAM_INT);
    $editGid       = optional_param('editgid',   0, PARAM_INT);
    $editOvId      = optional_param('editovid',  0, PARAM_INT);
    $editGlbId     = optional_param('editglbid', 0, PARAM_INT);

    // ── Section 1: Site-wide grader overview ────────────────────────────
    echo html_writer::start_div('', ['style' =>
        'border:2px solid #28a745;border-radius:10px;padding:20px;margin-bottom:28px;background:#f6fff8;']);
    echo html_writer::tag('h4', '✍️ Site-Wide Grader Overview', ['style' => 'color:#28a745;margin-bottom:6px;']);
    echo html_writer::tag('p',
        'All essay grader assignments across every LearnGuard assessment on this site. ' .
        'Use the course/assessment filter below to narrow the view and manage a specific assessment.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:16px;']);

    $allGraders = $DB->get_records_sql(
        "SELECT g.id AS gid, g.learnguard, g.userid, g.gradelayer, g.anonymous,
                COALESCE(g.deadline, 0) AS deadline,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, cm.id AS cmid,
                COUNT(DISTINCT a.id) AS totalattempts,
                COUNT(DISTINCT eg.attemptid) AS graded
           FROM {learnguard_graders} g
           JOIN {user} u ON u.id = g.userid
           JOIN {learnguard} lg ON lg.id = g.learnguard
           JOIN {course} c ON c.id = lg.course
           JOIN {course_modules} cm ON cm.instance = lg.id
           JOIN {modules} mdl ON mdl.id = cm.module AND mdl.name = 'learnguard'
           LEFT JOIN {learnguard_attempts} a ON a.learnguard = g.learnguard AND a.state = 'finished'
           LEFT JOIN {learnguard_essay_grades} eg ON eg.attemptid = a.id AND eg.graderid = g.userid
          WHERE 1=1
       GROUP BY g.id, g.learnguard, g.userid, g.gradelayer, g.anonymous, g.deadline,
                u.firstname, u.lastname, u.email, lg.name, c.fullname, cm.id
       ORDER BY c.fullname, lg.name, u.lastname, u.firstname", []
    );

    if (empty($allGraders)) {
        echo html_writer::div('No graders assigned yet across any assessment.', 'alert alert-info');
    } else {
        // Hidden bulk-remove form (JS populates graderids[] before submit).
        echo '<form id="lg-bulk-ov-form" method="post" action="" style="display:none;">' .
             '<input type="hidden" name="sesskey" value="' . sesskey() . '">' .
             '<input type="hidden" name="postaction" value="bulkremovesitegraders">' .
             '</form>';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">' .
             '<input type="checkbox" id="lg-ov-all" title="Select all" onchange="' .
             "document.querySelectorAll('.lg-ov-cb').forEach(c=>c.checked=this.checked);" . '">' .
             '<label for="lg-ov-all" style="font-size:13px;margin:0;cursor:pointer;">Select All</label>' .
             '<button type="button" class="btn btn-sm btn-outline-danger" onclick="lgBulkOvRemove()">' .
             '× Remove Selected</button></div>';
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'margin-bottom:8px;font-size:13px;']);
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', '☐', ['style' => 'padding:6px 8px;width:30px;text-align:center;']);
        foreach (['Grader', 'Assessment', 'Layer', 'Anon', 'Deadline', 'Total', 'Graded', 'Left', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:6px 8px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($allGraders as $g) {
            $left      = max(0, (int)$g->totalattempts - (int)$g->graded);
            $ddlStr    = ($g->deadline > 0) ? userdate($g->deadline, '%d/%m/%Y') : '—';
            $isOvEdit  = ($editOvId === (int)$g->gid);
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td',
                '<input type="checkbox" class="lg-ov-cb" value="' . (int)$g->gid . '">',
                ['style' => 'text-align:center;vertical-align:middle;']);
            echo html_writer::tag('td', html_writer::tag('strong', fullname($g)) .
                html_writer::tag('div', s($g->email), ['style' => 'font-size:11px;color:#6c757d;']));
            echo html_writer::tag('td',
                html_writer::tag('div', format_string($g->assessmentname)) .
                html_writer::tag('div', format_string($g->coursename), ['style' => 'font-size:11px;color:#6c757d;']));
            echo html_writer::tag('td', 'Layer ' . (int)$g->gradelayer, ['style' => 'text-align:center;']);
            echo html_writer::tag('td', $g->anonymous ? '🙈' : '👁', ['style' => 'text-align:center;']);
            echo html_writer::tag('td', $ddlStr);
            echo html_writer::tag('td', (int)$g->totalattempts, ['style' => 'text-align:center;']);
            echo html_writer::tag('td', (int)$g->graded, ['style' => 'text-align:center;']);
            echo html_writer::tag('td', $left, ['style' => 'text-align:center;font-weight:' . ($left > 0 ? '600' : 'normal') . ';']);
            echo html_writer::start_tag('td');
            echo '<div style="display:flex;gap:4px;flex-wrap:nowrap;align-items:center;">';
            // Grade link.
            echo html_writer::link(
                new moodle_url('/mod/learnguard/grader.php', ['id' => $g->cmid]),
                '✍️ Grade', ['class' => 'btn btn-sm btn-outline-success']
            );
            // Edit toggle.
            $ovEditUrl = new moodle_url('/mod/learnguard/siteadmin.php',
                ['tab' => 'graders', 'editovid' => $isOvEdit ? 0 : $g->gid]);
            echo html_writer::link($ovEditUrl, $isOvEdit ? '✕ Cancel' : '✏ Edit',
                ['class' => 'btn btn-sm btn-outline-secondary']);
            // Remove form — display:contents so the button joins the flex row directly.
            echo '<form method="post" style="display:contents;">';
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'removesitegrader']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid',   'value' => $g->gid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $g->learnguard]);
            echo '<button type="submit" class="btn btn-sm btn-outline-danger"'
                . ' onclick="return confirm(\'Remove ' . s(fullname($g)) . ' from this assessment?\')">× Remove</button>';
            echo '</form>';
            echo '</div>';
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
            // Inline edit row for overview.
            if ($isOvEdit) {
                $ddlOvIso = ($g->deadline > 0) ? date('Y-m-d', $g->deadline) : '';
                echo html_writer::start_tag('tr', ['style' => 'background:#f0f4ff;']);
                echo html_writer::start_tag('td', ['colspan' => '10', 'style' => 'padding:10px 14px;']);
                echo html_writer::start_tag('form', ['method' => 'post',
                    'style' => 'display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'editoverviewgrader']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid',   'value' => $g->gid]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $g->learnguard]);
                // Layer.
                echo html_writer::start_div('', ['style' => 'display:flex;flex-direction:column;gap:4px;']);
                echo html_writer::tag('label', 'Layer', ['style' => 'font-size:12px;font-weight:600;']);
                echo html_writer::start_tag('select', ['name' => 'gradelayer', 'class' => 'form-control', 'style' => 'width:110px;']);
                echo html_writer::tag('option', 'Layer 1', ['value' => '1', 'selected' => ($g->gradelayer == 1 ? 'selected' : null)]);
                echo html_writer::tag('option', 'Layer 2', ['value' => '2', 'selected' => ($g->gradelayer == 2 ? 'selected' : null)]);
                echo html_writer::end_tag('select');
                echo html_writer::end_div();
                // Deadline.
                echo html_writer::start_div('', ['style' => 'display:flex;flex-direction:column;gap:4px;']);
                echo html_writer::tag('label', 'Deadline', ['style' => 'font-size:12px;font-weight:600;']);
                echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'grader_deadline',
                    'class' => 'form-control', 'style' => 'width:160px;', 'value' => $ddlOvIso]);
                echo html_writer::end_div();
                // Anonymous.
                $anonOvAttr = ['type' => 'checkbox', 'name' => 'grader_anonymous', 'value' => '1', 'id' => 'ov_anon_' . $g->gid];
                if ($g->anonymous) { $anonOvAttr['checked'] = 'checked'; }
                echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;padding-bottom:2px;']);
                echo html_writer::empty_tag('input', $anonOvAttr);
                echo html_writer::tag('label', '🙈 Anonymous', ['for' => 'ov_anon_' . $g->gid, 'style' => 'font-size:13px;margin:0;']);
                echo html_writer::end_div();
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '✔ Save Changes', 'class' => 'btn btn-primary btn-sm']);
                echo html_writer::end_tag('form');
                echo html_writer::end_tag('td');
                echo html_writer::end_tag('tr');
            }
        }
        echo html_writer::end_tag('table');
    }
    echo html_writer::end_div();

    // ── Global Graders section ────────────────────────────────────────────
    echo html_writer::start_div('', ['style' =>
        'border:2px solid #6f42c1;border-radius:10px;padding:20px;margin-bottom:28px;background:#f9f4ff;']);
    echo html_writer::tag('h4', '🌍 Global Graders', ['style' => 'color:#6f42c1;margin-bottom:4px;']);
    echo html_writer::tag('p',
        'Global graders can access the grading interface for ALL LearnGuard assessments across the site. ' .
        'Add sparingly — a global grader sees every student\'s essay responses site-wide.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:14px;']);

    // List existing global graders.
    $globalGraders = [];
    try {
        $globalGraders = $DB->get_records_sql(
            "SELECT g.id AS gid, g.userid, g.anonymous, u.firstname, u.lastname, u.email
               FROM {learnguard_graders} g
               JOIN {user} u ON u.id = g.userid
              WHERE g.is_global = 1 AND g.courseid = 0
           ORDER BY u.lastname, u.firstname"
        );
    } catch (Exception $e) {
        echo $OUTPUT->notification('⚠️ Upgrade required: run Moodle database upgrade to add global grader columns.', 'warning');
    }

    if (empty($globalGraders)) {
        echo html_writer::div('No global graders assigned.', 'alert alert-info');
    } else {
        echo '<form id="lg-bulk-glb-form" method="post" action="" style="display:none;">' .
             '<input type="hidden" name="sesskey" value="' . sesskey() . '">' .
             '<input type="hidden" name="postaction" value="bulkremoveglobalgraders">' .
             '</form>';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">' .
             '<input type="checkbox" id="lg-glb-all" onchange="' .
             "document.querySelectorAll('.lg-glb-cb').forEach(c=>c.checked=this.checked);" . '">' .
             '<label for="lg-glb-all" style="font-size:13px;margin:0;cursor:pointer;">Select All</label>' .
             '<button type="button" class="btn btn-sm btn-outline-danger" onclick="lgBulkGlbRemove()">' .
             '× Remove Selected</button></div>';
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100 mb-3', 'style' => 'font-size:13px;']);
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', '☐', ['style' => 'padding:8px 10px;width:30px;text-align:center;']);
        foreach (['Grader', 'Email', 'Anonymous', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($globalGraders as $g) {
            $isGlbEdit = ($editGlbId === (int)$g->gid);
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td',
                '<input type="checkbox" class="lg-glb-cb" value="' . (int)$g->gid . '">',
                ['style' => 'text-align:center;vertical-align:middle;']);
            echo html_writer::tag('td', html_writer::tag('strong', fullname($g)));
            echo html_writer::tag('td', s($g->email));
            echo html_writer::tag('td', $g->anonymous ? '🙈 Yes' : '👁 No');
            echo html_writer::start_tag('td');
            echo '<div style="display:flex;gap:4px;flex-wrap:nowrap;align-items:center;">';
            // Edit toggle.
            $glbEditUrl = new moodle_url('/mod/learnguard/siteadmin.php',
                ['tab' => 'graders', 'editglbid' => $isGlbEdit ? 0 : $g->gid]);
            echo html_writer::link($glbEditUrl, $isGlbEdit ? '✕ Cancel' : '✏ Edit',
                ['class' => 'btn btn-sm btn-outline-secondary']);
            // Remove form.
            echo '<form method="post" style="display:contents;">';
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'removeglobalgrader']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid',   'value' => $g->gid]);
            echo '<button type="submit" class="btn btn-sm btn-outline-danger"'
                . ' onclick="return confirm(\'Remove ' . s(fullname($g)) . ' as global grader?\')">× Remove</button>';
            echo '</form>';
            echo '</div>';
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
            // Inline edit row.
            if ($isGlbEdit) {
                echo html_writer::start_tag('tr', ['style' => 'background:#f4eeff;']);
                echo html_writer::start_tag('td', ['colspan' => '5', 'style' => 'padding:10px 14px;']);
                echo html_writer::start_tag('form', ['method' => 'post',
                    'style' => 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'editglobalgrader']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid',   'value' => $g->gid]);
                $anonGlbAttr = ['type' => 'checkbox', 'name' => 'grader_anonymous', 'value' => '1', 'id' => 'glb_anon_' . $g->gid];
                if ($g->anonymous) { $anonGlbAttr['checked'] = 'checked'; }
                echo html_writer::empty_tag('input', $anonGlbAttr);
                echo html_writer::tag('label', '🙈 Anonymous', ['for' => 'glb_anon_' . $g->gid, 'style' => 'margin:0;font-size:13px;']);
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '✔ Save', 'class' => 'btn btn-primary btn-sm']);
                echo html_writer::end_tag('form');
                echo html_writer::end_tag('td');
                echo html_writer::end_tag('tr');
            }
        }
        echo html_writer::end_tag('table');
    }

    // Add global grader form.
    echo html_writer::tag('h5', 'Assign a Global Grader', ['class' => 'mt-2 mb-2']);
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addglobalgrader']);
    echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
         '<label style="font-size:12px;font-weight:600;">Email / Username / ID</label>' .
         html_writer::empty_tag('input', ['type' => 'text', 'name' => 'usersearch',
             'class' => 'form-control', 'style' => 'max-width:260px;', 'required' => 'required',
             'placeholder' => 'Email, username or user ID']) . '</div>';
    echo '<div style="display:flex;gap:6px;align-items:center;padding-bottom:4px;">' .
         html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'grader_anonymous', 'value' => '1', 'id' => 'lg-ganon']) .
         '<label for="lg-ganon" style="font-size:13px;margin-bottom:0;">Anonymous (hide student names)</label></div>';
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Add Global Grader', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    // ── Section 2: Assign grader — course + assessment filter ────────────
    echo html_writer::tag('h4', '📋 Assign Grader to Assessment', ['class' => 'mb-2']);
    echo html_writer::tag('p',
        'Select a course to filter the assessment list, then choose an assessment and add a grader below.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:14px;']);

    // Course filter form.
    $allCoursesWithLg = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname FROM {course} c
           JOIN {learnguard} lg ON lg.course = c.id
          WHERE c.id > 1 ORDER BY c.fullname", []
    );
    echo html_writer::start_tag('form', ['method' => 'get', 'style' =>
        'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'graders']);
    echo html_writer::start_tag('select', ['name' => 'courseid', 'class' => 'form-control', 'style' => 'max-width:300px;',
        'onchange' => 'this.form.submit()']);
    echo html_writer::tag('option', '— All Courses —', ['value' => '0', 'selected' => ($filterCourse === 0) ? 'selected' : null]);
    foreach ($allCoursesWithLg as $c) {
        echo html_writer::tag('option', format_string($c->fullname),
            ['value' => $c->id, 'selected' => ($filterCourse === (int)$c->id) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_tag('form');

    // Assessment picker (filtered by course if set).
    $asmtSql = "SELECT lg.id, lg.name, c.fullname AS coursename
                  FROM {learnguard} lg
                  JOIN {course} c ON c.id = lg.course
                 WHERE c.id > 1" . ($filterCourse > 0 ? ' AND c.id = :cid' : '') . "
              ORDER BY c.fullname, lg.name";
    $asmtParams = ($filterCourse > 0) ? ['cid' => $filterCourse] : [];
    $allAssessmentsG = $DB->get_records_sql($asmtSql, $asmtParams);

    echo html_writer::start_tag('form', ['method' => 'get', 'style' =>
        'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:20px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',      'value' => 'graders']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $filterCourse]);
    echo html_writer::start_tag('select', ['name' => 'lgid', 'class' => 'form-control', 'style' => 'max-width:420px;',
        'onchange' => 'this.form.submit()']);
    echo html_writer::tag('option', '— Choose an assessment —', ['value' => '0']);
    foreach ($allAssessmentsG as $a) {
        $label = ($filterCourse === 0)
            ? '[' . format_string($a->coursename) . '] ' . format_string($a->name)
            : format_string($a->name);
        echo html_writer::tag('option', $label,
            ['value' => $a->id, 'selected' => ($selectedLgid === (int)$a->id) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'View', 'class' => 'btn btn-outline-secondary']);
    echo html_writer::end_tag('form');

    if ($selectedLgid > 0) {
        $selInstance = $DB->get_record('learnguard', ['id' => $selectedLgid]);
        $selCm       = get_coursemodule_from_instance('learnguard', $selectedLgid, 0, false, MUST_EXIST);

        echo html_writer::start_div('', ['style' =>
            'border:1px solid #dee2e6;border-radius:8px;padding:20px;background:#fff;']);
        echo html_writer::tag('h5', format_string($selInstance->name),
            ['style' => 'border-left:4px solid ' . $brand->primary . ';padding-left:10px;margin-bottom:14px;']);

        // Current graders for this assessment.
        $asmtGraders = $DB->get_records_sql(
            "SELECT g.id AS gid, g.userid, g.gradelayer, g.anonymous,
                    COALESCE(g.deadline, 0) AS deadline,
                    u.firstname, u.lastname, u.email,
                    COUNT(DISTINCT eg.attemptid) AS graded,
                    (SELECT COUNT(*) FROM {learnguard_attempts} a2
                      WHERE a2.learnguard = g.learnguard AND a2.state = 'finished') AS totalattempts
               FROM {learnguard_graders} g
               JOIN {user} u ON u.id = g.userid
               LEFT JOIN {learnguard_essay_grades} eg ON eg.graderid = g.userid
                    AND eg.attemptid IN (SELECT id FROM {learnguard_attempts}
                                         WHERE learnguard = g.learnguard AND state = 'finished')
              WHERE g.learnguard = :lgid
           GROUP BY g.id, g.userid, g.gradelayer, g.anonymous, g.deadline,
                    u.firstname, u.lastname, u.email, g.learnguard
           ORDER BY g.gradelayer, u.lastname",
            ['lgid' => $selectedLgid]
        );

        if (empty($asmtGraders)) {
            echo html_writer::div('No graders assigned to this assessment yet.', 'alert alert-info');
        } else {
            echo '<form id="lg-bulk-asmt-form" method="post" action="" style="display:none;">' .
                 '<input type="hidden" name="sesskey" value="' . sesskey() . '">' .
                 '<input type="hidden" name="postaction" value="bulkremovesitegraders">' .
                 '<input type="hidden" name="lgid" value="' . $selectedLgid . '">' .
                 '</form>';
            echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">' .
                 '<input type="checkbox" id="lg-asmt-all" onchange="' .
                 "document.querySelectorAll('.lg-asmt-cb').forEach(c=>c.checked=this.checked);" . '">' .
                 '<label for="lg-asmt-all" style="font-size:13px;margin:0;cursor:pointer;">Select All</label>' .
                 '<button type="button" class="btn btn-sm btn-outline-danger" onclick="lgBulkAsmtRemove()">' .
                 '× Remove Selected</button></div>';
            echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'margin-bottom:14px;font-size:13px;']);
            echo html_writer::start_tag('tr');
            echo html_writer::tag('th', '☐', ['style' => 'padding:6px 8px;width:30px;text-align:center;']);
            foreach (['Grader', 'Layer', 'Anon', 'Deadline', 'Graded', 'Left', 'Actions'] as $h) {
                echo html_writer::tag('th', $h, ['style' => 'padding:6px 8px;']);
            }
            echo html_writer::end_tag('tr');
            foreach ($asmtGraders as $g) {
                $left   = max(0, (int)$g->totalattempts - (int)$g->graded);
                $ddlStr = ($g->deadline > 0) ? userdate($g->deadline, '%d/%m/%Y') : '—';
                $isEditing = ($editGid === (int)$g->gid);
                echo html_writer::start_tag('tr');
                echo html_writer::tag('td',
                    '<input type="checkbox" class="lg-asmt-cb" value="' . (int)$g->gid . '">',
                    ['style' => 'text-align:center;vertical-align:middle;']);
                echo html_writer::tag('td', fullname($g) . html_writer::tag('div', s($g->email), ['style' => 'font-size:11px;color:#6c757d;']));
                echo html_writer::tag('td', 'Layer ' . (int)$g->gradelayer, ['style' => 'text-align:center;']);
                echo html_writer::tag('td', $g->anonymous ? '🙈' : '👁', ['style' => 'text-align:center;']);
                echo html_writer::tag('td', $ddlStr);
                echo html_writer::tag('td', (int)$g->graded, ['style' => 'text-align:center;']);
                echo html_writer::tag('td', $left, ['style' => 'text-align:center;font-weight:' . ($left > 0 ? '600' : 'normal') . ';']);
                echo html_writer::start_tag('td');
                echo '<div style="display:flex;gap:4px;flex-wrap:nowrap;align-items:center;">';
                // Edit button.
                $editUrl = new moodle_url('/mod/learnguard/siteadmin.php',
                    ['tab' => 'graders', 'lgid' => $selectedLgid, 'editgid' => $isEditing ? 0 : $g->gid]);
                echo html_writer::link($editUrl, $isEditing ? '✕ Cancel' : '✏ Edit',
                    ['class' => 'btn btn-sm btn-outline-secondary']);
                // Remove form.
                echo '<form method="post" style="display:contents;">';
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'removesitegrader']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid',   'value' => $g->gid]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $selectedLgid]);
                echo '<button type="submit" class="btn btn-sm btn-outline-danger"'
                    . ' onclick="return confirm(\'Remove ' . s(fullname($g)) . '?\')">× Remove</button>';
                echo '</form>';
                echo '</div>';
                echo html_writer::end_tag('td');
                echo html_writer::end_tag('tr');
                // Inline edit row.
                if ($isEditing) {
                    $ddlIso = ($g->deadline > 0) ? date('Y-m-d', $g->deadline) : '';
                    echo html_writer::start_tag('tr', ['style' => 'background:#f0f4ff;']);
                    echo html_writer::start_tag('td', ['colspan' => '8', 'style' => 'padding:12px 16px;']);
                    echo html_writer::start_tag('form', ['method' => 'post',
                        'style' => 'display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'editsitegrader']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid',   'value' => $g->gid]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $selectedLgid]);
                    // Layer.
                    echo html_writer::start_div('', ['style' => 'display:flex;flex-direction:column;gap:4px;']);
                    echo html_writer::tag('label', 'Layer', ['style' => 'font-size:12px;font-weight:600;']);
                    echo html_writer::start_tag('select', ['name' => 'gradelayer', 'class' => 'form-control', 'style' => 'width:110px;']);
                    echo html_writer::tag('option', 'Layer 1', ['value' => '1', 'selected' => ($g->gradelayer == 1 ? 'selected' : null)]);
                    echo html_writer::tag('option', 'Layer 2', ['value' => '2', 'selected' => ($g->gradelayer == 2 ? 'selected' : null)]);
                    echo html_writer::end_tag('select');
                    echo html_writer::end_div();
                    // Deadline.
                    echo html_writer::start_div('', ['style' => 'display:flex;flex-direction:column;gap:4px;']);
                    echo html_writer::tag('label', 'Deadline', ['style' => 'font-size:12px;font-weight:600;']);
                    echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'grader_deadline',
                        'class' => 'form-control', 'style' => 'width:160px;', 'value' => $ddlIso]);
                    echo html_writer::end_div();
                    // Anonymous.
                    echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;padding-bottom:2px;']);
                    $anonChecked = $g->anonymous ? ['type' => 'checkbox', 'name' => 'grader_anonymous', 'value' => '1', 'id' => 'edit_anon_' . $g->gid, 'checked' => 'checked']
                                                 : ['type' => 'checkbox', 'name' => 'grader_anonymous', 'value' => '1', 'id' => 'edit_anon_' . $g->gid];
                    echo html_writer::empty_tag('input', $anonChecked);
                    echo html_writer::tag('label', '🙈 Anonymous', ['for' => 'edit_anon_' . $g->gid, 'style' => 'font-size:13px;margin:0;']);
                    echo html_writer::end_div();
                    // Save button.
                    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '✔ Save Changes',
                        'class' => 'btn btn-primary btn-sm']);
                    echo html_writer::end_tag('form');
                    echo html_writer::end_tag('td');
                    echo html_writer::end_tag('tr');
                }
            }
            echo html_writer::end_tag('table');
        }

        // Add grader form — supports multi-assessment assignment + auto-enrollment.
        echo html_writer::tag('h6', 'Add Grader to this Assessment', ['style' => 'margin-top:20px;margin-bottom:8px;']);
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addsitegrader_multi']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $selectedLgid]);
        echo html_writer::start_div('', ['style' => 'display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;']);
        // User field.
        echo html_writer::start_div('', ['style' => 'display:flex;flex-direction:column;gap:4px;']);
        echo html_writer::tag('label', 'User (email / username)', ['style' => 'font-size:12px;font-weight:600;']);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'usersearch', 'class' => 'form-control',
            'style' => 'min-width:220px;', 'placeholder' => 'Email or username', 'required' => 'required']);
        echo html_writer::end_div();
        // Layer.
        echo html_writer::start_div('', ['style' => 'display:flex;flex-direction:column;gap:4px;']);
        echo html_writer::tag('label', 'Layer', ['style' => 'font-size:12px;font-weight:600;']);
        echo html_writer::start_tag('select', ['name' => 'gradelayer', 'class' => 'form-control', 'style' => 'width:110px;']);
        echo html_writer::tag('option', 'Layer 1', ['value' => '1']);
        echo html_writer::tag('option', 'Layer 2', ['value' => '2']);
        echo html_writer::end_tag('select');
        echo html_writer::end_div();
        // Deadline.
        echo html_writer::start_div('', ['style' => 'display:flex;flex-direction:column;gap:4px;']);
        echo html_writer::tag('label', 'Deadline (optional)', ['style' => 'font-size:12px;font-weight:600;']);
        echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'grader_deadline',
            'class' => 'form-control', 'style' => 'width:160px;']);
        echo html_writer::end_div();
        // Anonymous checkbox.
        echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;padding-bottom:2px;']);
        echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'grader_anonymous', 'value' => '1', 'id' => 'sadmin_anon']);
        echo html_writer::tag('label', '🙈 Anonymous', ['for' => 'sadmin_anon', 'style' => 'font-size:13px;margin:0;']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        // Multi-assessment checkboxes (other LearnGuard activities in the same course).
        $otherLgRows = $DB->get_records_sql(
            "SELECT lg.id AS lgid, lg.name, cm.id AS cmid
               FROM {learnguard} lg
               JOIN {course_modules} cm ON cm.instance = lg.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'learnguard'
              WHERE lg.course = :courseid AND lg.id != :currid
              ORDER BY lg.name",
            ['courseid' => $selInstance->course, 'currid' => $selectedLgid]
        );
        if (!empty($otherLgRows)) {
            echo html_writer::start_div('', ['style' =>
                'margin-top:10px;padding:10px 12px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;']);
            echo html_writer::tag('div', 'Also assign to other assessments in this course:',
                ['style' => 'font-size:12px;font-weight:600;margin-bottom:6px;']);
            // Current assessment (always included — shown disabled).
            echo html_writer::start_div('', ['style' => 'display:flex;gap:16px;flex-wrap:wrap;']);
            echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;opacity:.5;']);
            echo html_writer::empty_tag('input', ['type' => 'checkbox', 'disabled' => 'disabled', 'checked' => 'checked']);
            echo html_writer::tag('span', '✔ ' . s(format_string($selInstance->name)) . ' (current)',
                ['style' => 'font-size:12px;']);
            echo html_writer::end_div();
            foreach ($otherLgRows as $row) {
                $cbId = 'extra_lg_' . (int)$row->lgid;
                echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;']);
                echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'extra_lgids[]',
                    'value' => (int)$row->lgid, 'id' => $cbId]);
                echo html_writer::tag('label', s(format_string($row->name)), ['for' => $cbId, 'style' => 'font-size:12px;margin:0;']);
                echo html_writer::end_div();
            }
            echo html_writer::end_div();
            echo html_writer::end_div();
        }
        echo html_writer::start_div('', ['style' => 'margin-top:10px;']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Assign Grader (& Grant Course Access)',
            'class' => 'btn btn-success']);
        echo html_writer::end_div();
        echo html_writer::end_tag('form');

        // ── Distribution & Status Section ────────────────────────────────────────
        $distGraders = $DB->get_records_sql(
            "SELECT g.id AS gid, g.userid, g.gradelayer, COALESCE(g.deadline, 0) AS deadline,
                    u.firstname, u.lastname, u.email,
                    COUNT(DISTINCT ga.attemptid) AS assigned
               FROM {learnguard_graders} g
               JOIN {user} u ON u.id = g.userid
               LEFT JOIN {learnguard_grader_assignments} ga
                      ON ga.graderid = g.userid AND ga.learnguard = :lgid1
              WHERE g.learnguard = :lgid2
           GROUP BY g.id, g.userid, g.gradelayer, g.deadline, u.firstname, u.lastname, u.email
           ORDER BY u.lastname, u.firstname",
            ['lgid1' => $selectedLgid, 'lgid2' => $selectedLgid]
        );
        $totalFinished  = $DB->count_records('learnguard_attempts', ['learnguard' => $selectedLgid, 'state' => 'finished']);
        $totalUngraded  = (int)$DB->count_records_sql(
            "SELECT COUNT(*) FROM {learnguard_attempts} WHERE learnguard = :lg AND state = 'finished' AND sumgrades IS NULL",
            ['lg' => $selectedLgid]
        );
        $totalAssigned  = $DB->count_records('learnguard_grader_assignments', ['learnguard' => $selectedLgid]);
        $hasDistrib     = ($totalAssigned > 0);
        $layer1Graders  = array_filter((array)$distGraders, fn($g) => (int)$g->gradelayer === 1);

        if (!empty($distGraders)) {
            // Fetch graded-per-grader counts (only relevant when distribution is active).
            $gradedMap = [];
            if ($hasDistrib) {
                $gRows = $DB->get_records_sql(
                    "SELECT eg.graderid, COUNT(DISTINCT eg.attemptid) AS cnt
                       FROM {learnguard_essay_grades} eg
                       JOIN {learnguard_grader_assignments} ga
                            ON ga.attemptid = eg.attemptid
                           AND ga.learnguard = :lg
                           AND ga.graderid = eg.graderid
                   GROUP BY eg.graderid",
                    ['lg' => $selectedLgid]
                );
                foreach ($gRows as $row) {
                    $gradedMap[(int)$row->graderid] = (int)$row->cnt;
                }
            }

            echo html_writer::start_div('', ['style' =>
                'margin-top:24px;padding:16px 18px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;']);
            echo html_writer::tag('h5', '📊 Grader Distribution & Status',
                ['style' => 'font-weight:700;margin-bottom:4px;']);
            $statusLine = 'Total finished: <strong>' . $totalFinished . '</strong>' .
                          ' &nbsp;|&nbsp; Ungraded: <strong style="color:' . ($totalUngraded > 0 ? '#dc3545' : '#198754') . ';">' .
                          $totalUngraded . '</strong>';
            if ($hasDistrib) {
                $statusLine .= ' &nbsp;|&nbsp; Assigned: <strong>' . $totalAssigned . '</strong>';
            } else {
                $statusLine .= ' &nbsp;— Distribution targets ungraded attempts only.';
            }
            echo html_writer::tag('p', $statusLine,
                ['style' => 'font-size:13px;color:#495057;margin-bottom:12px;']);

            // ── Status table ──────────────────────────────────────────────────────
            echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered mb-2',
                'style' => 'font-size:13px;background:#fff;']);
            echo html_writer::start_tag('thead', ['class' => 'table-dark']);
            echo html_writer::start_tag('tr');
            foreach (['Grader', 'Layer', 'Assigned', 'Graded', 'Remaining', 'Deadline', 'Action'] as $col) {
                echo html_writer::tag('th', $col, ['style' => 'padding:6px 10px;font-size:12px;white-space:nowrap;']);
            }
            echo html_writer::end_tag('tr');
            echo html_writer::end_tag('thead');
            echo html_writer::start_tag('tbody');
            foreach ($distGraders as $dg) {
                $uid       = (int)$dg->userid;
                $graded    = $gradedMap[$uid] ?? 0;
                $asgn      = (int)$dg->assigned;
                $remaining = max(0, $asgn - $graded);
                $dlLabel   = $dg->deadline > 0 ? userdate($dg->deadline, '%d %b %Y') : '—';
                $layerBg   = (int)$dg->gradelayer === 2 ? '#6f42c1' : '#0d6efd';
                $layerPill = '<span style="background:' . $layerBg . ';color:#fff;padding:1px 7px;border-radius:4px;font-size:11px;">L' . (int)$dg->gradelayer . '</span>';

                if (!$hasDistrib) {
                    $asgnCell = '<em style="color:#6c757d;font-size:12px;">all</em>';
                    $remCell  = '—';
                    $remStyle = '';
                } else {
                    $asgnCell = (string)$asgn;
                    $remStyle = $remaining > 0 ? 'color:#dc3545;font-weight:600;' : 'color:#198754;';
                    $remCell  = '<span style="' . $remStyle . '">' . $remaining . '</span>';
                }

                $nudgeForm =
                    '<form method="post" action="" style="margin:0;">' .
                    '<input type="hidden" name="sesskey" value="' . sesskey() . '">' .
                    '<input type="hidden" name="postaction" value="nudgesitegrader">' .
                    '<input type="hidden" name="lgid" value="' . $selectedLgid . '">' .
                    '<input type="hidden" name="graderid" value="' . (int)$dg->gid . '">' .
                    '<button type="submit" class="btn btn-sm btn-outline-secondary py-0" title="Send reminder email to ' .
                    s(fullname($dg)) . '">📧 Nudge</button>' .
                    '</form>';

                echo html_writer::start_tag('tr');
                echo html_writer::tag('td', s(fullname($dg)), ['style' => 'white-space:nowrap;']);
                echo html_writer::tag('td', $layerPill, ['style' => 'text-align:center;']);
                echo html_writer::tag('td', $asgnCell, ['style' => 'text-align:center;']);
                echo html_writer::tag('td', (string)$graded, ['style' => 'text-align:center;']);
                echo html_writer::tag('td', $remCell, ['style' => 'text-align:center;']);
                echo html_writer::tag('td', $dlLabel, ['style' => 'white-space:nowrap;']);
                echo html_writer::tag('td', $nudgeForm);
                echo html_writer::end_tag('tr');
            }
            echo html_writer::end_tag('tbody');
            echo html_writer::end_tag('table');

            // ── Distribution form (Layer-1 graders only) ──────────────────────────
            if (!empty($layer1Graders)) {
                echo html_writer::start_tag('form', ['method' => 'post', 'action' => '',
                    'id' => 'lg-distrib-form']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'distribsitegrader']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid', 'value' => $selectedLgid]);

                echo html_writer::tag('div',
                    'Distribute <strong>' . $totalUngraded . ' ungraded</strong> attempt(s) among ' . count($layer1Graders) . ' Layer-1 grader(s):',
                    ['style' => 'font-weight:600;font-size:13px;margin-bottom:8px;']);

                $lgDistId = 'distmode_' . $selectedLgid;
                $toggleJs = "document.getElementById('lg-dist-custom').style.display=" .
                            "this.value==='custom'?'block':'none';";
                echo html_writer::start_div('', ['style' => 'display:flex;gap:16px;align-items:center;margin-bottom:8px;flex-wrap:wrap;']);
                echo '<input type="radio" name="distmode" value="equal" id="' . $lgDistId . '_eq" checked ' .
                     'onchange="' . $toggleJs . '" style="margin-right:4px;">' .
                     '<label for="' . $lgDistId . '_eq" style="font-size:13px;margin-right:14px;cursor:pointer;">⚖️ Distribute Equally (round-robin)</label>';
                echo '<input type="radio" name="distmode" value="custom" id="' . $lgDistId . '_cu" ' .
                     'onchange="' . $toggleJs . '" style="margin-right:4px;">' .
                     '<label for="' . $lgDistId . '_cu" style="font-size:13px;cursor:pointer;">🔢 Custom count per grader</label>';
                echo html_writer::end_div();

                echo html_writer::start_div('', ['id' => 'lg-dist-custom',
                    'style' => 'display:none;background:#fff;border:1px solid #ced4da;border-radius:6px;padding:10px 14px;margin-bottom:8px;']);
                echo html_writer::tag('p',
                    'Specify how many attempts to assign each grader (randomly sampled from the pool):',
                    ['style' => 'font-size:12px;color:#6c757d;margin-bottom:8px;']);
                foreach ($layer1Graders as $dg) {
                    echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:8px;margin-bottom:4px;']);
                    echo html_writer::tag('span', s(fullname($dg)), ['style' => 'font-size:13px;min-width:160px;']);
                    echo html_writer::empty_tag('input', [
                        'type'  => 'number', 'name'  => 'dist_custom[' . (int)$dg->userid . ']',
                        'min'   => '0', 'max'   => (string)$totalUngraded, 'value' => '0',
                        'class' => 'form-control form-control-sm', 'style' => 'width:80px;',
                    ]);
                    echo html_writer::end_div();
                }
                echo html_writer::end_div();

                echo html_writer::start_div('', ['style' => 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;']);
                echo html_writer::tag('button', '▶ Run Distribution',
                    ['type' => 'submit', 'class' => 'btn btn-primary btn-sm',
                     'onclick' => "return confirm('This will replace any existing assignments for this assessment. Continue?');",
                    ]);
                if ($hasDistrib) {
                    echo '<form method="post" action="" style="margin:0;">' .
                         '<input type="hidden" name="sesskey" value="' . sesskey() . '">' .
                         '<input type="hidden" name="postaction" value="cleardistrib">' .
                         '<input type="hidden" name="lgid" value="' . $selectedLgid . '">' .
                         '<button type="submit" class="btn btn-sm btn-outline-danger" ' .
                         'onclick="return confirm(\'Remove all assignments? Graders will see all finished attempts again.\');">' .
                         '🗑 Clear All Assignments</button></form>';
                }
                echo html_writer::end_div();
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_div();
        }

        // Quick link to full per-activity grader management.
        echo html_writer::start_div('', ['style' => 'margin-top:16px;']);
        echo html_writer::link(
            new moodle_url('/mod/learnguard/admin.php', ['id' => $selCm->id, 'tab' => 'graders']),
            '✍️ Full Grader Management for this Assessment →',
            ['class' => 'btn btn-outline-primary btn-sm']
        );
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

if ($tab === 'scoreaccess') {
    echo html_writer::tag('h4', 'Score Matrix Access', ['class' => 'mb-3']);
    echo html_writer::tag('p',
        'An independent grant — not tied to being a Proctor or Grader. Someone with this ' .
        'can view the Score Matrix (read-only: no void/delete there) either for every course, ' .
        'or just the course(s) you assign below.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:18px;']);

    // ── Global Score Viewers ────────────────────────────────────────────────
    echo html_writer::start_div('', ['style' =>
        'border:2px solid #0f6cbf;border-radius:10px;padding:20px;margin-bottom:28px;background:#f0f7ff;']);
    echo html_writer::tag('h4', '🌍 Global Score Matrix Access', ['style' => 'color:#0f6cbf;margin-bottom:4px;']);
    echo html_writer::tag('p', 'Sees every course in the Score Matrix.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:14px;']);

    $globalViewers = $DB->get_records_sql(
        "SELECT sv.id AS svid, sv.userid, u.firstname, u.lastname, u.email
           FROM {learnguard_score_viewers} sv
           JOIN {user} u ON u.id = sv.userid
          WHERE sv.is_global = 1
       ORDER BY u.lastname, u.firstname");

    if (empty($globalViewers)) {
        echo html_writer::div('No global Score Matrix access granted.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100 mb-3', 'style' => 'font-size:13px;']);
        echo html_writer::start_tag('tr');
        foreach (['Name', 'Email', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($globalViewers as $v) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', fullname($v)));
            echo html_writer::tag('td', s($v->email));
            echo html_writer::start_tag('td');
            echo '<form method="post" style="display:inline;">';
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'removescoreviewer']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'viewerid',   'value' => $v->svid]);
            echo '<button type="submit" class="btn btn-sm btn-outline-danger"'
                . ' onclick="return confirm(\'Remove ' . s(fullname($v)) . '\\\'s Score Matrix access?\')">× Remove</button>';
            echo '</form>';
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }

    echo html_writer::tag('h5', 'Grant Global Access', ['class' => 'mt-2 mb-2']);
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addglobalscoreviewer']);
    echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
         '<label style="font-size:12px;font-weight:600;">Email / Username / ID</label>' .
         html_writer::empty_tag('input', ['type' => 'text', 'name' => 'usersearch',
             'class' => 'form-control', 'style' => 'max-width:260px;', 'required' => 'required',
             'placeholder' => 'Email, username or user ID']) . '</div>';
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Grant Global Access', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    // ── Per-Course Score Viewers ────────────────────────────────────────────
    echo html_writer::tag('h4', '📚 Per-Course Score Matrix Access', ['class' => 'mb-2']);
    echo html_writer::tag('p', 'Scoped to one course\'s assessments only.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:14px;']);

    $courseViewers = $DB->get_records_sql(
        "SELECT sv.id AS svid, sv.userid, sv.courseid, u.firstname, u.lastname, u.email, c.fullname AS coursename
           FROM {learnguard_score_viewers} sv
           JOIN {user} u ON u.id = sv.userid
           JOIN {course} c ON c.id = sv.courseid
          WHERE sv.is_global = 0
       ORDER BY c.fullname, u.lastname, u.firstname");

    if (empty($courseViewers)) {
        echo html_writer::div('No per-course Score Matrix access granted.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100 mb-3', 'style' => 'font-size:13px;']);
        echo html_writer::start_tag('tr');
        foreach (['Name', 'Email', 'Course', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($courseViewers as $v) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', fullname($v)));
            echo html_writer::tag('td', s($v->email));
            echo html_writer::tag('td', format_string($v->coursename));
            echo html_writer::start_tag('td');
            echo '<form method="post" style="display:inline;">';
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'removescoreviewer']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'viewerid',   'value' => $v->svid]);
            echo '<button type="submit" class="btn btn-sm btn-outline-danger"'
                . ' onclick="return confirm(\'Remove ' . s(fullname($v)) . '\\\'s access to that course?\')">× Remove</button>';
            echo '</form>';
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }

    echo html_writer::tag('h5', 'Grant Access to a Course', ['class' => 'mt-2 mb-2']);
    $svAllCourses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname FROM {course} c
           JOIN {learnguard} lg ON lg.course = c.id
          WHERE c.id > 1 ORDER BY c.fullname");
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addcoursescoreviewer']);
    echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
         '<label style="font-size:12px;font-weight:600;">Email / Username / ID</label>' .
         html_writer::empty_tag('input', ['type' => 'text', 'name' => 'usersearch',
             'class' => 'form-control', 'style' => 'max-width:260px;', 'required' => 'required',
             'placeholder' => 'Email, username or user ID']) . '</div>';
    echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
         '<label style="font-size:12px;font-weight:600;">Course</label>';
    echo html_writer::start_tag('select', ['name' => 'sv_courseid', 'class' => 'form-control', 'style' => 'max-width:280px;', 'required' => 'required']);
    foreach ($svAllCourses as $c) {
        echo html_writer::tag('option', format_string($c->fullname), ['value' => $c->id]);
    }
    echo html_writer::end_tag('select');
    echo '</div>';
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Grant Course Access', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');

    echo html_writer::start_div('alert alert-info mt-3');
    echo 'A LearnGuard-native link to Score Matrix: ';
    echo html_writer::link(new moodle_url('/mod/learnguard/scorematrix.php'), '🏆 Open Score Matrix',
        ['class' => 'btn btn-sm btn-primary']);
    echo html_writer::end_div();
}

// Bulk-action JS for grader sections (inlined so it's only emitted on the graders tab).
if ($tab === 'graders') {
    echo '<script>
function lgBulkSubmit(formId, cbClass, label) {
    var cbs = document.querySelectorAll("." + cbClass + ":checked");
    if (!cbs.length) { alert("Select at least one " + label + " first."); return; }
    if (!confirm("Remove " + cbs.length + " " + label + "(s)? This cannot be undone.")) return;
    var form = document.getElementById(formId);
    form.querySelectorAll("input[name=\'graderids[]\']").forEach(function(e) { e.remove(); });
    cbs.forEach(function(cb) {
        var h = document.createElement("input");
        h.type = "hidden"; h.name = "graderids[]"; h.value = cb.value;
        form.appendChild(h);
    });
    form.submit();
}
function lgBulkOvRemove()   { lgBulkSubmit("lg-bulk-ov-form",   "lg-ov-cb",   "grader"); }
function lgBulkGlbRemove()  { lgBulkSubmit("lg-bulk-glb-form",  "lg-glb-cb",  "global grader"); }
function lgBulkAsmtRemove() { lgBulkSubmit("lg-bulk-asmt-form", "lg-asmt-cb", "grader"); }
</script>';
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
    $filterBefore  = optional_param('filter_before',  '', PARAM_TEXT); // yyyy-mm-dd, snapshots OLDER than this.
    $filterAfter   = optional_param('filter_after',   '', PARAM_TEXT); // yyyy-mm-dd, snapshots NEWER than this.
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

    // Date range filter.
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'After date', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'date', 'name' => 'filter_after', 'class' => 'form-control', 'style' => 'max-width:160px;',
        'value' => s($filterAfter),
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Before date', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'date', 'name' => 'filter_before', 'class' => 'form-control', 'style' => 'max-width:160px;',
        'value' => s($filterBefore),
    ]);
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🔍 Filter', 'class' => 'btn btn-primary', 'style' => 'align-self:flex-end;']);
    if ($filterLgid || $filterStudent || $filterType || $filterBefore || $filterAfter) {
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
    // "After" = on or after that date; "Before" = strictly before that date.
    $afterTs = $filterAfter !== '' ? strtotime($filterAfter . ' 00:00:00') : false;
    if ($afterTs !== false) {
        $where .= ' AND s.timemodified >= :filter_after_ts';
        $params['filter_after_ts'] = $afterTs;
    }
    $beforeTs = $filterBefore !== '' ? strtotime($filterBefore . ' 00:00:00') : false;
    if ($beforeTs !== false) {
        $where .= ' AND s.timemodified < :filter_before_ts';
        $params['filter_before_ts'] = $beforeTs;
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
    // Do NOT select snapshot_url or its LENGTH() here — computing LENGTH() over a large
    // TEXT/LONGTEXT column in the main synchronous list query made the whole page take
    // minutes to load once the table grew large (the DB engine has to touch the actual
    // blob data to measure it, not just an index). Sizes are now fetched lazily via AJAX
    // after the page renders (see lgFetchMediaSizes() below) — same pattern storage.php
    // already uses for its SUM(LENGTH(...)) stats, for the same reason.
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
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'id' => 'lg-media-action-field', 'value' => 'deletemedia']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',        'value' => 'media']);
    // Single-item delete field — JS sets this and submits the form.
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'single_delete_id', 'id' => 'lg-single-del-id', 'value' => '']);

    echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;' .
         'background:#f8f9fa;padding:10px 14px;border-radius:8px;border:1px solid #dee2e6;">';
    echo '<strong style="font-size:13px;">Actions:</strong>';
    echo '<button type="button" onclick="lgSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ Select All</button>';
    echo '<button type="button" onclick="lgSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ Deselect All</button>';
    echo '<button type="button" id="lg-dl-selected-btn" onclick="lgDownloadSelected()" ' .
         'class="btn btn-sm btn-outline-primary" style="display:none;">⬇ Download Selected</button>';
    echo '<button type="submit" class="btn btn-sm btn-danger" ' .
         'onclick="return lgConfirmDelete()">' .
         '🗑 Delete Selected</button>';
    echo '<span id="lg-sel-count" style="font-size:12px;color:#6c757d;margin-left:4px;">0 selected</span>';
    echo '<div style="border-left:1px solid #dee2e6;margin:0 4px;height:24px;"></div>';
    // Delete everything matching the current filter (e.g. assessment + date range) in one click.
    if ($filterLgid || $filterStudent || $filterType || $filterBefore || $filterAfter) {
        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete ALL ' .
             $totalCount . ' item(s) matching the current filter? This cannot be undone.\');">';
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',       'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction',    'value' => 'deletemediafiltered']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',           'value' => 'media']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_lgid',   'value' => $filterLgid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_student','value' => $filterStudent]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_type',   'value' => $filterType]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_before', 'value' => $filterBefore]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_after',  'value' => $filterAfter]);
        echo html_writer::empty_tag('input', ['type' => 'submit',
            'value' => '🗑 Delete All Filtered (' . $totalCount . ')', 'class' => 'btn btn-sm btn-danger']);
        echo '</form>';
        echo '<div style="border-left:1px solid #dee2e6;margin:0 4px;height:24px;"></div>';
    }
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
    echo '<div style="border-left:1px solid #dee2e6;margin:0 4px;height:24px;"></div>';
    $reclaimStatus = get_config('learnguard', 'reclaim_status');
    if ($reclaimStatus === 'running') {
        echo '<span style="font-size:12px;color:#0a4d8c;">⏳ Reclaim running in the background — refresh to check.</span>';
    } else if ($reclaimStatus === 'done') {
        echo '<span style="font-size:12px;color:#28a745;">✅ Last reclaim finished ' .
            s(userdate((int)get_config('learnguard', 'reclaim_finished'))) . '</span>';
    } else if ($reclaimStatus === 'error') {
        echo '<span style="font-size:12px;color:#dc3545;">⚠️ Last reclaim failed: ' .
            s(get_config('learnguard', 'reclaim_error')) . '</span>';
    }
    echo '<form method="post" style="display:inline;" onsubmit="return confirm(' .
         '\'Queue a disk space reclaim? This rebuilds the storage tables in the background via ' .
         'cron (not limited by page load time) and can briefly lock them once it runs — best ' .
         'queued when the site is quiet, not during active exams.\');">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',     'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction',  'value' => 'reclaimdiskspace']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'reclaimtab',  'value' => 'media']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🗜 Reclaim Disk Space',
        'class' => 'btn btn-sm btn-outline-secondary']);
    echo '</form>';
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
                 'data-bytes="0" ' . // filled in by lgFetchMediaSizes() after page load
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
    function lgFormatBytes(n) {
        if (!n || n <= 0) { return '0 B'; }
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function lgRefreshSelectionBadge() {
        var checked = document.querySelectorAll('input[name="snapids[]"]:checked');
        var totalBytes = 0;
        checked.forEach(function(c) { totalBytes += parseInt(c.getAttribute('data-bytes'), 10) || 0; });
        var badge = document.getElementById('lg-sel-count');
        if (badge) {
            badge.textContent = checked.length + ' selected' +
                (checked.length ? ' — ' + lgFormatBytes(totalBytes) : '');
        }
        var dlBtn = document.getElementById('lg-dl-selected-btn');
        if (dlBtn) { dlBtn.style.display = checked.length ? 'inline-block' : 'none'; }
    }

    function lgUpdateCount(chk, cardId) {
        var card = document.getElementById(cardId);
        if (card) { card.style.borderColor = chk.checked ? '#dc3545' : '#dee2e6'; }
        lgRefreshSelectionBadge();
    }

    function lgSelectAll(select) {
        // Select/deselect every checkbox on the page (including those outside the media form grid).
        var boxes = document.querySelectorAll('input[name="snapids[]"]');
        boxes.forEach(function(chk) {
            chk.checked = select;
            var card = chk.closest('.lg-media-card');
            if (card) card.style.borderColor = select ? '#dc3545' : '#dee2e6';
        });
        lgRefreshSelectionBadge();
    }

    // Download the currently checked items — submits the same form to a download action.
    function lgDownloadSelected() {
        var boxes = document.querySelectorAll('input[name="snapids[]"]:checked');
        if (!boxes.length) { alert('Please tick at least one item to download.'); return; }
        if (boxes.length > 300) {
            alert('Please select 300 items or fewer per download (you have ' + boxes.length +
                '). Narrow the filter (e.g. by date) and download in batches.');
            return;
        }
        document.getElementById('lg-media-action-field').value = 'downloadmedia';
        document.getElementById('lg-media-form').target = '_blank';
        document.getElementById('lg-media-form').submit();
        document.getElementById('lg-media-action-field').value = 'deletemedia';
        document.getElementById('lg-media-form').target = '';
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

    // Fetch byte sizes for the items on this page AFTER it has rendered — keeps the
    // page load itself fast (see the PHP comment above the query for why this isn't
    // computed synchronously anymore).
    function lgFetchMediaSizes() {
        var boxes = document.querySelectorAll('input[name="snapids[]"]');
        if (!boxes.length) { return; }
        var qs = 'tab=media&ajaxsizes=1&sesskey=' + encodeURIComponent(<?php echo json_encode(sesskey()); ?>);
        boxes.forEach(function(b) { qs += '&ids[]=' + encodeURIComponent(b.value); });
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '<?php echo (new moodle_url('/mod/learnguard/siteadmin.php'))->out(false); ?>?' + qs, true);
        xhr.onload = function() {
            var sizes; try { sizes = JSON.parse(xhr.responseText); } catch (e) { return; }
            boxes.forEach(function(b) {
                if (sizes[b.value] !== undefined) { b.setAttribute('data-bytes', sizes[b.value]); }
            });
        };
        xhr.send();
    }
    lgFetchMediaSizes();
    </script>
    <?php
}

// =====================================================================
// TAB: Audio Clips (site-wide) — recorded when background noise exceeds threshold.
// There was previously no place to browse/manage these across all attempts; this
// mirrors the Media Gallery tab (filter, select, download, delete) for audio.
// =====================================================================
if ($tab === 'audio') {

    echo html_writer::tag('h4', '🎤 Audio Clips — All Attempts', ['class' => 'mb-1']);
    echo html_writer::tag('p',
        'Audio recorded automatically when background noise exceeded the configured threshold.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:16px;']);

    $totalAudio = $DB->count_records('learnguard_audio_clips');
    echo html_writer::start_div('', ['style' =>
        'background:' . $brand->primary . ';color:#fff;border-radius:8px;padding:12px 20px;' .
        'min-width:110px;text-align:center;display:inline-block;margin-bottom:16px;']);
    echo html_writer::tag('div', $totalAudio, ['style' => 'font-size:24px;font-weight:700;']);
    echo html_writer::tag('div', 'Total Clips', ['style' => 'font-size:11px;opacity:0.85;']);
    echo html_writer::end_div();

    // ── Filter form ───────────────────────────────────────────────────
    $afLgid    = optional_param('filter_lgid',    0,  PARAM_INT);
    $afStudent = optional_param('filter_student', '', PARAM_TEXT);
    $afBefore  = optional_param('filter_before',  '', PARAM_TEXT);
    $afAfter   = optional_param('filter_after',   '', PARAM_TEXT);
    $audioPage = optional_param('audiopage',      0,  PARAM_INT);
    $aPerPage  = 48;

    $allAssessments3 = $DB->get_records_sql(
        "SELECT lg.id, lg.name, c.fullname AS coursename
           FROM {learnguard} lg JOIN {course} c ON c.id = lg.course
       ORDER BY c.fullname, lg.name", []
    );

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'style'  => 'background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:16px;margin-bottom:20px;',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'audio']);
    echo html_writer::start_div('d-flex gap-3 flex-wrap align-items-end');

    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Assessment', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::start_tag('select', ['name' => 'filter_lgid', 'class' => 'form-control', 'style' => 'max-width:260px;']);
    echo html_writer::tag('option', '— All assessments —', ['value' => '0']);
    foreach ($allAssessments3 as $a) {
        echo html_writer::tag('option',
            '[' . format_string($a->coursename) . '] ' . format_string($a->name),
            ['value' => $a->id, 'selected' => ($afLgid === (int)$a->id) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_div();

    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Student (name/email)', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'filter_student', 'class' => 'form-control', 'style' => 'max-width:220px;',
        'value' => s($afStudent), 'placeholder' => 'Search name or email…',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('');
    echo html_writer::tag('label', 'After date', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'date', 'name' => 'filter_after', 'class' => 'form-control', 'style' => 'max-width:160px;',
        'value' => s($afAfter),
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Before date', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'date', 'name' => 'filter_before', 'class' => 'form-control', 'style' => 'max-width:160px;',
        'value' => s($afBefore),
    ]);
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🔍 Filter', 'class' => 'btn btn-primary', 'style' => 'align-self:flex-end;']);
    if ($afLgid || $afStudent || $afBefore || $afAfter) {
        echo ' ';
        echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'audio']),
            'Clear', ['class' => 'btn btn-outline-secondary', 'style' => 'align-self:flex-end;']);
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    // ── Build WHERE clause ────────────────────────────────────────────
    $awhere  = '1=1';
    $aparams = [];
    if ($afLgid > 0) {
        $awhere .= ' AND a.learnguard = :aflgid';
        $aparams['aflgid'] = $afLgid;
    }
    if ($afStudent !== '') {
        $alike = $DB->sql_like_escape($afStudent);
        $awhere .= ' AND (' .
            $DB->sql_like('u.firstname', ':affsn', false) . ' OR ' .
            $DB->sql_like('u.lastname',  ':aflsn', false) . ' OR ' .
            $DB->sql_like('u.email',     ':afesn', false) . ')';
        $aparams['affsn'] = '%' . $alike . '%';
        $aparams['aflsn'] = '%' . $alike . '%';
        $aparams['afesn'] = '%' . $alike . '%';
    }
    $aAfterTs = $afAfter !== '' ? strtotime($afAfter . ' 00:00:00') : false;
    if ($aAfterTs !== false) {
        $awhere .= ' AND ac.timecreated >= :afafterts';
        $aparams['afafterts'] = $aAfterTs;
    }
    $aBeforeTs = $afBefore !== '' ? strtotime($afBefore . ' 00:00:00') : false;
    if ($aBeforeTs !== false) {
        $awhere .= ' AND ac.timecreated < :afbeforets';
        $aparams['afbeforets'] = $aBeforeTs;
    }

    $abaseSql = "FROM {learnguard_audio_clips} ac
                 JOIN {learnguard_attempts} a  ON a.id = ac.attemptid
                 JOIN {user}                u  ON u.id = ac.userid
                 JOIN {learnguard}          lg ON lg.id = a.learnguard
                 JOIN {course}              c  ON c.id  = lg.course
                WHERE $awhere";
    $atotalCount = $DB->count_records_sql("SELECT COUNT(*) $abaseSql", $aparams);
    $atotalPages = $atotalCount > 0 ? ceil($atotalCount / $aPerPage) : 1;
    $audioPage   = max(0, min($audioPage, $atotalPages - 1));
    $aoffset     = $audioPage * $aPerPage;

    // No separate LENGTH(ac.audiodata) — audiodata is already loaded below for inline
    // playback, so its size is computed from that in PHP (strlen(), free) instead of
    // asking the DB to measure the blob a second time.
    $clips = $DB->get_records_sql(
        "SELECT ac.id, ac.timecreated, ac.duration, ac.noise_level, ac.audiodata,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, a.id AS attemptid,
                cm.id AS cmid
           FROM {learnguard_audio_clips} ac
           JOIN {learnguard_attempts} a  ON a.id = ac.attemptid
           JOIN {user}                u  ON u.id = ac.userid
           JOIN {learnguard}          lg ON lg.id = a.learnguard
           JOIN {course}              c  ON c.id  = lg.course
           JOIN {modules}             md ON md.name = 'learnguard'
           JOIN {course_modules}      cm ON cm.instance = lg.id AND cm.module = md.id
          WHERE $awhere
       ORDER BY ac.timecreated DESC",
        $aparams, $aoffset, $aPerPage
    );

    echo html_writer::tag('p',
        'Showing ' . ($atotalCount === 0 ? '0' : ($aoffset + 1)) . '–' .
        min($aoffset + $aPerPage, $atotalCount) . ' of ' . $atotalCount . ' clips.',
        ['style' => 'font-size:13px;color:#6c757d;margin-bottom:12px;']
    );

    echo '<form method="post" id="lg-audio-form">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'id' => 'lg-audio-action-field', 'value' => 'deleteaudio']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',        'value' => 'audio']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'single_delete_id', 'id' => 'lg-audio-single-del-id', 'value' => '']);

    echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;' .
         'background:#f8f9fa;padding:10px 14px;border-radius:8px;border:1px solid #dee2e6;">';
    echo '<strong style="font-size:13px;">Actions:</strong>';
    echo '<button type="button" onclick="lgAudioSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ Select All</button>';
    echo '<button type="button" onclick="lgAudioSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ Deselect All</button>';
    echo '<button type="button" id="lg-audio-dl-btn" onclick="lgAudioDownloadSelected()" ' .
         'class="btn btn-sm btn-outline-primary" style="display:none;">⬇ Download Selected</button>';
    echo '<button type="submit" class="btn btn-sm btn-danger" onclick="return lgAudioConfirmDelete()">🗑 Delete Selected</button>';
    echo '<span id="lg-audio-sel-count" style="font-size:12px;color:#6c757d;margin-left:4px;">0 selected</span>';
    echo '<div style="border-left:1px solid #dee2e6;margin:0 4px;height:24px;"></div>';
    if ($afLgid || $afStudent || $afBefore || $afAfter) {
        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete ALL ' .
             $atotalCount . ' clip(s) matching the current filter? This cannot be undone.\');">';
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',       'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction',    'value' => 'deleteaudiofiltered']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',           'value' => 'audio']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_lgid',   'value' => $afLgid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_student','value' => $afStudent]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_before', 'value' => $afBefore]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_after',  'value' => $afAfter]);
        echo html_writer::empty_tag('input', ['type' => 'submit',
            'value' => '🗑 Delete All Filtered (' . $atotalCount . ')', 'class' => 'btn btn-sm btn-danger']);
        echo '</form>';
    }
    echo '<div style="border-left:1px solid #dee2e6;margin:0 4px;height:24px;"></div>';
    $reclaimStatus = get_config('learnguard', 'reclaim_status');
    if ($reclaimStatus === 'running') {
        echo '<span style="font-size:12px;color:#0a4d8c;">⏳ Reclaim running in the background — refresh to check.</span>';
    } else if ($reclaimStatus === 'done') {
        echo '<span style="font-size:12px;color:#28a745;">✅ Last reclaim finished ' .
            s(userdate((int)get_config('learnguard', 'reclaim_finished'))) . '</span>';
    } else if ($reclaimStatus === 'error') {
        echo '<span style="font-size:12px;color:#dc3545;">⚠️ Last reclaim failed: ' .
            s(get_config('learnguard', 'reclaim_error')) . '</span>';
    }
    echo '<form method="post" style="display:inline;" onsubmit="return confirm(' .
         '\'Queue a disk space reclaim? This rebuilds the storage tables in the background via ' .
         'cron (not limited by page load time) and can briefly lock them once it runs — best ' .
         'queued when the site is quiet, not during active exams.\');">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',     'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction',  'value' => 'reclaimdiskspace']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'reclaimtab',  'value' => 'audio']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🗜 Reclaim Disk Space',
        'class' => 'btn btn-sm btn-outline-secondary']);
    echo '</form>';
    echo '</div>';

    if (empty($clips)) {
        echo html_writer::div('No audio clips match the current filter.', 'alert alert-info');
    } else {
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-bottom:20px;">';
        foreach ($clips as $clip) {
            $ts    = userdate($clip->timecreated, '%d/%m/%y %H:%M:%S');
            $level = (int)$clip->noise_level;
            $levColour = $level > 60 ? '#dc3545' : ($level > 40 ? '#fd7e14' : '#28a745');
            $detailUrl = (new moodle_url('/mod/learnguard/attemptdetail.php',
                ['id' => $clip->cmid, 'attempt' => $clip->attemptid]))->out(false);

            echo '<div style="border:2px solid #dee2e6;border-radius:8px;background:#fff;' .
                 'box-shadow:0 2px 6px rgba(0,0,0,0.07);padding:12px;position:relative;">';
            echo '<label style="position:absolute;top:6px;right:6px;cursor:pointer;">' .
                 '<input type="checkbox" name="audioids[]" value="' . $clip->id . '" ' .
                 'data-bytes="' . strlen($clip->audiodata ?? '') . '" ' .
                 'onchange="lgAudioRefreshBadge()" style="width:16px;height:16px;cursor:pointer;"></label>';

            echo html_writer::start_div('', ['style' => 'display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;padding-right:22px;']);
            echo html_writer::tag('span', $ts, ['style' => 'font-size:12px;font-weight:700;']);
            echo html_writer::tag('span', '🔊 ' . $level,
                ['style' => 'font-size:11px;background:' . $levColour . ';color:#fff;padding:2px 8px;border-radius:10px;font-weight:700;']);
            echo html_writer::end_div();

            echo html_writer::tag('div', fullname($clip), ['style' => 'font-size:12px;font-weight:600;']);
            echo html_writer::tag('div', format_string($clip->assessmentname),
                ['style' => 'font-size:11px;color:#6c757d;margin-bottom:6px;']);

            echo '<audio controls preload="none" style="width:100%;margin-bottom:6px;">' .
                 '<source src="data:audio/webm;base64,' . $clip->audiodata . '" type="audio/webm">' .
                 '<source src="data:audio/ogg;base64,' . $clip->audiodata . '" type="audio/ogg">' .
                 '</audio>';

            echo '<div style="display:flex;gap:8px;align-items:center;">';
            echo '<a href="' . $detailUrl . '" style="font-size:11px;color:' . $brand->primary . ';">View attempt →</a>';
            echo '<button type="button" onclick="lgAudioDeleteOne(' . $clip->id . ')" ' .
                 'style="background:none;border:none;color:#dc3545;font-size:11px;cursor:pointer;padding:0;margin-left:auto;">🗑 Delete</button>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</form>';

    if ($atotalPages > 1) {
        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">';
        for ($p = 0; $p < $atotalPages; $p++) {
            $active = ($p === $audioPage);
            $purl = new moodle_url('/mod/learnguard/siteadmin.php', [
                'tab' => 'audio', 'audiopage' => $p,
                'filter_lgid' => $afLgid, 'filter_student' => $afStudent,
                'filter_before' => $afBefore, 'filter_after' => $afAfter,
            ]);
            echo html_writer::link($purl, ($p + 1), [
                'style' => 'padding:6px 12px;border-radius:4px;text-decoration:none;font-size:13px;' .
                    ($active ? 'background:' . $brand->primary . ';color:#fff;' : 'background:#f8f9fa;border:1px solid #dee2e6;color:#333;'),
            ]);
        }
        echo '</div>';
    }
    ?>
    <script>
    function lgFormatBytesA(n) {
        if (!n || n <= 0) { return '0 B'; }
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }
    function lgAudioRefreshBadge() {
        var checked = document.querySelectorAll('input[name="audioids[]"]:checked');
        var totalBytes = 0;
        checked.forEach(function(c) { totalBytes += parseInt(c.getAttribute('data-bytes'), 10) || 0; });
        var badge = document.getElementById('lg-audio-sel-count');
        if (badge) {
            badge.textContent = checked.length + ' selected' +
                (checked.length ? ' — ' + lgFormatBytesA(totalBytes) : '');
        }
        var dlBtn = document.getElementById('lg-audio-dl-btn');
        if (dlBtn) { dlBtn.style.display = checked.length ? 'inline-block' : 'none'; }
    }
    function lgAudioSelectAll(select) {
        document.querySelectorAll('input[name="audioids[]"]').forEach(function(c) { c.checked = select; });
        lgAudioRefreshBadge();
    }
    function lgAudioConfirmDelete() {
        var count = document.querySelectorAll('input[name="audioids[]"]:checked').length;
        if (count === 0) {
            alert('Please tick at least one clip to delete.');
            return false;
        }
        return confirm('Delete ' + count + ' selected clip(s)? This cannot be undone.');
    }
    function lgAudioDeleteOne(id) {
        if (!confirm('Delete this audio clip? This cannot be undone.')) { return; }
        document.getElementById('lg-audio-single-del-id').value = id;
        document.querySelectorAll('input[name="audioids[]"]').forEach(function(c) { c.checked = false; });
        document.getElementById('lg-audio-form').submit();
    }
    function lgAudioDownloadSelected() {
        var boxes = document.querySelectorAll('input[name="audioids[]"]:checked');
        if (!boxes.length) { alert('Please tick at least one clip to download.'); return; }
        if (boxes.length > 300) {
            alert('Please select 300 clips or fewer per download (you have ' + boxes.length + ').');
            return;
        }
        document.getElementById('lg-audio-action-field').value = 'downloadaudio';
        document.getElementById('lg-audio-form').target = '_blank';
        document.getElementById('lg-audio-form').submit();
        document.getElementById('lg-audio-action-field').value = 'deleteaudio';
        document.getElementById('lg-audio-form').target = '';
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
