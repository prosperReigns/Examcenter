from datetime import datetime, timedelta
from fastapi import (
    APIRouter,
    Depends,
    HTTPException,
    Query,
    Request,
)
from fastapi.responses import Response

# rate limiting
try:
    from slowapi import Limiter
    from slowapi.util import get_remote_address
    limiter = Limiter(key_func=get_remote_address)
except Exception:
    # Fallback no-op limiter if slowapi is not available
    class _NoopLimiter:
        def limit(self, *args, **kwargs):
            def _decorator(func):
                return func
            return _decorator

    limiter = _NoopLimiter()

from sqlalchemy.orm import Session
from app.database.session import get_db

from app.services.license_download_service import validate_download_token


router = APIRouter(

    prefix="/api/public/",

    tags=["Public License"],

)

@limiter.limit(
    "5/minute"
)


@router.get(
    "/license/download/{token}"
)
def download_license(

    token:str,

    db:Session = Depends(get_db)

):


    download = (

        validate_download_token(

            db,

            token

        )

    )


    if not download:

        raise HTTPException(

            status_code=403,

            detail=
            "Invalid or expired token"

        )


    license = download.license



    license_file = generate_license_file(
        license
    )



    download.downloaded = True

    download.downloaded_at = (
        datetime.utcnow()
    )


    db.commit()



    return Response(

        content=license_file,

        media_type=
        "application/octet-stream",

        headers={

        "Content-Disposition":
        "attachment; filename=license.lic"

        }

    )