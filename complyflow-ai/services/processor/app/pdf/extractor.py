"""Extract page-scoped text from untrusted PDFs without interpreting it."""

from __future__ import annotations

import re
from abc import ABC, abstractmethod
from dataclasses import dataclass
from io import BytesIO

from pypdf import PdfReader


MAX_PDF_BYTES = 5 * 1024 * 1024
MAX_PAGES = 200
MAX_PAGE_CHARS = 1_000_000
MAX_TOTAL_TEXT_CHARS = 5_000_000
MIN_EXTRACTED_TEXT_CHARS = 20


class PdfExtractionError(ValueError):
    """A public, sanitized PDF processing error."""


@dataclass(frozen=True, slots=True)
class ExtractedPage:
    number: int
    text: str
    ocr_used: bool = False

    def __post_init__(self) -> None:
        if isinstance(self.number, bool) or self.number < 1:
            raise ValueError("page number must be a positive integer")


class OcrEngine(ABC):
    """Injectable OCR boundary. Page numbers are one-based."""

    @abstractmethod
    def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
        raise NotImplementedError


class DisabledOcrEngine(OcrEngine):
    """Safe default that keeps the free runtime independent of OCR binaries."""

    def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
        return ""


class TesseractOcrEngine(OcrEngine):
    """Optional adapter; imports its heavyweight dependencies only when used."""

    def __init__(self, *, language: str = "por+eng", dpi: int = 200):
        self.language = language
        self.dpi = dpi

    def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
        try:
            from pdf2image import convert_from_bytes
            import pytesseract
        except ImportError:
            raise PdfExtractionError("ocr_unavailable") from None

        try:
            images = convert_from_bytes(
                pdf_bytes,
                dpi=self.dpi,
                first_page=page_number,
                last_page=page_number,
            )
            if len(images) != 1:
                raise PdfExtractionError("ocr_failed")
            return pytesseract.image_to_string(images[0], lang=self.language)
        except PdfExtractionError:
            raise
        except Exception:
            raise PdfExtractionError("ocr_failed") from None


def normalize_page_text(text: str) -> str:
    """Canonicalize whitespace while retaining every non-whitespace character."""

    return re.sub(r"\s+", " ", text, flags=re.UNICODE).strip()


def _has_sufficient_text(text: str) -> bool:
    return sum(character.isalnum() for character in text) >= MIN_EXTRACTED_TEXT_CHARS


def extract_pages(
    data: bytes,
    *,
    ocr_engine: OcrEngine | None = None,
) -> list[ExtractedPage]:
    """Extract normalized text in PDF order, preserving strict page boundaries."""

    if not isinstance(data, bytes) or not data or not data.startswith(b"%PDF-"):
        raise PdfExtractionError("invalid_pdf")
    if len(data) > MAX_PDF_BYTES:
        raise PdfExtractionError("pdf_limit_exceeded")

    try:
        reader = PdfReader(BytesIO(data), strict=True)
        if reader.is_encrypted:
            raise PdfExtractionError("encrypted_pdf")
        page_count = len(reader.pages)
        if page_count == 0:
            raise PdfExtractionError("invalid_pdf")
        if page_count > MAX_PAGES:
            raise PdfExtractionError("pdf_limit_exceeded")
    except PdfExtractionError:
        raise
    except Exception:
        raise PdfExtractionError("invalid_pdf") from None

    configured_ocr = ocr_engine is not None and not isinstance(ocr_engine, DisabledOcrEngine)
    engine = ocr_engine if ocr_engine is not None else DisabledOcrEngine()
    pages: list[ExtractedPage] = []
    total_chars = 0

    for page_number, pdf_page in enumerate(reader.pages, start=1):
        try:
            extracted = normalize_page_text(pdf_page.extract_text() or "")
        except Exception:
            raise PdfExtractionError("invalid_pdf") from None

        text = extracted
        ocr_used = False
        if configured_ocr and not _has_sufficient_text(extracted):
            try:
                ocr_text = normalize_page_text(engine.extract_page(data, page_number))
            except PdfExtractionError:
                raise
            except Exception:
                raise PdfExtractionError("ocr_failed") from None
            if ocr_text:
                text = ocr_text
                ocr_used = True

        total_chars += len(text)
        if len(text) > MAX_PAGE_CHARS or total_chars > MAX_TOTAL_TEXT_CHARS:
            raise PdfExtractionError("pdf_limit_exceeded")
        pages.append(ExtractedPage(number=page_number, text=text, ocr_used=ocr_used))

    return pages
