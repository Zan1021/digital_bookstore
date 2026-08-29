"""
Generate persisted golden fixtures for the render-gate tests (brief §17.2/§17.3).

Covers the full layout-family library the brief requires. Each fixture is a small
synthetic PDF with a KNOWN expected gate outcome (pass/fail), so the regression
suite can assert both geometry (the gate verdict) and — via a rendered pixmap —
that the page rasterises. Book-agnostic: synthetic content, no real book.

Families:
  good, bad, centered, multicol (3-col), longword,
  merged_cells, borderless, fourcol, justified, text_over_illustration,
  rotated, long_translation_80pct, mixed_fonts, missing_font, rtl,
  complex_script, scanned, vector, cropmarked, landscape, double_page

Run once: python scripts/test_fixtures/make_gate_fixtures.py
"""
import os
import pymupdf

HERE = os.path.dirname(os.path.abspath(__file__))
FONTS_DIR = os.path.join(os.path.dirname(os.path.dirname(HERE)), "storage", "app", "fonts")


def _fonts():
    if not os.path.isdir(FONTS_DIR):
        return []
    return [os.path.join(FONTS_DIR, f) for f in sorted(os.listdir(FONTS_DIR))
            if f.lower().endswith((".ttf", ".otf"))]


def _font():
    fs = _fonts()
    return fs[0] if fs else None


def _kw(size, ff=None):
    ff = ff or _font()
    return dict(fontsize=size, fontname="F0", fontfile=ff) if ff else dict(fontsize=size)


# --- Core pass/fail ---------------------------------------------------------

def make_good(path):
    doc = pymupdf.open(); page = doc.new_page(width=300, height=200)
    page.draw_line(pymupdf.Point(150, 20), pymupdf.Point(150, 180), width=1)
    page.insert_text(pymupdf.Point(20, 100), "huis", **_kw(12))
    page.insert_text(pymupdf.Point(170, 100), "kat", **_kw(12))
    doc.save(path); doc.close()


def make_bad(path):
    doc = pymupdf.open(); page = doc.new_page(width=300, height=200)
    page.draw_line(pymupdf.Point(150, 20), pymupdf.Point(150, 180), width=1)
    page.insert_text(pymupdf.Point(120, 100), "tafeltennis", **_kw(20))  # crosses x=150
    doc.save(path); doc.close()


def make_centered(path):
    doc = pymupdf.open(); page = doc.new_page(width=300, height=160)
    page.insert_text(pymupdf.Point(110, 80), "Titel", **_kw(14))
    doc.save(path); doc.close()


def make_multicol(path):
    doc = pymupdf.open(); page = doc.new_page(width=360, height=200)
    for vx in (120, 240):
        page.draw_line(pymupdf.Point(vx, 20), pymupdf.Point(vx, 180), width=1)
    page.insert_text(pymupdf.Point(20, 60), "huis", **_kw(11))
    page.insert_text(pymupdf.Point(140, 60), "kat", **_kw(11))
    page.insert_text(pymupdf.Point(260, 60), "boom", **_kw(11))
    doc.save(path); doc.close()


def make_longword(path):
    doc = pymupdf.open(); page = doc.new_page(width=200, height=140)
    page.draw_line(pymupdf.Point(100, 10), pymupdf.Point(100, 130), width=1)
    page.insert_text(pymupdf.Point(70, 70), "Onafhanklikheidsverklaring", **_kw(18))
    doc.save(path); doc.close()


# --- §17.2 layout families --------------------------------------------------

def make_merged_cells(path):
    """A merged header cell spanning two columns; text stays inside — pass."""
    doc = pymupdf.open(); page = doc.new_page(width=360, height=200)
    # outer grid + one vertical divider only in the lower half (merged top row)
    page.draw_line(pymupdf.Point(180, 100), pymupdf.Point(180, 180), width=1)
    page.draw_line(pymupdf.Point(20, 100), pymupdf.Point(340, 100), width=1)
    page.insert_text(pymupdf.Point(120, 60), "Kop", **_kw(12))     # merged header
    page.insert_text(pymupdf.Point(60, 150), "een", **_kw(11))
    page.insert_text(pymupdf.Point(220, 150), "twee", **_kw(11))
    doc.save(path); doc.close()


