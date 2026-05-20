<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Server-Sent Events (SSE) stream for the LearnShield proctor dashboard.
 *
 * Sends alert events to connected proctor browsers without polling.
 * Falls back to 5-second XHR polling on the client side if SSE is unavailable.
 *
 * URL: /mod/quiz/accessrule/learnshield/proctor_sse.php?courseid=X&sesskey=Y
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();
require_capability('quizaccess/learnshield:liveproctor', context_system::instance());

$courseid = optional_param('courseid', 0, PARAM_INT);
$since    = optional_param('since', time() - 60, PARAM_INT);

// Set SSE headers.
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // Disable nginx buffering.

// Disable Moodle output buffering.
if (ob_get_level()) { ob_end_clean(); }

// Flush helper.
function ls_sse_send(string $event, $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    if (ob_get_level()) { ob_flush(); }
    flush();
}

// Send initial ping so client knows SSE is alive.
ls_sse_send('ping', ['ts' => time()]);

$maxLoops  = 30;    // Run for ~120 s then let client reconnect.
$loopSleep = 4;     // Send every 4 seconds.
$lastTs    = $since;

for ($i = 0; $i < $maxLoops; $i++) {
    // Check if client disconnected.
    if (connection_aborted()) { break; }

    // Fetch new high-severity events since last send.
    $where  = "e.severity IN ('warning','danger') AND e.timemodified > :since";
    $params = ['since' => $lastTs];
    if ($courseid) {
        $where  .= ' AND e.courseid = :courseid';
        $params['courseid'] = $courseid;
    }

    try {
        $rows = $DB->get_records_sql(
            "SELECT e.id, e.sessionid, e.userid, e.event_type, e.severity,
                    e.event_data, e.timemodified, u.firstname, u.lastname
               FROM {quizaccess_learnshield_events} e
               JOIN {user} u ON u.id = e.userid
              WHERE $where
           ORDER BY e.timemodified ASC LIMIT 20",
            $params
        );

        if (!empty($rows)) {
            $alerts = [];
            foreach ($rows as $row) {
                $lastTs = max($lastTs, $row->timemodified);
                $alerts[] = [
                    'id'         => $row->id,
                    'sessionid'  => $row->sessionid,
                    'userid'     => $row->userid,
                    'fullname'   => fullname($row),
                    'event_type' => $row->event_type,
                    'severity'   => $row->severity,
                    'event_data' => $row->event_data,
                    'ts'         => $row->timemodified,
                ];
            }
            ls_sse_send('alerts', ['alerts' => $alerts, 'since' => $lastTs]);
        } else {
            // Send heartbeat so browser keeps connection alive.
            ls_sse_send('ping', ['ts' => time()]);
        }
    } catch (\Exception $e) {
        ls_sse_send('error', ['message' => 'DB error']);
        break;
    }

    sleep($loopSleep);
}

// Tell client to reconnect.
echo "retry: 3000\n\n";
flush();
