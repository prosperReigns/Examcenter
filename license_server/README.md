# License Management Server

Production-ready FastAPI licensing server for a commercial CBT platform.

## Stack

- Python 3.12
- FastAPI
- Jinja2 Templates
- SQLAlchemy 2.0
- Alembic
- PostgreSQL
- JWT Authentication
- Passlib bcrypt
- python-dotenv
- Uvicorn
- Render deployment

## Environment Variables

- DATABASE_URL
- SECRET_KEY
- JWT_SECRET
- ACCESS_TOKEN_COOKIE_NAME
- ACCESS_TOKEN_COOKIE_SECURE
- ACCESS_TOKEN_COOKIE_SAMESITE
- ACCESS_TOKEN_COOKIE_MAX_AGE_SECONDS
- REMEMBER_ME_MAX_AGE_SECONDS
- BOOTSTRAP_ADMIN_FULL_NAME
- BOOTSTRAP_ADMIN_EMAIL
- BOOTSTRAP_ADMIN_PASSWORD
- BOOTSTRAP_ADMIN_ROLE
- FLUTTERWAVE_PUBLIC_KEY
- FLUTTERWAVE_SECRET_KEY
- FLUTTERWAVE_HASH
- FLUTTERWAVE_BASE_URL
- FLUTTERWAVE_WEBHOOK_SECRET_HEADER
- COMPANY_NAME
- SUPPORT_EMAIL
- DEMO_LICENSE_DURATION_DAYS
- MONTHLY_LICENSE_DURATION_DAYS
- QUARTERLY_LICENSE_DURATION_DAYS
- ANNUAL_LICENSE_DURATION_DAYS
- LIFETIME_LICENSE_DURATION_DAYS
- DEFAULT_LICENSE_ACTIVATION_LIMIT

## Run Locally

```bash
pip install -r requirements.txt
uvicorn app.main:app --reload
```

## Notes

- The private RSA key must remain on the licensing server only.
- The CBT client should ship with the public RSA key only.
- The first admin account can be bootstrapped from environment variables when the admins table is empty.
- Run Alembic migrations instead of relying on automatic table creation.
