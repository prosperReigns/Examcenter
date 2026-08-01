from uuid import uuid4

from fastapi import APIRouter
from fastapi import Depends
from fastapi import Header
from fastapi import Response

from sqlalchemy.orm import Session

from app.database.session import get_db

from app.services.purchase_poll_service import (
    PurchasePollService,
)
from app.schemas.purchase_poll import (
    PurchasePollResponse,
)


router = APIRouter(

    prefix="/api/public/purchase",

    tags=["Public Purchase"],

)


@router.get(
    "/{poll_token}",
    response_model=PurchasePollResponse,
)
def poll_purchase_status(
    poll_token: str,
    response: Response,
    if_none_match: str | None = Header(default=None),
    db: Session = Depends(get_db),
):
    """
    Public endpoint used by:

    - Desktop application
    - Browser success page

    Returns only the current purchase progress.
    """

    service = PurchasePollService(db)

    result = service.get_status(
    poll_token
)

    if if_none_match == result["etag"]:

        response.status_code = 304

        return

    response.headers["ETag"] = result["etag"]

    response.headers["Cache-Control"] = "no-cache"

    response.headers["Last-Modified"] = (
        result["last_modified"]
        .strftime(
            "%a, %d %b %Y %H:%M:%S GMT"
        )
    )

    #
    # Remove internal values before returning JSON
    #

    result.pop("etag", None)
    result.pop("last_modified", None)

    return result

@router.post(
    "/start"
)
def start_purchase(data: dict):
    purchase_token = str(uuid4())

    return {

        "status":"created",

        "purchase_token":
            purchase_token,

        "checkout_url":
            f"https://license.seedofabraham.com/checkout/{purchase_token}"

    }