import time
import hmac
import hashlib
import json

from fastapi import Request, HTTPException
from app.database import get_db

from app.services.replay_protection import (
    check_nonce,
    cleanup_old_nonces
)

API_SECRET = "CHANGE_THIS_TO_A_RANDOM_SECRET"


MAX_REQUEST_AGE = 300



async def validate_signed_request(
    request: Request,
    db
):


    timestamp = request.headers.get(
        "X-Request-Time"
    )


    nonce = request.headers.get(
        "X-Request-Nonce"
    )


    signature = request.headers.get(
        "X-Request-Signature"
    )



    if not timestamp or not nonce or not signature:

        raise HTTPException(

            status_code=401,

            detail="Missing request signature"

        )



    timestamp = int(timestamp)



    current_time = int(
        time.time()
    )



    if abs(
        current_time - timestamp
    ) > MAX_REQUEST_AGE:


        raise HTTPException(

            status_code=401,

            detail="Expired request"

        )

    cleanup_old_nonces(
        db
    )


    if not check_nonce(
        db,
        nonce
    ):

        raise HTTPException(

            status_code=401,

            detail="Replay attack detected"

        )


    body = await request.body()



    data = "|".join([

        body.decode(),

        str(timestamp),

        nonce

    ])



    expected = hmac.new(

        API_SECRET.encode(),

        data.encode(),

        hashlib.sha256

    ).hexdigest()



    if not hmac.compare_digest(

        expected,

        signature

    ):


        raise HTTPException(

            status_code=401,

            detail="Invalid request signature"

        )



    return True