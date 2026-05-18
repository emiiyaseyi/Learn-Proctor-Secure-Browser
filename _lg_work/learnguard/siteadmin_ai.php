<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard Phase 4 — AI Services Administration
 *
 * Configure the AI gateway endpoint, enable/disable each AI service,
 * set thresholds and sensitivity, view health status.
 *
 * URL: /mod/learnguard/siteadmin_ai.php
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

$tab = optional_param('tab', 'gateway', PARAM_ALPHA);

$PAGE->set_url('/mod/learnguard/siteadmin_ai.php', ['tab' => $tab]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — AI Services');
$PAGE->set_heading('LearnGuard — AI Services Administration');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/mod/learnguard/styles.css');

// ── Save AI settings ──────────────────────────────────────────────────────────
if (data_submitted() && confirm_sesskey()) {
    $fields = [
        'ai_gateway_url',    'ai_gateway_key',    'ai_socket_url',
        'ai_face_enable',    'ai_face_threshold',  'ai_face_timeout',   'ai_face_backend',
        'ai_yolo_enable',    'ai_yolo_phone_sens', 'ai_yolo_person_sens','ai_yolo_interval',
        'ai_gaze_enable',    'ai_gaze_threshold',  'ai_gaze_attention',
        'ai_whisper_enable', 'ai_whisper_model',   'ai_whisper_lang',
        'ai_ollama_enable',  'ai_ollama_model',    'ai_ollama_interview_model',
        'ai_essay_enable',   'ai_essay_threshold',
        'ai_ffmpeg_enable',  'ai_ffmpeg_quality',  'ai_ffmpeg_bitrate',
    ];
    foreach ($fields as $f) {
        $val = optional_param($f, '', PARAM_TEXT);
        set_config($f, $val, 'learnguard');
    }
    redirect(new moodle_url('/mod/learnguard/siteadmin_ai.php', ['tab' => $tab]),
        'AI settings saved.', null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
$brand = learnguard_brand();

echo learnguard_banner('LearnGuard — AI Services', 'Configure AI proctoring, interviewing, and analysis modules', '🤖');

// ── Back link ──────────────────────────────────────────────────────────────────
echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php'),
    '← Back to Site Admin', ['class' => 'btn btn-outline-secondary btn-sm mb-3']);

// ── Tabs ──────────────────────────────────────────────────────────────────────
$tabs = [
    'gateway'   => '🌐 AI Gateway',
    'face'      => '👤 Face Analysis',
    'objects'   => '📷 Object Detection',
    'gaze'      => '👁 Gaze Tracking',
    'audio'     => '🎤 Audio / Whisper',
    'essay'     => '📝 Essay Analysis',
    'interview' => '🤝 AI Interviewing',
    'status'    => '📊 Service Status',
];
echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4',
    ['style' => 'border-bottom:2px solid #dee2e6;']);
foreach ($tabs as $key => $label) {
    $active = ($tab === $key);
    echo html_writer::link(
        new moodle_url('/mod/learnguard/siteadmin_ai.php', ['tab' => $key]),
        $label,
        ['style' => 'padding:7px 14px;text-decoration:none;font-size:13px;font-weight:600;' .
            'border-radius:4px 4px 0 0;' .
            ($active ? 'background:' . $brand->primary . ';color:#fff;' : 'color:#495057;background:#f8f9fa;border:1px solid #dee2e6;')]
    );
}
echo html_writer::end_div();

// Helper: render a settings form field.
function aiField(string $name, string $label, string $type = 'text', string $help = '',
                 array $options = [], string $default = ''): string {
    $val = get_config('learnguard', $name) ?: $default;
    $id  = 'ai_' . $name;
    $html  = '<div class="mb-3">';
    $html .= '<label for="' . $id . '" style="font-weight:600;display:block;margin-bottom:3px;">' .
             htmlspecialchars($label) . '</label>';
    if ($help) {
        $html .= '<p style="font-size:12px;color:#6c757d;margin-bottom:4px;">' . htmlspecialchars($help) . '</p>';
    }
    if ($type === 'select') {
        $html .= '<select id="' . $id . '" name="' . $name . '" class="form-control" style="max-width:260px;">';
        foreach ($options as $v => $l) {
            $html .= '<option value="' . htmlspecialchars($v) . '"' . ($val == $v ? ' selected' : '') . '>' .
                     htmlspecialchars($l) . '</option>';
        }
        $html .= '</select>';
    } elseif ($type === 'toggle') {
        $checked = $val ? ' checked' : '';
        $html .= '<div style="display:flex;align-items:center;gap:10px;">';
        $html .= '<input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . $checked .
                 ' style="width:20px;height:20px;cursor:pointer;">';
        $html .= '<label for="' . $id . '" style="cursor:pointer;font-weight:normal;">Enable this module</label>';
        $html .= '</div>';
    } elseif ($type === 'password') {
        $html .= '<input type="password" id="' . $id . '" name="' . $name . '" value="' .
                 htmlspecialchars($val) . '" class="form-control" style="max-width:360px;">';
    } else {
        $html .= '<input type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' .
                 htmlspecialchars($val) . '" class="form-control" style="max-width:360px;">';
    }
    $html .= '</div>';
    return $html;
}

function aiSection(string $title): string {
    return '<h5 style="border-left:4px solid #0f6cbf;padding-left:10px;margin:20px 0 12px;">' .
           htmlspecialchars($title) . '</h5>';
}

$formOpen  = '<form method="post" style="max-width:720px;">' .
             html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$formClose = '<button type="submit" class="btn btn-primary mt-3">💾 Save AI Settings</button></form>';

// =====================================================================
// TAB: AI Gateway
// =====================================================================
if ($tab === 'gateway') {
    echo html_writer::tag('h4', '🌐 AI Gateway Configuration', ['class' => 'mb-2']);

    // ── Status explainer ─────────────────────────────────────────────────────
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">';

    echo '<div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:16px;">';
    echo '<h5 style="margin:0 0 8px;color:#155724;">✅ Works RIGHT NOW (no setup)</h5>';
    echo '<ul style="margin:0;padding-left:18px;font-size:13px;color:#155724;line-height:1.9;">';
    foreach ([
        'Pre-exam wizard (camera, audio, face check)',
        'Face detection in browser (face-api.js)',
        'Webcam snapshots + live dashboard',
        'Tab/fullscreen/keyboard enforcement',
        'Risk scoring, proctor chat, breaks',
        'Session playback, PDF reports',
        'All question types, grading, incidents',
    ] as $f) { echo "<li>$f</li>"; }
    echo '</ul></div>';

    echo '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px;">';
    echo '<h5 style="margin:0 0 8px;color:#856404;">⚙️ Requires AI Gateway (Docker on your server)</h5>';
    echo '<ul style="margin:0;padding-left:18px;font-size:13px;color:#856404;line-height:1.9;">';
    foreach ([
        'DeepFace identity verification (Photo ID matching)',
        'YOLOv8 phone & object detection on snapshots',
        'MediaPipe gaze tracking analysis',
        'Whisper speech-to-text transcription',
        'Ollama AI interview questions & scoring',
        'AI essay similarity & plagiarism detection',
    ] as $f) { echo "<li>$f</li>"; }
    echo '</ul></div>';
    echo '</div>';

    // ── Deployment guide ─────────────────────────────────────────────────────
    echo '<div style="background:#e8f4fd;border:1px solid #bee5eb;border-radius:8px;padding:16px;margin-bottom:20px;">';
    echo '<h5 style="margin:0 0 10px;color:#0c5460;">🚀 How to Deploy the AI Gateway on Your Server</h5>';
    echo '<ol style="margin:0;padding-left:18px;font-size:13px;color:#0c5460;line-height:2.1;">';
    echo '<li>Get a Linux VPS or use your Moodle server (Ubuntu 20.04+ recommended, 8 GB RAM min)</li>';
    echo '<li>Install Docker: <code>curl -fsSL https://get.docker.com | sh</code></li>';
    echo '<li>Upload <code>learnguard_ai_services.zip</code> to your server and extract it</li>';
    echo '<li>Edit <code>docker-compose.yml</code> → set <code>LEARNGUARD_AI_KEY</code> to a strong random password</li>';
    echo '<li>Start services: <code>cd learnguard-ai && docker compose up -d</code></li>';
    echo '<li>Download AI model: <code>docker exec learnguard-ollama ollama pull llama3.2</code></li>';
    echo '<li>Enter your server\'s URL below (e.g. <code>https://ai.yourschool.edu</code> or <code>http://YOUR_SERVER_IP:8765</code>)</li>';
    echo '</ol>';
    echo '<p style="margin:10px 0 0;font-size:12px;color:#0c5460;">⚠️ Never use <code>localhost</code> here — that points to your own computer, not the server. Use your server\'s public IP or domain.</p>';
    echo '</div>';

    echo $formOpen;
    echo aiSection('Server Connection');
    echo aiField('ai_gateway_url', 'AI Gateway URL',
        'text',
        'Your server URL where the AI Gateway is running. Example: http://203.0.113.10:8765 or https://ai.yourschool.edu — NOT localhost.',
        [], '');
    echo aiField('ai_gateway_key', 'API Key', 'password',
        'The secret key you set in LEARNGUARD_AI_KEY inside docker-compose.yml on your server.');

    echo aiSection('Socket.IO Server');
    echo aiField('ai_socket_url', 'Socket.IO Server URL',
        'text', 'Real-time events server URL (e.g. http://localhost:3001)',
        [], 'http://localhost:3001');

    echo html_writer::start_div('alert alert-info mt-4');
    echo '<strong>Quick Start:</strong><br>';
    echo '1. Install Docker Desktop<br>';
    echo '2. Copy <code>learnguard-ai/</code> folder to your server<br>';
    echo '3. Run: <code>docker compose up -d</code><br>';
    echo '4. Run: <code>docker exec learnguard-ollama ollama pull llama3.2</code><br>';
    echo '5. Enter the gateway URL and API key above, then test connection.';
    echo html_writer::end_div();
    echo $formClose;
}

// =====================================================================
// TAB: Face Analysis
// =====================================================================
if ($tab === 'face') {
    echo html_writer::tag('h4', '👤 Face Analysis (DeepFace + OpenCV)', ['class' => 'mb-2']);
    echo $formOpen;
    echo aiField('ai_face_enable', 'Enable Face Analysis', 'toggle',
        'Uses DeepFace to detect face presence, identity verification, and liveness.');
    echo aiSection('Detection Settings');
    echo aiField('ai_face_threshold', 'Match Confidence Threshold',
        'number', 'Identity match confidence (0.0–1.0). Default: 0.6. Higher = stricter.', [], '0.6');
    echo aiField('ai_face_timeout', 'Face Absence Timeout (seconds)',
        'number', 'Seconds without a detected face before logging a violation. Default: 5.', [], '5');
    echo aiField('ai_face_backend', 'Detection Backend', 'select',
        'OpenCV is fastest; MTCNN is most accurate.', [
            'opencv'      => 'OpenCV (fastest, recommended)',
            'ssd'         => 'SSD (balanced)',
            'mtcnn'       => 'MTCNN (most accurate)',
            'retinaface'  => 'RetinaFace (GPU recommended)',
        ], 'opencv');
    echo $formClose;
}

// =====================================================================
// TAB: Object Detection
// =====================================================================
if ($tab === 'objects') {
    echo html_writer::tag('h4', '📷 Object Detection (YOLOv8)', ['class' => 'mb-2']);
    echo $formOpen;
    echo aiField('ai_yolo_enable', 'Enable Object Detection', 'toggle',
        'Uses YOLOv8 to detect mobile phones, multiple persons, and suspicious objects.');
    echo aiSection('Sensitivity');
    echo aiField('ai_yolo_phone_sens', 'Mobile Phone Confidence Threshold',
        'number', '0.0–1.0. Default: 0.5. Lower = more sensitive (more false positives).', [], '0.5');
    echo aiField('ai_yolo_person_sens', 'Multiple Person Confidence Threshold',
        'number', '0.0–1.0. Default: 0.5.', [], '0.5');
    echo aiField('ai_yolo_interval', 'Detection Interval (seconds)',
        'number', 'How often to run object detection. Default: 30. Lower = more CPU usage.', [], '30');
    echo $formClose;
}

// =====================================================================
// TAB: Gaze Tracking
// =====================================================================
if ($tab === 'gaze') {
    echo html_writer::tag('h4', '👁 Gaze Tracking (MediaPipe)', ['class' => 'mb-2']);
    echo $formOpen;
    echo aiField('ai_gaze_enable', 'Enable Gaze Tracking', 'toggle',
        'Uses MediaPipe FaceMesh to track eye gaze, head pose, and attention.');
    echo aiSection('Thresholds');
    echo aiField('ai_gaze_threshold', 'Gaze Deviation Threshold',
        'number', 'Max allowed gaze offset (0.0–1.0). Default: 0.3.', [], '0.3');
    echo aiField('ai_gaze_attention', 'Attention Check Interval (seconds)',
        'number', 'How often to check gaze direction. Default: 10.', [], '10');
    echo $formClose;
}

// =====================================================================
// TAB: Audio / Whisper
// =====================================================================
if ($tab === 'audio') {
    echo html_writer::tag('h4', '🎤 Audio Analysis (Whisper)', ['class' => 'mb-2']);
    echo $formOpen;
    echo aiField('ai_whisper_enable', 'Enable Audio Transcription', 'toggle',
        'Uses OpenAI Whisper (local) for speech-to-text and audio monitoring.');
    echo aiSection('Model Settings');
    echo aiField('ai_whisper_model', 'Whisper Model Size', 'select',
        'Larger = more accurate but slower and needs more RAM/VRAM.', [
            'tiny'   => 'Tiny (fast, ~39MB, low accuracy)',
            'base'   => 'Base (recommended, ~74MB)',
            'small'  => 'Small (~244MB, good accuracy)',
            'medium' => 'Medium (~769MB, high accuracy, GPU recommended)',
            'large'  => 'Large (~1.5GB, best accuracy, GPU required)',
        ], 'base');
    echo aiField('ai_whisper_lang', 'Default Language',
        'text', 'ISO 639-1 code (e.g. "en", "fr", "ar"). Leave blank for auto-detect.', [], '');
    echo $formClose;
}

// =====================================================================
// TAB: Essay Analysis
// =====================================================================
if ($tab === 'essay') {
    echo html_writer::tag('h4', '📝 Essay Analysis (Sentence Transformers + Ollama)', ['class' => 'mb-2']);
    echo $formOpen;
    echo aiField('ai_essay_enable', 'Enable Essay Analysis', 'toggle',
        'Semantic similarity, plagiarism indicators, and AI grading suggestions.');
    echo aiSection('Thresholds');
    echo aiField('ai_essay_threshold', 'Plagiarism Similarity Threshold',
        'number', 'Cosine similarity above this value flags potential plagiarism (0.0–1.0). Default: 0.85.', [], '0.85');
    echo $formClose;
}

// =====================================================================
// TAB: AI Interviewing
// =====================================================================
if ($tab === 'interview') {
    echo html_writer::tag('h4', '🤝 AI Interviewing (Ollama)', ['class' => 'mb-2']);
    echo html_writer::tag('p',
        'AI-driven asynchronous video interviews with dynamic question generation, ' .
        'response analysis, and candidate summaries powered by local LLMs.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:16px;']);
    echo $formOpen;
    echo aiField('ai_ollama_enable', 'Enable AI Interviewing', 'toggle',
        'Requires Ollama running with at least one model downloaded.');
    echo aiSection('Model Configuration');
    echo aiField('ai_ollama_model', 'Interview Question Model',
        'text', 'Ollama model for generating questions (e.g. llama3.2, mistral).', [], 'llama3.2');
    echo aiField('ai_ollama_interview_model', 'Summary & Scoring Model',
        'text', 'Ollama model for candidate summaries (can be same or different).', [], 'llama3.2');

    echo html_writer::start_div('alert alert-warning mt-3');
    echo '<strong>First-time setup:</strong><br>';
    echo 'Run: <code>docker exec learnguard-ollama ollama pull llama3.2</code><br>';
    echo 'Available models: <a href="https://ollama.com/library" target="_blank">ollama.com/library</a>';
    echo html_writer::end_div();
    echo $formClose;
}

