from functools import lru_cache

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
    secret_key: str = Field(default="change-me-please-secret", min_length=16)
    jwt_secret: str = Field(default="change-me-please-jwt-secret", min_length=16)
    jwt_algorithm: str = "HS256"
    access_token_expire_minutes: int = 60
    access_token_cookie_name: str = "access_token"
    access_token_cookie_secure: bool = False
    access_token_cookie_samesite: str = "lax"
    access_token_cookie_max_age_seconds: int = 3600
    remember_me_max_age_seconds: int = 2592000
    database_url: str = "postgresql+psycopg://postgres:postgres@localhost:5432/license_server"
    flutterwave_public_key: str = ""
    flutterwave_secret_key: str = ""
    flutterwave_hash: str = ""
    flutterwave_base_url: str = "https://api.flutterwave.com/v3"
    flutterwave_webhook_secret_header: str = "verif-hash"
    company_name: str = "Your Company"
    support_email: str = "support@example.com"
    license_currency: str = "USD"
    monthly_price: float = 0.0
    quarterly_price: float = 0.0
    annual_price: float = 0.0
    lifetime_price: float = 0.0
    demo_license_duration_days: int = 7
    monthly_license_duration_days: int = 30
    quarterly_license_duration_days: int = 90
    annual_license_duration_days: int = 365
    lifetime_license_duration_days: int = 0
    default_license_activation_limit: int = 1
    bootstrap_admin_full_name: str = ""
    bootstrap_admin_email: str = ""
    bootstrap_admin_password: str = ""
    bootstrap_admin_role: str = "Super Admin"


@lru_cache
def get_settings() -> Settings:
    return Settings()