def make_borderless(path):
    """Column layout WITHOUT grid lines; words aligned in columns — pass."""
    doc = pymupdf.open(); page = doc.new_page(width=360, height=220)
    for x in (30, 150, 270):
        for i, w in enumerate(("een", "twee", "drie", "vier")):
            page.insert_text(pymupdf.Point(x, 40 + i * 40), w, **_kw(11))
    doc.save(path); doc.close()


def make_fourcol(path):
    doc = pymupdf.open(); page = doc.new_page(width=440, height=200)
    for vx in (110, 220, 330):
        page.draw_line(pymupdf.Point(vx, 20), pymupdf.Point(vx, 180), width=1)
    for i, x in enumerate((20, 130, 240, 350)):
        page.insert_text(pymupdf.Point(x, 60), ["huis", "kat", "boom", "son"][i], **_kw(10))
    doc.save(path); doc.close()


def make_justified(path):
    """Justified paragraph inside margins — pass."""
    doc = pymupdf.open(); page = doc.new_page(width=320, height=220)
    css = "*{font-size:11px;} p{text-align:justify;margin:0;}"
    body = ("<p>Hierdie paragraaf is uitgevul sodat die reels netjies teen "
            "beide kantlyne pas sonder om buite die blok te val.</p>")
    page.insert_htmlbox(pymupdf.Rect(30, 30, 290, 200), body, css=css)
    doc.save(path); doc.close()


def make_text_over_illustration(path):
    """Text placed over a coloured illustration block — pass (stays in bounds)."""
    doc = pymupdf.open(); page = doc.new_page(width=300, height=200)
    page.draw_rect(pymupdf.Rect(20, 20, 280, 180), color=(0.2, 0.5, 0.8), fill=(0.8, 0.9, 1.0))
    page.insert_text(pymupdf.Point(60, 110), "Byskrif", **_kw(16))
    doc.save(path); doc.close()


def make_rotated(path):
    """Vertical (rotated) text inside the page — pass."""
    doc = pymupdf.open(); page = doc.new_page(width=200, height=260)
    page.insert_text(pymupdf.Point(100, 200), "Kantteks", morph=(pymupdf.Point(100, 200),
                     pymupdf.Matrix(90)), **_kw(14))
    doc.save(path); doc.close()


def make_long_translation_80pct(path):
    """A translation ~80% longer than the cell in a narrow column — must FAIL."""
    doc = pymupdf.open(); page = doc.new_page(width=200, height=140)
    page.draw_line(pymupdf.Point(100, 10), pymupdf.Point(100, 130), width=1)
    page.insert_text(pymupdf.Point(60, 70), "Verantwoordelikheidsbesef", **_kw(16))
    doc.save(path); doc.close()


def make_mixed_fonts(path):
    """Two different fonts on one page — pass."""
    doc = pymupdf.open(); page = doc.new_page(width=320, height=160)
    fs = _fonts()
    f1 = fs[0] if fs else None
    f2 = fs[1] if len(fs) > 1 else f1
    page.insert_text(pymupdf.Point(30, 60), "Font A", **_kw(14, f1))
    page.insert_text(pymupdf.Point(30, 110), "Font B", **_kw(14, f2))
    doc.save(path); doc.close()


def make_missing_font(path):
    """Page using a base-14 font only (no embedded/approved family) — pass geometry.
    (The approved-font policy is validated separately in test_font_policy.)"""
    doc = pymupdf.open(); page = doc.new_page(width=300, height=160)
    page.insert_text(pymupdf.Point(30, 80), "Basis", fontsize=13)  # helv base-14
    doc.save(path); doc.close()


def make_rtl(path):
    """Right-to-left script (Arabic) — pass geometry (stays in bounds)."""
    doc = pymupdf.open(); page = doc.new_page(width=300, height=160)
    css = "* {font-size:16px; direction: rtl;}"
    try:
        page.insert_htmlbox(pymupdf.Rect(30, 30, 270, 130),
                            "<p>مرحبا بالعالم</p>", css=css)
    except Exception:
        page.insert_text(pymupdf.Point(40, 80), "RTL", **_kw(14))
    doc.save(path); doc.close()


def make_complex_script(path):
    """Complex script with combining marks (Devanagari) — pass geometry."""
    doc = pymupdf.open(); page = doc.new_page(width=300, height=160)
    try:
        page.insert_htmlbox(pymupdf.Rect(30, 30, 270, 130),
                            "<p>नमस्ते दुनिया</p>", css="*{font-size:16px;}")
    except Exception:
        page.insert_text(pymupdf.Point(40, 80), "script", **_kw(14))
    doc.save(path); doc.close()


