<?php
// Lightweight AJAX endpoint for the proctor dashboard alert inbox.
// Returns new high/medium severity events since a given timestamp.
// URL: /mod/learnguard/proctor_alerts_ajax.php?id={cmid}&since={ts}&sesskey={key}

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$id      = required_param('id',      PARAM_INT);
$since   = required_param('since',   PARAM_INT);
$sesskey = required_param('sesskey', PARAM_RAW);

if (!confirm_sesskey()) { die(json_encode(['alerts' => []])); }

$cm      = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_login(null, true, $cm);
require_capability('mod/learnguard:liveproctor', $context);

$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

$alerts = $DB->get_records_sql(
    "SELECT e.id, e.event_type, e.severity, e.score_impact, e.timemodified,
            u.firstname, u.lastname
       FROM {learnguard_events} e
       JOIN {learnguard_attempts} a ON a.id = e.attemptid
       JOIN {user} u ON u.id = e.userid
      WHERE a.learnguard = :lgid
        AND e.severity IN ('high','medium')
        AND e.timemodified > :since
      ORDER BY e.timemodified DESC
      LIMIT 20",
    ['lgid' => $instance->id, 'since' => $since]
);

$out = [];
foreach ($alerts as $a) {
    $out[] = [
        'id'           => (int)$a->id,
        'event_type'   => $a->event_type,
        'severity'     => $a->severity,
        'score_impact' => (int)$a->score_impact,
        'timemodified' => (int)$a->timemodified,
        'fullname'     => fullname($a),
    ];
}

header('Content-Type: application/json');
echo json_encode(['alerts' => $out]);
