<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Shared helpers for the background-built Storage Management ZIP exports — used by both
 * classes/task/build_storage_export.php (the ad-hoc task that builds the file) and
 * storage.php (which reads these same paths to show status: not started / building /
 * ready / failed). No database table for this deliberately — a handful of marker files
 * in a dedicated export directory is enough and needs no schema change.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard;

defined('MOODLE_INTERNAL') || die();

class storage_export {

    /**
     * Dedicated directory for these exports — deliberately under dataroot, not
     * $CFG->tempdir, since Moodle's own periodic temp-directory cleanup can remove
     * files there more aggressively than an admin might expect while a large export
     * is sitting ready to be downloaded.
     */
    public static function export_dir(): string {
        global $CFG;
        $dir = $CFG->dataroot . '/learnguard_exports';
        if (!is_dir($dir)) {
            @mkdir($dir, $CFG->directorypermissions ?? 0777, true);
        }
        return $dir;
    }

    /**
     * Current state of one type's export: 'none', 'building', 'ready', or 'error'.
     *
     * @return array{status:string, path:?string, size:?int, mtime:?int, error:?string}
     */
    public static function status(string $type): array {
        $dir = self::export_dir();
        $zipPath      = $dir . '/' . $type . '.zip';
        $buildingPath = $dir . '/' . $type . '.building';
        $errorPath    = $dir . '/' . $type . '.error';

        if (file_exists($errorPath)) {
            return ['status' => 'error', 'path' => null, 'size' => null, 'mtime' => null,
                'error' => file_get_contents($errorPath)];
        }
        if (file_exists($buildingPath)) {
            return ['status' => 'building', 'path' => null, 'size' => null, 'mtime' => null, 'error' => null];
        }
        if (file_exists($zipPath)) {
            return ['status' => 'ready', 'path' => $zipPath, 'size' => filesize($zipPath),
                'mtime' => filemtime($zipPath), 'error' => null];
        }
        return ['status' => 'none', 'path' => null, 'size' => null, 'mtime' => null, 'error' => null];
    }
}
