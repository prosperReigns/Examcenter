from datetime import datetime

from pydantic import BaseModel, EmailStr, Field


class AdminCreate(BaseModel):
    full_name: str = Field(min_length=1, max_length=150)
    email: EmailStr
    password: str = Field(min_length=8)
    role: str = Field(default="Staff", max_length=50)


class AdminUpdate(BaseModel):
    full_name: str | None = Field(default=None, min_length=1, max_length=150)
    email: EmailStr | None = None
    password: str | None = Field(default=None, min_length=8)
    role: str | None = Field(default=None, max_length=50)
    is_active: bool | None = None


class AdminRead(BaseModel):
    id: int
    full_name: str
    email: EmailStr
    role: str
    is_active: bool

    created_at: datetime
    updated_at: datetime

    model_config = {"from_attributes": True}

class ChangePasswordRequest(BaseModel):
    old_password: str
    new_password: str = Field(min_length=8)

class ResetPasswordRequest(BaseModel):
    new_password: str = Field(min_length=8)