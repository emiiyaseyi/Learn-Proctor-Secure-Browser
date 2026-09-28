<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard block plugin — edwiser-style grading queue and proctor dashboard.
 *
 * Shows a course selector dropdown. Below it:
 *  - Graders: per-assessment stats (requires grading / graded / not attempted) + Grade button.
 *  - Proctors: per-assessment active/suspended learner counts + Live Monitor button.
 *  - Site admins: quick link to site-admin overview.
 *
 * @package    block_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2026 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class block_learnguard extends block_base {

    public function init(): void {
        $this->title = get_string('pluginname', 'block_learnguard');
    }

    public function applicable_formats(): array {
        return ['all' => true];
    }

    public function has_config(): bool {
        return false;
    }

    public function get_content(): ?stdClass {
        global $DB, $USER, $CFG, $OUTPUT;

        if (!isloggedin() || isguestuser()) { return null; }
        if ($this->content !== null)         { return $this->content; }

        $this->content         = new stdClass();
        $this->content->footer = '';

        $uid     = (int)$USER->id;
        $sysctx  = context_system::instance();
        $isSysAdmin = has_capability('moodle/site:config', $sysctx);

        // ── Grading Units restriction (mod_learnguard's Grading Units feature) ──
        // A grader restricted to specific units (e.g. "Strategy") shouldn't see an
        // activity in their queue at all if it has no gradable content in any of
        // their allowed units — otherwise every restricted grader's dashboard still
        // lists every activity they're assigned to, with a "Requires Grading" count
        // that includes work that isn't actually theirs to grade (grader.php itself
        // already filters this correctly; this widget didn't know the feature existed).
        // Never applies to site admins, who are never restricted.
        $myUnitIds = [];
        $myUnitNames = [];
        if (!$isSysAdmin) {
            try {
                $myUnitIds = array_map('intval', $DB->get_fieldset_select(
                    'learnguard_grader_units', 'unitid', 'userid = :uid', ['uid' => $uid]));
            } catch (Exception $e) { $myUnitIds = []; }
            if (!empty($myUnitIds)) {
                [$nameInSql, $nameInParams] = $DB->get_in_or_equal($myUnitIds);
                $myUnitNames = array_values($DB->get_fieldset_select(
                    'learnguard_grading_units', 'name', "id $nameInSql", $nameInParams));
            }
        }
        $isUnitRestricted = !empty($myUnitIds);
        // Only ever NARROWS which activities show — an activity with no unit-tagged
        // content at all (the common case, nothing changes until units are actually
        // set up) is never excluded by this: EXISTS is only checked against slots
        // whose resolved unit is neither 0 (unassigned) nor in the allowed list, so
        // "no units configured" and "no restriction" both behave exactly as before.
        $unitFilterSQL = '';
        $unitFilterParams = [];
        if ($isUnitRestricted) {
            // Reported live: "Incorrect number of query parameters. Expected 4, got 3" for
            // any grader with exactly one restricted unit (the count scales with how many
            // units they're restricted to — this was always broken, just never triggered
            // before now, since it needs a grader to actually have a Grading Units
            // restriction set at all, which is the feature this whole session has been
            // building). Root cause: $uidsSql (e.g. "IN (:gu1)") was pasted into the SQL
            // text TWICE — once for the essay EXISTS clause, once for choiceessay — so the
            // same named placeholder appeared twice in the query, but $unitFilterParams
            // only ever supplied it once. Two independently-named placeholder sets fixes it
            // regardless of how many units are involved.
            [$uidsSqlEssay, $uidsParamsEssay] = $DB->get_in_or_equal($myUnitIds, SQL_PARAMS_NAMED, 'guess');
            [$uidsSqlCe, $uidsParamsCe] = $DB->get_in_or_equal($myUnitIds, SQL_PARAMS_NAMED, 'guce');
            $unitFilterSQL = "
               AND (
                   EXISTS (SELECT 1 FROM {learnguard_slots} rs
                             JOIN {learnguard_qbank} rq ON rq.id = rs.questionid AND rq.qtype = 'essay'
                            WHERE rs.learnguard = lg.id AND (rq.unitid = 0 OR rq.unitid $uidsSqlEssay))
                OR EXISTS (SELECT 1 FROM {learnguard_slots} rs2
                             JOIN {learnguard_qbank} rq2 ON rq2.id = rs2.questionid AND rq2.qtype = 'choiceessay'
                             JOIN {learnguard_ce_prompts} rcp ON rcp.questionid = rq2.id
                            WHERE rs2.learnguard = lg.id AND (rcp.unitid = 0 OR rcp.unitid $uidsSqlCe))
               )";
            $unitFilterParams = array_merge($uidsParamsEssay, $uidsParamsCe);
        }

        // ── Build course-grouped grader data ────────────────────────────────
        // Site admins see ALL activities (no grader-assignment filter).
        // Other users see only activities they are explicitly assigned to grade.
        // hasgradable: an assessment with NO essay/choiceessay slots at all (e.g.
        // MCQ-only) has nothing that ever needs manual grading — every finished attempt
        // there is already fully auto-graded and should count as Graded, not Requires
        // Grading. Without this, my_graded was always NULL for such an activity (there's
        // nothing to grade, so no learnguard_essay_grades rows can ever exist), which
        // permanently misclassified every one of its finished attempts as pending.
        // cm.deletioninprogress = 0: reported live — a deleted assessment ("dsdsd") kept
        // showing here (and on Score Matrix) with a live Requires Grading count, even
        // though it no longer appeared in the course. course_delete_module() marks this
        // flag immediately, but the actual learnguard/course_modules row deletion only
        // completes when core's adhoc deletion task next runs via cron — which can be
        // minutes to hours later, or indefinitely if cron isn't confirmed running. Without
        // this filter every query here kept including it for that whole window. Same fix
        // already applied to scorematrix.php's own assessment list for the same reason.
        // a.id IS NOT NULL — reported live: "Technical Essay Assessment" showed "Requires
        // Grading: 1" here while grader.php itself showed 0 attempts total (an impossible
        // combination — you can't need grading with zero finished attempts). Root cause:
        // the LEFT JOIN to learnguard_attempts still produces exactly one row (with every
        // a.* column NULL) for an activity with ZERO finished attempts — standard SQL LEFT
        // JOIN behaviour. COUNT(DISTINCT a.id) correctly ignores that NULL and reports 0,
        // but the needs_grading/graded_count CASE expressions never checked a.id itself —
        // they only tested has_gradable/my_graded, both of which can independently be
        // "true" for that phantom NULL row (has_gradable comes from a separate subquery on
        // lg.id, not on the attempt; my_graded IS NULL is trivially true when there's no
        // real attempt to have graded). So an activity with gradable content and zero
        // finished attempts was silently counted as needing exactly 1 grade, every time.
        $graderBaseSQL = "
            SELECT lg.id        AS lgid,
                   lg.name      AS lgname,
                   cm.id        AS cmid,
                   c.id         AS courseid,
                   c.shortname  AS coursename,
                   c.fullname   AS coursefullname,
                   COUNT(DISTINCT a.id)                         AS total_finished,
                   COALESCE(SUM(CASE WHEN a.id IS NOT NULL AND COALESCE(hg.has_gradable, 0) = 1
                                       AND (myeg.my_graded IS NULL OR myeg.my_graded = 0)
                                      THEN 1 ELSE 0 END), 0) AS needs_grading,
                   COALESCE(SUM(CASE WHEN a.id IS NOT NULL AND (COALESCE(hg.has_gradable, 0) = 0
                                       OR myeg.my_graded > 0)
                                      THEN 1 ELSE 0 END), 0) AS graded_count
              FROM {learnguard}          lg
              JOIN {course_modules}      cm  ON cm.instance = lg.id AND cm.deletioninprogress = 0
              JOIN {modules}             mdl ON mdl.id = cm.module AND mdl.name = 'learnguard'
              JOIN {course}              c   ON c.id = cm.course
         LEFT JOIN {learnguard_attempts} a
                ON a.learnguard = lg.id AND a.state = 'finished'
         LEFT JOIN (SELECT attemptid,
                           COUNT(DISTINCT slotid) AS my_graded
                      FROM {learnguard_essay_grades}
                  GROUP BY attemptid) myeg ON myeg.attemptid = a.id
         LEFT JOIN (SELECT DISTINCT s.learnguard, 1 AS has_gradable
                      FROM {learnguard_slots} s
                      JOIN {learnguard_qbank} q ON q.id = s.questionid
                     WHERE q.qtype IN ('essay', 'choiceessay')) hg ON hg.learnguard = lg.id";

        if ($isSysAdmin) {
            // Site admins see every LearnGuard activity.
            $graderSQL    = $graderBaseSQL . " GROUP BY lg.id, lg.name, cm.id, c.id, c.shortname, c.fullname ORDER BY c.shortname ASC, lg.name ASC";
            $graderParams = [];
        } else {
            // Non-admins: only activities where they are assigned as grader
            // (either directly on the activity OR as a global grader with is_global=1).
            $graderSQL    = $graderBaseSQL . "
               WHERE (
                   EXISTS (SELECT 1 FROM {learnguard_graders} g WHERE g.learnguard = lg.id AND g.userid = :uid AND (g.is_global = 0 OR g.is_global IS NULL))
                OR EXISTS (SELECT 1 FROM {learnguard_graders} g WHERE g.userid = :uid2 AND g.is_global = 1)
               )" . $unitFilterSQL . "
             GROUP BY lg.id, lg.name, cm.id, c.id, c.shortname, c.fullname ORDER BY c.shortname ASC, lg.name ASC";
            $graderParams = array_merge(['uid' => $uid, 'uid2' => $uid], $unitFilterParams);
        }

        $graderRows = $DB->get_records_sql($graderSQL, $graderParams);

        // ── Grading Distribution awareness ───────────────────────────────────
        // Reported live: a grader assigned exactly 1 attempt via Grading Distribution
        // (admin.php's Split/Random mode) still saw "Requires Grading: 2" here — this
        // widget's own count above has no idea Distribution exists at all, unlike
        // grader.php itself (which already restricts its view to only that grader's
        // assigned attempts). Recompute needs_grading/graded_count/total_finished for
        // any activity where Distribution is actually active FOR THIS SPECIFIC GRADER
        // (same scoping rule as grader.php: Layer 1 always scoped when assignments
        // exist; Layer 2 only when the admin opted it into its own exclusive split via
        // include_layer2) — restricted to only the attempts learnguard_grader_assignments
        // actually gave them. An activity with no assignments yet (Distribution not run,
        // or mode='all') is left completely untouched, matching grader.php's own
        // "no assignments yet → show everything" fallback.
        if (!$isSysAdmin && !empty($graderRows)) {
            $distLgIds = array_values(array_unique(array_map(fn($r) => (int)$r->lgid, $graderRows)));
            if (!empty($distLgIds)) {
                [$distLgInSql, $distLgInParams] = $DB->get_in_or_equal($distLgIds, SQL_PARAMS_NAMED, 'dlg');

                $distConfigByLg = [];
                try {
                    $distConfigRows = $DB->get_records_sql(
                        "SELECT * FROM {learnguard_grading_config} WHERE learnguard $distLgInSql", $distLgInParams);
                    foreach ($distConfigRows as $dc) { $distConfigByLg[(int)$dc->learnguard] = $dc; }
                } catch (Exception $e) { /* table not yet created — nothing is distributed */ }

                $myLayerByLg = []; $myGlobalLayer = null;
                try {
                    $myGraderRows = $DB->get_records_sql(
                        "SELECT * FROM {learnguard_graders}
                          WHERE userid = :duid AND (learnguard $distLgInSql OR is_global = 1)",
                        array_merge(['duid' => $uid], $distLgInParams)
                    );
                    foreach ($myGraderRows as $mg) {
                        if (!empty($mg->is_global)) { $myGlobalLayer = (int)$mg->gradelayer; }
                        else { $myLayerByLg[(int)$mg->learnguard] = (int)$mg->gradelayer; }
                    }
                } catch (Exception $e) {}

                $myAssignedLgIds = [];
                $myAssignedAttemptIds = [];
                try {
                    $myAssignRows = $DB->get_records_sql(
                        "SELECT id, learnguard, attemptid FROM {learnguard_grader_assignments}
                          WHERE graderid = :duid2 AND learnguard $distLgInSql",
                        array_merge(['duid2' => $uid], $distLgInParams)
                    );
                    foreach ($myAssignRows as $ma) {
                        $myAssignedLgIds[(int)$ma->learnguard] = true;
                        $myAssignedAttemptIds[] = (int)$ma->attemptid;
                    }
                } catch (Exception $e) {}

                foreach ($graderRows as $r) {
                    $dlgid = (int)$r->lgid;
                    $dcfg  = $distConfigByLg[$dlgid] ?? null;
                    $dmode = $dcfg->gradingmode ?? 'all';
                    if (!in_array($dmode, ['split', 'random'], true)) { continue; }
                    $dIncludeLayer2 = !empty($dcfg->include_layer2 ?? 0);
                    $dMyLayer = $myLayerByLg[$dlgid] ?? $myGlobalLayer ?? 1;
                    $dIsScope = ($dMyLayer == 1) || ($dMyLayer == 2 && $dIncludeLayer2);
                    if (!$dIsScope || empty($myAssignedLgIds[$dlgid])) { continue; }

                    $dAttemptIds = array_values(array_unique($myAssignedAttemptIds)) ?: [-1];
                    [$dAidSql, $dAidParams] = $DB->get_in_or_equal($dAttemptIds, SQL_PARAMS_NAMED, 'daid');
                    $recomputed = $DB->get_record_sql(
                        "SELECT COUNT(DISTINCT a.id) AS total_finished,
                                COALESCE(SUM(CASE WHEN COALESCE(hg.has_gradable, 0) = 1
                                                    AND (myeg.my_graded IS NULL OR myeg.my_graded = 0)
                                                   THEN 1 ELSE 0 END), 0) AS needs_grading,
                                COALESCE(SUM(CASE WHEN COALESCE(hg.has_gradable, 0) = 0
                                                    OR myeg.my_graded > 0
                                                   THEN 1 ELSE 0 END), 0) AS graded_count
                           FROM {learnguard_attempts} a
                      LEFT JOIN (SELECT attemptid, COUNT(DISTINCT slotid) AS my_graded
                                   FROM {learnguard_essay_grades} GROUP BY attemptid) myeg ON myeg.attemptid = a.id
                      LEFT JOIN (SELECT DISTINCT s.learnguard, 1 AS has_gradable
                                   FROM {learnguard_slots} s JOIN {learnguard_qbank} q ON q.id = s.questionid
                                  WHERE q.qtype IN ('essay', 'choiceessay')) hg ON hg.learnguard = a.learnguard
                          WHERE a.learnguard = :dlgid2 AND a.state = 'finished' AND a.id $dAidSql",
                        array_merge(['dlgid2' => $dlgid], $dAidParams)
                    );
                    if ($recomputed) {
                        $r->total_finished = (int)$recomputed->total_finished;
                        $r->needs_grading  = (int)$recomputed->needs_grading;
                        $r->graded_count   = (int)$recomputed->graded_count;
                    }
                }
            }
        }

        // ── Build course-grouped proctor data ────────────────────────────────
        // Handles three assignment scopes stored in learnguard_proctors:
        //   1. Activity-specific  (learnguard=X, is_global=0, courseid=0)
        //   2. Course-level       (learnguard=0, is_global=1, courseid=X)
        //   3. Global             (learnguard=0, is_global=1, courseid=0)
        // Proctor section: shows activities ONLY where the user is actually assigned as a
        // proctor (any of the 3 scopes below) — a grader with no proctor access anywhere
        // never matches any of these EXISTS clauses, so this section is correctly absent
        // for them per-course (see the per-course "if (!empty($cProctorRows))" gate below,
        // matching Grading Queue's own "if (!empty($cGraderRows))" gate).
        $proctorSQL = "
            SELECT DISTINCT lg.id        AS lgid,
                            lg.name      AS lgname,
                            cm.id        AS cmid,
                            c.id         AS courseid,
                            c.shortname  AS coursename,
                            c.fullname   AS coursefullname,
                            (SELECT COUNT(*) FROM {learnguard_attempts} aa
                              WHERE aa.learnguard = lg.id AND aa.state = 'inprogress') AS active_count,
                            (SELECT COUNT(*) FROM {learnguard_attempts} aa
                              WHERE aa.learnguard = lg.id AND aa.state = 'suspended') AS suspended_count
              FROM {learnguard}          lg
              JOIN {course_modules}      cm  ON cm.instance = lg.id AND cm.deletioninprogress = 0
              JOIN {modules}             mdl ON mdl.id = cm.module AND mdl.name = 'learnguard'
              JOIN {course}              c   ON c.id = cm.course
             WHERE (
                   EXISTS (SELECT 1 FROM {learnguard_proctors} p
                            WHERE p.userid = :uid  AND p.learnguard = lg.id AND p.is_global = 0)
                OR EXISTS (SELECT 1 FROM {learnguard_proctors} p
                            WHERE p.userid = :uid2 AND p.courseid = c.id   AND p.is_global = 1)
                OR EXISTS (SELECT 1 FROM {learnguard_proctors} p
                            WHERE p.userid = :uid3 AND p.is_global = 1
                            AND (p.courseid = 0 OR p.courseid IS NULL))
             )
          ORDER BY c.shortname ASC, lg.name ASC";

        $proctorRows = $DB->get_records_sql($proctorSQL, ['uid' => $uid, 'uid2' => $uid, 'uid3' => $uid]);

        // ── Collect all distinct courses ─────────────────────────────────────
        $courses   = [];
        foreach (array_merge(array_values($graderRows), array_values($proctorRows)) as $r) {
            if (!isset($courses[$r->courseid])) {
                $courses[$r->courseid] = ['id' => $r->courseid, 'name' => $r->coursefullname, 'short' => $r->coursename];
            }
        }
        // Sort by fullname.
        usort($courses, fn($a, $b) => strcmp($a['name'], $b['name']));

        $hasGrader  = !empty($graderRows);
        $hasProctor = !empty($proctorRows);
        $isEmpty    = !$hasGrader && !$hasProctor && !$isSysAdmin;

        // ── Group rows by course ─────────────────────────────────────────────
        $graderByCourse  = [];
        foreach ($graderRows  as $r) { $graderByCourse[$r->courseid][]  = $r; }
        $proctorByCourse = [];
        foreach ($proctorRows as $r) { $proctorByCourse[$r->courseid][] = $r; }

        // ── Unique block ID (multiple blocks on same page) ───────────────────
        static $blockInstCount = 0;
        $bid = 'lgblk' . (++$blockInstCount);

        ob_start();
        ?>
<style>
#<?php echo $bid; ?> { font-family:inherit; font-size:0.9em; }
#<?php echo $bid; ?> .blg-head { background:#0f6cbf; color:#fff; border-radius:6px 6px 0 0;
  padding:8px 12px; font-weight:700; font-size:0.97em; display:flex; align-items:center; gap:6px; }
#<?php echo $bid; ?> .blg-course-sel {
  width:100%; border:1px solid #dee2e6; border-radius:0; padding:7px 10px;
  font-size:0.9em; background:#f8f9fa; cursor:pointer; }
#<?php echo $bid; ?> .blg-course-block { display:none; padding:0; }
#<?php echo $bid; ?> .blg-course-block.active { display:block; }
#<?php echo $bid; ?> .blg-sect-title {
  font-weight:700; font-size:0.88em; color:#495057; text-transform:uppercase;
  letter-spacing:.04em; padding:8px 10px 4px; border-top:1px solid #f0f0f0; }
#<?php echo $bid; ?> .blg-act-row {
  border-bottom:1px solid #f4f4f4; padding:8px 10px; }
#<?php echo $bid; ?> .blg-act-name {
  font-weight:600; font-size:0.9em; color:#212529; white-space:nowrap;
  overflow:hidden; text-overflow:ellipsis; max-width:100%; display:block; }
