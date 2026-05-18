"""Health check endpoints for the AI gateway."""
import os
from fastapi import APIRouter
from fastapi.responses import JSONResponse

router = APIRouter()

@router.get("")
@router.get("/")
async def health():
    return {"status": "ok", "service": "LearnGuard AI Gateway", "version": "1.0.0"}

@router.get("/services")
async def service_status():
    """Check availability of all AI services."""
    import httpx
    ollama_url = os.getenv("OLLAMA_URL", "http://ollama:11434")
    status = {}

    try:
        async with httpx.AsyncClient(timeout=3) as c:
            r = await c.get(f"{ollama_url}/api/tags")
            status["ollama"] = {"ok": True, "models": [m["name"] for m in r.json().get("models", [])]}
    except Exception:
        status["ollama"] = {"ok": False, "error": "unreachable"}

    try:
        import cv2
        status["opencv"] = {"ok": True, "version": cv2.__version__}
    except Exception as e:
        status["opencv"] = {"ok": False, "error": str(e)}

    try:
        from deepface import DeepFace
        status["deepface"] = {"ok": True}
    except Exception as e:
        status["deepface"] = {"ok": False, "error": str(e)}

    try:
        from ultralytics import YOLO
        status["yolov8"] = {"ok": True}
    except Exception as e:
        status["yolov8"] = {"ok": False, "error": str(e)}

    try:
        import mediapipe
        status["mediapipe"] = {"ok": True, "version": mediapipe.__version__}
    except Exception as e:
        status["mediapipe"] = {"ok": False, "error": str(e)}

    try:
        import whisper
        status["whisper"] = {"ok": True}
    except Exception as e:
        status["whisper"] = {"ok": False, "error": str(e)}

    try:
        from sentence_transformers import SentenceTransformer
        status["sentence_transformers"] = {"ok": True}
    except Exception as e:
        status["sentence_transformers"] = {"ok": False, "error": str(e)}

    all_ok = all(v["ok"] for v in status.values())
    return JSONResponse(
        status_code=200 if all_ok else 207,
        content={"overall": "ok" if all_ok else "degraded", "services": status}
    )
