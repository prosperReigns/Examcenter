from datetime import datetime, timezone
from uuid import UUID


from sqlalchemy import func, select
from sqlalchemy.orm import Session, joinedload

from app.models.activation import Activation
from app.models.license_device import LicenseDevice


def get_activation_by_id(db: Session, activation_id: UUID) -> Activation | None:
    return db.get(Activation, activation_id)


def list_activations(
    db: Session,
    *,
    offset: int = 0,
    limit: int = 20,
) -> tuple[list[Activation], int]:

    statement = (
        select(Activation)
        .options(
            joinedload(Activation.school),
            joinedload(Activation.license)
        )
        .order_by(Activation.activated_at.desc())
        .offset(offset)
        .limit(limit)
    )

    count_statement = (
        select(func.count())
        .select_from(Activation)
    )

    total = db.scalar(count_statement) or 0
    items = db.scalars(statement).all()
    return items, total

def get_activation_details(
    db: Session,
    activation_id: UUID,
) -> Activation | None:

    statement = (
        select(Activation)
        .options(

            joinedload(Activation.school),

            joinedload(Activation.license)

        )
        .where(
            Activation.id == activation_id
        )
    )

    return db.scalar(statement)

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


def create_activation_record(db: Session, *, license_id: UUID, device_id: UUID, school_id: UUID, machine_id: str, computer_name: str | None, ip_address: str | None) -> Activation:
    activation = Activation(
        license_id=license_id,
        device_id=device_id,
        school_id=school_id,
        machine_id=machine_id,
        computer_name=computer_name,
        ip_address=ip_address,
        status="active",
    )
    db.add(activation)
    db.flush()
    return activation


def upsert_license_device(
    db: Session,
    *,
    license_id: UUID,
    device_id: UUID,
    machine_id: str,
    computer_name: str | None,
    windows_version: str | None,
    cpu_id: str | None,
    motherboard_serial: str | None,
    disk_serial: str | None,
    mac_address: str | None,
    ip_address: str | None,
    last_user: str | None,
) -> LicenseDevice:

    statement = select(LicenseDevice).where(
        LicenseDevice.license_id == license_id,
        LicenseDevice.machine_id == machine_id,
    )

    device = db.scalar(statement)

    if device is None:
        device = LicenseDevice(
            license_id=license_id,
            machine_id=machine_id,
            computer_name=computer_name,
            windows_version=windows_version,
            cpu_id=cpu_id,
            motherboard_serial=motherboard_serial,
            disk_serial=disk_serial,
            mac_address=mac_address,
            ip_address=ip_address,
            last_user=last_user,
            activation_count=1,
            status="active",
        )

        db.add(device)

    else:
        device.computer_name = computer_name or device.computer_name
        device.windows_version = windows_version or device.windows_version
        device.cpu_id = cpu_id or device.cpu_id
        device.motherboard_serial = (
            motherboard_serial or device.motherboard_serial
        )
        device.disk_serial = disk_serial or device.disk_serial
        device.mac_address = mac_address or device.mac_address
        device.ip_address = ip_address or device.ip_address
        device.last_user = last_user or device.last_user

        device.last_seen = datetime.now(timezone.utc)

        if device.status != "blacklisted":
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

def deactivate_device_activations(
    db: Session,
    device_id: UUID,
) -> int:

    statement = (
        select(Activation)
        .where(
            Activation.device_id == device_id,
            Activation.status == "active",
        )
    )

    activations = db.scalars(statement).all()

    count = 0

    for activation in activations:

        activation.status = "inactive"
        activation.deactivated_at = datetime.now(timezone.utc)

        db.add(activation)
        count += 1

    db.flush()

    return count

def reset_device_activation(
    db: Session,
    device_id: UUID,
) -> int:

    deactivate_device_activations(
        db,
        device_id,
    )

def activation_statistics(
    db: Session,
):

    total = db.scalar(
        select(func.count())
        .select_from(Activation)
    ) or 0

    active = db.scalar(
        select(func.count())
        .select_from(Activation)
        .where(Activation.status == "active")
    ) or 0

    inactive = db.scalar(
        select(func.count())
        .select_from(Activation)
        .where(Activation.status == "inactive")
    ) or 0

    return {
        "total": total,
        "active": active,
        "inactive": inactive,
    }