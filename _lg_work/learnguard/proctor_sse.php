<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Server-Sent Events stream for proctor real-time alerts.
 *
 * Usage: new EventSource('/mod/learnguard/proctor_sse.php?id=CMID&sesskey=SK')
 *
 * Streams JSON events every 4 seconds for:
 * - New high/medium severity proctoring events
 * - New face approval requests
 * - Student suspensions
 * - Screen share stopped
 *
 * Falls back gracefully: if SSE is blocked by server config, the proctor
 * dashboard falls back to standard 5-second XHR polling automatically.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// SSE must run without session locking — Moodle's session holds an exclusive lock.
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');

$cmid    = required_param('id',      PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);
$since   = optional_param('since',   time() - 60, PARAM_INT);

// Validate session manually without locking.
if (!confirm_sesskey($sesskey)) {
    http_response_code(403);
    die('Forbidden');
}
require_login(null, false);

$cm      = get_coursemodule_from_id('learnguard', $cmid, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:liveproctor', $context);

// SSE headers.
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no'); // Disable nginx buffering.
header('Connection: keep-alive');

// Disable output buffering.
if (function_exists('ob_end_clean')) { while (ob_get_level()) { ob_end_clean(); } }

$maxRuntime = 30; // Max 30 seconds then client reconnects.
$start      = time();
$lastSince  = $since;

// Send initial heartbeat.
echo "event: heartbeat\ndata: {\"ts\":" . time() . "}\n\n";
flush();

while ((time() - $start) < $maxRuntime) {
    $now = time();

    // Fetch new events since last check.
    $newEvents = $DB->get_records_sql(
        "SELECT e.id, e.event_type, e.severity, e.score_impact, e.timemodified, e.event_data,
                u.firstname, u.lastname, a.id AS attemptid
           FROM {learnguard_events} e
           JOIN {learnguard_attempts} a ON a.id = e.attemptid
           JOIN {user} u ON u.id = e.userid
          WHERE a.learnguard = :lgid
            AND e.severity IN ('high','medium')
            AND e.timemodified > :since
       ORDER BY e.timemodified ASC
          LIMIT 20",
        ['lgid' => $cm->instance, 'since' => $lastSince]
    );

    // Also check for new face approval requests.
    $newFaceApprovals = [];
    try {
        $newFaceApprovals = $DB->get_records_sql(
            "SELECT fa.id, fa.userid, fa.matchpct, fa.timecreated,
                    u.firstname, u.lastname
               FROM {learnguard_face_approvals} fa
               JOIN {user} u ON u.id = fa.userid
              WHERE fa.cmid = :cmid
                AND fa.status = 'pending'
                AND fa.timecreated > :since",
            ['cmid' => $cmid, 'since' => $lastSince]
        );
    } catch (Exception $e) {}

    // Live session count update.
    $liveCount = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {learnguard_attempts}
          WHERE learnguard = ? AND state = 'inprogress'",
        [$cm->instance]
    );

    $payload = [
        'events'        => [],
        'face_approvals'=> [],
        'live_count'    => (int)$liveCount,
        'ts'            => $now,
    ];

    foreach ($newEvents as $e) {
        $payload['events'][] = [
            'id'         => (int)$e->id,
            'event_type' => (string)$e->event_type,
            'severity'   => (string)$e->severity,
            'score_impact'=> (int)$e->score_impact,
            'fullname'   => fullname($e),
            'attemptid'  => (int)$e->attemptid,
            'timemodified'=> (int)$e->timemodified,
        ];
        $lastSince = max($lastSince, (int)$e->timemodified);
    }

    foreach ($newFaceApprovals as $fa) {
        $payload['face_approvals'][] = [
            'id'       => (int)$fa->id,
            'fullname' => fullname($fa),
            'matchpct' => (int)$fa->matchpct,
            'time'     => (int)$fa->timecreated,
        ];
        $lastSince = max($lastSince, (int)$fa->timecreated);
    }

    // Send event regardless (heartbeat keeps connection alive + updates live count).
    echo "data: " . json_encode($payload) . "\n\n";
    flush();

    if (connection_aborted()) { break; }
    sleep(4);
}

// Tell client to reconnect immediately.
echo "event: reconnect\ndata: {}\n\n";
flush();
exit;
