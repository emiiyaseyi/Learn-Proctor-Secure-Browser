# Learn-Proctor-Secure-Browser

AI-based exam proctoring for Moodle, shipped as two parallel plugins that must
stay in feature/UX parity (see `CLAUDE.md` for the full rules):

- **LearnGuard** (`_lg_work/learnguard/`) — `mod_learnguard`, a standalone
  assessment activity module (SPA) with its own question bank, proctoring
  dashboard, and AI settings.
- **LearnShield** (`_ls_work/learnshield/`) — `quizaccess_learnshield`, a quiz
  access rule that adds the same proctoring/precheck experience to standard
  Moodle Quiz attempts.

All face/object/audio detection runs client-side (face-api.js TinyFaceDetector +
ONNX YOLOv5n) — no Docker, no paid APIs, no server-side AI.

## Status (as of 2026-06-12)
- LearnGuard: v1.5.9 / 2025052020
- LearnShield: v1.5.9 / 2025052029

Both plugins implement the full 15-step pre-exam wizard, live monitor, gallery,
storage management, suspended/reinstate flow, bulk assignment, and 360°
environment recording (see feature tables in `CLAUDE.md`).

### Known gap — parity behind on LearnGuard
The last several commits (LearnShield v1.5.4 → v1.5.9) fixed precheck-wizard bugs
(typing blocked by Moodle's focus trap, popup-after-precheck, missing `cmid`,
scroll-to-top, closetabs checkbox lock, photo-ID UX) but were applied to
**LearnShield only**. LearnGuard's wizard (`assessment.js`) still needs the
applicable UX fixes ported over. See the "OUTSTANDING — parity gap" section in
`CLAUDE.md` for the itemized list and notes on which fixes are/aren't applicable
to LearnGuard's SPA architecture.

## Build
```
./make_zip.ps1
```
Builds `learnguard_learnguard.zip` and `learnshield_learnshield.zip`. Run after
every change (see `CLAUDE.md` → "After every coding session").

## Legacy folders
- `learnguard-ai/` — superseded Docker/FastAPI/Ollama architecture plan, not part
  of the active build (see `CLAUDE.md`).
- `extracted/proctoring/` — third-party reference plugin, kept for reference only.
