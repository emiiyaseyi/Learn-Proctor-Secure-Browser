"""
Essay Analysis Service
────────────────────────
Uses Sentence Transformers + Ollama for:
  • Semantic similarity detection (plagiarism indicators)
  • Essay quality scoring
  • Response similarity comparison against model answers
  • AI-assisted grading suggestions

POST /api/v1/essay/similarity  — compare two texts semantically
POST /api/v1/essay/analyze     — full essay quality + similarity analysis
POST /api/v1/essay/grade       — AI grading suggestion via Ollama
"""

import os, logging
from typing import Optional, List
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

router = APIRouter()
log    = logging.getLogger(__name__)

_embedder     = None
OLLAMA_URL    = os.getenv("OLLAMA_URL", "http://ollama:11434")
OLLAMA_MODEL  = os.getenv("ESSAY_GRADE_MODEL", "llama3.2")


def _get_embedder():
    global _embedder
    if _embedder is None:
        from sentence_transformers import SentenceTransformer
        _embedder = SentenceTransformer("all-MiniLM-L6-v2")  # Fast & accurate.
        log.info("Sentence Transformer loaded.")
    return _embedder


class SimilarityPayload(BaseModel):
    text_a: str
    text_b: str

class EssayPayload(BaseModel):
    essay:          str
    model_answer:   Optional[str] = None
    other_answers:  Optional[List[str]] = []
    max_score:      float = 10.0
    question:       Optional[str] = None
    attemptid:      Optional[int] = None


@router.post("/similarity")
async def semantic_similarity(payload: SimilarityPayload):
    """
    Compute cosine similarity between two texts.
    Score > 0.85 = likely paraphrase / plagiarism.
    """
    try:
        from sklearn.metrics.pairwise import cosine_similarity
        import numpy as np
        model = _get_embedder()
        embs  = model.encode([payload.text_a, payload.text_b])
        score = float(cosine_similarity([embs[0]], [embs[1]])[0][0])
        return {
            "similarity":    round(score, 4),
            "likely_copied": score > 0.85,
            "risk_level":    "high" if score > 0.9 else ("medium" if score > 0.75 else "low"),
        }
    except Exception as e:
        log.error(f"Similarity error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/analyze")
async def analyze_essay(payload: EssayPayload):
    """
    Full essay analysis: similarity to model answer + plagiarism check.
    """
    try:
        from sklearn.metrics.pairwise import cosine_similarity
        import numpy as np
        model   = _get_embedder()
        results = {"essay_length": len(payload.essay.split())}

        # Similarity to model answer.
        if payload.model_answer:
            embs = model.encode([payload.essay, payload.model_answer])
            sim  = float(cosine_similarity([embs[0]], [embs[1]])[0][0])
            results["model_answer_similarity"] = round(sim, 4)
            results["relevance_score"]         = round(sim * 100, 1)

        # Cross-attempt plagiarism check.
        if payload.other_answers:
            all_texts = [payload.essay] + payload.other_answers
            embs      = model.encode(all_texts)
            max_sim   = 0.0
            for i in range(1, len(embs)):
                s = float(cosine_similarity([embs[0]], [embs[i]])[0][0])
                max_sim = max(max_sim, s)
            results["max_peer_similarity"] = round(max_sim, 4)
            results["plagiarism_risk"]     = "high" if max_sim > 0.9 else ("medium" if max_sim > 0.75 else "low")

        return results

    except Exception as e:
        log.error(f"Essay analysis error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/grade")
async def ai_grade_essay(payload: EssayPayload):
    """
    Ask Ollama (local LLM) to suggest a grade and provide feedback.
    Returns: suggested_score, feedback, strengths, improvements.
    NOTE: This is a SUGGESTION only — final grading is always done by the instructor.
    """
    try:
        import httpx
        prompt = f"""You are an academic assessor. Grade the following essay out of {payload.max_score:.0f} marks.

Question: {payload.question or "Not provided"}

Student Essay:
{payload.essay}

{"Model Answer Reference: " + payload.model_answer if payload.model_answer else ""}

Respond in JSON with these keys:
- suggested_score: number (0 to {payload.max_score:.0f})
- feedback: string (2-3 sentence overall assessment)
- strengths: list of strings (max 3)
- improvements: list of strings (max 3)
- confidence: "high" | "medium" | "low"

JSON only, no other text."""

        async with httpx.AsyncClient(timeout=60) as client:
            resp = await client.post(
                f"{OLLAMA_URL}/api/generate",
                json={"model": OLLAMA_MODEL, "prompt": prompt, "stream": False, "format": "json"},
            )
            resp.raise_for_status()
            data = resp.json()

        import json
        result = json.loads(data.get("response", "{}"))
        result["model"]   = OLLAMA_MODEL
        result["note"]    = "AI grading suggestion only. Final grade must be set by instructor."
        return result

    except Exception as e:
        log.error(f"AI grade error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
