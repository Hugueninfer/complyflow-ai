from time import monotonic
from typing import Annotated

import anyio
from fastapi import APIRouter, Header, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.routing import APIRoute
from starlette.responses import JSONResponse, Response

from app.execution import ActiveAnalyses, ExecutionBudget, ExecutionStopped
from app.pdf.extractor import PdfExtractionError
from app.pipeline.analyze import AnalysisError, AnalysisPipeline
from app.providers.base import ProviderError
from app.schemas import AnalyzeRequest, AnalyzeResponse, ErrorResponse
from app.security.hmac_auth import verify_signed_request


PUBLIC_ANALYSIS_ERRORS = frozenset({
    'invalid_document', 'document_hash_mismatch', 'duplicate_identifiers',
    'analysis_limit_exceeded', 'invalid_pdf', 'encrypted_pdf', 'pdf_limit_exceeded',
    'ocr_unavailable', 'ocr_failed', 'provider_not_configured', 'provider_unavailable',
    'invalid_provider_response', 'invalid_provider_request', 'invalid_finding', 'invalid_citation',
})
PUBLIC_EXECUTION_ERRORS = frozenset({
    'analysis_budget_exceeded', 'analysis_cancelled', 'analysis_in_progress',
    'analysis_capacity_exceeded', 'analysis_not_configured',
})
active_analyses = ActiveAnalyses()


async def _watch_disconnect(request: Request, budget: ExecutionBudget, scope) -> None:
    # Authentication has already consumed/cached the entire body. This receive
    # now watches only the transport lifecycle, without racing JSON parsing.
    while True:
        if (await request.receive())['type'] == 'http.disconnect':
            budget.cancel()
            scope.cancel()
            return


class SignedRoute(APIRoute):
    def get_route_handler(self):
        handler = super().get_route_handler()

        async def authenticated_handler(request: Request):
            # APIRoute authenticates before FastAPI parses JSON/Pydantic. Reading
            # Request.body() caches the original bytes for the regular handler.
            budget = None
            try:
                budget = ExecutionBudget.from_settings(clock=monotonic)
                request.state.analysis_budget = budget
                failure = None
                response = None
                with anyio.move_on_after(budget.remaining()) as scope:
                    await verify_signed_request(request)
                    async with anyio.create_task_group() as watchers:
                        watchers.start_soon(_watch_disconnect, request, budget, scope)
                        try:
                            response = await handler(request)
                        except Exception as error:
                            failure = error
                        finally:
                            watchers.cancel_scope.cancel()
                budget.checkpoint()
                if failure is not None:
                    raise failure
                return response
            except ExecutionStopped as error:
                code = str(error) if str(error) in PUBLIC_EXECUTION_ERRORS else 'analysis_failed'
                return JSONResponse(status_code=503, content={'detail': code})
            except RequestValidationError:
                # FastAPI's default errors can echo input document content.
                return JSONResponse(status_code=422, content={"detail": "invalid_request"})
            finally:
                # Also runs on server/task cancellation. The thread owns its
                # lease and observes this token even after the HTTP task exits.
                if budget:
                    budget.cancel()

        return authenticated_handler


router = APIRouter(route_class=SignedRoute)


def _execute_analysis(body: AnalyzeRequest, budget: ExecutionBudget) -> Response:
    with active_analyses.lease(body.analysis_id, body.idempotency_key):
        budget.checkpoint()
        result = AnalysisPipeline.from_settings().run(body, budget=budget)
        # Serialize on the same worker, under the same deadline and lease.
        response = Response(content=result.model_dump_json(), media_type='application/json')
        budget.checkpoint()
        return response


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
async def analyze(
    request: Request,
    body: AnalyzeRequest,
    timestamp: Annotated[str, Header(alias="X-CF-Timestamp", pattern=r"^[0-9]{1,12}$")],
    nonce: Annotated[str, Header(alias="X-CF-Nonce", pattern=r"^[A-Za-z0-9_-]{1,128}$")],
    signature: Annotated[str, Header(alias="X-CF-Signature", pattern=r"^[0-9a-f]{64}$")],
) -> Response:
    try:
        return await anyio.to_thread.run_sync(
            _execute_analysis, body, request.state.analysis_budget, abandon_on_cancel=True,
        )
    except ExecutionStopped:
        raise
    except (AnalysisError, PdfExtractionError) as error:
        code = str(error) if str(error) in PUBLIC_ANALYSIS_ERRORS else 'analysis_failed'
        raise HTTPException(422, detail=code) from None
    except ProviderError as error:
        code = str(error) if str(error) in PUBLIC_ANALYSIS_ERRORS else 'analysis_failed'
        raise HTTPException(503, detail=code) from None
    except Exception:
        # Do not expose provider/parser exceptions that may carry document data.
        raise HTTPException(503, detail='analysis_failed') from None
