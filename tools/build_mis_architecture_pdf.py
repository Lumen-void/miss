from __future__ import annotations

import json
from pathlib import Path

from PIL import Image as PILImage
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import inch
from reportlab.platypus import (
    Image,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)

ROOT = Path("/Applications/XAMPP/xamppfiles/htdocs/miss")
DATA = json.loads((ROOT / "storage/run-1-architecture-data.json").read_text())
SHOT_DIR = ROOT / "storage/architecture-screenshots-doc"
OUT = ROOT / "docs/MIS_Tool_Architecture_Run_1.pdf"

SCREENSHOTS = [
    ("01-start-dashboard.png", "Start - Command Center", "The dashboard is where the monthly run starts."),
    ("02-dashboard-close-board.png", "Dashboard - Close Board", "Close readiness and approval checks."),
    ("03-dashboard-trends.png", "Dashboard - Trends", "Month trend comparison."),
    ("04-import-guided-sources.png", "Import - Guided Sources", "Manual, API, browser automation, and scheduling entry point."),
    ("05-import-api-connect.png", "Import - API Connect", "Direct API setup for custom/API sources."),
    ("06-import-activity.png", "Import - Activity", "Browser automation job log and status."),
    ("07-import-manual-upload.png", "Import - Manual Upload", "Upload full workbook or individual source reports."),
    ("08-sales-overview.png", "Sales - Overview", "Sales totals, filters, and latest imported rows."),
    ("09-sales-charts.png", "Sales - Charts", "Graphical sales breakdowns."),
    ("10-sales-platforms.png", "Sales - Platforms", "Platform-level sales review."),
    ("11-sales-records.png", "Sales - Records", "Raw sales-row audit trail."),
    ("12-inventory-overview.png", "Inventory - Overview", "Stock and sales control."),
    ("13-inventory-stock.png", "Inventory - Stock", "Stock position by item."),
    ("14-inventory-movements.png", "Inventory - Movements", "Inventory ledger from sales sync and stock actions."),
    ("15-inventory-setup.png", "Inventory - Setup", "Warehouse/item setup."),
    ("16-mis-preview.png", "MIS - Preview", "Approval page for final MIS result."),
    ("17-mis-charts.png", "MIS - Charts", "Visual MIS breakdown."),
    ("18-mis-profit-bridge.png", "MIS - Profit Bridge", "Bridge from sales to net surplus/burn."),
    ("19-mis-platforms.png", "MIS - Platforms", "Platform MIS output."),
    ("20-mis-categories.png", "MIS - Categories", "Category profitability."),
    ("21-mis-audit.png", "MIS - Audit", "Detailed MIS line audit."),
    ("22-reports-overview.png", "Reports - Overview", "Report hub."),
    ("23-reports-executive.png", "Reports - Executive", "Executive summary output."),
    ("24-reports-pnl.png", "Reports - P&L", "P&L mapped cost report."),
    ("25-reports-loss-watch.png", "Reports - Loss Watch", "Risk and loss watch."),
    ("26-validation.png", "Validation", "Notices and data quality checks."),
    ("27-adjustments.png", "Adjustments", "Monthly additions/deductions before recalculation."),
    ("28-masters.png", "Masters", "SKU and COGS masters."),
]


def money(value) -> str:
    n = float(value or 0)
    sign = "-" if n < 0 else ""
    n = abs(n)
    return f"{sign}Rs. {n:,.2f}"


def pct(value) -> str:
    return "" if value is None else f"{float(value):.2f}%"


