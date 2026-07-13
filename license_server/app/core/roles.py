from enum import StrEnum


class Roles(StrEnum):
    SUPER_ADMIN = "Super Admin"
    ADMIN = "Admin"
    STAFF = "Staff"