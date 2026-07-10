from io import BytesIO
from reportlab.lib.units import mm
from reportlab.lib.colors import HexColor
from reportlab.lib.styles import getSampleStyleSheet
from reportlab.platypus import (
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.lib.enums import TA_CENTER
from app.models.invoice import Invoice


def generate_invoice_pdf(invoice: Invoice) -> bytes:

    buffer = BytesIO()

    doc = SimpleDocTemplate(
        buffer,
        rightMargin=20 * mm,
        leftMargin=20 * mm,
        topMargin=20 * mm,
        bottomMargin=20 * mm,
    )

    styles = getSampleStyleSheet()

    title_style = styles["Heading1"]
    title_style.alignment = TA_CENTER

    elements = []

    elements.append(
        Paragraph(
            "SEED OF ABRAHAM TECHNOLOGIES",
            title_style,
        )
    )

    elements.append(
        Spacer(1, 8)
    )

    elements.append(
        Paragraph(
            "<b>LICENSE RENEWAL INVOICE</b>",
            styles["Heading2"],
        )
    )

    elements.append(
        Spacer(1, 12)
    )

    data = [
        ["Invoice Number", invoice.invoice_number],
        ["Status", invoice.status.title()],
        ["Amount", f"₦{invoice.amount:,.2f}"],
        ["Currency", invoice.currency],
        ["Description", invoice.description],
        ["Due Date", invoice.due_date.strftime("%d %b %Y")
        if invoice.due_date
        else "-"],
        ["Paid At", invoice.paid_at.strftime("%d %b %Y")
        if invoice.paid_at
        else "-"],
    ]

    table = Table(
        data,
        colWidths=[55 * mm, 100 * mm],
    )

    table.setStyle(
        TableStyle(
            [
                ("GRID", (0, 0), (-1, -1), 0.3, HexColor("#bbbbbb")),
                ("BACKGROUND", (0, 0), (0, -1), HexColor("#eeeeee")),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
                ("TOPPADDING", (0, 0), (-1, -1), 8),
            ]
        )
    )

    elements.append(table)
    elements.append(
        Spacer(1, 15)
    )

    elements.append(
        Paragraph(
            "<b>Customer</b>",
            styles["Heading3"],
        )
    )

    if invoice.school:
        elements.append(
            Paragraph(
                invoice.school.name,
                styles["Normal"],
            )
        )

    if invoice.license:

        elements.append(
            Spacer(1, 12)
        )

        elements.append(

            Paragraph(
                "<b>License</b>",
                styles["Heading3"],
            )

        )

        elements.append(

            Paragraph(
                str(invoice.license.id),
                styles["Normal"],
            )

        )

        elements.append(
            Spacer(1, 25)
        )

        elements.append(

            Paragraph(
                "Thank you for choosing Seed of Abraham Technologies.",
                styles["Normal"],
            )
        )

    doc.build(elements)
    pdf = buffer.getvalue()
    buffer.close()

    return pdf