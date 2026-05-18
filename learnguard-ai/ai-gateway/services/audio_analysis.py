"""
Audio Analysis Service
───────────────────────
Uses OpenAI Whisper for:
  • Speech-to-text transcription (interview recordings)
  • Audio activity detection (is someone speaking?)
  • Language detection
  • Transcript generation for AI interviews

POST /api/v1/audio/transcribe  — transcribe audio file
POST /api/v1/audio/detect      — detect speech activity in audio segment
"""

import io, base64, logging, tempfile, os
from typing import Optional
from fastapi import APIRouter, HTTPException, UploadFile, File
from pydantic import BaseModel

router = APIRouter()
log    = logging.getLogger(__name__)

_whisper_model = None
WHISPER_MODEL_SIZE = os.getenv("WHISPER_MODEL", "base")  # tiny|base|small|medium|large


def _get_whisper():
    global _whisper_model
    if _whisper_model is None:
        import whisper
        _whisper_model = whisper.load_model(WHISPER_MODEL_SIZE)
        log.info(f"Whisper '{WHISPER_MODEL_SIZE}' model loaded.")
    return _whisper_model


class AudioPayload(BaseModel):
    audiodata:  str            # Base64-encoded audio (WAV or WebM).
    language:   Optional[str] = None  # ISO code e.g. "en", "fr". None = auto-detect.
    attemptid:  Optional[int] = None


@router.post("/transcribe")
async def transcribe_audio(payload: AudioPayload):
    """
    Transcribe base64-encoded audio using Whisper.
    Supports WAV, MP3, WebM, OGG.
    """
    try:
        model = _get_whisper()

        # Decode and write to temp file (Whisper requires a file path).
        audio_bytes = base64.b64decode(payload.audiodata)
        with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tf:
            tf.write(audio_bytes)
            tmp_path = tf.name

        try:
            opts = {"language": payload.language} if payload.language else {}
            result = model.transcribe(tmp_path, **opts)
            return {
                "transcript":  result["text"].strip(),
                "language":    result.get("language", "unknown"),
                "duration":    result.get("duration", 0),
                "segments":    [
                    {"start": s["start"], "end": s["end"], "text": s["text"]}
                    for s in result.get("segments", [])
                ],
                "model":       f"Whisper/{WHISPER_MODEL_SIZE}",
            }
        finally:
            os.unlink(tmp_path)

    except Exception as e:
        log.error(f"Transcription error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/detect")
async def detect_speech(payload: AudioPayload):
    """
    Simple speech detection: is there audible speech in this audio clip?
    Used by proctoring to flag unexpected voices during exam.
    """
    try:
        import numpy as np
        audio_bytes = base64.b64decode(payload.audiodata)

        # Simple energy-based VAD (Voice Activity Detection).
        # For production, replace with WebRTC VAD or Silero VAD.
        audio_array = np.frombuffer(audio_bytes, dtype=np.int16).astype(np.float32)
        rms = float(np.sqrt(np.mean(audio_array ** 2))) if len(audio_array) > 0 else 0.0
        speech_detected = rms > 500  # Threshold; tunable in admin.

        return {
            "speech_detected": speech_detected,
            "rms_level":       round(rms, 2),
            "threshold":       500,
        }

    except Exception as e:
        log.error(f"Speech detect error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
