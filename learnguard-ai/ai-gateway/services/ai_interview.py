"""
AI Interview Service
─────────────────────
Uses Ollama (local LLM) + Whisper for:
  • AI-driven asynchronous video interviews
  • Dynamic follow-up question generation
  • Candidate response analysis
  • Communication scoring
  • Interview summary generation

POST /api/v1/interview/next-question   — get next question based on context
POST /api/v1/interview/analyze-response — score candidate response
POST /api/v1/interview/summarize       — generate full interview summary
"""

import os, logging
from typing import Optional, List
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

router    = APIRouter()
log       = logging.getLogger(__name__)
OLLAMA_URL   = os.getenv("OLLAMA_URL", "http://ollama:11434")
INTERVIEW_MODEL = os.getenv("INTERVIEW_MODEL", "llama3.2")
SUMMARY_MODEL   = os.getenv("SUMMARY_MODEL",   "llama3.2")


async def _ollama(prompt: str, model: str = None, max_tokens: int = 500) -> str:
    import httpx
    m = model or INTERVIEW_MODEL
    async with httpx.AsyncClient(timeout=90) as client:
        resp = await client.post(
            f"{OLLAMA_URL}/api/generate",
            json={"model": m, "prompt": prompt, "stream": False,
                  "options": {"num_predict": max_tokens}},
        )
        resp.raise_for_status()
    return resp.json().get("response", "").strip()


class InterviewContext(BaseModel):
    job_title:      str
    assessment_name: str
    previous_qa:    List[dict] = []  # [{"question": "...", "answer": "..."}]
    question_count: int = 0
    max_questions:  int = 5

class ResponsePayload(BaseModel):
    question:   str
    answer:     str
    job_title:  Optional[str] = None

class SummaryPayload(BaseModel):
    job_title:  str
    qa_pairs:   List[dict]   # [{"question": "...", "answer": "...", "score": X}]
    candidate:  Optional[str] = None


@router.post("/next-question")
async def get_next_question(ctx: InterviewContext):
    """Generate the next interview question based on prior context."""
    try:
        history = "\n".join(
            [f"Q{i+1}: {qa['question']}\nA: {qa['answer']}"
             for i, qa in enumerate(ctx.previous_qa)]
        ) if ctx.previous_qa else "None yet."

        prompt = f"""You are conducting a professional interview for the position of {ctx.job_title}
as part of an assessment titled "{ctx.assessment_name}".

Previous Q&A:
{history}

Generate question #{ctx.question_count + 1} of {ctx.max_questions}.
{'This is the first question — start with an icebreaker introduction question.' if ctx.question_count == 0 else ''}
{'This is the final question — ask about future goals or challenges.' if ctx.question_count == ctx.max_questions - 1 else ''}

Requirements:
- Ask one clear, professional question
- Build naturally on previous answers if any
- Keep it concise (1-2 sentences)
- Do not number the question
- Only output the question text, nothing else"""

        question = await _ollama(prompt, max_tokens=100)
        return {
            "question":        question,
            "question_number": ctx.question_count + 1,
            "is_final":        ctx.question_count + 1 >= ctx.max_questions,
        }
    except Exception as e:
        log.error(f"Next question error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/analyze-response")
async def analyze_response(payload: ResponsePayload):
    """Score a candidate's answer to an interview question."""
    try:
        prompt = f"""Score this interview answer on a scale of 0-10.

Job: {payload.job_title or "General"}
Question: {payload.question}
Answer: {payload.answer}

Respond in JSON:
{{
  "score": <0-10>,
  "communication_score": <0-10>,
  "relevance_score": <0-10>,
  "brevity": "too_short" | "good" | "too_long",
  "keywords_used": ["list", "of", "key", "terms"],
  "feedback": "One sentence feedback",
  "red_flags": ["any concerns if any, else empty list"]
}}

JSON only."""

        import json
        raw = await _ollama(prompt, max_tokens=300)
        result = json.loads(raw)
        result["model"] = INTERVIEW_MODEL
        return result
    except Exception as e:
        log.error(f"Analyze response error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/summarize")
async def summarize_interview(payload: SummaryPayload):
    """Generate a complete interview summary with scores and recommendation."""
    try:
        qa_text = "\n".join([
            f"Q{i+1}: {qa['question']}\nScore: {qa.get('score','N/A')}/10\nAnswer: {qa['answer']}"
            for i, qa in enumerate(payload.qa_pairs)
        ])

        avg_score = (
            sum(qa.get("score", 0) for qa in payload.qa_pairs) / len(payload.qa_pairs)
            if payload.qa_pairs else 0
        )

        prompt = f"""Generate a professional interview assessment summary.

Position: {payload.job_title}
Candidate: {payload.candidate or "Anonymous"}
Average Score: {avg_score:.1f}/10

Interview Q&A:
{qa_text}

Respond in JSON:
{{
  "overall_score": <0-100>,
  "recommendation": "strong_hire" | "hire" | "consider" | "no_hire",
  "executive_summary": "3-4 sentence summary",
  "strengths": ["list", "of", "strengths"],
  "development_areas": ["list", "of", "areas"],
  "communication_assessment": "brief paragraph",
  "technical_assessment": "brief paragraph"
}}

JSON only."""

        import json
        raw = await _ollama(prompt, model=SUMMARY_MODEL, max_tokens=600)
        result = json.loads(raw)
        result["average_raw_score"] = round(avg_score, 2)
        result["model"] = SUMMARY_MODEL
        result["note"]  = "AI assessment — for guidance only. Human review required."
        return result
    except Exception as e:
        log.error(f"Summarize error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