// =====================================================================
// TAB: Service Status
// =====================================================================
if ($tab === 'status') {
    echo html_writer::tag('h4', '📊 AI Service Health Status', ['class' => 'mb-2']);

    $gatewayUrl = get_config('learnguard', 'ai_gateway_url') ?: '';
    $gatewayKey = get_config('learnguard', 'ai_gateway_key') ?: '';

    if (empty($gatewayUrl)) {
        echo html_writer::div(
            '⚠️ AI Gateway URL not configured. Go to the <strong>AI Gateway</strong> tab to set it up.',
            'alert alert-warning'
        );
    } else {
        // Check health via Moodle's curl wrapper.
        echo html_writer::tag('p', 'Checking service health at: <code>' . s($gatewayUrl) . '</code>',
            ['style' => 'color:#6c757d;font-size:13px;']);

        $curl = new curl();
        $curl->setHeader(['X-API-Key: ' . $gatewayKey]);
        $response = $curl->get($gatewayUrl . '/health/services');
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode === 200 || $httpcode === 207) {
            $data = json_decode($response, true) ?? [];
            $overall = $data['overall'] ?? 'unknown';
            $services = $data['services'] ?? [];

            echo html_writer::start_div('', ['style' =>
                'background:' . ($overall === 'ok' ? '#d4edda' : '#fff3cd') . ';' .
                'border-radius:8px;padding:16px;margin-bottom:20px;']);
            echo html_writer::tag('h5', ($overall === 'ok' ? '✅' : '⚠️') . ' Overall: ' . strtoupper($overall));
            echo html_writer::end_div();

            echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
            echo html_writer::start_tag('tr');
            foreach (['Service', 'Status', 'Details'] as $h) {
                echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
            }
            echo html_writer::end_tag('tr');
            foreach ($services as $name => $info) {
                $ok  = $info['ok'] ?? false;
                $det = $ok ? implode(', ', array_filter([
                    isset($info['version']) ? 'v' . $info['version'] : null,
                    isset($info['models'])  ? count($info['models']) . ' model(s)' : null,
                ])) : ($info['error'] ?? 'unavailable');
                echo html_writer::start_tag('tr');
                echo html_writer::tag('td', ucfirst($name), ['style' => 'font-weight:600;']);
                echo html_writer::tag('td', $ok
                    ? html_writer::tag('span', '✅ Online',  ['style' => 'color:#28a745;font-weight:700;'])
                    : html_writer::tag('span', '❌ Offline', ['style' => 'color:#dc3545;font-weight:700;'])
                );
                echo html_writer::tag('td', s($det), ['style' => 'font-size:13px;color:#6c757d;']);
                echo html_writer::end_tag('tr');
            }
            echo html_writer::end_tag('table');
        } else {
            echo html_writer::div(
                '❌ Cannot reach AI Gateway (HTTP ' . $httpcode . '). ' .
                'Ensure Docker is running and the gateway URL is correct.',
                'alert alert-danger'
            );
        }
    }
}

echo $OUTPUT->footer();
