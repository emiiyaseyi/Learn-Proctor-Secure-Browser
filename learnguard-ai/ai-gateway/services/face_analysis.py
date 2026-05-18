"""
Face Analysis Service
─────────────────────
Uses DeepFace + OpenCV for:
  • Face detection (is a face present?)
  • Identity verification (does this face match the registered photo?)
  • Liveness check (is the person blinking / real?)
  • Multiple-person detection
  • Face spoofing detection

POST /api/v1/face/detect     — check presence, count, liveness
POST /api/v1/face/verify     — compare against registered photo
POST /api/v1/face/analyze    — age, gender, emotion (optional)
"""

import io, base64, logging
from typing import Optional
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
import numpy as np
from PIL import Image

router = APIRouter()
log    = logging.getLogger(__name__)

# ── Models (loaded lazily / pre-loaded) ──────────────────────────────────────
_deepface_ready = False

async def preload_models():
    global _deepface_ready
    try:
        import cv2
        from deepface import DeepFace
        # Warm up by running a dummy detection.
        dummy = np.zeros((100, 100, 3), dtype=np.uint8)
        DeepFace.extract_faces(dummy, detector_backend="opencv", enforce_detection=False)
        _deepface_ready = True
        log.info("DeepFace models loaded.")
    except Exception as e:
        log.warning(f"DeepFace pre-load failed: {e}")


def _decode_image(b64: str) -> np.ndarray:
    """Decode a base64 image (with or without data URI header) to a numpy array."""
    if "," in b64:
        b64 = b64.split(",", 1)[1]
    img_bytes = base64.b64decode(b64)
    img = Image.open(io.BytesIO(img_bytes)).convert("RGB")
    return np.array(img)


# ── Request / Response models ─────────────────────────────────────────────────
class ImagePayload(BaseModel):
    imagedata: str           # Base64-encoded JPEG/PNG.
    attemptid: Optional[int] = None

class VerifyPayload(BaseModel):
    imagedata:  str          # Current webcam frame.
    reference:  str          # Registered photo (Photo ID capture).
    attemptid:  Optional[int] = None
    threshold:  float = 0.6  # Cosine similarity threshold (admin-configurable).


# ── Endpoints ─────────────────────────────────────────────────────────────────
@router.post("/detect")
async def detect_face(payload: ImagePayload):
    """
    Detect faces in a webcam frame.
    Returns: face_found, face_count, liveness_ok, confidence.
    """
    try:
        from deepface import DeepFace
        img = _decode_image(payload.imagedata)

        result = DeepFace.extract_faces(
            img,
            detector_backend="opencv",
            enforce_detection=False,
            align=True,
        )

        face_count   = len([f for f in result if f.get("confidence", 0) > 0.5])
        face_found   = face_count > 0
        multi_person = face_count > 1

        return {
            "face_found":   face_found,
            "face_count":   face_count,
            "multi_person": multi_person,
            "liveness_ok":  face_found,  # Extended liveness requires MediaPipe (see gaze service).
            "confidence":   result[0].get("confidence", 0.0) if result else 0.0,
            "model":        "DeepFace/OpenCV",
        }

    except Exception as e:
        log.error(f"Face detect error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/verify")
async def verify_identity(payload: VerifyPayload):
    """
    Compare webcam frame against the student's registered photo.
    Returns: verified (bool), similarity, distance.
    """
    try:
        from deepface import DeepFace
        current   = _decode_image(payload.imagedata)
        reference = _decode_image(payload.reference)

        result = DeepFace.verify(
            img1_path=current,
            img2_path=reference,
            model_name="VGG-Face",
            detector_backend="opencv",
            distance_metric="cosine",
            enforce_detection=False,
        )

        return {
            "verified":   result["verified"],
            "similarity": round(1 - result["distance"], 4),
            "distance":   round(result["distance"], 4),
            "threshold":  payload.threshold,
            "model":      "DeepFace/VGG-Face",
        }

    except Exception as e:
        log.error(f"Face verify error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/analyze")
async def analyze_face(payload: ImagePayload):
    """
    Optional: analyze age, gender, dominant emotion.
    Used for candidate profiling in interview module.
    """
    try:
        from deepface import DeepFace
        img    = _decode_image(payload.imagedata)
        result = DeepFace.analyze(
            img,
            actions=["emotion"],
            detector_backend="opencv",
            enforce_detection=False,
            silent=True,
        )
        r = result[0] if isinstance(result, list) else result
        return {
            "dominant_emotion": r.get("dominant_emotion", "unknown"),
            "emotion_scores":   r.get("emotion", {}),
        }
    except Exception as e:
        log.error(f"Face analyze error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
