from app.core.pricing import list_plans
from app.schemas.pricing import (
    LicensePlanRead,
    PricingResponse,
)


def get_public_pricing() -> PricingResponse:

    return PricingResponse(

        plans=[

            LicensePlanRead(

                code=plan.code,

                name=plan.name,

                duration_months=plan.duration_months,

                price=plan.price,

                currency=plan.currency,

                trial=plan.trial,

            )

            for plan in list_plans()

        ]

    )