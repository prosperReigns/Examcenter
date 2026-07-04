from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

INVOICES_DIR = Path(__file__).resolve().parents[2] / "invoices"


def ensure_invoices_dir() -> Path:
    INVOICES_DIR.mkdir(parents=True, exist_ok=True)
    return INVOICES_DIR


def save_invoice_document(filename: str, payload: dict[str, Any]) -> str:
    invoices_dir = ensure_invoices_dir()
    file_path = invoices_dir / filename
    file_path.write_text(json.dumps(payload, indent=2, sort_keys=True, default=str), encoding="utf-8")
    return str(file_path)


def build_invoice_payload(*, payment_ref: str, amount: int, currency: str, payment_type: str, customer_name: str, school_name: str, license_payload: dict[str, Any] | None = None) -> dict[str, Any]:
    return {
        "payment_ref": payment_ref,
        "amount": amount,
        "currency": currency,
        "payment_type": payment_type,
        "customer_name": customer_name,
        "school_name": school_name,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "license": license_payload,
    }
