"""Metadata-only detection for instructions embedded in untrusted documents."""

import re
from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class GuardResult:
    suspicious: bool
    signals: list[str]


_SIGNAL_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    (
        "instruction_override",
        re.compile(
            r"(?:"
            r"\b(?:ignore|disregard|forget|desconsidere|esque[cç]a)\b"
            r".{0,100}\b(?:previous|prior|above|anteriores?|pr[eé]vias?|acima)\b"
            r".{0,60}\b(?:instructions?|rules?|regras?|instru[cç][oõ]es|instrucoes)\b"
            r"|\b(?:ignore|disregard|forget|desconsidere|esque[cç]a)\b"
            r".{0,100}\b(?:instructions?|rules?|regras?|instru[cç][oõ]es|instrucoes)\b"
            r".{0,60}\b(?:previous|prior|above|anteriores?|pr[eé]vias?|acima)\b"
            r"|\bdo\s+not\s+follow\b.{0,100}\b(?:previous|prior|above)\b"
            r".{0,60}\b(?:instructions?|rules?)\b"
            r"|\b(?:ignore|disregard|desconsidere)\b.{0,80}"
            r"\b(?:system|developer|sistema|desenvolvedor)\b.{0,40}"
            r"\b(?:prompt|message|mensagem|instru[cç][oõ]es|instrucoes)\b"
            r")",
            re.IGNORECASE | re.DOTALL,
        ),
    ),
    (
        "role_impersonation",
        re.compile(
            r"\b(?:you|voce|voc[eê])\b.{0,40}\b(?:now|agora)\b.{0,40}"
            r"\b(?:are|e|[eé])\b.{0,30}\b(?:system|developer|sistema|desenvolvedor)\b",
            re.IGNORECASE | re.DOTALL,
        ),
    ),
    (
        "prompt_exfiltration",
        re.compile(
            r"\b(?:reveal|show|print|expose|mostre|revele|exiba)\b"
            r".{0,100}(?:"
            r"\b(?:system|developer|sistema|desenvolvedor)\b"
            r".{0,40}\b(?:prompt|message|mensagem|instru[cç][oõ]es|instrucoes)\b"
            r"|\b(?:prompt|message|mensagem|instru[cç][oõ]es|instrucoes)\b"
            r".{0,40}\b(?:system|developer|sistema|desenvolvedor)\b"
            r")",
            re.IGNORECASE | re.DOTALL,
        ),
    ),
    (
        "tool_execution",
        re.compile(
            r"\b(?:call|invoke|use|run|execute|chame|invoque|use|rode|execute)\b"
            r".{0,80}\b(?:tool|function|command|shell|ferramenta|fun[cç][aã]o|comando)\b",
            re.IGNORECASE | re.DOTALL,
        ),
    ),
    (
        "decision_manipulation",
        re.compile(
            r"\b(?:approve|accept|reject|aprove|aceite|rejeite)\b"
            r".{0,80}\b(?:supplier|vendor|fornecedor|document|documento)\b",
            re.IGNORECASE | re.DOTALL,
        ),
    ),
)


def scan_untrusted_text(text: str) -> GuardResult:
    """Return deterministic signals; never execute, transform, or redact input."""

    if not isinstance(text, str):
        raise TypeError("text must be a string")
    signals = [name for name, pattern in _SIGNAL_PATTERNS if pattern.search(text)]
    return GuardResult(suspicious=bool(signals), signals=signals)
