#!/usr/bin/env python3
"""Build PDF versions of the launch documents (docs/launch/*.md -> docs/launch/pdf/).

Needs: pip install reportlab markdown. Uses DejaVu Sans when installed (accents,
« », ’), otherwise Helvetica. Supports the Markdown used in these documents:
headings, paragraphs, bullet lists, tables, block quotes, bold, italics, code.
"""
from __future__ import annotations

import html
import re
import sys
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import ListFlowable, ListItem, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "docs" / "launch"
OUTPUT = SOURCE / "pdf"
INDIGO = colors.HexColor("#312e81")

FONT, BOLD, ITALIC = "Helvetica", "Helvetica-Bold", "Helvetica-Oblique"
dejavu = Path("/usr/share/fonts/truetype/dejavu")
if (dejavu / "DejaVuSans.ttf").exists():
    pdfmetrics.registerFont(TTFont("DejaVu", str(dejavu / "DejaVuSans.ttf")))
    pdfmetrics.registerFont(TTFont("DejaVu-Bold", str(dejavu / "DejaVuSans-Bold.ttf")))
    pdfmetrics.registerFont(TTFont("DejaVu-Oblique", str(dejavu / "DejaVuSans-Oblique.ttf")))
    pdfmetrics.registerFontFamily("DejaVu", normal="DejaVu", bold="DejaVu-Bold", italic="DejaVu-Oblique", boldItalic="DejaVu-Bold")
    FONT, BOLD, ITALIC = "DejaVu", "DejaVu-Bold", "DejaVu-Oblique"

STYLES = {
    "h1": ParagraphStyle("h1", fontName=BOLD, fontSize=18, leading=23, textColor=INDIGO, spaceAfter=8),
    "h2": ParagraphStyle("h2", fontName=BOLD, fontSize=13, leading=17, textColor=INDIGO, spaceBefore=10, spaceAfter=4),
    "h3": ParagraphStyle("h3", fontName=BOLD, fontSize=11, leading=14, spaceBefore=8, spaceAfter=3),
    "body": ParagraphStyle("body", fontName=FONT, fontSize=9.5, leading=13.5, alignment=TA_LEFT, spaceAfter=5),
    "quote": ParagraphStyle("quote", fontName=FONT, fontSize=9, leading=12.5, textColor=colors.HexColor("#7c2d12"), backColor=colors.HexColor("#fff7ed"), borderPadding=6, spaceBefore=4, spaceAfter=8, leftIndent=6),
    "cell": ParagraphStyle("cell", fontName=FONT, fontSize=8.5, leading=11),
    "code": ParagraphStyle("code", fontName="Courier", fontSize=8, leading=10, backColor=colors.HexColor("#f3f4f6"), borderPadding=4, spaceAfter=6),
}


def inline(text: str) -> str:
    # Keep French guillemets with their words (no line break inside « … »).
    text = text.replace("« ", "«\u00a0").replace(" »", "\u00a0»")
    text = html.escape(text, quote=False)
    text = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", text)
    text = re.sub(r"(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)", r"<i>\1</i>", text)
    text = re.sub(r"`([^`]+)`", r'<font name="Courier">\1</font>', text)
    return text


def table(rows: list[str], width: float) -> Table:
    cells = [[c.strip() for c in row.strip().strip("|").split("|")] for row in rows if not re.match(r"^\|?\s*:?-{2,}", row.strip())]
    data = [[Paragraph(("<b>%s</b>" if i == 0 else "%s") % inline(c), STYLES["cell"]) for c in r] for i, r in enumerate(cells)]
    cols = max(len(r) for r in data)
    t = Table(data, colWidths=[width / cols] * cols, repeatRows=1)
    t.setStyle(TableStyle([
        ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#c7c9d9")),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#e0e7ff")),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
    ]))
    return t


def build(md_path: Path) -> Path:
    lines = md_path.read_text(encoding="utf-8").splitlines()
    out = OUTPUT / (md_path.stem + ".pdf")
    doc = SimpleDocTemplate(str(out), pagesize=A4, leftMargin=18 * mm, rightMargin=18 * mm, topMargin=16 * mm, bottomMargin=16 * mm, title=md_path.stem, author="Mayura Consultancy Services")
    width = A4[0] - 36 * mm
    story: list = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if not line.strip():
            i += 1
            continue
        if line.startswith("```"):
            code = []
            i += 1
            while i < len(lines) and not lines[i].startswith("```"):
                code.append(html.escape(lines[i]))
                i += 1
            story.append(Paragraph("<br/>".join(code), STYLES["code"]))
            i += 1
            continue
        m = re.match(r"^(#{1,3})\s+(.*)", line)
        if m:
            story.append(Paragraph(inline(m.group(2)), STYLES["h" + str(len(m.group(1)))]))
            i += 1
            continue
        if line.lstrip().startswith("|"):
            rows = []
            while i < len(lines) and lines[i].lstrip().startswith("|"):
                rows.append(lines[i])
                i += 1
            story += [table(rows, width), Spacer(1, 6)]
            continue
        if line.startswith(">"):
            quote = []
            while i < len(lines) and lines[i].startswith(">"):
                quote.append(lines[i].lstrip("> ").strip())
                i += 1
            story.append(Paragraph(inline(" ".join(quote)), STYLES["quote"]))
            continue
        if re.match(r"^\s*([*-]|\d+\.)\s+", line):
            items = []
            ordered = bool(re.match(r"^\s*\d+\.", line))
            while i < len(lines) and re.match(r"^\s*([*-]|\d+\.)\s+", lines[i]):
                items.append(ListItem(Paragraph(inline(re.sub(r"^\s*([*-]|\d+\.)\s+", "", lines[i])), STYLES["body"]), leftIndent=12))
                i += 1
            story.append(ListFlowable(items, bulletType="1" if ordered else "bullet", start="1" if ordered else "•", leftIndent=12, bulletFontName=FONT, bulletFontSize=8))
            continue
        para = [line.strip()]
        i += 1
        while i < len(lines) and lines[i].strip() and not re.match(r"^(#|\||>|```|\s*([*-]|\d+\.)\s)", lines[i]):
            para.append(lines[i].strip())
            i += 1
        story.append(Paragraph(inline(" ".join(para)), STYLES["body"]))

    def footer(canvas, document):
        canvas.saveState()
        canvas.setFont(FONT, 7.5)
        canvas.setFillColor(colors.grey)
        canvas.drawString(18 * mm, 9 * mm, "Assistant Hôtel · CNH Global Tech & Mayura Consultancy Services")
        canvas.drawRightString(A4[0] - 18 * mm, 9 * mm, str(document.page))
        canvas.restoreState()

    doc.build(story, onFirstPage=footer, onLaterPages=footer)
    return out


if __name__ == "__main__":
    OUTPUT.mkdir(parents=True, exist_ok=True)
    targets = [p for p in sorted(SOURCE.glob("*.md")) if p.name != "README.md"]
    for path in targets:
        print("built", build(path).relative_to(ROOT))
    sys.exit(0)
