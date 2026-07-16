"""add purchase sessions and activation tokens

Revision ID: 20260716_0003
Revises: 23d760c3b07e
Create Date: 2026-07-16 00:00:00.000000
"""
from alembic import op
import sqlalchemy as sa


revision = "20260716_0003"
down_revision = "23d760c3b07e"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "purchase_sessions",
        sa.Column("id", sa.UUID(), nullable=False),
        sa.Column("fingerprint", sa.String(length=255), nullable=False),
        sa.Column("product_code", sa.String(length=80), nullable=False),
        sa.Column("version", sa.String(length=50), nullable=False),
        sa.Column("plan_code", sa.String(length=50), nullable=False),
        sa.Column("duration_months", sa.Integer(), nullable=False),
        sa.Column("amount", sa.Numeric(precision=12, scale=2), nullable=False),
        sa.Column("currency", sa.String(length=10), nullable=False),
        sa.Column("customer_name", sa.String(length=150), nullable=False),
        sa.Column("customer_email", sa.String(length=255), nullable=False),
        sa.Column("customer_phone", sa.String(length=50), nullable=True),
        sa.Column("school_name", sa.String(length=150), nullable=False),
        sa.Column("payment_reference", sa.String(length=100), nullable=True),
        sa.Column("gateway", sa.String(length=50), nullable=True),
        sa.Column("gateway_reference", sa.String(length=255), nullable=True),
        sa.Column("gateway_transaction_id", sa.String(length=150), nullable=True),
        sa.Column("status", sa.String(length=40), nullable=False),
        sa.Column("completed", sa.Boolean(), nullable=False),
        sa.Column("retry_count", sa.Integer(), nullable=False),
        sa.Column("customer_id", sa.UUID(), nullable=True),
        sa.Column("school_id", sa.UUID(), nullable=True),
        sa.Column("license_id", sa.UUID(), nullable=True),
        sa.Column("invoice_id", sa.UUID(), nullable=True),
        sa.Column("payment_id", sa.UUID(), nullable=True),
        sa.Column("device_id", sa.UUID(), nullable=True),
        sa.Column("activation_id", sa.UUID(), nullable=True),
        sa.Column("receipt_id", sa.UUID(), nullable=True),
        sa.Column("activation_token_id", sa.UUID(), nullable=True),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("completed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.text("now()"), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.text("now()"), nullable=True),
        sa.ForeignKeyConstraint(["activation_id"], ["activations.id"]),
        sa.ForeignKeyConstraint(["customer_id"], ["customers.id"]),
        sa.ForeignKeyConstraint(["device_id"], ["license_devices.id"]),
        sa.ForeignKeyConstraint(["invoice_id"], ["invoices.id"]),
        sa.ForeignKeyConstraint(["license_id"], ["licenses.id"]),
        sa.ForeignKeyConstraint(["payment_id"], ["payments.id"]),
        sa.ForeignKeyConstraint(["receipt_id"], ["receipts.id"]),
        sa.ForeignKeyConstraint(["school_id"], ["schools.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index(op.f("ix_purchase_sessions_customer_email"), "purchase_sessions", ["customer_email"], unique=False)
    op.create_index(op.f("ix_purchase_sessions_fingerprint"), "purchase_sessions", ["fingerprint"], unique=False)
    op.create_index(op.f("ix_purchase_sessions_payment_reference"), "purchase_sessions", ["payment_reference"], unique=True)
    op.create_index(op.f("ix_purchase_sessions_school_name"), "purchase_sessions", ["school_name"], unique=False)
    op.create_index(op.f("ix_purchase_sessions_status"), "purchase_sessions", ["status"], unique=False)

    op.create_table(
        "activation_tokens",
        sa.Column("id", sa.UUID(), nullable=False),
        sa.Column("token", sa.String(length=128), nullable=False),
        sa.Column("purchase_session_id", sa.UUID(), nullable=False),
        sa.Column("license_id", sa.UUID(), nullable=False),
        sa.Column("machine_fingerprint", sa.String(length=255), nullable=False),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("used_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("revoked_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.text("now()"), nullable=False),
        sa.ForeignKeyConstraint(["license_id"], ["licenses.id"]),
        sa.ForeignKeyConstraint(["purchase_session_id"], ["purchase_sessions.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index(op.f("ix_activation_tokens_token"), "activation_tokens", ["token"], unique=True)

    op.create_foreign_key(
        "fk_purchase_sessions_activation_token_id_activation_tokens",
        "purchase_sessions",
        "activation_tokens",
        ["activation_token_id"],
        ["id"],
    )


def downgrade() -> None:
    op.drop_constraint(
        "fk_purchase_sessions_activation_token_id_activation_tokens",
        "purchase_sessions",
        type_="foreignkey",
    )
    op.drop_index(op.f("ix_activation_tokens_token"), table_name="activation_tokens")
    op.drop_table("activation_tokens")
    op.drop_index(op.f("ix_purchase_sessions_status"), table_name="purchase_sessions")
    op.drop_index(op.f("ix_purchase_sessions_school_name"), table_name="purchase_sessions")
    op.drop_index(op.f("ix_purchase_sessions_payment_reference"), table_name="purchase_sessions")
    op.drop_index(op.f("ix_purchase_sessions_fingerprint"), table_name="purchase_sessions")
    op.drop_index(op.f("ix_purchase_sessions_customer_email"), table_name="purchase_sessions")
    op.drop_table("purchase_sessions")
