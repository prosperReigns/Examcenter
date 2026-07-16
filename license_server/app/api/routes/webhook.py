from fastapi import APIRouter, Depends, Request

from app.database.session import get_db
from app.schemas.payment import FlutterwaveWebhookPayload
from app.services.payment_service import handle_flutterwave_webhook

router = APIRouter(
    prefix="/api/webhooks",
    tags=["Webhooks"],
)

@router.post("/flutterwave")
async def flutterwave_webhook(
    payload: FlutterwaveWebhookPayload,
    request: Request,
    db=Depends(get_db),
):
    return await handle_flutterwave_webhook(
        db,
        request,
        payload,
    )
