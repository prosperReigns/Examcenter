from datetime import datetime, timezone
from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.activation import Activation
from app.models.license_device import LicenseDevice


def get_activation_by_id(db: Session, activation_id: UUID) -> Activation | None:
    return db.get(Activation, activation_id)


def list_activations(db: Session, *, offset: int = 0, limit: int = 20) -> tuple[list[Activation], int]:
    statement = select(Activation)
    count_statement = select(func.count()).select_from(Activation)
    total = db.scalar(count_statement) or 0
    items = db.scalars(statement.order_by(Activation.activated_at.desc()).offset(offset).limit(limit)).all()
    return items, total


def count_active_activations(db: Session, license_id: UUID) -> int:
    statement = select(func.count()).select_from(Activation).where(Activation.license_id == license_id, Activation.status == "active")
    return db.scalar(statement) or 0


def get_activation_for_machine(db: Session, license_id: UUID, machine_id: str) -> Activation | None:
    statement = select(Activation).where(
        Activation.license_id == license_id,
        Activation.machine_id == machine_id,
        Activation.status == "active",
    )
    return db.scalar(statement)


def create_activation_record(db: Session, *, license_id: UUID, school_id: UUID, machine_id: str, computer_name: str | None, ip_address: str | None) -> Activation:
    activation = Activation(
        license_id=license_id,
        school_id=school_id,
        machine_id=machine_id,
        computer_name=computer_name,
        ip_address=ip_address,
        status="active",
    )
    db.add(activation)
    db.flush()
    return activation


def upsert_license_device(db: Session, *, license_id: UUID, machine_id: str, computer_name: str | None, ip_address: str | None) -> LicenseDevice:
    statement = select(LicenseDevice).where(LicenseDevice.license_id == license_id, LicenseDevice.machine_id == machine_id)
    device = db.scalar(statement)
    if device is None:
        device = LicenseDevice(
            license_id=license_id,
            machine_id=machine_id,
            computer_name=computer_name,
            ip_address=ip_address,
            activation_count=1,
            status="active",
        )
        db.add(device)
    else:
        device.activation_count += 1
        device.computer_name = computer_name or device.computer_name
        device.ip_address = ip_address or device.ip_address
        device.status = "active"
        db.add(device)
    db.flush()
    return device


def deactivate_activation(db: Session, activation: Activation) -> Activation:
    activation.status = "inactive"
    activation.deactivated_at = datetime.now(timezone.utc)
    db.add(activation)
    db.flush()
    return activation