#<?php echo $bid; ?> .blg-stats {
  display:flex; gap:6px; margin:4px 0; flex-wrap:wrap; }
#<?php echo $bid; ?> .blg-stat {
  font-size:0.78em; background:#f0f4ff; border:1px solid #cfe2ff; border-radius:4px;
  padding:2px 7px; color:#0a4d8c; font-weight:600; }
#<?php echo $bid; ?> .blg-stat.warn  { background:#fff3cd; border-color:#ffe082; color:#856404; }
#<?php echo $bid; ?> .blg-stat.ok    { background:#d4edda; border-color:#c3e6cb; color:#155724; }
#<?php echo $bid; ?> .blg-stat.danger { background:#f8d7da; border-color:#f5c6cb; color:#721c24; }
#<?php echo $bid; ?> .blg-act-btns {
  display:flex; gap:5px; margin-top:5px; flex-wrap:wrap; }
#<?php echo $bid; ?> .blg-btn {
  background:#0f6cbf; color:#fff; border:none; border-radius:5px;
  padding:4px 12px; font-size:0.82em; font-weight:700; text-decoration:none;
  cursor:pointer; white-space:nowrap; }
#<?php echo $bid; ?> .blg-btn:hover { background:#0a52a2; color:#fff; }
#<?php echo $bid; ?> .blg-btn.secondary {
  background:#fff; color:#0f6cbf; border:1px solid #0f6cbf; }
