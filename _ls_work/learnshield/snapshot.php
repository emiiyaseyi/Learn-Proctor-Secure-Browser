<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Serves binary snapshot data for a single LearnShield log record.
 *
 * Avoids embedding large base64 strings inline in HTML, enabling lazy loading
 * and dramatically reducing page size for gallery/playback pages.
 *
 * URL: /mod/quiz/accessrule/learnshield/snapshot.php?id=LOGID&sesskey=SK
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();

$logid = required_param('id', PARAM_INT);

// Require at least liveproctor capability or the snapshot belongs to this user.
$record = $DB->get_record('quizaccess_learnshield_logs', ['id' => $logid], 'id,userid,webcampicture', MUST_EXIST);

$context = context_system::instance();
if ($record->userid !== $USER->id) {
    require_capability('quizaccess/learnshield:liveproctor', $context);
}

$raw = $record->webcampicture;
if (!$raw) {
    // Return a 1×1 transparent PNG placeholder.
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}

// Strip data-URI prefix if present (old records may store as data:image/...;base64,...).
if (str_starts_with($raw, 'data:')) {
    $commaPos = strpos($raw, ',');
    $header   = substr($raw, 5, $commaPos - 5); // e.g. "image/jpeg;base64"
    $mime     = explode(';', $header)[0];
    $binary   = base64_decode(substr($raw, $commaPos + 1), true);
} else {
    // Assume raw base64 JPEG.
    $mime   = 'image/jpeg';
    $binary = base64_decode($raw, true);
}

if ($binary === false || strlen($binary) < 4) {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}

// Cache for 1 hour (snapshots are immutable).
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($binary));
header('Cache-Control: private, max-age=3600');
echo $binary;
