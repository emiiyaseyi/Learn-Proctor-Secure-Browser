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
 * Library functions for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php');

// -----------------------------------------------------------------------
// File serving
// -----------------------------------------------------------------------

/**
 * Serve plugin files (webcam snapshots, user photos).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context  $context
 * @param string   $filearea
 * @param array    $args
 * @param bool     $forcedownload
 * @param array    $options
 * @return bool
 */
function quizaccess_learnshield_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    $itemid   = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? ('/' . implode('/', $args) . '/') : '/';

    $fs   = get_file_storage();
    $file = $fs->get_file($context->id, 'quizaccess_learnshield', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

// -----------------------------------------------------------------------
// User image helpers
// -----------------------------------------------------------------------

/**
 * Return the URL of a user's reference image, or false.
 *
 * @param int $userid
 * @return string|false
 */
function quizaccess_learnshield_get_image_url($userid) {
    $context = context_system::instance();
    $fs      = get_file_storage();

    if ($files = $fs->get_area_files($context->id, 'quizaccess_learnshield', 'user_photo')) {
        foreach ($files as $file) {
            if ($userid == $file->get_itemid() && $file->get_filename() !== '.') {
                $url = moodle_url::make_pluginfile_url(
                    $file->get_contextid(), $file->get_component(), $file->get_filearea(),
                    $file->get_itemid(), $file->get_filepath(), $file->get_filename(), true
                );
                return $url->out(false);
            }
        }
    }
    return false;
}

/**
 * Return (and clean up DB records for) a user's reference image file.
 *
 * @param int $userid
 * @return stored_file|false
 */
function quizaccess_learnshield_get_image_file($userid) {
    global $DB;
    $context = context_system::instance();
    $fs      = get_file_storage();

    if ($files = $fs->get_area_files($context->id, 'quizaccess_learnshield', 'user_photo')) {
        foreach ($files as $file) {
            if ($userid == $file->get_itemid() && $file->get_filename() !== '.') {
                $recordid = $DB->get_field('quizaccess_learnshield_user_images', 'id', ['user_id' => $userid]);
                $DB->delete_records('quizaccess_learnshield_user_images',  ['user_id'  => $userid]);
                $DB->delete_records('quizaccess_learnshield_face_images',  ['parentid' => $recordid]);
                return $file;
            }
        }
    }
    return false;
}

// -----------------------------------------------------------------------
// Face match result update
// -----------------------------------------------------------------------

/**
 * Update the awsscore / awsflag on a log record.
 *
 * @param int    $rowid
 * @param string $matchresult  Similarity value (coerced to int).
 * @param int    $awsflag
 */
function quizaccess_learnshield_update_match_result($rowid, $matchresult, $awsflag) {
    global $DB;
    $DB->update_record('quizaccess_learnshield_logs', (object)[
        'id'      => $rowid,
        'awsflag' => $awsflag,
        'awsscore'=> (int)$matchresult,
    ]);
}

// -----------------------------------------------------------------------
// Settings helper
// -----------------------------------------------------------------------

/**
 * Read a plugin setting value.
 *
 * @param string $settingtype
 * @return string
 */
function quizaccess_learnshield_get_setting($settingtype) {
    global $DB;
    $record = $DB->get_record('config_plugins', [
        'plugin' => 'quizaccess_learnshield',
        'name'   => $settingtype,
    ], 'value', IGNORE_MISSING);
    return $record ? $record->value : '';
}

// -----------------------------------------------------------------------
// Per-learner scheduling (schedule.php / {quiz_overrides})
// -----------------------------------------------------------------------

/**
 * Resolve the effective open/close window for a specific learner: a personal
 * {quiz_overrides} row (set via schedule.php) overrides the quiz's own
 * timeopen/timeclose for whichever field it sets — exactly as Moodle's own
 * quiz_update_effective_access() does for the native quiz timer.
 *
 * @param int $quizid
 * @param int $userid
 * @return array{0:int,1:int} [timeopen, timeclose]
 */
function quizaccess_learnshield_effective_window(int $quizid, int $userid): array {
    global $DB;

    $quiz = $DB->get_record('quiz', ['id' => $quizid], 'timeopen, timeclose');
    $timeopen  = $quiz ? (int)$quiz->timeopen  : 0;
    $timeclose = $quiz ? (int)$quiz->timeclose : 0;

    $override = $DB->get_record('quiz_overrides', ['quiz' => $quizid, 'userid' => $userid]);
    if ($override) {
        if ($override->timeopen !== null) {
            $timeopen = (int)$override->timeopen;
        }
        if ($override->timeclose !== null) {
            $timeclose = (int)$override->timeclose;
        }
    }

    return [$timeopen, $timeclose];
}

/**
 * Render a small self-contained "opens in / closes in" countdown widget for
 * the quiz view page (shown above the Start button via rule.php::description()).
 *
 * @param int $timeopen
 * @param int $timeclose
 * @return string HTML, or '' if no countdown is needed right now.
 */
function quizaccess_learnshield_countdown_widget(int $timeopen, int $timeclose): string {
    $now = time();

    if ($timeopen && $now < $timeopen) {
        $id      = 'ls-sched-open';
        $text    = get_string('schedule_open_countdown', 'quizaccess_learnshield', (object)[
            'countdown' => '<strong id="' . $id . '">--:--:--</strong>',
            'date'      => userdate($timeopen),
        ]);
        $target  = $timeopen;
        $reload  = 'true';
    } else if ($timeclose && $now <= $timeclose) {
        $id      = 'ls-sched-close';
        $text    = get_string('schedule_close_countdown', 'quizaccess_learnshield', (object)[
            'countdown' => '<strong id="' . $id . '">--:--:--</strong>',
            'date'      => userdate($timeclose),
        ]);
        $target  = $timeclose;
        $reload  = 'false';
    } else {
        return '';
    }

    $remaining = max(0, $target - $now);

    $html  = html_writer::div($text, 'alert alert-info mt-2', ['id' => $id . '-wrap']);
    $html .= '<script>(function(){'
        . 'var remaining=' . (int)$remaining . ';'
        . 'var reload=' . $reload . ';'
        . 'var el=document.getElementById(' . json_encode($id) . ');'
        . 'if(!el){return;}'
        . 'function pad(n){return n<10?"0"+n:String(n);}'
        . 'function fmt(s){if(s<0){s=0;}var d=Math.floor(s/86400);var h=Math.floor((s%86400)/3600);'
        . 'var m=Math.floor((s%3600)/60);var sec=s%60;var hms=pad(h)+":"+pad(m)+":"+pad(sec);'
        . 'return d>0?(d+"d "+hms):hms;}'
        . 'el.textContent=fmt(remaining);'
        . 'var t=setInterval(function(){'
        . 'remaining--;'
        . 'if(remaining<=0){clearInterval(t);el.textContent=fmt(0);if(reload){location.reload();}return;}'
        . 'el.textContent=fmt(remaining);'
        . '},1000);'
        . '})();</script>';

    return $html;
}

// -----------------------------------------------------------------------
// Face match task execution
// -----------------------------------------------------------------------

/**
 * Process up to 5 pending face-match tasks from the queue.
 */
function quizaccess_learnshield_execute_fm_task() {
    global $DB;

    $tasks           = $DB->get_records('quizaccess_learnshield_facematch_task', null, '', '*', 0, 5);
    $facematchmethod = quizaccess_learnshield_get_setting('fcmethod');

    if (empty($tasks)) {
        mtrace('No face match tasks found.');
        return;
    }

    if ($facematchmethod !== 'BS') {
        mtrace("Face match method is not BS; skipping BS tasks. Method: {$facematchmethod}");
        return;
    }

    foreach ($tasks as $row) {
        $rowid    = $row->id;
        $reportid = $row->reportid;

        list($userfaceimageurl, $webcamfaceimageurl) = quizaccess_learnshield_get_face_images($reportid);

        if (!empty($userfaceimageurl) && !empty($webcamfaceimageurl)) {
            quizaccess_learnshield_extracted($userfaceimageurl, $webcamfaceimageurl, $reportid);

            $result = $DB->get_record('quizaccess_learnshield_logs', ['id' => $reportid], 'awsscore', MUST_EXIST);
            if ($result->awsscore > 0) {
                $DB->delete_records('quizaccess_learnshield_facematch_task', ['id' => $rowid]);
                mtrace("Processed task ID {$rowid} (Report ID: {$reportid}).");
            } else {
                mtrace("Face match failed for report ID {$reportid}.");
            }
        } else {
            mtrace("Missing image URLs for report ID {$reportid}.");
        }
    }
}

/**
 * Log face-match tasks for all unprocessed attempt records.
 */
function quizaccess_learnshield_log_facematch_task() {
    global $DB;

    $sql     = 'SELECT DISTINCT id, courseid, quizid, userid FROM {quizaccess_learnshield_logs} WHERE awsflag = :awsflag';
    $records = $DB->get_records_sql($sql, ['awsflag' => 0]);

    foreach ($records as $record) {
        quizaccess_learnshield_log_specific_quiz($record->courseid, $record->quizid, $record->userid);
    }
    mtrace('Facematch task logging complete.');
}

/**
 * Create face-match tasks for a specific student / quiz.
 *
 * @param int $courseid
 * @param int $cmid
 * @param int $studentid
 * @return bool
 */
function quizaccess_learnshield_log_specific_quiz($courseid, $cmid, $studentid) {
    global $DB;

    $profileimageurl = quizaccess_learnshield_get_image_url($studentid);
    if (empty($profileimageurl)) {
        mtrace("No profile image for user ID {$studentid}.");
        return false;
    }

    $DB->set_field('quizaccess_learnshield_logs', 'awsflag', 1, [
        'courseid' => $courseid,
        'quizid'   => $cmid,
        'userid'   => $studentid,
    ]);

    $awschecknumber = quizaccess_learnshield_get_setting('awschecknumber');
    $limit          = ($awschecknumber > 0) ? (int)$awschecknumber : 5;

    $allids = $DB->get_fieldset_sql(
        "SELECT id FROM {quizaccess_learnshield_logs}
          WHERE courseid = :courseid AND quizid = :quizid AND userid = :userid AND webcampicture != ''",
        ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $studentid]
    );

    if (empty($allids)) {
        mtrace("No snapshots for user ID {$studentid}.");
        return false;
    }

    shuffle($allids);
    $selectedids = array_slice($allids, 0, $limit);

    list($insql, $inparams) = $DB->get_in_or_equal($selectedids, SQL_PARAMS_NAMED);
    $records = $DB->get_records_sql(
        "SELECT id, webcampicture FROM {quizaccess_learnshield_logs} WHERE id {$insql}",
        $inparams
    );

    foreach ($records as $record) {
        $DB->insert_record('quizaccess_learnshield_facematch_task', (object)[
            'refimageurl'    => $profileimageurl,
            'targetimageurl' => $record->webcampicture,
            'reportid'       => $record->id,
            'timemodified'   => time(),
        ]);
    }
    return true;
}

