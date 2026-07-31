from app.core.config import get_settings

settings = get_settings()

#
# Central pricing configuration.
#
# Every purchase in the system should obtain its
# pricing information from this file.
#

LICENSE_PRICES = {

    #
    # Free demonstration license
    #
    "demo": {
        "name": "Demo",
        "duration_months": 0,
        "price": 0,
        "currency": settings.license_currency,
    },

    #
    # Six-month subscription
    #
    "6months": {
        "name": "6 Months",
        "duration_months": 6,
        "price": settings.six_month_price,
        "currency": settings.license_currency,
    },

    #
    # One-year subscription
    #
    "1year": {
        "name": "1 Year",
        "duration_months": 12,
        "price": settings.one_year_price,
        "currency": settings.license_currency,
    },

    #
    # Two-year subscription
    #
    "2years": {
        "name": "2 Years",
        "duration_months": 24,
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