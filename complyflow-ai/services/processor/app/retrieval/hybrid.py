"""Deterministic lexical + cosine retrieval with local feature-hash embeddings.

The 384-dimensional vectors share a stable coordinate space across documents and
queries. They require neither model downloads nor an external embedding service.
This lightweight demo representation does not claim learned semantic accuracy.
"""

import hashlib
import math
import re
import unicodedata

from app.providers.base import AnalysisContext


def _tokens(text: str) -> list[str]:
    normalized = unicodedata.normalize('NFKD', text.casefold())
    return re.findall(r'\w+', ''.join(char for char in normalized if not unicodedata.combining(char)))


def embed_text(text: str) -> list[float]:
    vector = [0.0] * 384
    for token in _tokens(text):
        digest = hashlib.sha256(token.encode()).digest()
        vector[int.from_bytes(digest[:4], 'big') % 384] += 1.0 if digest[4] & 1 else -1.0
    norm = math.sqrt(sum(value * value for value in vector))
    return [value / norm for value in vector] if norm else vector


class HybridRetriever:
    def __init__(self, contexts: list[AnalysisContext], embeddings: list[list[float]]):
        if len(contexts) != len(embeddings) or any(
            len(vector) != 384 or any(
                isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value)
                for value in vector
            ) for vector in embeddings
        ):
            raise ValueError('invalid_embedding')
        self.entries = []
        for context, vector in zip(contexts, embeddings, strict=True):
            norm = math.sqrt(sum(value * value for value in vector))
            normalized = [value / norm for value in vector] if norm else vector
            self.entries.append((context, set(_tokens(context.text)), normalized))

    def search(self, query: str, limit: int = 5) -> list[AnalysisContext]:
        if limit < 1:
            raise ValueError('invalid_retrieval_limit')
        terms, vector = set(_tokens(query)), embed_text(query)
        ranked = []
        for context, tokens, embedding in self.entries:
            lexical = len(terms & tokens) / len(terms) if terms else 0.0
            cosine = max(0.0, sum(left * right for left, right in zip(vector, embedding, strict=True)))
            if lexical == 0 and cosine < 0.15:
                continue
            score = 0.6 * lexical + 0.4 * cosine
            ranked.append((-score, str(context.document_id), context.page_number, context.index, context))
        ranked.sort(key=lambda item: item[:4])
        return [item[-1] for item in ranked[:limit]]
