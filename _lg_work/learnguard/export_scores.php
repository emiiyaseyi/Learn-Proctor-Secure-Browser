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
 * Export assessment scores to CSV, Excel (TSV), or plain text.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id     = required_param('id',     PARAM_INT);  // course module ID
$format = optional_param('format', 'csv', PARAM_ALPHA); // csv | tsv | json

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);
$context  = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/learnguard:manage', $context);
require_sesskey();

// Fetch all finished attempts with user info.
$attempts = $DB->get_records_sql(
    "SELECT a.id, a.userid, a.attempt, a.state, a.timefinish, a.timestart,
            a.risk_score, a.risk_level, a.score, a.maxscore,
            u.firstname, u.lastname, u.email, u.idnumber, u.username
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.learnguard = ?
   ORDER BY u.lastname ASC, u.firstname ASC, a.attempt ASC",
    [$instance->id]
);

$sep  = ($format === 'tsv') ? "\t" : ',';
$ext  = ($format === 'tsv') ? 'xls' : ($format === 'json' ? 'json' : 'csv');
$mime = ($format === 'tsv') ? 'application/vnd.ms-excel' :
        ($format === 'json' ? 'application/json' : 'text/csv');

$filename = clean_filename($instance->name) . '_scores.' . $ext;

if ($format === 'json') {
    $rows = [];
    foreach ($attempts as $a) {
        $pct = ($a->maxscore > 0) ? round(($a->score / $a->maxscore) * 100, 1) : 0;
        $rows[] = [
            'firstname'   => $a->firstname,
            'lastname'    => $a->lastname,
            'email'       => $a->email,
            'idnumber'    => $a->idnumber,
            'username'    => $a->username,
            'attempt'     => (int)$a->attempt,
            'state'       => $a->state,
            'score'       => (float)$a->score,
            'maxscore'    => (float)$a->maxscore,
            'percent'     => $pct,
            'risk_score'  => (int)$a->risk_score,
            'risk_level'  => $a->risk_level,
            'timestart'   => date('Y-m-d H:i:s', (int)$a->timestart),
            'timefinish'  => $a->timefinish ? date('Y-m-d H:i:s', (int)$a->timefinish) : '',
            'duration_min'=> $a->timefinish ? round(((int)$a->timefinish - (int)$a->timestart) / 60, 1) : '',
        ];
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($rows, JSON_PRETTY_PRINT);
    exit;
}

// CSV / TSV output.
header('Content-Type: ' . $mime . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
// UTF-8 BOM so Excel opens without garbling.
echo "\xEF\xBB\xBF";

$headers = [
    'First Name', 'Last Name', 'Email', 'ID Number', 'Username',
    'Attempt #', 'State', 'Score', 'Max Score', 'Percentage',
    'Risk Score', 'Risk Level',
    'Start Time', 'Finish Time', 'Duration (min)',
];
echo implode($sep, array_map(function($h) use ($sep) {
    return '"' . str_replace('"', '""', $h) . '"';
}, $headers)) . "\n";

foreach ($attempts as $a) {
    $pct      = ($a->maxscore > 0) ? round(($a->score / $a->maxscore) * 100, 1) : 0;
    $duration = $a->timefinish ? round(((int)$a->timefinish - (int)$a->timestart) / 60, 1) : '';
    $row = [
        $a->firstname,
        $a->lastname,
        $a->email,
        $a->idnumber,
        $a->username,
        (int)$a->attempt,
        $a->state,
        number_format((float)$a->score, 2, '.', ''),
        number_format((float)$a->maxscore, 2, '.', ''),
        $pct . '%',
        (int)$a->risk_score,
        $a->risk_level,
        $a->timestart ? date('Y-m-d H:i:s', (int)$a->timestart) : '',
        $a->timefinish ? date('Y-m-d H:i:s', (int)$a->timefinish) : '',
        $duration,
    ];
    echo implode($sep, array_map(function($v) use ($sep) {
        return '"' . str_replace('"', '""', (string)$v) . '"';
    }, $row)) . "\n";
}
exit;
