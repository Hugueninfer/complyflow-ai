import pytest

from app.providers.base import ProviderError
from app.providers.failover import FailoverProvider
from app.providers.fake import FakeAIProvider
from test_fake_provider import context, requirement


class StubProvider:
    def __init__(self, outcome):
        self.outcome = outcome
        self.calls = 0

    def analyze(self, requirement, contexts, *, budget=None):
        self.calls += 1
        if isinstance(self.outcome, Exception):
            raise self.outcome
        return self.outcome


def finding():
    return FakeAIProvider().analyze(requirement(), [context()])


def test_failover_returns_primary_result_without_calling_fallback():
    primary = StubProvider(finding())
    fallback = StubProvider(ProviderError('provider_unavailable'))

    result = FailoverProvider([primary, fallback]).analyze(requirement(), [context()])

    assert result == finding()
    assert primary.calls == 1
    assert fallback.calls == 0


def test_failover_uses_next_provider_after_sanitized_provider_failure():
    primary = StubProvider(ProviderError('provider_unavailable'))
    fallback = StubProvider(finding())

    result = FailoverProvider([primary, fallback]).analyze(requirement(), [context()])

    assert result == finding()
    assert primary.calls == 1
    assert fallback.calls == 1


def test_failover_raises_last_sanitized_failure_when_all_providers_fail():
    first = StubProvider(ProviderError('provider_unavailable'))
    second = StubProvider(ProviderError('invalid_provider_response'))

    with pytest.raises(ProviderError, match='^invalid_provider_response$'):
        FailoverProvider([first, second]).analyze(requirement(), [context()])


def test_failover_does_not_hide_programming_errors():
    primary = StubProvider(RuntimeError('bug'))
    fallback = StubProvider(finding())

    with pytest.raises(RuntimeError, match='^bug$'):
        FailoverProvider([primary, fallback]).analyze(requirement(), [context()])

    assert fallback.calls == 0
