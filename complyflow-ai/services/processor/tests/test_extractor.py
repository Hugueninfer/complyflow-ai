from io import BytesIO
import os
from pathlib import Path
from multiprocessing import get_context
import resource
import signal
import struct
import subprocess
import sys
from time import monotonic, sleep

import pytest
from pypdf import PdfReader, PdfWriter

from app.pdf.extractor import (
    MAX_PDF_BYTES,
    DisabledOcrEngine,
    PdfExtractionError,
    extract_pages,
)
from app.execution import ExecutionBudget, ExecutionStopped


FIXTURE = Path(__file__).parent / "fixtures/two-pages.pdf"


def test_extracts_page_numbers_and_normalized_text_in_order():
    pages = extract_pages(FIXTURE.read_bytes())

    assert [(page.number, page.text, page.ocr_used) for page in pages] == [
        (1, "Primeira pagina", False),
        (2, "Segunda pagina", False),
    ]


def test_uses_configured_ocr_only_for_pages_with_insufficient_text():
    class EchoArgumentsOcr:
        def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
            return f"OCR pagina {page_number}; PDF com {len(pdf_bytes)} bytes"

    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    output = BytesIO()
    writer.write(output)
    pdf_bytes = output.getvalue()
    pages = extract_pages(pdf_bytes, ocr_engine=EchoArgumentsOcr())

    assert pages[0].text == f"OCR pagina 1; PDF com {len(pdf_bytes)} bytes"
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


def test_ocr_cannot_smuggle_sensitive_text_through_pdf_error():
    class SensitivePdfErrorOcr:
        def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
            raise PdfExtractionError("SECRET prompt and /tmp/private.pdf")

    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    output = BytesIO()
    writer.write(output)

    with pytest.raises(PdfExtractionError) as error:
        extract_pages(output.getvalue(), ocr_engine=SensitivePdfErrorOcr())

    assert str(error.value) == "ocr_failed"
    assert error.value.__cause__ is None
    assert error.value.__context__ is None
    assert "SECRET" not in repr(error.value)


def test_pdf_and_ocr_run_in_a_resource_limited_child_process():
    class ResourceProbeOcr:
        def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
            address_space, _ = resource.getrlimit(resource.RLIMIT_AS)
            cpu_seconds, _ = resource.getrlimit(resource.RLIMIT_CPU)
            return f"pid={os.getpid()} memory={address_space} cpu={cpu_seconds}"

    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    output = BytesIO()
    writer.write(output)

    page = extract_pages(output.getvalue(), ocr_engine=ResourceProbeOcr())[0]
    fields = dict(item.split("=") for item in page.text.split())

    assert int(fields["pid"]) != os.getpid()
    assert int(fields["memory"]) not in (-1, resource.RLIM_INFINITY)
    assert int(fields["memory"]) > 0
    assert int(fields["cpu"]) not in (-1, resource.RLIM_INFINITY)
    assert int(fields["cpu"]) > 0


def test_parser_timeout_kills_isolated_work_and_returns_sanitized_error(monkeypatch):
    from app.pdf import extractor

    class SlowReader:
        def __init__(self, *args, **kwargs):
            sleep(1)

    monkeypatch.setattr(extractor, "PdfReader", SlowReader)
    monkeypatch.setattr(extractor, "PROCESS_TIMEOUT_SECONDS", 0.05, raising=False)
    started = monotonic()

    with pytest.raises(PdfExtractionError) as error:
        extract_pages(FIXTURE.read_bytes())

    assert monotonic() - started < 0.75
    assert str(error.value) == "pdf_limit_exceeded"
    assert error.value.__cause__ is None
    assert error.value.__context__ is None


def test_parser_memory_exhaustion_is_contained_and_sanitized(monkeypatch):
    from app.pdf import extractor

    class MemoryHungryReader:
        def __init__(self, *args, **kwargs):
            self.payload = bytearray(64 * 1024 * 1024)
            raise AssertionError("allocation escaped its budget")

    monkeypatch.setattr(extractor, "PdfReader", MemoryHungryReader)
    monkeypatch.setattr(
        extractor, "PROCESS_MEMORY_LIMIT_BYTES", 32 * 1024 * 1024, raising=False,
    )

    with pytest.raises(PdfExtractionError) as error:
        extract_pages(FIXTURE.read_bytes())

    assert str(error.value) == "pdf_limit_exceeded"
    assert error.value.__cause__ is None
    assert error.value.__context__ is None


def test_ocr_descendants_are_cleaned_up_with_the_isolated_process_group():
    class ChildProcessOcr:
        def extract_page(self, pdf_bytes: bytes, page_number: int) -> str:
            child = subprocess.Popen(["sleep", "10"])
            return f"OCR descendant pid {child.pid}"

    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    output = BytesIO()
    writer.write(output)
    descendant_pid = int(
        extract_pages(output.getvalue(), ocr_engine=ChildProcessOcr())[0].text.rsplit(" ", 1)[1]
    )

    try:
        deadline = monotonic() + 0.5
        while monotonic() < deadline:
            stat = Path(f"/proc/{descendant_pid}/stat")
            if not stat.exists() or stat.read_text().split()[2] == "Z":
                break
            sleep(0.01)
        else:
            pytest.fail("OCR descendant remained running after extraction")
    finally:
        try:
            os.kill(descendant_pid, signal.SIGKILL)
        except ProcessLookupError:
            pass


