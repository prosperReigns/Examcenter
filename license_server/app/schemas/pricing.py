from pydantic import BaseModel


class LicensePlanRead(BaseModel):

    code: str

    name: str

    duration_months: int

    price: int

    currency: str

    trial: bool


class PricingResponse(BaseModel):

    plans: list[LicensePlanRead]