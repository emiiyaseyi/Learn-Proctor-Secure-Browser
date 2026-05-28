# LearnGuard & LearnShield — Claude Session Rules

## Project owner
Michael O. Adeniran — all commits must use this name and email `michaeladeniransnr@gmail.com`.
Never use Claude's name in commits.

## Repository layout
```
_lg_work/learnguard/      ← mod_learnguard (Moodle activity module, SPA)
_ls_work/learnshield/     ← quizaccess_learnshield (Moodle quiz access rule)
make_zip.ps1              ← builds both ZIPs; always run after any change
learnguard_learnguard.zip
learnshield_learnshield.zip
```

## CRITICAL — same fix in both plugins
**Any fix, feature, or improvement applied to one plugin MUST be applied to the other.**
Never fix LearnGuard only. Never fix LearnShield only. Always both.
If the architectures differ (SPA vs quiz access rule), adapt — but the learner and proctor experience must be identical.

## After every coding session
1. Run `make_zip.ps1` to rebuild both ZIPs
2. Bump `$plugin->version` and `$plugin->release` in both `version.php` files
3. Commit with `git -c user.name="Michael O. Adeniran" -c user.email="michaeladeniransnr@gmail.com" commit`
4. Never push unless Michael explicitly asks

## Before making breaking changes
Create dated backup ZIPs first:
```
cp learnguard_learnguard.zip learnguard_learnguard_vX.X.X_BACKUP_YYYY-MM-DD.zip
cp learnshield_learnshield.zip learnshield_learnshield_vX.X.X_BACKUP_YYYY-MM-DD.zip
```

## Current versions (update this when you bump)
- LearnGuard: v1.5.1 / 2025052011
- LearnShield: v1.4.1 / 2025052011

## Tech stack
- Moodle 4.5+ (PHP 8.1+)
- LearnGuard: AMD modules via `core/ajax`, vanilla JS SPA inside a `mod_` activity
- LearnShield: AMD modules via `core/ajax` + `core/notification`, `quizaccess_` plugin wrapping Moodle Quiz
- face-api.js v0.22.2 — bundled in `thirdpartylibs/models/` (TinyFaceDetector, faceLandmark68Tiny, faceRecognitionNet)
- ONNX Runtime Web — bundled in `thirdpartylibs/onnx/ort.min.js`
- YOLOv5n — bundled in `thirdpartylibs/onnx/yolov5n.onnx`
- COCO-SSD (CDN fallback only when ONNX unavailable)
- All AI detection runs client-side in the student's browser — zero server load

## Standing architecture decisions
- No Docker required — all detection is browser-based (ONNX + face-api.js)
- No paid APIs or licence keys — everything is MIT/Apache open-source
- Whisper NOT bundled (74 MB, overkill — noise level monitoring is sufficient)
- MinIO NOT used — Moodle's own file storage handles snapshots and recordings
- Screenshots every 15 seconds (not 30)
- face-api uses TinyFaceDetector (not ssdMobilenetv1 — too large)

## Key PHP paths
- LearnShield models URL: `$CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/models'`
- LearnShield ONNX URL: `$CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/onnx'`
- LearnGuard models URL: `$CFG->wwwroot . '/mod/learnguard/thirdpartylibs/models'`
- LearnGuard ONNX URL: `$CFG->wwwroot . '/mod/learnguard/thirdpartylibs/onnx'`

## Proctoring features — must exist in BOTH plugins
| Feature | Notes |
|---|---|
| Pre-exam wizard (14 steps) | closetabs, welcome+ack, browser, internet, camera, audio, photoid, face, screenshare, monitor, environment360, privacy, fullscreen, ready |
| Welcome acknowledgment checkbox | Student must tick before Continue |
| Passport/Photo ID upload | Required step, uploads via send_camshot WS |
| Mandatory screen share (entire screen only) | Next disabled until entire screen confirmed |
| Face detection + liveness (blink) | TinyFaceDetector + faceLandmark68Tiny |
| Face identity matching | faceRecognitionNet, every 5 min during exam |
| Violation toasts shown to learner | showToast() for every flagged event |
| Chat widget suppression | CSS + API override + setInterval + MutationObserver; includes :fullscreen CSS |
| Fullscreen enforcement | Blocker overlay; quiz reload does NOT log as violation (beforeunload flag) |
| Screenshots every 15s | Both periodic and random |
| YOLOv5n object detection | phone, book, laptop, keyboard, multiple persons |
| Audio noise monitoring | THRESHOLD=8, strict |
| Audio clip recording | 8s clip when noise sustained |
| Background change detection | Pixel diff every 15s |
| Tab/window switch detection | Logs + notifies learner |
| Keyboard blocking | Meta, Escape, Alt combos, Ctrl+Tab — always on |
| Proctor ↔ learner chat | Both directions |
| Risk score widget | Visible to learner |
| Noise meter widget | Visible to learner |
| Connection status widget | Visible to learner |
| Auto-suspend on high risk | Score ≥ 80 |
| Mobile device block | Hard block |
| Multiple monitor detection | Log + toast |

## Backend / Admin features — must exist in BOTH plugins
| Feature | Notes |
|---|---|
| Live monitor grid | Filter, per-page, grid columns, webcam/screen toggle |
| Gallery | Shows actual images + 360° videos; checkboxes to select + delete |
| Gallery type filter | Snapshots / 360° videos / Photo IDs |
| Face approvals panel | Manual mode only; auto mode skips invigilator |
| Storage management page | `storage.php` — shows size by type, filter, delete |
| Precheck tab | Shows step-by-step progress per candidate |
| 360° video upload | Learner sees success popup; admin/proctor sees it in gallery |

## face_approval_mode
- `auto` (default) — system approves automatically, no invigilator needed
- `manual` — invigilator must review and approve before student can proceed
- Controlled via Moodle admin settings (both plugins have this setting)

## Common mistakes to avoid
- Do NOT use `ssdMobilenetv1` — use `TinyFaceDetector` everywhere
- Do NOT load face-api from CDN — use the local bundled copy
- Do NOT set snapshot interval to 30000 — must be 15000
- Do NOT log fullscreen_exit event when `lsQuizNavigating === true` (LearnShield)
- Do NOT commit with Claude's name — always Michael O. Adeniran
- Do NOT skip rebuilding ZIPs after changes
- Always read a file before editing it
