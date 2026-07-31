from uuid import UUID

from fastapi import APIRouter, Depends, Request, status, Query,HTTPException
from sqlalchemy.orm import Session
from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.core.roles import Roles
from app.schemas.payment import PaymentRead 
from app.services.payment_service import handle_flutterwave_webhook, get_payment_record, get_payment_list
from app.repositories.payment_repository import get_payment_by_id, list_payments

router = APIRouter(prefix="/api/payments", tags=["payments"])


@router.get("", response_model=list[PaymentRead])
def list_payments_endpoint(page: int = Query(1, ge=1),
    page_size: int = Query(20, ge=1, le=100),
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(Roles.SUPER_ADMIN, Roles.STAFF)
    ),):
    items, _ = list_payments(
        db,
        page=page,
        page_size=page_size,
    )
    return items


@router.get("/{payment_id}", response_model=PaymentRead)
def get_payment_endpoint(payment_id: UUID, db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))):
    payment = get_payment_by_id(db, payment_id)
    if payment is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Payment not found")
    return payment

@router.post("/webhooks/flutterwave")
def flutterwave_webhook_endpoint(payload: Request, request: Request, db: Session = Depends(get_db)):
    return handle_flutterwave_webhook(db, request)

# @router.post("/{invoice_id}/initialize")
# def initialize_payment_endpoint(
#     invoice_id: UUID,
#     db: Session = Depends(get_db),
#     admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
# ):
#     return initialize_payment(
#         db,
#         invoice_id,
#     )

# @router.post("/verify/{transaction_id}")
# def verify_payment_endpoint(
#     transaction_id: str,
#     db: Session = Depends(get_db),
#     admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
# ):
#     return verify_payment(
#         db,
#         transaction_id,
#     )

# @router.post("/{transaction_id}/refund")
# def refund_payment_endpoint(
#     transaction_id: str,
#     db: Session = Depends(get_db),
#     admin=Depends(require_roles(Roles.SUPER_ADMIN)),
# ):
#     return refund_payment(
#         db,
#         transaction_id,
#     )