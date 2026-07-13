from pathlib import Path
from datetime import datetime

from fastapi import Request
from fastapi.templating import Jinja2Templates
from app.web.dependencies import template_context

from app.core.config import get_settings
from app.utils.flash import pop_flashes

BASE_DIR = Path(__file__).resolve().parent.parent

settings = get_settings()

templates = Jinja2Templates(
    directory=str(BASE_DIR / "templates"),
    context_processors=[template_context],
)

templates.context_processors.append(template_context)