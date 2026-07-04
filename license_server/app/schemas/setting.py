from pydantic import BaseModel, Field
from uuid import UUID


class SettingRead(BaseModel):
    id: UUID
    key: str
    value: str
    category: str | None = None
    description: str | None = None
    is_system: bool = False

    model_config = {"from_attributes": True}


class SettingUpsert(BaseModel):
    key: str = Field(min_length=1, max_length=150)
    value: str = Field(min_length=1)
    category: str | None = Field(default=None, max_length=100)
    description: str | None = None
    is_system: bool = False
