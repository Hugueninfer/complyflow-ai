FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

COPY services/processor/requirements*.lock /tmp/processor/
RUN pip install --no-cache-dir -r /tmp/processor/requirements-test.lock

WORKDIR /workspace/services/processor
