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
 * External storage adapter for Learn Shield.
 * Supports: Moodle only | Google Drive | OneDrive / SharePoint.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles file upload to external storage (Google Drive or OneDrive) in addition to Moodle.
 *
 * Folder structure in external storage:
 *   {root_folder}/{Course_Name}/{Quiz_Name}/{Learner_Name}/filename
 *
 * Setup requirements:
 *   Google Drive: Service account JSON key uploaded via settings. Folder shared with service account.
 *   OneDrive:     App registration in Azure AD. Client ID + secret + tenant ID in settings.
 */
class storage_adapter {

    const TYPE_MOODLE   = 'moodle';
    const TYPE_GDRIVE   = 'gdrive';
    const TYPE_ONEDRIVE = 'onedrive';

    /**
     * Upload a file to the configured external storage.
     *
     * @param string $filecontents  Raw binary file content.
     * @param string $filename      Target filename.
     * @param string $coursename    Course name (used for folder structure).
     * @param string $quizname      Quiz name (used for folder structure).
     * @param string $learnername   Learner full name.
     * @param string $mimetype      MIME type of the file.
     * @return string|false         External URL of the uploaded file, or false on failure.
     */
    public static function upload(
        string $filecontents,
        string $filename,
        string $coursename,
        string $quizname,
        string $learnername,
        string $mimetype = 'image/png'
    ) {
        $type = get_config('quizaccess_learnshield', 'external_storage_type') ?: self::TYPE_MOODLE;

        switch ($type) {
            case self::TYPE_GDRIVE:
                return self::upload_to_gdrive($filecontents, $filename, $coursename, $quizname, $learnername, $mimetype);
            case self::TYPE_ONEDRIVE:
                return self::upload_to_onedrive($filecontents, $filename, $coursename, $quizname, $learnername, $mimetype);
            default:
                return false; // Moodle-only — no external upload needed.
        }
    }

    /**
     * Delete a file from external storage.
     *
     * @param string $externalurl  URL previously returned by upload().
     * @return bool
     */
    public static function delete(string $externalurl): bool {
        $type = get_config('quizaccess_learnshield', 'external_storage_type') ?: self::TYPE_MOODLE;
        // Extract file ID from URL and delete.
        // Implementation depends on provider.
        return true; // Placeholder.
    }

    // -----------------------------------------------------------------------
    // Google Drive (Service Account)
    // -----------------------------------------------------------------------

    private static function upload_to_gdrive(
        string $content, string $filename,
        string $course, string $quiz, string $learner,
        string $mimetype
    ) {
        global $CFG;

        $keyJson     = get_config('quizaccess_learnshield', 'gdrive_service_key');
        $rootFolderId = get_config('quizaccess_learnshield', 'gdrive_folder_id');

        if (empty($keyJson) || empty($rootFolderId)) {
            debugging('Learn Shield: Google Drive not configured.', DEBUG_DEVELOPER);
            return false;
        }

        try {
            $keyData = json_decode($keyJson, true);
            $token   = self::gdrive_get_token($keyData);
            if (!$token) { return false; }

            // Ensure folder structure: course → quiz → learner.
            $courseFolderId  = self::gdrive_ensure_folder($token, self::sanitize_name($course), $rootFolderId);
            $quizFolderId    = self::gdrive_ensure_folder($token, self::sanitize_name($quiz), $courseFolderId);
            $learnerFolderId = self::gdrive_ensure_folder($token, self::sanitize_name($learner), $quizFolderId);

            // Upload file.
            return self::gdrive_upload_file($token, $filename, $content, $mimetype, $learnerFolderId);
        } catch (\Exception $e) {
            debugging('Learn Shield GDrive: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    private static function gdrive_get_token(array $key): ?string {
        $now     = time();
        $exp     = $now + 3600;
        $payload = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' .
                   base64_encode(json_encode([
                       'iss'   => $key['client_email'],
                       'scope' => 'https://www.googleapis.com/auth/drive',
                       'aud'   => 'https://oauth2.googleapis.com/token',
                       'exp'   => $exp, 'iat'  => $now,
                   ]));

        $privateKey = openssl_pkey_get_private($key['private_key']);
        if (!$privateKey) { return null; }
        openssl_sign($payload, $signature, $privateKey, 'SHA256');
        $jwt = $payload . '.' . base64_encode($signature);

        $curl = new \curl();
        $resp = $curl->post('https://oauth2.googleapis.com/token', http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]));
        $data = json_decode($resp);
        return $data->access_token ?? null;
    }

