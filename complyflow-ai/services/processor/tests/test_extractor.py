from io import BytesIO
from pathlib import Path

import pytest
from pypdf import PdfReader, PdfWriter

from app.pdf.extractor import (
    MAX_PDF_BYTES,
    DisabledOcrEngine,
    PdfExtractionError,
    extract_pages,
)


FIXTURE = Path(__file__).parent / "fixtures/two-pages.pdf"


def test_extracts_page_numbers_and_normalized_text_in_order():
    pages = extract_pages(FIXTURE.read_bytes())

    assert [(page.number, page.text, page.ocr_used) for page in pages] == [
        (1, "Primeira pagina", False),
        (2, "Segunda pagina", False),
    ]


def test_uses_configured_ocr_only_for_pages_with_insufficient_text():
    class RecordingOcr:
        def __init__(self):
            self.calls = []

        def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
            self.calls.append((pdf_bytes, page_number))
            return "  Texto   recuperado por OCR  "

    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    output = BytesIO()
    writer.write(output)
    pdf_bytes = output.getvalue()
    engine = RecordingOcr()

    pages = extract_pages(pdf_bytes, ocr_engine=engine)

    assert engine.calls == [(pdf_bytes, 1)]
    assert pages[0].text == "Texto recuperado por OCR"
    assert pages[0].ocr_used is True


def test_disabled_ocr_is_the_safe_default_for_textless_pages():
    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    output = BytesIO()
    writer.write(output)

    assert DisabledOcrEngine().extract_page(output.getvalue(), 1) == ""
    assert extract_pages(output.getvalue())[0].ocr_used is False


@pytest.mark.parametrize(
    ("payload", "expected"),
    [
        (b"not a pdf SECRET", "invalid_pdf"),
        (b"", "invalid_pdf"),
        (b"%PDF-1.7\nSECRET" + b"x" * MAX_PDF_BYTES, "pdf_limit_exceeded"),
    ],
)
def test_rejects_invalid_or_oversized_pdf_with_sanitized_error(payload, expected):
    with pytest.raises(PdfExtractionError) as error:
        extract_pages(payload)

    assert str(error.value) == expected
    assert "SECRET" not in repr(error.value)


def test_rejects_encrypted_pdf_with_sanitized_error():
    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    writer.encrypt("secret password")
    output = BytesIO()
    writer.write(output)

    with pytest.raises(PdfExtractionError) as error:
        extract_pages(output.getvalue())

    assert str(error.value) == "encrypted_pdf"


def test_ocr_failure_does_not_expose_engine_or_document_details():
    class BrokenOcr:
        def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
            raise RuntimeError("SECRET document path /tmp/private.pdf")

    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    output = BytesIO()
    writer.write(output)

    with pytest.raises(PdfExtractionError) as error:
        extract_pages(output.getvalue(), ocr_engine=BrokenOcr())

    assert str(error.value) == "ocr_failed"
    assert "SECRET" not in repr(error.value)


def test_fixture_really_has_two_distinct_pdf_pages():
    reader = PdfReader(FIXTURE)
    assert len(reader.pages) == 2
