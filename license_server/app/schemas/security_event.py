from pydantic import BaseModel

from typing import Optional, Dict



class SecurityEventRequest(BaseModel):

    license_key: str

    event: str

    message: str

    installation_id: str

    fingerprint: str

    context: Optional[Dict] = None