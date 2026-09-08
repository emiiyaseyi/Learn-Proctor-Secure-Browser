<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Ad-hoc task: builds a ZIP of every learnguard_snapshots row of one type, in the
 * background — no HTTP request/gateway timeout applies to a cron-run task, unlike the
 * web-request-driven download this replaces, which was confirmed live to hit a hard
 * "Gateway Timeout" even at a bounded 5,000-item batch size. Runs once, writes the
 * result to a dedicated export directory under dataroot (not tempdir, which Moodle's
 * own cleanup can clear more aggressively), with simple marker files storage.php reads
 * to show status — deliberately no new database table for this.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class build_storage_export extends \core\task\adhoc_task {

    public function execute(): void {
        global $DB, $CFG;

        $data = (array)$this->get_custom_data();
        $type = $data['type'] ?? '';
        if ($type === '') {
            mtrace('LearnGuard export: no type supplied, aborting.');
            return;
        }

        $dir = \mod_learnguard\storage_export::export_dir();
        $buildingPath = $dir . '/' . $type . '.building';
        $zipPath      = $dir . '/' . $type . '.zip';
        $tmpZipPath   = $dir . '/' . $type . '.zip.tmp';
        $errorPath    = $dir . '/' . $type . '.error';

        @unlink($errorPath);
        @unlink($zipPath);
        touch($buildingPath);

        try {
            if (!class_exists('ZipArchive')) {
                throw new \Exception('The PHP zip extension is not available on this server.');
            }

            $rows = $DB->get_records_sql(
                "SELECT s.id, s.snapshot_type, s.snapshot_url, s.timemodified, u.firstname, u.lastname
                   FROM {learnguard_snapshots} s
                   JOIN {user} u ON u.id = s.userid
                  WHERE s.snapshot_type = :t
               ORDER BY s.id ASC",
                ['t' => $type]
            );

            $zip = new \ZipArchive();
            if ($zip->open($tmpZipPath, \ZipArchive::OVERWRITE) !== true) {
                throw new \Exception('Could not create the ZIP archive on the server.');
            }
            $n = 0;
            foreach ($rows as $r) {
                $ext = (in_array($r->snapshot_type, ['360_video'], true) || strpos($r->snapshot_type, 'screen') !== false)
                    ? 'webm' : 'jpg';
                $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $r->firstname . '_' . $r->lastname);
                $fname = $r->id . '_' . $r->snapshot_type . '_' . $safeName . '_' .
                    userdate($r->timemodified, '%Y%m%d_%H%M%S') . '.' . $ext;
                $binary = base64_decode($r->snapshot_url);
                $zip->addFromString($fname, $binary);
                // Already-compressed image/video content gains nothing from ZIP's own
                // DEFLATE pass and just burns CPU time attempting it — store as-is.
                $zip->setCompressionName($fname, \ZipArchive::CM_STORE);
                unset($binary);
                $n++;
                if ($n % 5000 === 0) {
                    mtrace("  LearnGuard export ($type): $n items zipped so far…");
                }
            }
            $zip->close();

            // Atomic-ish: only becomes visible under its real name once fully written,
            // so storage.php can never see a partially-built file as "ready".
            rename($tmpZipPath, $zipPath);
            mtrace("LearnGuard export ($type) complete: $n item(s).");
        } catch (\Throwable $e) {
            @unlink($tmpZipPath);
            file_put_contents($errorPath, $e->getMessage());
            mtrace('LearnGuard export (' . $type . ') FAILED: ' . $e->getMessage());
        } finally {
            @unlink($buildingPath);
        }
    }
}
