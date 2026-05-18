"""
Gaze Tracking & Head Pose Service
────────────────────────────────────
Uses MediaPipe Face Mesh + OpenCV for:
  • Eye tracking and gaze direction estimation
  • Head pose analysis (looking away, looking down)
  • Attention monitoring (is student looking at screen?)
  • Blink detection (liveness confirmation)

POST /api/v1/gaze/analyze  — analyse gaze and head pose from webcam frame
"""

import io, base64, logging
import numpy as np
from typing import Optional
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
from PIL import Image

router = APIRouter()
log    = logging.getLogger(__name__)

_mp_ready = False
_face_mesh = None


def _get_face_mesh():
    global _face_mesh, _mp_ready
    if _face_mesh is None:
        import mediapipe as mp
        _face_mesh = mp.solutions.face_mesh.FaceMesh(
            static_image_mode=True,
            max_num_faces=1,
            refine_landmarks=True,
            min_detection_confidence=0.5,
        )
        _mp_ready = True
        log.info("MediaPipe FaceMesh loaded.")
    return _face_mesh


def _decode_image(b64: str) -> np.ndarray:
    if "," in b64:
        b64 = b64.split(",", 1)[1]
    img_bytes = base64.b64decode(b64)
    return np.array(Image.open(io.BytesIO(img_bytes)).convert("RGB"))


class GazePayload(BaseModel):
    imagedata:          str
    attemptid:          Optional[int] = None
    gaze_threshold:     float = 0.3   # Deviation threshold (admin-configurable).
    attention_timeout:  int   = 10    # Seconds before "looking away" violation.


@router.post("/analyze")
async def analyze_gaze(payload: GazePayload):
    """
    Analyse gaze direction and head pose.
    Returns: looking_at_screen, gaze_x, gaze_y, head_pitch, head_yaw, blink_detected.
    """
    try:
        face_mesh = _get_face_mesh()
        img = _decode_image(payload.imagedata)
        h, w = img.shape[:2]

        results = face_mesh.process(img)

        if not results.multi_face_landmarks:
            return {
                "face_found":        False,
                "looking_at_screen": False,
                "gaze_x":            0.0,
                "gaze_y":            0.0,
                "head_pitch":        0.0,
                "head_yaw":          0.0,
                "blink_detected":    False,
                "attention_ok":      False,
            }

        lm = results.multi_face_landmarks[0].landmark

        # ── Gaze estimation using iris landmarks (refined landmarks 468+) ──
        # Left iris centre: landmarks 468-472; Right iris: 473-477.
        left_iris  = lm[468]
        right_iris = lm[473]
        nose_tip   = lm[4]
        left_eye_l = lm[33]
        left_eye_r = lm[133]
        right_eye_l = lm[362]
        right_eye_r = lm[263]

        # Gaze offset relative to eye centre.
        left_eye_centre_x  = (left_eye_l.x  + left_eye_r.x)  / 2
        right_eye_centre_x = (right_eye_l.x + right_eye_r.x) / 2
        gaze_offset_left   = left_iris.x  - left_eye_centre_x
        gaze_offset_right  = right_iris.x - right_eye_centre_x
        gaze_x = (gaze_offset_left + gaze_offset_right) / 2

        gaze_y_left  = left_iris.y  - (lm[159].y + lm[145].y) / 2
        gaze_y_right = right_iris.y - (lm[386].y + lm[374].y) / 2
        gaze_y = (gaze_y_left + gaze_y_right) / 2

        looking_at_screen = abs(gaze_x) < payload.gaze_threshold and abs(gaze_y) < payload.gaze_threshold

        # ── Head pose (simplified Euler angles from nose/eye geometry) ──
        eye_mid_y = (lm[159].y + lm[386].y) / 2
        head_pitch = nose_tip.y - eye_mid_y   # Positive = looking down.
        head_yaw   = nose_tip.x - (lm[234].x + lm[454].x) / 2  # Positive = turning right.

        # ── Blink detection (EAR — Eye Aspect Ratio) ──
        def ear(top, bot, left, right):
            v = abs(lm[top].y - lm[bot].y)
            h2 = abs(lm[left].x - lm[right].x)
            return v / h2 if h2 > 0 else 1.0

        left_ear  = ear(159, 145, 33,  133)
        right_ear = ear(386, 374, 362, 263)
        avg_ear   = (left_ear + right_ear) / 2
        blink     = avg_ear < 0.2

        return {
            "face_found":        True,
            "looking_at_screen": bool(looking_at_screen),
            "gaze_x":            round(float(gaze_x), 4),
            "gaze_y":            round(float(gaze_y), 4),
            "head_pitch":        round(float(head_pitch), 4),
            "head_yaw":          round(float(head_yaw), 4),
            "blink_detected":    bool(blink),
            "attention_ok":      bool(looking_at_screen and abs(head_pitch) < 0.15 and abs(head_yaw) < 0.15),
            "model":             "MediaPipe FaceMesh",
        }

    except Exception as e:
        log.error(f"Gaze analysis error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