def test_partial_ipc_frame_with_inherited_writer_respects_deadline_and_cleans_group(
    monkeypatch,
):
    from app.pdf import extractor

    descendant_pid = get_context("fork").Value("i", 0)

    def partial_frame_worker(writer, *args):
        os.setsid()
        writer_fd = writer if isinstance(writer, int) else writer.fileno()
        descendant = subprocess.Popen(
            [
                sys.executable,
                "-c",
                f"import os,time; time.sleep(1); os.close({writer_fd}); time.sleep(9)",
            ],
            pass_fds=(writer_fd,),
        )
        descendant_pid.value = descendant.pid
        os.write(writer_fd, struct.pack("!I", 4096) + b"{")
        os._exit(1)

    monkeypatch.setattr(extractor, "_extract_worker", partial_frame_worker)
    monkeypatch.setattr(extractor, "PROCESS_TIMEOUT_SECONDS", 0.05)
    started = monotonic()

    with pytest.raises(PdfExtractionError) as error:
        extract_pages(FIXTURE.read_bytes())

    elapsed = monotonic() - started
    pid = descendant_pid.value
    assert elapsed < 0.75
    assert str(error.value) == "pdf_limit_exceeded"
    assert error.value.__cause__ is None
    assert error.value.__context__ is None
    assert pid > 0
    deadline = monotonic() + 0.5
    while monotonic() < deadline:
        stat = Path(f"/proc/{pid}/stat")
        if not stat.exists() or stat.read_text().split()[2] == "Z":
            break
        sleep(0.01)
    else:
        try:
            os.kill(pid, signal.SIGKILL)
        finally:
            pytest.fail("inherited IPC writer remained alive after timeout")


def test_fixture_really_has_two_distinct_pdf_pages():
    reader = PdfReader(FIXTURE)
    assert len(reader.pages) == 2


@pytest.mark.parametrize('elapsed,expected_deadline', [(0, 30), (44, 45)])
def test_pdf_receives_the_smaller_of_operation_limit_and_remaining_analysis(monkeypatch, elapsed, expected_deadline):
    from app.pdf import extractor
    from test_execution_budget import Clock

    clock = Clock()
    monkeypatch.setattr(extractor, 'monotonic', clock)
    budget = ExecutionBudget(45, clock=clock)
    clock.advance(elapsed)
    deadlines = []

    def receive(reader_fd, deadline, **kwargs):
        deadlines.append(deadline)
        return []

    monkeypatch.setattr(extractor, '_receive_result', receive)
    assert extract_pages(FIXTURE.read_bytes(), budget=budget) == []
    assert deadlines == [expected_deadline]


def test_disconnect_interrupts_blocked_pdf_pipe_and_reaps_child(monkeypatch):
    from app.pdf import extractor

    budget = ExecutionBudget(45)
    children = []
    original_stop = extractor._stop_process

    def stop(process):
        original_stop(process)
        children.append(process.is_alive())

    def disconnect(readers, writers, errors, timeout):
        assert timeout <= 0.1
        budget.cancel()
        return [], [], []

    monkeypatch.setattr(extractor, '_stop_process', stop)
    monkeypatch.setattr(extractor.select, 'select', disconnect)
    with pytest.raises(ExecutionStopped, match='^analysis_cancelled$'):
        extract_pages(FIXTURE.read_bytes(), budget=budget)
    assert children == [False]


def test_pdf_global_timeout_is_retryable_and_reaps_child(monkeypatch):
    from app.pdf import extractor
    from test_execution_budget import Clock

    clock = Clock()
    budget = ExecutionBudget(0.5, clock=clock)
    monkeypatch.setattr(extractor, 'monotonic', clock)

    def timeout(*args):
        clock.advance(0.5)
        return [], [], []

    monkeypatch.setattr(extractor.select, 'select', timeout)
    with pytest.raises(ExecutionStopped, match='^analysis_budget_exceeded$'):
        extract_pages(FIXTURE.read_bytes(), budget=budget)


def test_expiration_before_fork_does_not_leak_pipe_descriptors(monkeypatch):
    from app.pdf import extractor

    times = iter([0, 0.5, 1])
    budget = ExecutionBudget(1, clock=lambda: next(times))
    original_pipe = os.pipe
    opened = []

    def pipe():
        descriptors = original_pipe()
        opened.extend(descriptors)
        return descriptors

    monkeypatch.setattr(extractor.os, 'pipe', pipe)
    try:
        with pytest.raises(ExecutionStopped, match='^analysis_budget_exceeded$'):
            extract_pages(FIXTURE.read_bytes(), budget=budget)
        for descriptor in opened:
            with pytest.raises(OSError):
                os.fstat(descriptor)
    finally:
        for descriptor in opened:
            try:
                os.close(descriptor)
            except OSError:
                pass
