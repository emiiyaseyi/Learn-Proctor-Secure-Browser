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
 * Orphaned-data report — read-only. Reports rows left behind by activity deletions
 * that happened before learnguard_delete_instance() was fixed to cascade through the
 * full current schema (v2.0.100). Never deletes anything; just counts and, for a quick
 * sanity check, lists the first few IDs per table so an admin can inspect before
 * deciding what — if anything — to clean up.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url('/mod/learnguard/orphan_data_report.php');
$PAGE->set_title('LearnGuard — Orphaned Data Report');
$PAGE->set_heading('LearnGuard — Orphaned Data Report');
$PAGE->requires->css('/mod/learnguard/styles.css');

/**
 * Count + sample rows in $table whose $col value has no matching row in $refTable.$refCol.
 * Read-only — never deletes.
 */
function lg_orphan_check(string $table, string $col, string $refTable, string $refCol, string $extraWhere = ''): array {
    global $DB;
    if (!$DB->get_manager()->table_exists($table)) {
        return ['count' => 0, 'sample' => []];
    }
    $where = "NOT EXISTS (SELECT 1 FROM {" . $refTable . "} r WHERE r.$refCol = t.$col)";
    if ($extraWhere !== '') { $where = "$extraWhere AND $where"; }
    $count = (int)$DB->count_records_sql("SELECT COUNT(*) FROM {" . $table . "} t WHERE $where");
    $sample = [];
    if ($count > 0) {
        $sample = $DB->get_fieldset_sql(
            "SELECT t.id FROM {" . $table . "} t WHERE $where ORDER BY t.id ASC", [], 0, 10
        );
    }
    return ['count' => $count, 'sample' => $sample];
}

// Activity-scoped tables: orphaned when their learnguard id no longer exists.
$directChecks = [
    'learnguard_slots'              => ['learnguard', 'Per-activity question slot assignments'],
    'learnguard_pools'               => ['learnguard', 'Random-draw pool rules'],
    'learnguard_incidents'           => ['learnguard', 'Proctor-flagged incidents'],
    'learnguard_messages'            => ['learnguard', 'Proctor/student chat messages'],
    'learnguard_schedules'           => ['learnguard', 'Per-learner date/time window overrides'],
    'learnguard_brand_overrides'     => ['learnguard', 'Per-activity branding overrides'],
    'learnguard_grading_config'      => ['learnguard', 'Essay grading distribution settings'],
    'learnguard_grader_assignments'  => ['learnguard', 'Layer-1 grader-to-attempt assignments'],
    'learnguard_attempts'            => ['learnguard', 'Attempt records (should be rare — these were already cleaned up by the old code)'],
];
$results = [];
foreach ($directChecks as $table => [$col, $desc]) {
    $results[$table] = lg_orphan_check($table, $col, 'learnguard', 'id') + ['desc' => $desc];
}

// Proctors/graders: only rows scoped to one activity (learnguard > 0) can be orphaned —
// is_global=1 / course-level rows use learnguard=0 and aren't tied to any one activity.
foreach (['learnguard_proctors' => 'Proctor assignments', 'learnguard_graders' => 'Grader assignments'] as $table => $desc) {
    $results[$table] = lg_orphan_check($table, 'learnguard', 'learnguard', 'id', 't.learnguard > 0') + ['desc' => $desc];
}

// Attempt-scoped child tables: orphaned when their attemptid no longer exists in
// learnguard_attempts — these are the ones the old delete function never cleaned up.
$attemptChildren = [
    'learnguard_snapshots'        => 'Webcam/screen/360° snapshots and recordings',
    'learnguard_events'           => 'Proctoring violation log entries',
    'learnguard_attempt_questions'=> 'Per-attempt question order',
    'learnguard_responses'        => 'Student answers',
    'learnguard_response_history' => 'Answer-change audit log',
    'learnguard_essay_grades'     => 'Manual essay/MCQ/SA grades',
    'learnguard_audio_clips'      => 'Recorded audio clips',
    'learnguard_attempt_sections' => 'Per-section timer state',
];
foreach ($attemptChildren as $table => $desc) {
    $results[$table] = lg_orphan_check($table, 'attemptid', 'learnguard_attempts', 'id') + ['desc' => $desc];
}

// Precheck-time tables: keyed by course module id, orphaned when the cmid no longer exists.
foreach ([
    'learnguard_face_approvals'    => 'Pre-exam face/ID approval requests',
    'learnguard_precheck_sessions' => 'In-progress precheck wizard tracking',
] as $table => $desc) {
    $results[$table] = lg_orphan_check($table, 'cmid', 'course_modules', 'id') + ['desc' => $desc];
}

// Face-approval chat messages: orphaned when their approval request is gone.
$results['learnguard_fa_messages'] = lg_orphan_check(
    'learnguard_fa_messages', 'approvalid', 'learnguard_face_approvals', 'id'
) + ['desc' => 'Chat messages tied to face approval requests'];

$totalOrphaned = array_sum(array_column($results, 'count'));

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Orphaned Data Report');
echo learnguard_banner('Orphaned Data Report',
    'Read-only — counts rows left behind by activity deletions before learnguard_delete_instance() ' .
    'was fixed to cascade through the full schema (v2.0.100). Nothing here is deleted automatically.', '🔍');
?>
<div style="max-width:1000px;">

  <?php if ($totalOrphaned === 0): ?>
  <div class="alert alert-success">✅ No orphaned data found — every row checked has a live parent record.</div>
  <?php else: ?>
  <div class="alert alert-warning">
    <strong>⚠️ <?php echo $totalOrphaned; ?> orphaned row(s) found</strong> across the tables below.
    This is historical — left behind by activity deletions made before this report existed.
    Nothing has been deleted. Review the sample IDs before deciding whether to clean anything up.
  </div>
  <?php endif; ?>

  <table class="generaltable w-100" style="font-size:13px;">
    <thead>
      <tr>
        <th style="padding:8px 10px;">Table</th>
        <th style="padding:8px 10px;">What it stores</th>
        <th style="padding:8px 10px;text-align:center;">Orphaned rows</th>
        <th style="padding:8px 10px;">Sample IDs (first 10)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($results as $table => $r): ?>
      <tr<?php echo $r['count'] > 0 ? ' style="background:#fff8e1;"' : ''; ?>>
        <td style="padding:8px 10px;font-family:monospace;font-size:12px;"><?php echo s($table); ?></td>
        <td style="padding:8px 10px;color:#6c757d;"><?php echo s($r['desc']); ?></td>
        <td style="padding:8px 10px;text-align:center;">
          <?php if ($r['count'] > 0): ?>
            <strong style="color:#dc3545;"><?php echo $r['count']; ?></strong>
          <?php else: ?>
            <span style="color:#28a745;">0</span>
          <?php endif; ?>
        </td>
        <td style="padding:8px 10px;font-family:monospace;font-size:11px;color:#6c757d;">
          <?php echo !empty($r['sample']) ? implode(', ', $r['sample']) . ($r['count'] > 10 ? ' …' : '') : '—'; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p style="font-size:12px;color:#6c757d;margin-top:16px;">
    Not checked here — deliberately not counted as "orphaned": the shared question bank
    (<code>learnguard_qbank</code>, <code>learnguard_qbank_cats</code>, <code>learnguard_qbank_answers</code>,
    <code>learnguard_ce_prompts</code>) and <code>learnguard_learner_cohorts</code>. Both are resources other
    activities can still reference, so they aren't tied to any single deleted activity.
  </p>
</div>
<?php
echo $OUTPUT->footer();
