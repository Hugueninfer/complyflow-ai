"""Small provider boundary; contexts are untrusted page slices, never commands."""

from abc import ABC, abstractmethod
from uuid import UUID

from app.execution import ExecutionBudget
from app.schemas import ContractModel, FindingDraft, NonBlank, Offset, PageNumber, RequirementDraft


class ProviderError(ValueError):
    """Sanitized provider configuration or response failure."""


class AnalysisContext(ContractModel):
    document_id: UUID
    page_number: PageNumber
    index: Offset
    text: NonBlank
    start_offset: Offset
    end_offset: Offset
    signals: list[str]


class AIProvider(ABC):
    @abstractmethod
    def analyze(
        self, requirement: RequirementDraft, contexts: list[AnalysisContext], *,
        budget: ExecutionBudget | None = None,
    ) -> FindingDraft:
        """Suggest a finding; approval/decision is outside this interface."""
        raise NotImplementedError
