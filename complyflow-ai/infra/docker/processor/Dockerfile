FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

COPY services/processor/pyproject.toml /tmp/processor/pyproject.toml
RUN pip install --no-cache-dir /tmp/processor

WORKDIR /workspace/services/processor
