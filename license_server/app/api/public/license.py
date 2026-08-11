from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from app.database.session import get_db
from app.repositories.activation_token_repository import (
    get_valid_download_token
)

from app.services.activation_token_service import (
    validate_token, consume_token
)


router = APIRouter(
    prefix="/api/public",
    tags=["Public License"]
)



@router.get("/license/{token}")
def get_license(
    token: str,
    db: Session = Depends(get_db)
):
    print("LICENSE ENDPOINT HIT: token={token[:12]}...")

    activation_token = get_valid_download_token(
        db,
        token
    )

    print(
        f"LICENSE TOKEN FOUND: "
        f"{activation_token is not None}"
    )

    validate_token(
        activation_token
    )


    license_obj = activation_token.license

    license_data = license_obj.signed_license

    consume_token(db, activation_token)

    db.commit()

    print(
        f"LICENSE DELIVERED: "
        f"license_id={license_obj.id}"
    )
    return {

        "success": True,

        "license":
            license_data

    }