<?php
/**
 * Navigation bar helper — renders a consistent top nav bar on LearnShield admin pages.
 * Include this file and call ls_nav_bar($courseid, $cmid, $activePage).
 *
 * @package    quizaccess_learnshield
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Renders a consistent top navigation bar for LearnShield admin/proctor pages.
 *
 * @param int    $courseid  Course ID (0 for global pages)
 * @param int    $cmid      Course module ID (0 for global pages)
 * @param string $active    Active page key: 'dashboard','proctor','report','grades','schedule','gallery','storage','videos360','bulkassign','scorematrix','settings'
 */
function ls_nav_bar(int $courseid = 0, int $cmid = 0, string $active = '') {
    global $CFG;

    $items = [];

    if ($cmid > 0 && $courseid > 0) {
        // Quiz-specific pages.
        $items['dashboard'] = [
            '🏠 Dashboard',
            new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php',
                ['courseid' => $courseid, 'cmid' => $cmid]),
        ];
        $items['proctor'] = [
            '🛡 Live Monitor',
            new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php',
                ['tab' => 'live']),
        ];
        $items['report'] = [
            '📊 Report',
            new moodle_url('/mod/quiz/accessrule/learnshield/report.php',
                ['courseid' => $courseid, 'cmid' => $cmid]),
        ];
        $items['grades'] = [
            '🏆 Grades',
            new moodle_url('/mod/quiz/report.php',
                ['id' => $cmid, 'mode' => 'overview']),
        ];
        $items['schedule'] = [
            '📅 Schedule',
            new moodle_url('/mod/quiz/accessrule/learnshield/schedule.php',
                ['courseid' => $courseid, 'cmid' => $cmid]),
        ];
    } else {
        $items['proctor'] = [
            '🛡 Live Monitor',
            new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php'),
        ];
    }

    $items['storage'] = [
        '🗄 Storage',
        new moodle_url('/mod/quiz/accessrule/learnshield/storage.php'),
    ];
    $items['videos360'] = [
        '🎥 360° Videos',
        new moodle_url('/mod/quiz/accessrule/learnshield/videos360.php'),
    ];
    $items['bulkassign'] = [
        '🗂 Bulk Assign',
        new moodle_url('/mod/quiz/accessrule/learnshield/bulkassign.php'),
    ];
    $items['scorematrix'] = [
        '🏆 Score Matrix',
        new moodle_url('/mod/quiz/accessrule/learnshield/scorematrix.php'),
    ];
    $items['settings'] = [
        '⚙️ Settings',
        new moodle_url('/admin/settings.php', ['section' => 'quizaccess_learnshield']),
    ];

    echo '<div style="display:flex;align-items:center;gap:6px;padding:8px 0 16px;flex-wrap:wrap;">';
    foreach ($items as $key => [$label, $url]) {
        $isActive = ($key === $active);
        echo html_writer::link($url, $label, [
            'class' => 'btn btn-sm ' . ($isActive ? 'btn-primary' : 'btn-outline-secondary'),
            'style' => $isActive ? '' : '',
        ]);
    }
    echo '</div>';
}
