from fastapi import APIRouter
from app.database.session import engine
from sqlalchemy import text


router = APIRouter(
    prefix="/health", 
    tags=["Web - Health"],
)


@router.get("/")
def health_check() -> dict[str, str]:
    with engine.connect() as connection:
        connection.execute(text("SELECT 1"))
    return {"status": "ok"}

