from datetime import datetime, timezone
from uuid import UUID

from sqlalchemy import func, or_, select
from sqlalchemy.orm import Session

from app.models.license_device import LicenseDevice

def list_devices(
    db: Session,
    *,
    search: str | None = None,
    status: str | None = None,
    license_id: UUID | None = None,
    offset: int = 0,
    limit: int = 20,
) -> tuple[list[LicenseDevice], int]:

    statement = select(LicenseDevice)
    count_statement = select(func.count()).select_from(LicenseDevice)

    if search:

        term = f"%{search.strip()}%"

        condition = or_(
            LicenseDevice.machine_id.ilike(term),
            LicenseDevice.computer_name.ilike(term),
            LicenseDevice.renamed_to.ilike(term),
        )

        statement = statement.where(condition)
        count_statement = count_statement.where(condition)

    if status:
        statement = statement.where(
            LicenseDevice.status == status
        )

        count_statement = count_statement.where(
            LicenseDevice.status == status
        )

    if license_id:

        statement = statement.where(
            LicenseDevice.license_id == license_id
        )

        count_statement = count_statement.where(
            LicenseDevice.license_id == license_id
        )

    total = db.scalar(count_statement) or 0

    devices = db.scalars(
        statement
        .order_by(LicenseDevice.last_seen.desc())
        .offset(offset)
        .limit(limit)
    ).all()

    return devices, total

def get_device(
    db: Session,
    device_id: UUID,
) -> LicenseDevice | None:

    return db.get(
        LicenseDevice,
        device_id,
    )

def create_device(
    db: Session,
    *,
    license_id: UUID,
    machine_id: str,
    computer_name: str | None = None,
    ip_address: str | None = None,
    windows_version: str | None = None,
    cpu_id: str | None = None,
    motherboard_serial: str | None = None,
    disk_serial: str | None = None,
    mac_address: str | None = None,
    last_user: str | None = None,
) -> LicenseDevice:

    device = LicenseDevice(

        license_id=license_id,

        machine_id=machine_id,

        computer_name=computer_name,

        ip_address=ip_address,

        windows_version=windows_version,

        cpu_id=cpu_id,

        motherboard_serial=motherboard_serial,

        disk_serial=disk_serial,

        mac_address=mac_address,

        last_user=last_user,

    )

    db.add(device)

    db.flush()

    return device

def save_device(
    db: Session,
    device: LicenseDevice,
) -> LicenseDevice:

    db.add(device)

    db.flush()

    return device

def get_device_by_machine_id(
    db: Session,
    machine_id: str,
) -> LicenseDevice | None:

    statement = (

        select(LicenseDevice)

        .where(

            LicenseDevice.machine_id == machine_id

        )

    )

    return db.scalar(statement)

def get_devices_by_license(
    db: Session,
    license_id: UUID,
) -> list[LicenseDevice]:

    statement = (

        select(LicenseDevice)

        .where(

            LicenseDevice.license_id == license_id

        )

        .order_by(

            LicenseDevice.first_seen.asc()

        )

    )

    return db.scalars(statement).all()


def rename_device(
    db: Session,
    device: LicenseDevice,
    new_name: str,
) -> LicenseDevice:

    device.renamed_to = new_name.strip()

    db.add(device)

    db.flush()

    return device

def blacklist_device(
    db: Session,
    device: LicenseDevice,
    reason: str | None,
) -> LicenseDevice:

    device.blacklisted = True

    device.blacklist_reason = reason

    device.status = "blacklisted"

    db.add(device)

    db.flush()

    return device

def unblacklist_device(
    db: Session,
    device: LicenseDevice,
) -> LicenseDevice:

    device.blacklisted = False

    device.blacklist_reason = None

    device.status = "active"

    db.add(device)

    db.flush()

    return device

def update_device_notes(
    db: Session,
    device: LicenseDevice,
    notes: str,
) -> LicenseDevice:

    device.notes = notes

    db.add(device)

    db.flush()

    return device

def record_heartbeat(
    db: Session,
    device: LicenseDevice,
    *,
    ip_address: str | None = None,
) -> LicenseDevice:

    device.last_seen = datetime.now(timezone.utc)

    if ip_address:

        device.ip_address = ip_address

    db.add(device)

    db.flush()

    return device

def device_statistics(
    db: Session,
) -> dict:

    total = db.scalar(
        select(func.count()).select_from(LicenseDevice)
    ) or 0

    active = db.scalar(
        select(func.count())
        .select_from(LicenseDevice)
        .where(LicenseDevice.status == "active")
    ) or 0

    blacklisted = db.scalar(
        select(func.count())
        .select_from(LicenseDevice)
        .where(LicenseDevice.status == "blacklisted")
    ) or 0

    inactive = db.scalar(
        select(func.count())
        .select_from(LicenseDevice)
        .where(LicenseDevice.status == "inactive")
    ) or 0

    return {
        "total": total,
        "active": active,
        "inactive": inactive,
        "blacklisted": blacklisted,
    }

def get_device_by_fingerprint(
    db: Session,
    fingerprint: str,
) -> LicenseDevice | None:
    return get_device_by_machine_id(db, fingerprint)
