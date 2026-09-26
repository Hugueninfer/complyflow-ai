"""Stateless PDF-to-finding pipeline; Laravel remains the persistence boundary."""

import base64
import binascii
import hashlib
import os
from dataclasses import asdict
from time import monotonic

from pydantic import ValidationError

from app.execution import ExecutionBudget
from app.pdf.chunker import chunk_pages
from app.pdf.extractor import MAX_PDF_BYTES, extract_pages
from app.providers.base import AIProvider, AnalysisContext, ProviderError
from app.providers.fake import FakeAIProvider
from app.retrieval.hybrid import HybridRetriever, embed_text
from app.schemas import (
    AnalyzeRequest, AnalyzeResponse, ChunkDraft, FindingDraft, PageDraft, ProcessedDocumentDraft,
)
from app.security.document_guard import scan_untrusted_text


MAX_CHUNKS = 2000
MAX_DOCUMENTS = 10
MAX_REQUIREMENTS = 100
MAX_TOTAL_PDF_BYTES = 15 * 1024 * 1024
GEMINI_OPENAI_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/openai/'
DEFAULT_GEMINI_MODEL = 'gemini-3.8-flash'


class AnalysisError(ValueError):
    """Sanitized analysis input or budget error."""


class InvalidFinding(ProviderError):
    pass


class InvalidCitation(InvalidFinding):
    pass


class AnalysisPipeline:
    def __init__(self, provider: AIProvider):
        self.provider = provider

    @classmethod
    def from_settings(cls):
        selected = os.getenv('AI_PROVIDER', 'fake')
        if selected == 'fake':
            return cls(FakeAIProvider())
        if selected == 'gemini':
            api_key = os.getenv('GEMINI_API_KEY', '').strip()
            if not api_key:
                raise ProviderError('provider_not_configured')
            from app.providers.openai_compatible import OpenAICompatibleProvider

            return cls(OpenAICompatibleProvider(
                base_url=GEMINI_OPENAI_BASE_URL,
                api_key=api_key,
                model=os.getenv('GEMINI_MODEL', DEFAULT_GEMINI_MODEL).strip() or DEFAULT_GEMINI_MODEL,
                reasoning_effort='low',
            ))
        if selected != 'openai-compatible' or not all(
            os.getenv(name, '').strip() for name in ('AI_BASE_URL', 'AI_API_KEY', 'AI_MODEL')
        ):
            raise ProviderError('provider_not_configured')
        from app.providers.openai_compatible import OpenAICompatibleProvider

        return cls(OpenAICompatibleProvider(
            base_url=os.environ['AI_BASE_URL'], api_key=os.environ['AI_API_KEY'], model=os.environ['AI_MODEL'],
        ))

    def validate_finding(
        self, finding: FindingDraft, documents: list[ProcessedDocumentDraft],
    ) -> FindingDraft:
        try:
            raw = finding.model_dump(warnings=False) if isinstance(finding, FindingDraft) else finding
            finding = FindingDraft.model_validate(raw)
        except (ValidationError, TypeError, ValueError):
            raise InvalidFinding('invalid_finding') from None
        pages = {(document.document_id, page.page_number): page.text
                 for document in documents for page in document.pages}
        for citation in finding.citations:
            text = pages.get((citation.document_id, citation.page_number))
            if text is None or citation.end_offset > len(text) or (
                text[citation.start_offset:citation.end_offset] != citation.quote
            ):
                raise InvalidCitation('invalid_citation')
        return finding

    def run(self, request: AnalyzeRequest, *, budget: ExecutionBudget | None = None) -> AnalyzeResponse:
        budget = budget or ExecutionBudget.from_settings(clock=monotonic)
        budget.checkpoint()
        if len(request.documents) > MAX_DOCUMENTS or len(request.requirements) > MAX_REQUIREMENTS:
            raise AnalysisError('analysis_limit_exceeded')
        for identifiers in (
            [item.document_id for item in request.documents],
            [item.requirement_id for item in request.requirements],
        ):
            if len(identifiers) != len(set(identifiers)):
                raise AnalysisError('duplicate_identifiers')

        documents, contexts, embeddings = [], [], []
        total_bytes = 0
        for document in request.documents:
            budget.checkpoint()
            if len(document.content_base64) > 4 * ((MAX_PDF_BYTES + 2) // 3):
                raise AnalysisError('analysis_limit_exceeded')
            try:
                content = base64.b64decode(document.content_base64, validate=True)
            except (binascii.Error, ValueError):
                raise AnalysisError('invalid_document') from None
            total_bytes += len(content)
            if total_bytes > MAX_TOTAL_PDF_BYTES or len(content) > MAX_PDF_BYTES:
                raise AnalysisError('analysis_limit_exceeded')
            if hashlib.sha256(content).hexdigest() != document.sha256:
                raise AnalysisError('document_hash_mismatch')
            budget.checkpoint()
            pages = extract_pages(content, budget=budget)
            budget.checkpoint()
            chunks = chunk_pages(pages, budget=budget)
            budget.checkpoint()
            if len(contexts) + len(chunks) > MAX_CHUNKS:
                raise AnalysisError('analysis_limit_exceeded')
            # Scan whole pages so an instruction split across chunks stays flagged.
            signals = {page.number: scan_untrusted_text(page.text, budget=budget).signals for page in pages}
            drafts = []
            for chunk in chunks:
                budget.checkpoint()
                if not chunk.text.strip():
                    continue
                embedding = embed_text(chunk.text, budget=budget)
                budget.checkpoint()
                drafts.append(ChunkDraft(**asdict(chunk), embedding=embedding))
                contexts.append(AnalysisContext(
                    document_id=document.document_id, **asdict(chunk), signals=signals[chunk.page_number],
                ))
                embeddings.append(embedding)
            documents.append(ProcessedDocumentDraft(
                document_id=document.document_id,
                pages=[PageDraft(page_number=page.number, text=page.text, ocr_used=page.ocr_used) for page in pages],
                chunks=drafts,
            ))

        budget.checkpoint()
        retriever = HybridRetriever(contexts, embeddings, budget=budget)
        budget.checkpoint()
        findings = []
        for requirement in request.requirements:
            budget.checkpoint()
            selected = retriever.search(requirement.criterion + ' ' + requirement.evaluation_text, budget=budget)
            budget.checkpoint()
            draft = self.provider.analyze(requirement, selected, budget=budget)
            budget.checkpoint()
            finding = self.validate_finding(draft, documents)
            if finding.requirement_id != requirement.requirement_id:
                raise InvalidFinding('invalid_finding')
            for citation in finding.citations:
                if not any(
                    citation.document_id == context.document_id
                    and citation.page_number == context.page_number
                    and context.start_offset <= citation.start_offset < citation.end_offset <= context.end_offset
                    for context in selected
                ):
                    raise InvalidCitation('invalid_citation')
            findings.append(finding)
        result = AnalyzeResponse(analysis_id=request.analysis_id, findings=findings, processed_documents=documents)
        budget.checkpoint()
        return result
