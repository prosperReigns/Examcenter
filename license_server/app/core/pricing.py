from app.core.config import get_settings


settings = get_settings()

LICENSE_PRICES = {

    "demo": 0,

    "monthly": settings.monthly_price,

    "quarterly": settings.quarterly_price,

    "annual": settings.annual_price,

    "lifetime": settings.lifetime_price,

}