<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Serve a stored snapshot or recording by ID.
 *
 * Usage: /mod/learnguard/snapshot.php?id={snapshotid}&sesskey={sesskey}
 *
 * Outputs the binary image/video directly with correct Content-Type.
 * No page template — raw binary output only.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$snapid  = required_param('id',      PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

require_login(null, false);

// Basic session authentication.
if (!confirm_sesskey($sesskey)) {
    http_response_code(403);
    die('Forbidden');
}

$snap = $DB->get_record('learnguard_snapshots', ['id' => $snapid], '*', MUST_EXIST);

// Verify user has access to this snapshot's attempt.
$attempt = $DB->get_record('learnguard_attempts', ['id' => $snap->attemptid], '*', MUST_EXIST);
$cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

// Must be the student, a proctor, or an admin.
$canView = ($snap->userid === $USER->id)
        || has_capability('mod/learnguard:viewreports',  $context)
        || has_capability('mod/learnguard:liveproctor',  $context)
        || has_capability('moodle/site:config',          context_system::instance());

if (!$canView) {
    http_response_code(403);
    die('Forbidden');
}

// Determine MIME type from snapshot_type.
$isVideo = ($snap->snapshot_type === '360_video');
$mime    = $isVideo ? 'video/webm' : 'image/jpeg';

// The stored data is base64-encoded binary.
$binary = base64_decode($snap->snapshot_url, true);
if ($binary === false || strlen($binary) < 10) {
    http_response_code(404);
    die('Not found or empty');
}

// Cache for 10 minutes (snapshots are immutable).
header('Cache-Control: private, max-age=600');
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($binary));
echo $binary;
exit;