/**
 * Analyse a student's quiz snapshots against the BS face-match service.
 *
 * @param int    $courseid
 * @param int    $cmid
 * @param int    $studentid
 * @param string $reportpageurl
 * @return bool
 */
function quizaccess_learnshield_bs_analyze_specific_quiz($courseid, $cmid, $studentid, $reportpageurl) {
    global $DB;

    $profileimageurl = quizaccess_learnshield_get_image_url($studentid);
    $redirecturl     = new moodle_url('/mod/quiz/accessrule/learnshield/upload_image.php', ['id' => $studentid]);

    if (!$profileimageurl) {
        redirect($redirecturl, get_string('user_image_not_uploaded', 'quizaccess_learnshield'), 1,
            \core\output\notification::NOTIFY_WARNING);
    }

    $DB->set_field_select('quizaccess_learnshield_logs', 'awsflag', 1,
        "courseid = :courseid AND quizid = :quizid AND userid = :userid AND awsflag = :awsflag",
        ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $studentid, 'awsflag' => 0]
    );

    $awschecknumber = quizaccess_learnshield_get_setting('awschecknumber');
    $limit          = ($awschecknumber > 0) ? (int)$awschecknumber : 5;

    $sql = "SELECT e.id as reportid, e.userid as studentid, e.webcampicture as webcampicture,
                   e.status as status, e.timemodified as timemodified,
                   u.firstname as firstname, u.lastname as lastname, u.email as email
              FROM {quizaccess_learnshield_logs} e
             INNER JOIN {user} u ON u.id = e.userid
             WHERE e.courseid = :courseid AND e.quizid = :quizid AND u.id = :userid AND e.webcampicture != ''
             ORDER BY RAND() LIMIT " . (int)$limit;

    $rows = $DB->get_recordset_sql($sql, ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $studentid]);
    foreach ($rows as $row) {
        list($userfaceimageurl, $webcamfaceimageurl) = quizaccess_learnshield_get_face_images($row->reportid);
        if (!$userfaceimageurl || !$webcamfaceimageurl) {
            quizaccess_learnshield_log_fm_warning($row->reportid);
            quizaccess_learnshield_update_match_result($row->reportid, 0, 3);
            continue;
        }
        quizaccess_learnshield_extracted($userfaceimageurl, $webcamfaceimageurl, $row->reportid, $reportpageurl);
    }
    $rows->close();
    return true;
}

