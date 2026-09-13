from typing import Annotated

from fastapi import APIRouter, Header, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.routing import APIRoute
from starlette.responses import JSONResponse

from app.pipeline.analyze import AnalysisError, AnalysisPipeline
from app.pdf.extractor import PdfExtractionError
from app.providers.base import ProviderError
from app.schemas import AnalyzeRequest, AnalyzeResponse, ErrorResponse
from app.security.hmac_auth import verify_signed_request


PUBLIC_ANALYSIS_ERRORS = frozenset({
    'invalid_document', 'document_hash_mismatch', 'duplicate_identifiers',
    'analysis_limit_exceeded', 'invalid_pdf', 'encrypted_pdf', 'pdf_limit_exceeded',
    'ocr_unavailable', 'ocr_failed', 'provider_not_configured', 'provider_unavailable',
    'invalid_provider_response', 'invalid_finding', 'invalid_citation',
})


class SignedRoute(APIRoute):
    def get_route_handler(self):
        handler = super().get_route_handler()

        async def authenticated_handler(request: Request):
            # APIRoute authenticates before FastAPI parses JSON/Pydantic. Reading
            # Request.body() caches the original bytes for the regular handler.
            await verify_signed_request(request)
            try:
                return await handler(request)
            except RequestValidationError:
                # FastAPI's default errors can echo input document content.
                return JSONResponse(status_code=422, content={"detail": "invalid_request"})

        return authenticated_handler


router = APIRouter(route_class=SignedRoute)


@router.post(
    "/v1/analyze",
    response_model=AnalyzeResponse,
    operation_id="analyze",
    description=(
        "Internal Laravel-to-processor endpoint. Sign the exact request body bytes: "
        "HMAC-SHA256(secret, timestamp + LF + nonce + LF + lowercase_hex(SHA256(body))). "
        "Send the lowercase hex signature; no trailing LF in the signed message. "
        "Timestamp is Unix seconds with an inclusive 60-second clock window. "
        "Use a fresh nonce for every attempt, retained for 120 seconds. "
        "No browser access. Extracts PDFs and returns validated findings, pages and 384-dimensional chunks."
    ),
    responses={
        401: {"model": ErrorResponse, "description": "Missing/invalid authentication or replay"},
        422: {"model": ErrorResponse, "description": "Invalid request, PDF, document hash or analysis limit"},
        503: {"model": ErrorResponse, "description": "Authentication, provider or analysis unavailable"},
    },
)
def analyze(
    body: AnalyzeRequest,
    timestamp: Annotated[str, Header(alias="X-CF-Timestamp", pattern=r"^[0-9]{1,12}$")],
    nonce: Annotated[str, Header(alias="X-CF-Nonce", pattern=r"^[A-Za-z0-9_-]{1,128}$")],
    signature: Annotated[str, Header(alias="X-CF-Signature", pattern=r"^[0-9a-f]{64}$")],
) -> AnalyzeResponse:
    try:
        return AnalysisPipeline.from_settings().run(body)
    except (AnalysisError, PdfExtractionError) as error:
        code = str(error) if str(error) in PUBLIC_ANALYSIS_ERRORS else 'analysis_failed'
        raise HTTPException(422, detail=code) from None
    except ProviderError as error:
        code = str(error) if str(error) in PUBLIC_ANALYSIS_ERRORS else 'analysis_failed'
        raise HTTPException(503, detail=code) from None
    except Exception:
        # Do not expose provider/parser exceptions that may carry document data.
        raise HTTPException(503, detail='analysis_failed') from None
