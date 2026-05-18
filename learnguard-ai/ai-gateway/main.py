"""
LearnGuard AI Gateway
FastAPI service — receives requests from the Moodle plugin (mod_learnguard)
and dispatches to the appropriate AI service module.

All endpoints accept a shared API key (set in Moodle admin → LearnGuard → AI Settings).

Base URL: http://your-server:8765/api/v1/
"""

import os
import logging
from contextlib import asynccontextmanager
from fastapi import FastAPI, HTTPException, Depends, Header
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

from services.face_analysis  import router as face_router
from services.object_detect  import router as object_router
from services.gaze_tracking  import router as gaze_router
from services.audio_analysis import router as audio_router
from services.essay_analysis import router as essay_router
from services.ai_interview   import router as interview_router
from services.health         import router as health_router

# ── App lifecycle ──────────────────────────────────────────────────────────────
@asynccontextmanager
async def lifespan(app: FastAPI):
    logging.info("LearnGuard AI Gateway starting…")
    # Pre-load models to avoid cold-start on first request.
    try:
        from services.face_analysis import preload_models
        await preload_models()
        logging.info("AI models pre-loaded.")
    except Exception as e:
        logging.warning(f"Model pre-load skipped: {e}")
    yield
    logging.info("LearnGuard AI Gateway shutting down.")


# ── App ────────────────────────────────────────────────────────────────────────
app = FastAPI(
    title="LearnGuard AI Gateway",
    description="AI inference gateway for the LearnGuard Moodle assessment platform.",
    version="1.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],   # Restrict to your Moodle domain in production.
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── API Key auth ───────────────────────────────────────────────────────────────
API_KEY = os.getenv("LEARNGUARD_AI_KEY", "change-this-key-in-production")

def verify_api_key(x_api_key: str = Header(...)):
    if x_api_key != API_KEY:
        raise HTTPException(status_code=401, detail="Invalid API key")
    return x_api_key

# ── Routes ─────────────────────────────────────────────────────────────────────
app.include_router(health_router,    prefix="/health",                             tags=["Health"])
app.include_router(face_router,      prefix="/api/v1/face",    dependencies=[Depends(verify_api_key)], tags=["Face Analysis"])
app.include_router(object_router,    prefix="/api/v1/objects", dependencies=[Depends(verify_api_key)], tags=["Object Detection"])
app.include_router(gaze_router,      prefix="/api/v1/gaze",    dependencies=[Depends(verify_api_key)], tags=["Gaze Tracking"])
app.include_router(audio_router,     prefix="/api/v1/audio",   dependencies=[Depends(verify_api_key)], tags=["Audio Analysis"])
app.include_router(essay_router,     prefix="/api/v1/essay",   dependencies=[Depends(verify_api_key)], tags=["Essay Analysis"])
app.include_router(interview_router, prefix="/api/v1/interview",dependencies=[Depends(verify_api_key)], tags=["AI Interview"])


@app.exception_handler(Exception)
async def global_exception_handler(request, exc):
    logging.error(f"Unhandled error: {exc}", exc_info=True)
    return JSONResponse(status_code=500, content={"error": "Internal server error"})