/**
 * Analyse a single snapshot via the BS face-match service.
 *
 * @param int    $reportid
 * @param string $redirecturl
 * @return bool
 */
function quizaccess_learnshield_bs_analyze_specific_image($reportid, $redirecturl) {
    global $DB;

    $reportdata = $DB->get_record('quizaccess_learnshield_logs', ['id' => $reportid],
        'id, courseid, quizid, userid, webcampicture');
    if (!$reportdata) {
        redirect($redirecturl, get_string('error_invalid_report', 'quizaccess_learnshield'), 1,
            \core\output\notification::NOTIFY_ERROR);
        return false;
    }

    list($userfaceimageurl, $webcamfaceimageurl) = quizaccess_learnshield_get_face_images($reportid);
    if (!$userfaceimageurl || !$webcamfaceimageurl) {
        quizaccess_learnshield_log_fm_warning($reportid);
        quizaccess_learnshield_update_match_result($reportid, 0, 3);
        redirect($redirecturl, get_string('error_face_not_found', 'quizaccess_learnshield'), 1,
            \core\output\notification::NOTIFY_ERROR);
        return true;
    }

    $DB->execute(
        "UPDATE {quizaccess_learnshield_logs}
            SET awsflag = 1
          WHERE courseid = :courseid AND quizid = :quizid AND userid = :userid AND awsflag = 0",
        ['courseid' => $reportdata->courseid, 'quizid' => $reportdata->quizid, 'userid' => $reportdata->userid]
    );

    quizaccess_learnshield_extracted($userfaceimageurl, $webcamfaceimageurl, $reportid, $redirecturl);
    redirect($redirecturl, get_string('facematch', 'quizaccess_learnshield'), 1,
        \core\output\notification::NOTIFY_SUCCESS);
    return true;
}

