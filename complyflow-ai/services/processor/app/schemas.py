"""Closed wire contract shared by Laravel and the internal processor."""

from enum import StrEnum
from typing import Annotated, Literal, Self
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator


NonBlank = Annotated[str, Field(min_length=1, pattern=r"\S")]
PageNumber = Annotated[int, Field(ge=1, strict=True)]
Offset = Annotated[int, Field(ge=0, strict=True)]
Number = Annotated[float, Field(strict=True, allow_inf_nan=False)]


class ContractModel(BaseModel):
    model_config = ConfigDict(extra="forbid", allow_inf_nan=False)


class RequirementDraft(ContractModel):
    requirement_id: UUID
    criterion: NonBlank
    category: NonBlank
    weight: Annotated[Number, Field(gt=0)]
    evaluation_text: NonBlank


class DocumentDraft(ContractModel):
    document_id: UUID
    sha256: Annotated[str, Field(pattern=r"^[0-9a-f]{64}$")]
    content_base64: NonBlank


class AnalyzeRequest(ContractModel):
    analysis_id: UUID
    idempotency_key: Annotated[NonBlank, Field(max_length=255)]
    requirements: Annotated[list[RequirementDraft], Field(min_length=1)]
    documents: Annotated[list[DocumentDraft], Field(min_length=1)]


class FindingStatus(StrEnum):
    MET = "met"
    PARTIAL = "partial"
    MISSING = "missing"
    INCONCLUSIVE = "inconclusive"


class CitationDraft(ContractModel):
    """Page-relative character offsets; end_offset must exceed start_offset."""

    document_id: UUID
    page_number: PageNumber
    quote: NonBlank
    start_offset: Offset
    end_offset: Offset

    @model_validator(mode="after")
    def ordered_offsets(self) -> Self:
        if self.end_offset <= self.start_offset:
            raise ValueError("end_offset must exceed start_offset")
        return self


class FindingDraft(ContractModel):
    """Missing requires no citations and a search summary; other statuses require citations."""

    requirement_id: UUID
    status: FindingStatus
    justification: NonBlank
    confidence: Annotated[Number, Field(ge=0, le=1)]
    requires_human_review: Literal[True]
    search_summary: NonBlank | None
    citations: list[CitationDraft]

    @field_validator("requires_human_review", mode="before")
    @classmethod
    def human_review_is_mandatory(cls, value):
        if value is not True:
            raise ValueError("requires_human_review must be true")
        return value

    @model_validator(mode="after")
    def evidence_required(self) -> Self:
        if self.status == FindingStatus.MISSING:
            if self.citations or not self.search_summary:
                raise ValueError("missing requires a search summary and no citations")
        elif not self.citations:
            raise ValueError("non-missing findings require citations")
        return self


class PageDraft(ContractModel):
    page_number: PageNumber
    text: str
    ocr_used: Annotated[bool, Field(strict=True)]


class ChunkDraft(ContractModel):
    """Page-relative character offsets; end_offset must not precede start_offset."""

    page_number: PageNumber
    index: Offset
    text: NonBlank
    start_offset: Offset
    end_offset: Offset
    embedding: Annotated[list[Number], Field(min_length=384, max_length=384)]

    @model_validator(mode="after")
    def ordered_offsets(self) -> Self:
        if self.end_offset < self.start_offset:
            raise ValueError("end_offset must not precede start_offset")
        return self


class ProcessedDocumentDraft(ContractModel):
    document_id: UUID
    pages: list[PageDraft]
    chunks: list[ChunkDraft]


class AnalyzeResponse(ContractModel):
    analysis_id: UUID
    findings: list[FindingDraft]
    processed_documents: list[ProcessedDocumentDraft]


class ErrorResponse(ContractModel):
    detail: str
