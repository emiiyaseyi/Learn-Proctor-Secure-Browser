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
 * Screenshot / webcam snapshot handler for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles saving webcam snapshots to Moodle file storage.
 */
class screenshot {

    /**
     * Store a base64 webcam snapshot image for a quiz attempt.
     *
     * @param int    $courseid
     * @param int    $quizid       Course-module ID.
     * @param int    $userid
     * @param int    $screenshotid Log record ID.
     * @param string $imagedata    Base64 image data (with data URI prefix).
     * @return \moodle_url|false   URL of stored image or false on failure.
     */
    public static function save_webcam_image(
        int $courseid,
        int $quizid,
        int $userid,
        int $screenshotid,
        string $imagedata
    ) {
        global $DB;

        if (empty($imagedata) || strpos($imagedata, ',') === false) {
            return false;
        }

        [, $base64data] = explode(',', $imagedata, 2);
        $imgcontent     = base64_decode($base64data);
        if (!$imgcontent) {
            return false;
        }

        $context  = \context_system::instance();
        $fs       = get_file_storage();
        $filename = 'webcam-' . $userid . '-' . time() . random_int(1, 9999) . '.png';

        $filerecord = [
            'contextid' => $context->id,
            'component' => 'quizaccess_learnshield',
            'filearea'  => 'picture',
            'itemid'    => $screenshotid,
            'filepath'  => '/',
            'filename'  => $filename,
        ];

        try {
            $fs->create_file_from_string($filerecord, $imgcontent);
        } catch (\Exception $e) {
            return false;
        }

        $url = \moodle_url::make_pluginfile_url(
            $context->id, 'quizaccess_learnshield', 'picture',
            $screenshotid, '/', $filename, false
        );

        $DB->set_field('quizaccess_learnshield_logs', 'webcampicture', $url->out(false), ['id' => $screenshotid]);

        return $url;
    }

    /**
     * Store a face-image fragment (extracted from a snapshot or reference image).
     *
     * @param string $base64data  Base64 image with data URI prefix.
     * @param int    $userid
     * @param int    $parentid
     * @param string $parenttype  'camshot_image' | 'admin_image'
     * @param int    $facefound   1 if face detected, 0 otherwise.
     * @return string Stored face image URL or empty string.
     */
    public static function save_face_image(
        string $base64data,
        int $userid,
        int $parentid,
        string $parenttype,
        int $facefound
    ): string {
        global $DB;

        if (empty($base64data) || strpos($base64data, ',') === false) {
            $DB->insert_record('quizaccess_learnshield_face_images', (object)[
                'parent_type' => $parenttype,
                'parentid'    => $parentid,
                'faceimage'   => '',
                'facefound'   => 0,
            ]);
            return '';
        }

        $context  = \context_system::instance();
        $fs       = get_file_storage();

        $filerecord = (object)[
            'contextid' => $context->id,
            'component' => 'quizaccess_learnshield',
            'filearea'  => 'faceimage',
            'itemid'    => $parentid,
            'filepath'  => '/',
            'filename'  => '', // Set below.
            'userid'    => $userid,
        ];

        $faceurl = quizaccess_learnshield_geturl_of_faceimage($base64data, $userid, $filerecord, $context, $fs);
        $faceurlstr = $faceurl->out(false);

        $DB->insert_record('quizaccess_learnshield_face_images', (object)[
            'parent_type' => $parenttype,
            'parentid'    => $parentid,
            'faceimage'   => $faceurlstr,
            'facefound'   => $facefound,
        ]);

        return $faceurlstr;
    }
}