/**
 * Analyse a single snapshot (no redirect — used from JS / validate endpoint).
 *
 * @param int $reportid
 */
function quizaccess_learnshield_bs_analyze_specific_image_from_validate($reportid) {
    global $DB;

    $reportdata = $DB->get_record('quizaccess_learnshield_logs', ['id' => $reportid],
        'id, courseid, quizid, userid, webcampicture');
    if (!$reportdata) {
        return;
    }

    list($userfaceimageurl, $webcamfaceimageurl) = quizaccess_learnshield_get_face_images($reportid);
    if (!$userfaceimageurl || !$webcamfaceimageurl) {
        quizaccess_learnshield_log_fm_warning($reportid);
        quizaccess_learnshield_update_match_result($reportid, 0, 3);
        return;
    }

    $DB->execute(
        "UPDATE {quizaccess_learnshield_logs}
            SET awsflag = 1
          WHERE courseid = :courseid AND quizid = :quizid AND userid = :userid AND awsflag = 0",
        ['courseid' => $reportdata->courseid, 'quizid' => $reportdata->quizid, 'userid' => $reportdata->userid]
    );

    $bsapi    = quizaccess_learnshield_get_setting('bsapi');
    $bsapikey = quizaccess_learnshield_get_setting('bs_api_key');

    if (!empty($bsapi) && !empty($bsapikey)) {
        quizaccess_learnshield_extracted($userfaceimageurl, $webcamfaceimageurl, $reportid);
    } else {
        quizaccess_learnshield_update_match_result($reportid, 0, 101);
    }
}

