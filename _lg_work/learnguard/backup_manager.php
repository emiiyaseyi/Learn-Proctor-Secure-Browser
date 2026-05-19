<?php
/**
 * LearnGuard backup and restoration manager.
 *
 * Full backup: question bank, assessments config, snapshots metadata,
 * attempt records, events, proctors, graders.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/mod/learnguard/backup_manager.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Backup & Restore');
$PAGE->set_heading('LearnGuard — Backup & Restore');
$PAGE->requires->css('/mod/learnguard/styles.css');

$action = optional_param('action', '', PARAM_ALPHA);

// ── DOWNLOAD BACKUP ─────────────────────────────────────────────────────────
if ($action === 'download' && confirm_sesskey()) {
    $scope = optional_param('scope', 'full', PARAM_ALPHA);

    $export = [
        'learnguard_version' => '1.3.8',
        'exported_at'        => date('Y-m-d H:i:s'),
        'scope'              => $scope,
        'data'               => [],
    ];

    $tables = [
        'full' => [
            'learnguard'           => 'SELECT * FROM {learnguard}',
            'learnguard_slots'     => 'SELECT * FROM {learnguard_slots}',
            'learnguard_attempts'  => 'SELECT * FROM {learnguard_attempts}',
            'learnguard_events'    => 'SELECT * FROM {learnguard_events}',
            'learnguard_proctors'  => 'SELECT * FROM {learnguard_proctors}',
            'learnguard_graders'   => 'SELECT * FROM {learnguard_graders}',
            'learnguard_incidents' => 'SELECT * FROM {learnguard_incidents}',
        ],
        'questions' => [
            'learnguard_qbank'         => 'SELECT * FROM {learnguard_qbank}',
            'learnguard_qbank_cats'    => 'SELECT * FROM {learnguard_qbank_cats}',
            'learnguard_qbank_answers' => 'SELECT * FROM {learnguard_qbank_answers}',
        ],
        'settings' => [
            'learnguard' => 'SELECT * FROM {learnguard}',
        ],
        'results' => [
            'learnguard_attempts'    => 'SELECT * FROM {learnguard_attempts}',
            'learnguard_essay_grades'=> 'SELECT * FROM {learnguard_essay_grades}',
        ],
    ];

    $selected = $tables[$scope] ?? $tables['full'];

    // Always include question bank in full backup
    if ($scope === 'full') {
        $selected['learnguard_qbank']         = 'SELECT * FROM {learnguard_qbank}';
        $selected['learnguard_qbank_cats']    = 'SELECT * FROM {learnguard_qbank_cats}';
        $selected['learnguard_qbank_answers'] = 'SELECT * FROM {learnguard_qbank_answers}';
        $selected['learnguard_essay_grades']  = 'SELECT * FROM {learnguard_essay_grades}';
        $selected['learnguard_audio_clips']   = 'SELECT id, attemptid, userid, duration, noise_level, timecreated FROM {learnguard_audio_clips}';
        // Note: snapshot_url (base64) excluded from full backup to keep file size manageable
        $selected['learnguard_snapshots_meta']= 'SELECT id, attemptid, userid, snapshot_type, timemodified FROM {learnguard_snapshots}';
    }

    foreach ($selected as $tableName => $sql) {
        try {
            $rows = $DB->get_records_sql($sql);
            $export['data'][$tableName] = array_values((array)$rows);
        } catch (Exception $e) {
            $export['data'][$tableName] = ['error' => $e->getMessage()];
        }
    }

    $json     = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $filename = 'learnguard_backup_' . $scope . '_' . date('Ymd_His') . '.json';

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

// ── RESTORE FROM UPLOAD ──────────────────────────────────────────────────────
$restoreResult = null;
if ($action === 'restore' && confirm_sesskey() && isset($_FILES['backup_file'])) {
    $file = $_FILES['backup_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($file['tmp_name']);
        $data    = json_decode($content, true);
        if (!$data || empty($data['data']) || empty($data['learnguard_version'])) {
            $restoreResult = ['success' => false, 'msg' => 'Invalid or corrupt backup file.'];
        } else {
            $restoreMode = optional_param('restore_mode', 'merge', PARAM_ALPHA);
            $restored    = 0;
            $errors      = [];
            // Restore only safe tables — never overwrite core Moodle tables.
            $restorableTables = [
                'learnguard_qbank', 'learnguard_qbank_cats', 'learnguard_qbank_answers',
                'learnguard_proctors', 'learnguard_graders',
            ];
            foreach ($restorableTables as $tbl) {
                if (empty($data['data'][$tbl])) { continue; }
                foreach ($data['data'][$tbl] as $row) {
                    $row = (object)$row;
                    unset($row->id); // let DB assign new ID
                    try {
                        $DB->insert_record($tbl, $row);
                        $restored++;
                    } catch (Exception $e) {
                        $errors[] = $tbl . ': ' . $e->getMessage();
                    }
                }
            }
            $restoreResult = [
                'success' => true,
                'msg'     => $restored . ' records restored successfully.' .
                             ($errors ? ' Errors: ' . implode('; ', array_slice($errors, 0, 5)) : ''),
            ];
        }
    } else {
        $restoreResult = ['success' => false, 'msg' => 'Upload failed (error code ' . $file['error'] . ').'];
    }
}

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Backup & Restore');
echo learnguard_banner('💾 Backup & Restore', 'Export and restore LearnGuard data, settings, questions, and results.', '💾');

if ($restoreResult) {
    $cls = $restoreResult['success'] ? 'alert-success' : 'alert-danger';
    echo html_writer::div($restoreResult['msg'], 'alert ' . $cls);
}
?>

<div style="max-width:960px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:24px;font-family:Segoe UI,system-ui,sans-serif;">

  <!-- Download / Export -->
  <div style="background:#fff;border:1px solid #dee2e6;border-radius:12px;padding:24px;">
    <h4 style="margin-bottom:4px;">⬇ Download Backup</h4>
    <p style="font-size:13px;color:#6c757d;margin-bottom:16px;">Export a JSON snapshot of your LearnGuard data.</p>

    <form method="post" action="<?php echo new moodle_url('/mod/learnguard/backup_manager.php', ['action' => 'download', 'sesskey' => sesskey()]); ?>">
      <div class="mb-3">
        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Backup Scope</label>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ([
              'full'      => ['💾 Full Backup', 'Everything: questions, assessments, attempts, events, proctors, graders (excludes large snapshot images)'],
              'questions' => ['🏦 Question Bank Only', 'All questions, categories, and answer options'],
              'settings'  => ['⚙️ Assessment Settings', 'Assessment configurations and settings only'],
              'results'   => ['📊 Results Only', 'Attempt records and essay grades'],
          ] as $val => [$lbl, $desc]): ?>
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px;border:1px solid #dee2e6;border-radius:8px;">
            <input type="radio" name="scope" value="<?php echo $val; ?>" <?php echo $val === 'full' ? 'checked' : ''; ?> style="margin-top:3px;">
            <span>
              <strong style="font-size:13px;"><?php echo $lbl; ?></strong><br>
              <small style="color:#6c757d;"><?php echo $desc; ?></small>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100">⬇ Download JSON Backup</button>
    </form>
  </div>

  <!-- Restore / Import -->
  <div style="background:#fff;border:1px solid #dee2e6;border-radius:12px;padding:24px;">
    <h4 style="margin-bottom:4px;">⬆ Restore Backup</h4>
    <p style="font-size:13px;color:#6c757d;margin-bottom:16px;">Upload a previously downloaded LearnGuard JSON backup file.</p>

    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;">
      <strong>⚠️ Important:</strong> Restore only adds new records — it does not overwrite existing data. For full replacement, clear the target tables first via the admin backend.
    </div>

    <form method="post" enctype="multipart/form-data" action="<?php echo new moodle_url('/mod/learnguard/backup_manager.php', ['action' => 'restore', 'sesskey' => sesskey()]); ?>">
      <div class="mb-3">
        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Backup File (.json)</label>
        <input type="file" name="backup_file" accept=".json,application/json" required class="form-control">
      </div>
      <button type="submit" class="btn btn-warning w-100" onclick="return confirm('Restore data from this backup? Existing records will NOT be deleted — new records will be added.')">⬆ Restore from Backup</button>
    </form>

    <hr style="margin:20px 0;">
    <h6>Recent Backups</h6>
    <p style="font-size:13px;color:#6c757d;">Backups are downloaded to your browser — check your Downloads folder.</p>
  </div>

</div>

<div style="max-width:960px;margin:24px auto 0;background:#fff;border:1px solid #dee2e6;border-radius:12px;padding:20px;">
  <h5>📋 Current Data Summary</h5>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-top:12px;">
    <?php
    $counts = [
        'Assessments'  => $DB->count_records('learnguard'),
        'Questions'    => $DB->count_records('learnguard_qbank'),
        'Attempts'     => $DB->count_records('learnguard_attempts'),
        'Snapshots'    => $DB->count_records('learnguard_snapshots'),
        'Events'       => $DB->count_records('learnguard_events'),
        'Proctors'     => $DB->count_records('learnguard_proctors'),
        'Incidents'    => $DB->count_records('learnguard_incidents'),
    ];
    $colours = ['#0f6cbf','#28a745','#6f42c1','#fd7e14','#dc3545','#20c997','#856404'];
    $i = 0;
    foreach ($counts as $label => $count):
        $col = $colours[$i++ % count($colours)];
    ?>
    <div style="background:<?php echo $col; ?>;color:#fff;border-radius:10px;padding:14px;text-align:center;">
      <div style="font-size:26px;font-weight:700;"><?php echo number_format($count); ?></div>
      <div style="font-size:12px;opacity:.85;"><?php echo $label; ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php echo $OUTPUT->footer(); ?>
