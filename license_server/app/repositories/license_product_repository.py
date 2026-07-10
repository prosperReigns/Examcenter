from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.license_product import LicenseProduct


def create_product(
    db: Session,
    product: LicenseProduct,
) -> LicenseProduct:

    db.add(product)
    db.flush()

    return product


def get_product(
    db: Session,
    product_id: UUID,
) -> LicenseProduct | None:

    return db.get(LicenseProduct, product_id)


def get_product_by_name(
    db: Session,
    name: str,
) -> LicenseProduct | None:

    statement = (
        select(LicenseProduct)
        .where(LicenseProduct.name == name)
    )

    return db.scalar(statement)


def list_products(
    db: Session,
    *,
    offset: int = 0,
    limit: int = 20,
) -> tuple[list[LicenseProduct], int]:

    statement = (
        select(LicenseProduct)
        .offset(offset)
        .limit(limit)
        .order_by(LicenseProduct.created_at.desc())
    )

    total = db.scalar(
        select(func.count()).select_from(LicenseProduct)
    ) or 0

    items = db.scalars(statement).all()

    return items, total


def active_products(
    db: Session,
) -> list[LicenseProduct]:

    statement = (
        select(LicenseProduct)
        .where(LicenseProduct.is_active.is_(True))
        .order_by(LicenseProduct.name.asc())
    )

    return db.scalars(statement).all()


def update_product(
    db: Session,
    product: LicenseProduct,
) -> LicenseProduct:

    db.add(product)
    db.flush()

    return product


def delete_product(
    db: Session,
    product: LicenseProduct,
):

    db.delete(product)
    db.flush()