// -----------------------------------------------------------------------
// Face image retrieval
// -----------------------------------------------------------------------

/**
 * Return [userFaceImageUrl, webcamFaceImageUrl] for a report ID.
 *
 * @param int $reportid
 * @return array
 */
function quizaccess_learnshield_get_face_images($reportid) {
    global $DB;

    $reportdata = $DB->get_record('quizaccess_learnshield_logs', ['id' => $reportid]);
    if (!$reportdata) {
        return [null, null];
    }

    $studentid = $reportdata->userid;

    // Webcam face image.
    $webcamfaceimage = $DB->get_records('quizaccess_learnshield_face_images', [
        'parentid'    => $reportid,
        'parent_type' => 'camshot_image',
        'facefound'   => 1,
    ]);
    $webcamfaceimageurl = '';
    if ($webcamfaceimage) {
        $first = reset($webcamfaceimage);
        $webcamfaceimageurl = $first->faceimage;
    }

    // User reference image.
    $userimagerow = $DB->get_record('quizaccess_learnshield_user_images', ['user_id' => $studentid]);
    if (!$userimagerow) {
        $redirecturl = new moodle_url('/mod/quiz/accessrule/learnshield/upload_image.php', ['id' => $studentid]);
        redirect($redirecturl, get_string('userimagenotuploaded', 'quizaccess_learnshield'), 1,
            \core\output\notification::NOTIFY_WARNING);
    }

    $userfaceimageurl = '';
    if ($userimagerow) {
        $userfaceimagerow = $DB->get_record('quizaccess_learnshield_face_images', [
            'parentid'    => $userimagerow->id,
            'parent_type' => 'admin_image',
        ]);
        if ($userfaceimagerow) {
            $userfaceimageurl = $userfaceimagerow->faceimage;
        }
    }

    return [$userfaceimageurl, $webcamfaceimageurl];
}

// -----------------------------------------------------------------------
// BS face comparison
// -----------------------------------------------------------------------

/**
 * Compare two face images via the BS API and record the result.
 *
 * @param string      $profileimageurl
 * @param string      $targetimage
 * @param int         $reportid
 * @param string|null $redirecturl
 */
function quizaccess_learnshield_extracted(
    string $profileimageurl,
    string $targetimage,
    int $reportid,
    ?string $redirecturl = null
): void {
    $similarityresult = quizaccess_learnshield_check_similarity_bs($profileimageurl, $targetimage, $redirecturl, $reportid);
    $response         = json_decode($similarityresult);
    $threshold        = (float)quizaccess_learnshield_get_setting('threshold');
    $similarity       = 0;

    if (isset($response->message) && $response->message === 'Forbidden') {
        if (!empty($redirecturl)) {
            redirect($redirecturl, get_string('invalid_api', 'quizaccess_learnshield'), 1,
                \core\output\notification::NOTIFY_ERROR);
        } else {
            quizaccess_learnshield_update_match_result($reportid, 0, 101);
            return;
        }
    } else if ($response && isset($response->statusCode) && $response->statusCode == 200
               && isset($response->body->distance)) {
        if ($response->body->distance <= $threshold / 100) {
            $similarity = 100;
        } else {
            quizaccess_learnshield_log_fm_warning($reportid);
        }
    } else {
        quizaccess_learnshield_log_fm_warning($reportid);
    }

    quizaccess_learnshield_update_match_result($reportid, $similarity, 2);
}

/**
 * Call the BS face-match API and return the raw JSON response.
 *
 * @param string      $referenceimageurl
 * @param string      $targetimageurl
 * @param string|null $redirecturl
 * @param int         $reportid
 * @return string|false
 */
