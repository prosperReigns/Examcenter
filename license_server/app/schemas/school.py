from uuid import UUID

from pydantic import BaseModel, EmailStr, Field


class SchoolBase(BaseModel):
    customer_id: UUID
    name: str = Field(min_length=1, max_length=150)
    code: str | None = Field(default=None, max_length=80)
    address: str | None = Field(default=None, max_length=255)
    contact_email: EmailStr | None = None
    contact_phone: str | None = Field(default=None, max_length=50)
    is_active: bool = True


class SchoolCreate(SchoolBase):
    pass


class SchoolUpdate(BaseModel):
    customer_id: UUID | None = None
    name: str | None = Field(default=None, min_length=1, max_length=150)
    code: str | None = Field(default=None, max_length=80)
    address: str | None = Field(default=None, max_length=255)
    contact_email: EmailStr | None = None
    contact_phone: str | None = Field(default=None, max_length=50)
    is_active: bool | None = None


class SchoolRead(SchoolBase):
    id: UUID

    model_config = {"from_attributes": True}
