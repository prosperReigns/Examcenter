from app.core.config import get_settings

settings = get_settings()

#
# Central pricing configuration.
#
# Every purchase in the system should obtain its
# pricing information from this file.
#
LICENSE_PRICES = {

    "trial": {

        "name": "7-Day Trial",

        "duration_months": 0,

        "duration_days": settings.trial_duration_days,

        "price": 0,

        "currency": settings.license_currency,

    },


    "6_months": {

        "name": "6 Months",

        "duration_months": 6,

        "duration_days": settings.six_month_duration_days,

        "price": settings.six_month_price,

        "currency": settings.license_currency,

    },


    "12_months": {

        "name": "12 Months",

        "duration_months": 12,

        "duration_days": settings.one_year_duration_days,

        "price": settings.one_year_price,

        "currency": settings.license_currency,

    },


    "24_months": {

        "name": "24 Months",

        "duration_months": 24,

        "duration_days": settings.two_year_duration_days,

        "price": settings.two_year_price,

        "currency": settings.license_currency,

    },

}


def get_plan(plan_code: str) -> dict:
    """
    Return a pricing definition for a plan.
    """

    try:

        return LICENSE_PRICES[
            plan_code.lower()
        ]

    except KeyError:

        raise ValueError(
            f"Unknown pricing plan: {plan_code}"
        )


def get_price(plan_code: str) -> int | float:
    """
    Return only the price.
    """

    return get_plan(
        plan_code
    )["price"]


def get_duration(plan_code: str) -> int:
    """
    Return duration in months.
    """

    return get_plan(
        plan_code
    )["duration_months"]

def list_plans():

    return list(
        LICENSE_PRICES.values()
    )

def get_currency(plan_code: str) -> str:
    """
    Return plan currency.
    """

    return get_plan(
        plan_code
    )["currency"]


def get_plan_name(plan_code: str) -> str:
    """
    Return display name.
    """

    return get_plan(
        plan_code
    )["name"]