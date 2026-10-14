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
 * Lets a learner download their OWN submission PDF (question + answer, no score)
 * straight from the course page's "Your Attempts" list — a self-service alternative
 * to the emailed copy, for when the email doesn't arrive or lands in junk.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/submission_pdf.php');

$attemptid = required_param('attempt', PARAM_INT);

require_login();
require_sesskey();

global $DB, $USER;

$attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
$cm       = get_coursemodule_from_instance('learnguard', $instance->id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

$isowner   = ((int)$attempt->userid === (int)$USER->id);
$ismanager = has_capability('mod/learnguard:viewreports', $context);
if (!$isowner && !$ismanager) {
    throw new moodle_exception('nopermissions', 'error', '', 'view this submission');
}
if ($attempt->state !== 'finished') {
    throw new moodle_exception('invalidrecord', 'error', '', 'this attempt is not finished yet');
}

// A learner can only self-download once an admin has actually selected them on
// Export Submissions — this is deliberately not automatic for every finished
// attempt. A manager bypasses this (they're the one who'd be selecting students
// in the first place, and may want to preview the PDF before sending it).
if ($isowner && !$ismanager) {
    $wasexported = $DB->record_exists('learnguard_submission_exports', ['attemptid' => $attemptid]);
    if (!$wasexported) {
        throw new moodle_exception('invalidrecord', 'error', '', 'this submission is not yet available for download');
    }
}

// A stray PHP notice/warning printed before the binary stream would corrupt the PDF
// (the exact "Failed to load" class of bug fixed for grader.php's AJAX JSON in
// v2.0.12) — suppress display, never let debugging output land inside the stream.
@ini_set('display_errors', '0');

$pdfcontent = \mod_learnguard\submission_pdf::build($attemptid);
$filename   = clean_filename(format_string($instance->name) . '_attempt' . $attempt->attempt . '.pdf');

// Only the learner's own download counts toward the "who has used this" audit trail —
// a manager/admin re-checking someone else's PDF isn't a learner self-service download.
if ($isowner) {
    $DB->insert_record('learnguard_submission_downloads', (object)[
        'learnguard'  => (int)$instance->id,
        'attemptid'   => $attemptid,
        'userid'      => (int)$USER->id,
        'timecreated' => time(),
    ]);
}

\core\session\manager::write_close();
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfcontent));
echo $pdfcontent;
