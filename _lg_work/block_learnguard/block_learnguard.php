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
 * LearnGuard block plugin — shows a user's grading queue and proctoring assignments.
 *
 * Displays:
 *  - Graders: list of assessments they are assigned to grade, with ungraded essay counts.
 *  - Proctors: list of active assessments they are assigned to proctor.
 *  - Site admins: a quick link to the LearnGuard site-admin page.
 *
 * @package    block_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2026 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * LearnGuard block class.
 */
class block_learnguard extends block_base {

    /**
     * Initialise block title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_learnguard');
    }

    /**
     * This block can appear on any page type.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return ['all' => true];
    }

    /**
     * No instance-level config needed.
     *
     * @return bool
     */
    public function has_config(): bool {
        return false;
    }

    /**
     * Build and return the block content.
     *
     * @return stdClass|null
     */
    public function get_content(): ?stdClass {
        global $DB, $USER, $CFG;

        // Only show content to logged-in non-guest users.
        if (!isloggedin() || isguestuser()) {
            return null;
        }

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content        = new stdClass();
        $this->content->footer = '';

        $uid = (int)$USER->id;

        // ------------------------------------------------------------------
        // Shared inline styles (defined once, referenced via class names).
        // ------------------------------------------------------------------
        $css = '
<style>
.blg-block { font-family: inherit; font-size: 0.92em; }
.blg-section { margin-bottom: 14px; }
.blg-section-title {
    font-weight: 700;
    font-size: 0.97em;
    color: #0f6cbf;
    border-bottom: 2px solid #0f6cbf;
    padding-bottom: 4px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.blg-list { list-style: none; margin: 0; padding: 0; }
.blg-list li {
    background: #f4f8fd;
    border: 1px solid #d6e4f5;
    border-radius: 6px;
    padding: 7px 10px;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.blg-name {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 500;
    color: #212529;
}
.blg-badge {
    background: #0f6cbf;
    color: #fff;
    border-radius: 10px;
    padding: 1px 8px;
    font-size: 0.82em;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
}
.blg-badge-ok {
    background: #28a745;
}
.blg-link {
    color: #0f6cbf;
    text-decoration: none;
    font-size: 0.88em;
    font-weight: 600;
    white-space: nowrap;
    flex-shrink: 0;
}
.blg-link:hover { text-decoration: underline; }
.blg-empty {
    color: #6c757d;
    font-size: 0.88em;
    padding: 4px 2px;
}
.blg-admin-row {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #dee2e6;
    text-align: center;
}
.blg-admin-btn {
    display: inline-block;
    background: #0f6cbf;
    color: #fff !important;
    border-radius: 6px;
    padding: 5px 14px;
    font-size: 0.88em;
    font-weight: 600;
    text-decoration: none;
}
.blg-admin-btn:hover { background: #0a5ca8; text-decoration: none; }
</style>';

        $html  = $css . '<div class="blg-block">';
        $empty = true; // Track whether we rendered anything useful.

        // ------------------------------------------------------------------
        // 1. GRADING QUEUE
        // ------------------------------------------------------------------
        // Fetch all assessments this user is assigned to grade, plus a count
        // of finished attempts that have NO essay grade row attributed to them
        // by this user (across all essay slots in the attempt).
        //
        // Logic:
        //   A finished attempt is "ungraded by me" when there is NO row in
        //   learnguard_essay_grades with graderid = my uid for that attempt.
        //   We count distinct such attemptids per assessment.
        //
        // We also respect the grader_assignments distribution table: if a
        // learnguard_grading_config row exists for the activity with mode
        // != 'all', we only count attempts that are assigned to this grader.
        // To keep the block query simple and correct in both modes, we count
        // ALL finished attempts that lack a grade from this user — managers
        // see all; the grader.php page itself handles the distribution filter
        // on the detail view. The block count is therefore a "tasks awaiting
        // your attention" indicator, which is accurate in both modes.
        // ------------------------------------------------------------------

        $graderSQL = "
            SELECT g.learnguard AS lgid,
                   lg.name      AS lgname,
                   cm.id        AS cmid,
                   COUNT(DISTINCT a.id) AS ungraded
              FROM {learnguard_graders}  g
              JOIN {learnguard}          lg  ON lg.id  = g.learnguard
              JOIN {course_modules}      cm  ON cm.instance = lg.id
              JOIN {modules}             mdl ON mdl.id = cm.module AND mdl.name = 'learnguard'
         LEFT JOIN {learnguard_attempts} a
                ON a.learnguard = lg.id
               AND a.state = 'finished'
               AND NOT EXISTS (
                       SELECT 1
                         FROM {learnguard_essay_grades} eg
                        WHERE eg.attemptid = a.id
                          AND eg.graderid  = :uid2
                   )
             WHERE g.userid = :uid
          GROUP BY g.learnguard, lg.name, cm.id
          ORDER BY lg.name ASC";

        $graderRows = $DB->get_records_sql($graderSQL, ['uid' => $uid, 'uid2' => $uid]);

        if (!empty($graderRows)) {
            $empty = false;
            $html .= '<div class="blg-section">';
            $html .= '<div class="blg-section-title"><span>&#x1F4DD;</span> My Grading Queue</div>';
            $html .= '<ul class="blg-list">';

            foreach ($graderRows as $row) {
                $url     = $CFG->wwwroot . '/mod/learnguard/grader.php?id=' . (int)$row->cmid;
                $count   = (int)$row->ungraded;
                $name    = s($row->lgname);

                if ($count > 0) {
                    $badge = '<span class="blg-badge">' . $count . ' ungraded</span>';
                } else {
                    $badge = '<span class="blg-badge blg-badge-ok">&#10003; All graded</span>';
                }

                $html .= '<li>';
                $html .= '<span class="blg-name" title="' . $name . '">' . $name . '</span>';
                $html .= $badge;
                $html .= '<a class="blg-link" href="' . $url . '">Grade &rsaquo;</a>';
                $html .= '</li>';
            }

            $html .= '</ul></div>';
        }

        // ------------------------------------------------------------------
        // 2. PROCTORING ASSIGNMENTS
        // ------------------------------------------------------------------
        // Show assessments where this user is listed in learnguard_proctors.
        // We show all assignments regardless of is_global / courseid so the
        // proctor can navigate to any dashboard they are responsible for.
        // DISTINCT prevents duplicate rows if a user appears twice (e.g.
        // once as global proctor and once as course-specific proctor for the
        // same assessment).
        // ------------------------------------------------------------------

        $proctorSQL = "
            SELECT DISTINCT lg.id  AS lgid,
                            lg.name AS lgname,
                            cm.id  AS cmid
              FROM {learnguard_proctors} p
              JOIN {learnguard}          lg  ON lg.id  = p.learnguard
              JOIN {course_modules}      cm  ON cm.instance = lg.id
              JOIN {modules}             mdl ON mdl.id = cm.module AND mdl.name = 'learnguard'
             WHERE p.userid = :uid
          ORDER BY lg.name ASC";

        $proctorRows = $DB->get_records_sql($proctorSQL, ['uid' => $uid]);

        if (!empty($proctorRows)) {
            $empty = false;
            $html .= '<div class="blg-section">';
            $html .= '<div class="blg-section-title"><span>&#x1F6E1;</span> My Proctoring</div>';
            $html .= '<ul class="blg-list">';

            foreach ($proctorRows as $row) {
                $url  = $CFG->wwwroot . '/mod/learnguard/proctordashboard.php?id=' . (int)$row->cmid;
                $name = s($row->lgname);

                $html .= '<li>';
                $html .= '<span class="blg-name" title="' . $name . '">' . $name . '</span>';
                $html .= '<a class="blg-link" href="' . $url . '">Live &rsaquo;</a>';
                $html .= '</li>';
            }

            $html .= '</ul></div>';
        }

        // ------------------------------------------------------------------
        // 3. SITE ADMIN SHORTCUT
        // ------------------------------------------------------------------
        $syscontext = context_system::instance();
        if (has_capability('moodle/site:config', $syscontext)) {
            $empty = false;
            $adminUrl = $CFG->wwwroot . '/mod/learnguard/siteadmin.php';
            $html .= '<div class="blg-admin-row">';
            $html .= '<a class="blg-admin-btn" href="' . $adminUrl . '">&#x2699; LearnGuard Admin</a>';
            $html .= '</div>';
        }

        // ------------------------------------------------------------------
        // Nothing to show for this user.
        // ------------------------------------------------------------------
        if ($empty) {
            $html .= '<p class="blg-empty">No LearnGuard assignments found for your account.</p>';
        }

        $html .= '</div>'; // .blg-block

        $this->content->text = $html;
        return $this->content;
    }
}
