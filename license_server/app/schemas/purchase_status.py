from datetime import datetime
from pydantic import BaseModel


class PurchaseStatusResponse(BaseModel):

    purchase_number: str

    status: str

    payment_status: str

    activation_token: str | None = None

    download_url: str | None = None

    expires_at: datetime | None = None

    message: str | None = None