from typing import Annotated

from fastapi import APIRouter, Header, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.routing import APIRoute
from starlette.responses import JSONResponse

from app.schemas import AnalyzeRequest, AnalyzeResponse, ErrorResponse
from app.security.hmac_auth import verify_signed_request


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
        "No browser access. Analysis execution is unavailable until the pipeline is installed."
    ),
    responses={
        401: {"model": ErrorResponse, "description": "Missing/invalid authentication or replay"},
        422: {"model": ErrorResponse, "description": "Invalid JSON or closed request schema"},
        503: {"model": ErrorResponse, "description": "Authentication or analysis unavailable"},
    },
)
async def analyze(
    body: AnalyzeRequest,
    timestamp: Annotated[str, Header(alias="X-CF-Timestamp", pattern=r"^[0-9]{1,12}$")],
    nonce: Annotated[str, Header(alias="X-CF-Nonce", pattern=r"^[A-Za-z0-9_-]{1,128}$")],
    signature: Annotated[str, Header(alias="X-CF-Signature", pattern=r"^[0-9a-f]{64}$")],
) -> AnalyzeResponse:
    # Task 8 replaces this explicit seam with AnalysisPipeline.run(body).
    raise HTTPException(503, detail="analysis_not_available")