#<?php echo $bid; ?> .blg-btn.secondary:hover { background:#e8f0fb; }
#<?php echo $bid; ?> .blg-empty { color:#6c757d; font-size:0.85em; padding:6px 10px; }
#<?php echo $bid; ?> .blg-admin-row {
  padding:8px 10px; border-top:1px solid #dee2e6; margin-top:4px; text-align:center; }
</style>

<div id="<?php echo $bid; ?>">
<?php if ($isEmpty): ?>
  <p class="blg-empty">No LearnGuard assignments found for your account.</p>
<?php else: ?>

  <!-- Course selector -->
  <?php if (!empty($courses)): ?>
  <select class="blg-course-sel"
    onchange="(function(sel){var bid='<?php echo $bid; ?>';document.querySelectorAll('#'+bid+' .blg-course-block').forEach(function(b){b.classList.remove('active')});var t=document.getElementById(bid+'-course-'+sel.value);if(t){t.classList.add('active');}})(this)">
    <option value="">— Select a course —</option>
    <?php foreach ($courses as $c): ?>
    <option value="<?php echo (int)$c['id']; ?>"><?php echo s($c['name'] ?: $c['short']); ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <!-- Per-course panels -->
  <?php foreach ($courses as $c):
    $cid = (int)$c['id'];
    $cGraderRows  = $graderByCourse[$cid]  ?? [];
    $cProctorRows = $proctorByCourse[$cid] ?? [];
  ?>
  <div class="blg-course-block" id="<?php echo $bid; ?>-course-<?php echo $cid; ?>">

    <?php if (!empty($cGraderRows)): ?>
    <div class="blg-sect-title">📝 Grading Queue</div>
    <?php if ($isUnitRestricted): ?>
    <p class="blg-empty" style="padding:0 10px 6px;">
      🎯 You're restricted to: <strong><?php echo s(implode(', ', $myUnitNames)); ?></strong> —
      counts below may include other units' questions; open Grade to see exactly what's yours.
    </p>
    <?php endif; ?>
    <?php foreach ($cGraderRows as $r):
      $needsGrading = (int)$r->needs_grading;
      $gradedCount  = (int)$r->graded_count;
      $total        = (int)$r->total_finished;
      $notAttempted = max(0, $total - $needsGrading - $gradedCount);
    ?>
    <div class="blg-act-row">
      <span class="blg-act-name"><?php echo s($r->lgname); ?></span>
      <div class="blg-stats">
        <span class="blg-stat <?php echo $needsGrading > 0 ? 'warn' : ''; ?>">
          Requires Grading: <strong><?php echo $needsGrading; ?></strong>
        </span>
        <span class="blg-stat ok">
          Graded: <strong><?php echo $gradedCount; ?></strong>
        </span>
        <span class="blg-stat">
          Finished: <strong><?php echo $total; ?></strong>
        </span>
      </div>
      <div class="blg-act-btns">
        <a href="<?php echo $CFG->wwwroot . '/mod/learnguard/grader.php?id=' . (int)$r->cmid; ?>"
           class="blg-btn">
          <?php echo $needsGrading > 0 ? '✏️ Grade (' . $needsGrading . ')' : '✅ Review'; ?>
        </a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($cProctorRows)): ?>
    <div class="blg-sect-title">🛡️ Proctoring</div>
    <?php foreach ($cProctorRows as $r):
      $active    = (int)$r->active_count;
      $suspended = (int)$r->suspended_count;
    ?>
    <div class="blg-act-row">
      <span class="blg-act-name"><?php echo s($r->lgname); ?></span>
      <div class="blg-stats">
        <span class="blg-stat ok">Active: <strong><?php echo $active; ?></strong></span>
        <?php if ($suspended > 0): ?>
        <span class="blg-stat danger">Suspended: <strong><?php echo $suspended; ?></strong></span>
        <?php endif; ?>
      </div>
      <div class="blg-act-btns">
        <a href="<?php echo $CFG->wwwroot . '/mod/learnguard/proctordashboard.php?id=' . (int)$r->cmid; ?>"
           class="blg-btn">📺 Live Monitor</a>
        <a href="<?php echo $CFG->wwwroot . '/mod/learnguard/proctorview.php?id=' . (int)$r->cmid; ?>"
           class="blg-btn secondary">🔍 View</a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($cGraderRows) && empty($cProctorRows)): ?>
    <p class="blg-empty">No assignments for this course.</p>
    <?php endif; ?>

  </div><!-- /course-block -->
  <?php endforeach; ?>

  <!-- Auto-select the first course if there is exactly one -->
  <?php if (count($courses) === 1): ?>
  <script>
  (function(){
    var sel = document.querySelector('#<?php echo $bid; ?> .blg-course-sel');
    if (sel) { sel.value = '<?php echo reset($courses)['id']; ?>'; sel.dispatchEvent(new Event('change')); }
  })();
  </script>
  <?php endif; ?>

<?php endif; ?>

  <?php if ($isSysAdmin): ?>
  <div class="blg-admin-row">
    <a class="blg-btn" href="<?php echo $CFG->wwwroot . '/mod/learnguard/siteadmin.php'; ?>">⚙️ LearnGuard Admin</a>
  </div>
  <?php endif; ?>
</div><!-- /block -->
<?php
        $this->content->text = ob_get_clean();
        return $this->content;
    }
}
