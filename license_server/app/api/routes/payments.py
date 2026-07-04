from uuid import UUID

from fastapi import APIRouter, Depends, Request, status
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.payment import FlutterwaveWebhookPayload, PaymentInitializeRequest, PaymentInitializationResponse, PaymentRead, PaymentVerifyRequest
from app.services.payment_service import handle_flutterwave_webhook, initialize_payment, verify_payment
from app.repositories.payment_repository import get_payment_by_id, list_payments

router = APIRouter(prefix="/api/payments", tags=["payments"])


@router.get("", response_model=list[PaymentRead])
def list_payments_endpoint(db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin", "Staff"))):
    items, _ = list_payments(db)
    return items


@router.get("/{payment_id}", response_model=PaymentRead)
def get_payment_endpoint(payment_id: UUID, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin", "Staff"))):
    payment = get_payment_by_id(db, payment_id)
    if payment is None:
        from fastapi import HTTPException, status

        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Payment not found")
    return payment


@router.post("/initialize", response_model=PaymentInitializationResponse, status_code=status.HTTP_201_CREATED)
def initialize_payment_endpoint(
    payload: PaymentInitializeRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    return initialize_payment(db, payload, admin=admin, request=request)


@router.post("/verify", response_model=PaymentRead)
def verify_payment_endpoint(
    payload: PaymentVerifyRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    return verify_payment(db, payload, admin=admin, request=request)


@router.post("/webhooks/flutterwave")
def flutterwave_webhook_endpoint(payload: FlutterwaveWebhookPayload, request: Request, db: Session = Depends(get_db)):
    return handle_flutterwave_webhook(db, request, payload)
