from fastapi import (
    APIRouter,
    Depends
)

from sqlalchemy.orm import Session

from app.database import get_db

from app.models.security_event import (
    SecurityEvent
)

from app.schemas.security_event import (
    SecurityEventRequest
)
from app.middleware.request_validator import validate_signed_request

router = APIRouter()



@router.post(
    "/security/event",
    dependencies=[
        Depends(validate_signed_request)]
)
async def receive_event(

    data: SecurityEventRequest,

    db: Session = Depends(get_db)

):


    event = SecurityEvent(

        event=data.event,

        message=data.message,

        installation_id=
            data.installation_id,

        fingerprint=
            data.fingerprint,

        context=
            data.context

    )


    db.add(event)

    db.commit()


    return {

        "status":"received"

    }