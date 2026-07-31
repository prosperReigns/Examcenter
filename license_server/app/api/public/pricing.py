from fastapi import APIRouter

from app.schemas.pricing import PricingResponse
from app.services.pricing_service import get_public_pricing

router = APIRouter(

    prefix="/api/public/pricing",

    tags=["Public Pricing"],

)


@router.get(

    "",

    response_model=PricingResponse,

)

def pricing():

    return get_public_pricing()