def tbl(data, widths=None):
    t = Table(data, colWidths=widths, repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#0F766E")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE", (0, 0), (-1, -1), 7.2),
        ("LEADING", (0, 0), (-1, -1), 8.5),
        ("GRID", (0, 0), (-1, -1), 0.25, colors.HexColor("#D9E2EC")),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#F8FAFC")]),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    return t


def screenshot_flow(story, styles, filename, title, caption):
    story.append(Paragraph(title, styles["H3"]))
    story.append(Paragraph(caption, styles["Body"]))
    image_path = SHOT_DIR / filename
    with PILImage.open(image_path) as img:
        max_w = 6.8 * inch
        max_h = 4.75 * inch
        scale = min(max_w / img.width, max_h / img.height)
        story.append(Image(str(image_path), width=img.width * scale, height=img.height * scale))
    story.append(Spacer(1, 0.13 * inch))


def main():
    doc = SimpleDocTemplate(str(OUT), pagesize=letter, rightMargin=.55*inch, leftMargin=.55*inch, topMargin=.5*inch, bottomMargin=.5*inch)
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle(name="TitleCenter", parent=styles["Title"], alignment=TA_CENTER, fontName="Helvetica-Bold", fontSize=22, leading=26, textColor=colors.HexColor("#0B1220")))
    styles.add(ParagraphStyle(name="H1", parent=styles["Heading1"], fontSize=15, leading=18, textColor=colors.HexColor("#0F766E"), spaceBefore=10, spaceAfter=6))
    styles.add(ParagraphStyle(name="H2", parent=styles["Heading2"], fontSize=12, leading=15, textColor=colors.HexColor("#172033"), spaceBefore=8, spaceAfter=4))
    styles.add(ParagraphStyle(name="H3", parent=styles["Heading3"], fontSize=10, leading=12, textColor=colors.HexColor("#172033"), spaceBefore=6, spaceAfter=3))
    styles.add(ParagraphStyle(name="Body", parent=styles["BodyText"], fontSize=8.5, leading=11, spaceAfter=5))
    styles.add(ParagraphStyle(name="Callout", parent=styles["BodyText"], backColor=colors.HexColor("#EAF8F6"), borderColor=colors.HexColor("#B7E4DD"), borderWidth=.5, borderPadding=6, fontSize=8.5, leading=11, spaceAfter=8))

    story = []
    run = DATA["run"]
    story.append(Paragraph("MIS Tool Architecture, Data Flow, Screens, and Calculation Guide", styles["TitleCenter"]))
    story.append(Paragraph(f"Run ID 1 | Month {run['month']} | Status {run['status']} | Live URL: http://localhost/miss/?run_id=1", styles["Body"]))
    story.append(Paragraph("This PDF explains where the tool starts, where data is imported from, what is captured, how each MIS output is calculated, and where the workflow ends.", styles["Callout"]))

    story.append(Paragraph("1. Run Snapshot", styles["H1"]))
    final = next(x for x in DATA["overview"] if x["line_item"] == "Net surplus / burn")
    net = next(x for x in DATA["overview"] if x["line_item"] == "Net sales after tax")
    story.append(tbl([
        ["Metric", "Value"],
        ["Imported sales rows", str(DATA["counts"]["import_rows"])],
        ["P&L mapping rows", str(DATA["counts"]["profit_loss_entries"])],
        ["Product cost rows", str(DATA["counts"]["product_costs"])],
        ["Inventory movements", str(DATA["counts"]["inventory_movements"])],
        ["Net sales after tax", money(net["amount"])],
        ["Final net surplus / burn", f"{money(final['amount'])} ({pct(final['pct'])})"],
    ], [2.5*inch, 4.2*inch]))

    story.append(Paragraph("2. Data Starts Here", styles["H1"]))
    story.append(tbl([
        ["Path", "What enters", "Where it goes"],
        ["Manual workbook upload", "Full MIS workbook or individual source reports", "Importer, WorkbookMappingImporter, import_rows, P&L, COGS"],
        ["Browser portal automation", "Marketplace reports after user login/OTP", "AutoImportService, downloaded files, Importer"],
        ["API Connect", "CSV/JSON endpoint data", "ApiIntegrationService, temporary CSV, Importer"],
    ], [1.4*inch, 2.4*inch, 2.9*inch]))

    story.append(Paragraph("3. Calculation Chain", styles["H1"]))
    story.append(tbl([
        ["Output", "Calculation source"],
        ["Net sales after tax", "Sum of import_rows.net_revenue"],
        ["Platform costs", "P&L categories: Seller Fee, Logistics, Storage, Transactions, Packing, Support, Cold Storage"],
        ["Marketing", "P&L category Marketing"],
        ["COGS raw material", "quantity * multiplier * purchase_price from COGS Cal."],
        ["Packaging", "quantity * packaging_rate from COGS Cal."],
        ["Net surplus / burn", "Gross margin after COGS minus agency/admin expenses"],
    ], [2.1*inch, 4.6*inch]))

    story.append(Paragraph("4. Current MIS Output", styles["H1"]))
    output_rows = [["Section", "Line", "Amount", "%", "Why"]]
    for x in DATA["overview"]:
        output_rows.append([x["section"], x["line_item"], money(x["amount"]), pct(x["pct"]), x["note"]])
    story.append(tbl(output_rows, [0.75*inch, 1.65*inch, 1.0*inch, .55*inch, 2.75*inch]))

    story.append(PageBreak())
    story.append(Paragraph("5. Platform and Category Outputs", styles["H1"]))
    story.append(tbl([["Platform", "Rows", "Qty", "Gross", "Tax", "Net"]] + [[p["platform"], p["rows_count"], p["qty"], money(p["gross"]), money(p["tax"]), money(p["net"])] for p in DATA["platforms"]], [1.55*inch, .55*inch, .55*inch, 1.05*inch, .95*inch, 1.05*inch]))
    story.append(tbl([["Category", "Qty", "Revenue", "COGS", "Packaging", "Gross profit"]] + [[c["category"], c["qty"], money(c["revenue"]), money(c["cogs"]), money(c["packaging"]), money(c["gross_profit"])] for c in DATA["categories"][:8]], [1.8*inch, .5*inch, 1.0*inch, .85*inch, .85*inch, 1.0*inch]))

    story.append(PageBreak())
    story.append(Paragraph("6. Screen-by-Screen Walkthrough", styles["H1"]))
    for i, (filename, title, caption) in enumerate(SCREENSHOTS, 1):
        screenshot_flow(story, styles, filename, f"6.{i} {title}", caption)
        if i < len(SCREENSHOTS):
            story.append(PageBreak())

    story.append(Paragraph("7. Where The Tool Ends", styles["H1"]))
    story.append(tbl([
        ["Final stage", "User checks", "Output"],
        ["MIS Preview", "Revenue, cost, margin, platform, category and final surplus", "Approval-ready MIS"],
        ["Validation", "Notices, unmapped rows, duplicate files, audit concerns", "Confidence before finalization"],
        ["Adjustments", "Manual additions/deductions if accounting needs them", "Recalculated MIS"],
        ["Reports", "Executive, P&L and loss watch pages", "Management presentation view"],
        ["Finalize/lock", "Approve the monthly result", "Stable month-end MIS output"],
    ], [1.3*inch, 3.1*inch, 2.3*inch]))

    doc.build(story)
    print(OUT)


if __name__ == "__main__":
    main()