def make_scanned(path):
    """A 'scanned' page: an image raster with no extractable text layer — pass
    geometry (nothing to overflow)."""
    doc = pymupdf.open(); page = doc.new_page(width=300, height=200)
    pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 240, 160), False)
    pix.clear_with(220)
    page.insert_image(pymupdf.Rect(30, 20, 270, 180), pixmap=pix)
    doc.save(path); doc.close()


def make_vector(path):
    """Vector artwork (drawings) plus a caption — pass."""
    doc = pymupdf.open(); page = doc.new_page(width=300, height=200)
    page.draw_circle(pymupdf.Point(150, 90), 50, color=(0.1, 0.1, 0.1), fill=(0.9, 0.7, 0.2))
    page.draw_rect(pymupdf.Rect(40, 40, 90, 90), color=(0, 0, 0))
    page.insert_text(pymupdf.Point(110, 180), "Prent", **_kw(12))
    doc.save(path); doc.close()


def make_cropmarked(path):
    """Page with crop marks near the corners + centred content — pass (content
    sits well inside trim; crop marks are thin vector lines in the bleed)."""
    doc = pymupdf.open(); page = doc.new_page(width=320, height=220)
    for (x, y, dx, dy) in [(10, 10, 20, 0), (10, 10, 0, 20),
                           (310, 10, -20, 0), (310, 10, 0, 20)]:
        page.draw_line(pymupdf.Point(x, y), pymupdf.Point(x + dx, y + dy), width=0.5)
    page.insert_text(pymupdf.Point(120, 110), "Inhoud", **_kw(14))
    doc.save(path); doc.close()


def make_landscape(path):
    doc = pymupdf.open(); page = doc.new_page(width=400, height=240)  # landscape
    page.insert_text(pymupdf.Point(40, 120), "Landskap bladsy", **_kw(16))
    doc.save(path); doc.close()


def make_double_page(path):
    """Double-page spread: a centre gutter divider + content on each half — pass."""
    doc = pymupdf.open(); page = doc.new_page(width=480, height=220)
    page.draw_line(pymupdf.Point(240, 10), pymupdf.Point(240, 210), width=0.5)
    page.insert_text(pymupdf.Point(60, 110), "Links", **_kw(14))
    page.insert_text(pymupdf.Point(300, 110), "Regs", **_kw(14))
    doc.save(path); doc.close()


# name -> (builder, expected_gate_ok, page_type_for_gate)
FAMILIES = {
    "gate_good": (make_good, True, "vocabulary"),
    "gate_bad": (make_bad, False, "vocabulary"),
    "gate_centered": (make_centered, True, "story"),
    "gate_multicol": (make_multicol, True, "vocabulary"),
    "gate_longword": (make_longword, False, "vocabulary"),
    "gate_merged_cells": (make_merged_cells, True, "vocabulary"),
    "gate_borderless": (make_borderless, True, "vocabulary"),
    "gate_fourcol": (make_fourcol, True, "vocabulary"),
    "gate_justified": (make_justified, True, "story"),
    "gate_text_over_illustration": (make_text_over_illustration, True, "story"),
    "gate_rotated": (make_rotated, True, "story"),
    "gate_long_translation_80pct": (make_long_translation_80pct, False, "vocabulary"),
    "gate_mixed_fonts": (make_mixed_fonts, True, "story"),
    "gate_missing_font": (make_missing_font, True, "story"),
    "gate_rtl": (make_rtl, True, "story"),
    "gate_complex_script": (make_complex_script, True, "story"),
    "gate_scanned": (make_scanned, True, "story"),
    "gate_vector": (make_vector, True, "story"),
    "gate_cropmarked": (make_cropmarked, True, "story"),
    "gate_landscape": (make_landscape, True, "story"),
    "gate_double_page": (make_double_page, True, "story"),
}


def build_all():
    written = []
    for name, (builder, _ok, _pt) in FAMILIES.items():
        path = os.path.join(HERE, f"{name}.pdf")
        builder(path)
        written.append(name)
    return written


if __name__ == "__main__":
    names = build_all()
    print(f"golden layout-family fixtures written: {len(names)}")
    for n in names:
        print("  ", n)
