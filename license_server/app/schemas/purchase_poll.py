from pydantic import BaseModel


class PurchasePollResponse(BaseModel):

    status: str

    progress: int

    message: str

    download_ready: bool

    poll_after: int

    server_time: str