"""
Object Detection Service
─────────────────────────
Uses YOLOv8 (Ultralytics) for:
  • Mobile phone detection in exam environment
  • Multiple person detection
  • Suspicious object detection (earpiece, book, paper, etc.)
  • Environment monitoring

POST /api/v1/objects/detect  — scan frame for violations
"""

import io, base64, logging
from typing import Optional, List
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
import numpy as np
from PIL import Image

router = APIRouter()
log    = logging.getLogger(__name__)

_model = None  # YOLO model loaded on first use.

# Suspicious object classes from COCO dataset (YOLOv8 default).
SUSPICIOUS_CLASSES = {
    "cell phone":    {"severity": "high",   "score": 25},
    "book":          {"severity": "medium", "score": 10},
    "laptop":        {"severity": "high",   "score": 20},
    "remote":        {"severity": "low",    "score": 5},
    "keyboard":      {"severity": "low",    "score": 5},
    "person":        {"severity": None,     "score": 0},  # handled separately
    "earphones":     {"severity": "high",   "score": 20},
    "headphones":    {"severity": "medium", "score": 15},
}


def _get_model():
    global _model
    if _model is None:
        from ultralytics import YOLO
        # Uses YOLOv8n (nano) for speed; swap to yolov8m for accuracy.
        _model = YOLO("yolov8n.pt")
        log.info("YOLOv8 model loaded.")
    return _model


def _decode_image(b64: str) -> np.ndarray:
    if "," in b64:
        b64 = b64.split(",", 1)[1]
    img_bytes = base64.b64decode(b64)
    img = Image.open(io.BytesIO(img_bytes)).convert("RGB")
    return np.array(img)


class DetectPayload(BaseModel):
    imagedata:          str
    attemptid:          Optional[int] = None
    phone_threshold:    float = 0.5   # Admin-configurable confidence threshold.
    person_threshold:   float = 0.5
    object_threshold:   float = 0.4


@router.post("/detect")
async def detect_objects(payload: DetectPayload):
    """
    Run YOLOv8 on a webcam frame.
    Returns violations list with class, confidence, severity.
    """
    try:
        model  = _get_model()
        img    = _decode_image(payload.imagedata)
        results = model(img, conf=0.35, verbose=False)

        detections = []
        violations = []
        person_count = 0
        phone_found  = False

        for r in results:
            for box in r.boxes:
                cls_name   = model.names[int(box.cls[0])]
                confidence = float(box.conf[0])
                detections.append({"class": cls_name, "confidence": round(confidence, 3)})

                if cls_name == "person":
                    person_count += 1

                if cls_name == "cell phone" and confidence >= payload.phone_threshold:
                    phone_found = True
                    violations.append({
                        "class":      "cell phone",
                        "confidence": round(confidence, 3),
                        "severity":   "high",
                        "score":      25,
                        "message":    "Mobile phone detected in exam area.",
                    })

                elif cls_name in SUSPICIOUS_CLASSES and cls_name != "person":
                    meta = SUSPICIOUS_CLASSES[cls_name]
                    if confidence >= payload.object_threshold and meta["severity"]:
                        violations.append({
                            "class":      cls_name,
                            "confidence": round(confidence, 3),
                            "severity":   meta["severity"],
                            "score":      meta["score"],
                            "message":    f"Suspicious object detected: {cls_name}.",
                        })

        # Multiple person check.
        if person_count > 1:
            violations.append({
                "class":      "multiple_persons",
                "confidence": 1.0,
                "severity":   "high",
                "score":      20,
                "message":    f"{person_count} persons detected in frame.",
            })

        return {
            "phone_found":    phone_found,
            "person_count":   person_count,
            "violations":     violations,
            "total_score":    sum(v["score"] for v in violations),
            "detections":     detections,
            "model":          "YOLOv8n",
        }

    except Exception as e:
        log.error(f"Object detect error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