function quizaccess_learnshield_check_similarity_bs(
    string $referenceimageurl,
    string $targetimageurl,
    $redirecturl,
    int $reportid
) {
    global $CFG;

    $bsapi    = quizaccess_learnshield_get_setting('bsapi');
    $bsapikey = quizaccess_learnshield_get_setting('bs_api_key');

    if (empty($bsapi) || empty($bsapikey)) {
        mtrace('Error: Missing BS API URL or API key.');
        return false;
    }

    $image1      = basename($referenceimageurl);
    $image2      = basename($targetimageurl);
    $imagepath1  = $CFG->dataroot . '/temp/' . $image1;
    $imagepath2  = $CFG->dataroot . '/temp/' . $image2;

    try {
        file_put_contents($imagepath1, file_get_contents($referenceimageurl));
        file_put_contents($imagepath2, file_get_contents($targetimageurl));
    } catch (Exception $e) {
        mtrace('Error: Unable to save images to temporary directory.');
        return false;
    }

    $imagedata1 = file_get_contents($imagepath1);
    $imagedata2 = file_get_contents($imagepath2);

    $payload = json_encode([
        'original_img_response' => base64_encode($imagedata1),
        'face_img_response'     => base64_encode($imagedata2),
    ]);

    $curl     = new curl();
    $options  = [
        'CURLOPT_TIMEOUT'       => 30,
        'CURLOPT_FOLLOWLOCATION'=> true,
        'CURLOPT_HTTPHEADER'    => [
            'x-api-key: ' . $bsapikey,
            'Content-Type: application/json',
        ],
    ];
    $response = $curl->post($bsapi, $payload, $options);

    if ($curl->get_errno()) {
        if (!empty($redirecturl)) {
            redirect($redirecturl, get_string('invalid_service_api', 'quizaccess_learnshield'), 1,
                \core\output\notification::NOTIFY_ERROR);
        } else {
            quizaccess_learnshield_update_match_result($reportid, 0, 101);
        }
        return false;
    }

    @unlink($imagepath1);
    @unlink($imagepath2);

    return $response;
}

// -----------------------------------------------------------------------
// Warnings
// -----------------------------------------------------------------------

/**
 * Log a face-match warning (once per user/course/quiz).
 *
 * @param int $reportid
 */
function quizaccess_learnshield_log_fm_warning(int $reportid): void {
    global $DB;

    $report = $DB->get_record('quizaccess_learnshield_logs', ['id' => $reportid]);
    if (!$report) {
        mtrace("Error: Report ID {$reportid} not found.");
        return;
    }

    if (!$DB->record_exists('quizaccess_learnshield_fm_warnings', [
        'userid'   => $report->userid,
        'courseid' => $report->courseid,
        'quizid'   => $report->quizid,
    ])) {
        $DB->insert_record('quizaccess_learnshield_fm_warnings', (object)[
            'reportid' => $reportid,
            'courseid' => $report->courseid,
            'quizid'   => $report->quizid,
            'userid'   => $report->userid,
        ]);
    }
}

// -----------------------------------------------------------------------
// Face image storage helper
// -----------------------------------------------------------------------

/**
 * Decode a base64 image, store it in Moodle file storage, and return its URL.
 *
 * @param string          $data     Base64-encoded image (with data URI prefix).
 * @param int             $userid
 * @param stdClass        $record   File record template.
 * @param context         $context
 * @param file_storage    $fs
 * @return moodle_url
 */
function quizaccess_learnshield_geturl_of_faceimage(
    string $data,
    int $userid,
    stdClass $record,
    $context,
    $fs
): moodle_url {
    [, $data] = explode(',', $data);
    $data      = base64_decode($data);

    $filename          = 'faceimage-' . $userid . '-' . time() . random_int(1, 1000) . '.png';
    $record->filename  = $filename;
    $record->contextid = $context->id;
    $record->userid    = $userid;

    try {
        $fs->create_file_from_string($record, $data);
    } catch (Exception $e) {
        throw new moodle_exception('filecreationerror', 'error', '', $e->getMessage());
    }

    return moodle_url::make_pluginfile_url(
        $context->id, $record->component, $record->filearea,
        $record->itemid, $record->filepath, $record->filename, false
    );
}
