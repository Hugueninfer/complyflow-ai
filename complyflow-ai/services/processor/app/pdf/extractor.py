"""Extract page-scoped text from untrusted PDFs without interpreting it."""

from __future__ import annotations

import json
import os
import re
import resource
import select
import signal
import struct
from abc import ABC, abstractmethod
from dataclasses import dataclass
from io import BytesIO
from multiprocessing import get_context
from time import monotonic

from pypdf import PdfReader

from app.execution import ExecutionBudget
from app.processes import reap_group


MAX_PDF_BYTES = 5 * 1024 * 1024
MAX_PAGES = 200
MAX_PAGE_CHARS = 1_000_000
MAX_TOTAL_TEXT_CHARS = 5_000_000
MIN_EXTRACTED_TEXT_CHARS = 20
PROCESS_MEMORY_LIMIT_BYTES = 256 * 1024 * 1024
PROCESS_CPU_SECONDS = 20
PROCESS_TIMEOUT_SECONDS = 30.0
MAX_IPC_PAYLOAD_BYTES = MAX_TOTAL_TEXT_CHARS * 4 + MAX_PAGES * 128 + 1024
PUBLIC_ERROR_CODES = frozenset({
    "invalid_pdf",
    "encrypted_pdf",
    "pdf_limit_exceeded",
    "ocr_unavailable",
    "ocr_failed",
})


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
        except MemoryError:
            raise PdfExtractionError("pdf_limit_exceeded") from None
        except PdfExtractionError:
            raise
        except Exception:
            raise PdfExtractionError("ocr_failed") from None


def normalize_page_text(text: str) -> str:
    """Canonicalize whitespace while retaining every non-whitespace character."""

    return re.sub(r"\s+", " ", text, flags=re.UNICODE).strip()


def _has_sufficient_text(text: str) -> bool:
    return sum(character.isalnum() for character in text) >= MIN_EXTRACTED_TEXT_CHARS


def _extract_pages(data: bytes, ocr_engine: OcrEngine | None) -> list[ExtractedPage]:
    try:
        reader = PdfReader(BytesIO(data), strict=True)
        if reader.is_encrypted:
            raise PdfExtractionError("encrypted_pdf")
        page_count = len(reader.pages)
        if page_count == 0:
            raise PdfExtractionError("invalid_pdf")
        if page_count > MAX_PAGES:
            raise PdfExtractionError("pdf_limit_exceeded")
    except MemoryError:
        raise PdfExtractionError("pdf_limit_exceeded") from None
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
        except MemoryError:
            raise PdfExtractionError("pdf_limit_exceeded") from None
        except Exception:
            raise PdfExtractionError("invalid_pdf") from None

        text = extracted
        ocr_used = False
        if configured_ocr and not _has_sufficient_text(extracted):
            ocr_error_code = None
            try:
                raw_ocr_text = engine.extract_page(data, page_number)
            except MemoryError:
                ocr_error_code = "pdf_limit_exceeded"
            except PdfExtractionError as error:
                candidate = str(error)
                ocr_error_code = candidate if candidate in PUBLIC_ERROR_CODES else "ocr_failed"
            except Exception:
                ocr_error_code = "ocr_failed"
            if ocr_error_code is not None:
                raise PdfExtractionError(ocr_error_code) from None
            ocr_text = normalize_page_text(raw_ocr_text)
            if ocr_text:
                text = ocr_text
                ocr_used = True

        total_chars += len(text)
        if len(text) > MAX_PAGE_CHARS or total_chars > MAX_TOTAL_TEXT_CHARS:
            raise PdfExtractionError("pdf_limit_exceeded")
        pages.append(ExtractedPage(number=page_number, text=text, ocr_used=ocr_used))

    return pages


def _apply_resource_limits() -> None:
    try:
        resource.setrlimit(
            resource.RLIMIT_AS,
            (PROCESS_MEMORY_LIMIT_BYTES, PROCESS_MEMORY_LIMIT_BYTES),
        )
        resource.setrlimit(
            resource.RLIMIT_CPU,
            (PROCESS_CPU_SECONDS, PROCESS_CPU_SECONDS),
        )
    except (OSError, ValueError):
        raise PdfExtractionError("pdf_limit_exceeded") from None


def _extract_worker(
    writer_fd: int,
    reader_fd: int,
    data: bytes,
    ocr_engine: OcrEngine | None,
) -> None:
    try:
        os.close(reader_fd)
    except OSError:
        pass
    try:
        os.setsid()
        _apply_resource_limits()
        result = ("ok", _extract_pages(data, ocr_engine))
    except MemoryError:
        result = ("error", "pdf_limit_exceeded")
    except PdfExtractionError as error:
        candidate = str(error)
        result = ("error", candidate if candidate in PUBLIC_ERROR_CODES else "invalid_pdf")
    except BaseException:
        result = ("error", "invalid_pdf")

    try:
        status, payload = result
        if status == "ok":
            message = {
                "status": "ok",
                "pages": [
                    {
                        "number": page.number,
                        "text": page.text,
                        "ocr_used": page.ocr_used,
                    }
                    for page in payload
                ],
            }
        else:
            message = {"status": "error", "code": payload}
        encoded = json.dumps(
            message,
            ensure_ascii=False,
            separators=(",", ":"),
        ).encode("utf-8")
        if len(encoded) > MAX_IPC_PAYLOAD_BYTES:
            encoded = b'{"status":"error","code":"pdf_limit_exceeded"}'
        frame = memoryview(struct.pack("!I", len(encoded)) + encoded)
        while frame:
            frame = frame[os.write(writer_fd, frame):]
    except BaseException:
        pass
    finally:
        try:
            os.close(writer_fd)
        except OSError:
            pass


