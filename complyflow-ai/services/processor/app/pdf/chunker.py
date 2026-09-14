"""Deterministic page-local character chunking."""

from dataclasses import dataclass
from typing import Iterable

from app.execution import ExecutionBudget
from app.pdf.extractor import ExtractedPage


class ChunkingError(ValueError):
    """A public, sanitized chunking configuration or input error."""


@dataclass(frozen=True, slots=True)
class Chunk:
    page_number: int
    index: int
    text: str
    start_offset: int
    end_offset: int


def chunk_pages(
    pages: Iterable[ExtractedPage],
    max_chars: int = 1200,
    overlap: int = 150,
    *,
    budget: ExecutionBudget | None = None,
) -> list[Chunk]:
    """Split normalized pages into exact slices; a chunk never spans pages."""

    if (
        isinstance(max_chars, bool)
        or isinstance(overlap, bool)
        or not isinstance(max_chars, int)
        or not isinstance(overlap, int)
        or max_chars <= 0
        or overlap < 0
        or overlap >= max_chars
    ):
        raise ChunkingError("invalid_chunk_configuration")

    page_list = []
    for page in pages:
        if budget:
            budget.checkpoint()
        page_list.append(page)
    page_numbers = [page.number for page in page_list]
    if any(
        isinstance(number, bool) or not isinstance(number, int) or number < 1
        for number in page_numbers
    ) or any(right <= left for left, right in zip(page_numbers, page_numbers[1:])):
        raise ChunkingError("invalid_page_sequence")

    chunks: list[Chunk] = []
    step = max_chars - overlap
    for page in page_list:
        start = 0
        while start < len(page.text):
            if budget:
                budget.checkpoint()
            end = min(start + max_chars, len(page.text))
            chunks.append(
                Chunk(
                    page_number=page.number,
                    index=len(chunks),
                    text=page.text[start:end],
                    start_offset=start,
                    end_offset=end,
                )
            )
            if end == len(page.text):
                break
            start += step

    return chunks
