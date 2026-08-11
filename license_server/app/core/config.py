from functools import lru_cache

from app.core.roles import Roles
from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    app_name: str = "License Management Server"
    debug: bool = False
    secret_key: str
    jwt_secret: str 
    jwt_algorithm: str
    license_server_url: str = "https://5138-197-211-53-98.ngrok-free.app"
    access_token_expire_minutes: int = 60
    access_token_cookie_name: str
    access_token_cookie_secure: bool
    access_token_cookie_samesite: str
    access_token_cookie_max_age_seconds: int
    remember_me_max_age_seconds: int
    database_url: str
    flutterwave_public_key: str
    flutterwave_secret_key: str 
    flutterwave_hash: str
    flutterwave_base_url: str
    flutterwave_webhook_secret_header: str
    paystack_public_key: str 
    paystack_secret_key: str 
    paystack_base_url: str
    paystack_webhook_secret: str
    payment_gateway: str
    payment_callback_url: str
    company_name: str
    support_email: str
    license_currency: str
    six_month_price: float
    one_year_price: float
    two_year_price: float
    demo_license_duration_days: int = 7
    trial_duration_days: int = 7
    six_month_duration_days: int = 180
    one_year_duration_days: int = 365
    two_year_duration_days: int = 730
    default_license_activation_limit: int
    bootstrap_admin_full_name: str 
    bootstrap_admin_email: str 
    bootstrap_admin_password: str 
    bootstrap_admin_role: str = Roles.SUPER_ADMIN

    

@lru_cache
def get_settings() -> Settings:
    return Settings()

def validate_production_settings(settings: Settings) -> None:
        required = {
            "flutterwave_secret_key": settings.flutterwave_secret_key,
            "flutterwave_hash": settings.flutterwave_hash,
            "payment_gateway": settings.payment_gateway,
            "payment_callback_url": settings.payment_callback_url,
        }

        missing = [key for key, value in required.items() if not value]

        if missing:
            raise RuntimeError(
                f"Missing required production settings: {', '.join(missing)}"
            )

settings = get_settings()
validate_production_settings(settings)