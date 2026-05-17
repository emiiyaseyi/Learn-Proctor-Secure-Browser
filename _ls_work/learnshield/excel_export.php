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
 * Excel export of integrity attempt data — Learn Shield.
 * Uses the XML-based Excel format (no external library required).
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use quizaccess_learnshield\suspicion_scorer;

$courseid = required_param('courseid', PARAM_INT);
$cmid     = required_param('cmid',     PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$cm     = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
$quiz   = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('quizaccess/learnshield:viewreport', $context);

// Fetch all attempt summaries.
$sql = "SELECT e.userid, u.firstname, u.lastname, u.email,
               COUNT(e.id) AS snapshots,
               COALESCE(s.risk_score, 0) AS risk_score,
               COALESCE(s.risk_level, 'low') AS risk_level,
               COALESCE(s.tab_switches, 0) AS tab_switches,
               COALESCE(s.fullscreen_exits, 0) AS fullscreen_exits,
               COALESCE(s.face_missing_count, 0) AS face_missing,
               COALESCE(s.keyboard_violations, 0) AS keyboard_violations,
               COALESCE(s.clipboard_events, 0) AS clipboard_events,
               COALESCE(s.is_flagged, 0) AS is_flagged,
               s.status AS session_status,
               MAX(e.timemodified) AS last_activity
          FROM {quizaccess_learnshield_logs} e
         INNER JOIN {user} u ON u.id = e.userid
          LEFT JOIN {quizaccess_learnshield_sessions} s ON s.userid = e.userid AND s.quizid = e.quizid
         WHERE e.courseid = :courseid AND e.quizid = :cmid
         GROUP BY e.userid, u.firstname, u.lastname, u.email,
                  s.risk_score, s.risk_level, s.tab_switches, s.fullscreen_exits,
                  s.face_missing_count, s.keyboard_violations, s.clipboard_events,
                  s.is_flagged, s.status
         ORDER BY COALESCE(s.risk_score, 0) DESC, u.lastname ASC";

$records = $DB->get_records_sql($sql, ['courseid' => $courseid, 'cmid' => $cmid]);

// Output as Excel XML (compatible with Excel 2003+, no library needed).
$filename = 'learnshield-' . clean_filename($quiz->name) . '-' . date('Ymd') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:x="urn:schemas-microsoft-com:office:excel"
    xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Styles>
    <Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#0f6cbf" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF" ss:Bold="1"/></Style>
    <Style ss:ID="High"><Interior ss:Color="#f8d7da" ss:Pattern="Solid"/></Style>
    <Style ss:ID="Medium"><Interior ss:Color="#fff3cd" ss:Pattern="Solid"/></Style>
    <Style ss:ID="Normal"><Interior ss:Color="#d4edda" ss:Pattern="Solid"/></Style>
</Styles>
<Worksheet ss:Name="Integrity Report">
<Table>';

// Header row.
$headers = [
    'Student Name', 'Email', 'Risk Score', 'Risk Level', 'Snapshots',
    'Tab Switches', 'Fullscreen Exits', 'Face Missing', 'Keyboard Viol.', 'Clipboard Events',
    'Flagged', 'Session Status', 'Last Activity',
];
echo '<Row>';
foreach ($headers as $h) {
    echo '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>';
}
echo '</Row>';

// Data rows.
foreach ($records as $row) {
    $styleId = $row->risk_level === 'high' ? 'High' : ($row->risk_level === 'medium' ? 'Medium' : 'Normal');
    echo '<Row ss:StyleID="' . $styleId . '">';
    $cells = [
        fullname($row),
        $row->email,
        (int)$row->risk_score,
        ucfirst($row->risk_level),
        (int)$row->snapshots,
        (int)$row->tab_switches,
        (int)$row->fullscreen_exits,
        (int)$row->face_missing,
        (int)$row->keyboard_violations,
        (int)$row->clipboard_events,
        $row->is_flagged ? 'Yes' : 'No',
        $row->session_status ?? '—',
        $row->last_activity ? date('Y-m-d H:i', $row->last_activity) : '—',
    ];
    foreach ($cells as $val) {
        $type = is_numeric($val) && !in_array($val, ['Yes', 'No']) ? 'Number' : 'String';
        echo '<Cell><Data ss:Type="' . $type . '">' . htmlspecialchars((string)$val) . '</Data></Cell>';
    }
    echo '</Row>';
}

echo '</Table></Worksheet></Workbook>';
exit;
