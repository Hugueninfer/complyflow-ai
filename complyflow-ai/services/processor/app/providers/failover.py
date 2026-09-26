"""Ordered real-provider fallback; demo fixtures are never used implicitly."""

from collections.abc import Callable, Sequence
from typing import TypeVar

from app.execution import ExecutionBudget
from app.providers.base import AIProvider, AnalysisContext, ProviderError
from app.schemas import FindingDraft, RequirementDraft


T = TypeVar('T')


class FailoverProvider(AIProvider):
    def __init__(self, providers: Sequence[AIProvider]):
        if len(providers) < 2:
            raise ValueError('failover requires at least two providers')
        self.providers = tuple(providers)

    def analyze(
        self, requirement: RequirementDraft, contexts: list[AnalysisContext], *,
        budget: ExecutionBudget | None = None,
    ) -> FindingDraft:
        return self.attempt(
            lambda provider: provider.analyze(requirement, contexts, budget=budget), budget=budget,
        )

    def attempt(self, operation: Callable[[AIProvider], T], *, budget: ExecutionBudget | None = None) -> T:
        failure: ProviderError | None = None
        for provider in self.providers:
            if budget:
                budget.checkpoint()
            try:
                return operation(provider)
            except ProviderError as error:
                failure = error
        assert failure is not None
        raise failure