def _read_exact_until(
    reader_fd: int, size: int, deadline: float, budget: ExecutionBudget | None = None,
) -> bytes:
    output = bytearray()
    while len(output) < size:
        if budget:
            budget.checkpoint()
        remaining = deadline - monotonic()
        if remaining <= 0:
            raise TimeoutError
        # Poll the cooperative token even when a parser/descendant never writes.
        readable, _, _ = select.select((reader_fd,), (), (), min(remaining, 0.05) if budget else remaining)
        if not readable:
            continue
        try:
            chunk = os.read(reader_fd, min(64 * 1024, size - len(output)))
        except BlockingIOError:
            continue
        if not chunk:
            raise EOFError
        output.extend(chunk)
    return bytes(output)


def _receive_result(
    reader_fd: int, deadline: float, *, budget: ExecutionBudget | None = None,
) -> list[ExtractedPage]:
    os.set_blocking(reader_fd, False)
    payload_size = struct.unpack("!I", _read_exact_until(reader_fd, 4, deadline, budget))[0]
    if payload_size == 0 or payload_size > MAX_IPC_PAYLOAD_BYTES:
        raise ValueError
    raw_payload = _read_exact_until(reader_fd, payload_size, deadline, budget)
    message = json.loads(raw_payload)
    if budget:
        budget.checkpoint()
    if not isinstance(message, dict) or message.get("status") not in {"ok", "error"}:
        raise ValueError
    if message["status"] == "error":
        code = message.get("code")
        if code not in PUBLIC_ERROR_CODES:
            raise ValueError
        raise PdfExtractionError(code)

    raw_pages = message.get("pages")
    if not isinstance(raw_pages, list) or len(raw_pages) > MAX_PAGES:
        raise ValueError
    pages: list[ExtractedPage] = []
    total_chars = 0
    for raw_page in raw_pages:
        if budget:
            budget.checkpoint()
        if not isinstance(raw_page, dict) or set(raw_page) != {
            "number",
            "text",
            "ocr_used",
        }:
            raise ValueError
        page = ExtractedPage(**raw_page)
        if not isinstance(page.text, str) or not isinstance(page.ocr_used, bool):
            raise ValueError
        total_chars += len(page.text)
        if len(page.text) > MAX_PAGE_CHARS or total_chars > MAX_TOTAL_TEXT_CHARS:
            raise ValueError
        pages.append(page)
    return pages


def _stop_process(process) -> None:
    if process.pid is None:
        return
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        if process.is_alive():
            process.terminate()
    process.join(0.2)
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    if process.is_alive():
        process.kill()
        process.join()
    # Provider isolation enables Linux subreaping in this same server. Orphan
    # OCR descendants adopted here must not remain zombies after PDF cleanup.
    reap_group(process.pid)


def extract_pages(
    data: bytes,
    *,
    ocr_engine: OcrEngine | None = None,
    budget: ExecutionBudget | None = None,
) -> list[ExtractedPage]:
    """Extract pages in a Linux child process with strict resource budgets."""

    if budget:
        budget.checkpoint()
    if not isinstance(data, bytes) or not data or not data.startswith(b"%PDF-"):
        raise PdfExtractionError("invalid_pdf")
    if len(data) > MAX_PDF_BYTES:
        raise PdfExtractionError("pdf_limit_exceeded")

    timeout = budget.remaining(PROCESS_TIMEOUT_SECONDS) if budget else PROCESS_TIMEOUT_SECONDS
    deadline = monotonic() + timeout
    context = get_context("fork")
    reader_fd, writer_fd = os.pipe()
    process = context.Process(
        target=_extract_worker,
        args=(writer_fd, reader_fd, data, ocr_engine),
        daemon=True,
    )
    result = None
    process_error = None
    try:
        process.start()
        os.close(writer_fd)
        writer_fd = -1
        result = _receive_result(reader_fd, deadline, budget=budget)
    except PdfExtractionError as error:
        process_error = str(error)
    except (EOFError, OSError, TimeoutError, TypeError, ValueError):
        process_error = "pdf_limit_exceeded"
    finally:
        _stop_process(process)
        try:
            os.close(reader_fd)
        except OSError:
            pass
        if writer_fd >= 0:
            try:
                os.close(writer_fd)
            except OSError:
                pass
        process.close()

    if budget:
        budget.checkpoint()
    if process_error is not None:
        raise PdfExtractionError(process_error) from None
    return result
