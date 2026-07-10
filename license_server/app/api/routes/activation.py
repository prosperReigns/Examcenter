from fastapi import APIRouter, Query
from fastapi.responses import RedirectResponse
from urllib.parse import urlencode

router = APIRouter(
    prefix="/activate",
    tags=["Public Activation"],
)


@router.get("", summary="Redirect desktop client to activation UI")
def activation_page(
    fingerprint: str = Query(..., min_length=20, max_length=255),

    product: str = Query(..., min_length=1, max_length=100),

    version: str = Query(..., min_length=1, max_length=50),

    plan: str = Query(default="trial", max_length=30),
):
    """
    Entry point from the installed CBT application.

    Example:

    https://license.examcenter.com/activate?
        fingerprint=xxxx
        &product=CBT
        &version=1.0
        &plan=12
    """

    params = urlencode({
        "fingerprint": fingerprint,
        "product": product,
        "version": version,
        "plan": plan,
    })

    return RedirectResponse(
        url=f"/activation?{params}"
    )