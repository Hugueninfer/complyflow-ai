import pytest

from app.pdf.chunker import ChunkingError, chunk_pages
from app.pdf.extractor import ExtractedPage


def test_chunks_never_cross_pages_and_are_exact_page_slices():
    pages = [
        ExtractedPage(number=1, text="A" * 2000),
        ExtractedPage(number=2, text="B" * 1300),
    ]

    chunks = chunk_pages(pages)

    assert [chunk.index for chunk in chunks] == [0, 1, 2, 3]
    assert [chunk.page_number for chunk in chunks] == [1, 1, 2, 2]
    assert all(len(chunk.text) <= 1200 for chunk in chunks)
    assert all(
        chunk.text
        == pages[chunk.page_number - 1].text[chunk.start_offset:chunk.end_offset]
        for chunk in chunks
    )


def test_chunks_have_exact_requested_overlap_and_coherent_offsets():
    page = ExtractedPage(number=7, text="0123456789" * 10)

    chunks = chunk_pages([page], max_chars=30, overlap=5)

    assert [(chunk.start_offset, chunk.end_offset) for chunk in chunks] == [
        (0, 30),
        (25, 55),
        (50, 80),
        (75, 100),
    ]
    assert all(left.text[-5:] == right.text[:5] for left, right in zip(chunks, chunks[1:]))


def test_empty_page_produces_no_false_evidence_chunk():
    assert chunk_pages([ExtractedPage(number=1, text="")]) == []


@pytest.mark.parametrize(
    ("max_chars", "overlap"),
    [(0, 0), (-1, 0), (10, -1), (10, 10), (10, 11), (True, 0)],
)
def test_rejects_chunk_parameters_that_cannot_make_progress(max_chars, overlap):
    with pytest.raises(ChunkingError, match="^invalid_chunk_configuration$"):
        chunk_pages([ExtractedPage(number=1, text="text")], max_chars=max_chars, overlap=overlap)


def test_rejects_duplicate_or_out_of_order_page_numbers():
    pages = [
        ExtractedPage(number=2, text="second"),
        ExtractedPage(number=1, text="first"),
    ]

    with pytest.raises(ChunkingError, match="^invalid_page_sequence$"):
        chunk_pages(pages)