    private static function gdrive_ensure_folder(string $token, string $name, string $parentId): string {
        $curl = new \curl();
        $q    = urlencode("name='{$name}' and mimeType='application/vnd.google-apps.folder' and '{$parentId}' in parents and trashed=false");
        $resp = json_decode($curl->get(
            "https://www.googleapis.com/drive/v3/files?q={$q}&fields=files(id,name)",
            [], ['CURLOPT_HTTPHEADER' => ["Authorization: Bearer {$token}"]]
        ));

        if (!empty($resp->files)) {
            return $resp->files[0]->id;
        }

        // Create folder.
        $body = json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId]]);
        $resp = json_decode($curl->post(
            'https://www.googleapis.com/drive/v3/files?fields=id',
            $body,
            ['CURLOPT_HTTPHEADER' => ["Authorization: Bearer {$token}", 'Content-Type: application/json']]
        ));
        return $resp->id ?? '';
    }

    private static function gdrive_upload_file(string $token, string $name, string $content, string $mime, string $parent): ?string {
        $metadata = json_encode(['name' => $name, 'parents' => [$parent]]);
        $boundary = '----LearnShieldBoundary' . md5(time());
        $body     = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
                  . "--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n{$content}\r\n--{$boundary}--";

        $curl = new \curl();
        $resp = json_decode($curl->post(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink',
            $body,
            ['CURLOPT_HTTPHEADER' => [
                "Authorization: Bearer {$token}",
                "Content-Type: multipart/related; boundary={$boundary}",
            ]]
        ));
        return $resp->webViewLink ?? null;
    }

    // -----------------------------------------------------------------------
    // OneDrive / SharePoint (Microsoft Graph API)
    // -----------------------------------------------------------------------

    private static function upload_to_onedrive(
        string $content, string $filename,
        string $course, string $quiz, string $learner,
        string $mimetype
    ) {
        $tenantId    = get_config('quizaccess_learnshield', 'onedrive_tenant_id');
        $clientId    = get_config('quizaccess_learnshield', 'onedrive_client_id');
        $clientSecret = get_config('quizaccess_learnshield', 'onedrive_client_secret');
        $driveId     = get_config('quizaccess_learnshield', 'onedrive_drive_id');
        $rootPath    = get_config('quizaccess_learnshield', 'onedrive_root_path') ?: 'LearnShield';

        if (empty($tenantId) || empty($clientId) || empty($clientSecret)) {
            debugging('Learn Shield: OneDrive not configured.', DEBUG_DEVELOPER);
            return false;
        }

        try {
            $token = self::onedrive_get_token($tenantId, $clientId, $clientSecret);
            if (!$token) { return false; }

            $path = $rootPath . '/' . self::sanitize_name($course) . '/' .
                    self::sanitize_name($quiz) . '/' . self::sanitize_name($learner) . '/' . $filename;

            $curl = new \curl();
            $resp = json_decode($curl->put(
                "https://graph.microsoft.com/v1.0/drives/{$driveId}/root:/{$path}:/content",
                $content,
                ['CURLOPT_HTTPHEADER' => [
                    "Authorization: Bearer {$token}",
                    "Content-Type: {$mimetype}",
                ]]
            ));

            return $resp->webUrl ?? null;
        } catch (\Exception $e) {
            debugging('Learn Shield OneDrive: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    private static function onedrive_get_token(string $tenant, string $clientId, string $clientSecret): ?string {
        $curl = new \curl();
        $resp = json_decode($curl->post(
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default',
            ]),
            ['CURLOPT_HTTPHEADER' => ['Content-Type: application/x-www-form-urlencoded']]
        ));
        return $resp->access_token ?? null;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static function sanitize_name(string $name): string {
        return preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
    }
}
