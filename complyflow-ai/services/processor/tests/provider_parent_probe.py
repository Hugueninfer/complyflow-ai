"""Disposable server-process stand-in for the abrupt-parent-death regression."""

from pathlib import Path
import sys

sys.path.insert(0, str(Path.cwd()))

from app.execution import ExecutionBudget
from app.providers import process_isolation
from app.providers.openai_compatible import OpenAICompatibleProvider
from app.schemas import RequirementDraft

process_isolation._child_command = lambda: [
    sys.executable, str(Path(__file__).with_name('provider_child_probe.py')), 'dns_only', sys.argv[1],
]
requirement = RequirementDraft.model_validate({
    'requirement_id': '20000000-0000-4000-8000-000000000001', 'criterion': 'Offline',
    'category': 'Demo', 'weight': 1.0, 'evaluation_text': 'Offline only.',
})
OpenAICompatibleProvider(base_url='https://offline.invalid', api_key='test-only', model='demo').analyze(
    requirement, [], budget=ExecutionBudget(30),
)
