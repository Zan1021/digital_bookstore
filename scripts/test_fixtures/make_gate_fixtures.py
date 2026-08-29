"""
Generate persisted golden fixtures for the render-gate tests (brief §17.2/§17.3).
Creates two small PDFs:
  gate_good.pdf — text well inside a single cell (must PASS the gate)
  gate_bad.pdf  — a word crossing a vertical grid line (must FAIL the gate)
Run once: python scripts/test_fixtures/make_gate_fixtures.py
"""
import os
import pymupdf

HERE = os.path.dirname(os.path.abspath(__file__))


def _font():
    fdir = os.path.join(os.path.dirname(os.path.dirname(HERE)), "storage", "app", "fonts")
    if os.path.isdir(fdir):
        for f in os.listdir(fdir):
            if f.lower().endswith((".ttf", ".otf")):
                return os.path.join(fdir, f)
    return None


def make_good(path):
    doc = pymupdf.open()
    page = doc.new_page(width=300, height=200)
    page.draw_line(pymupdf.Point(150, 20), pymupdf.Point(150, 180), width=1)
    ff = _font()
    kw = dict(fontsize=12, fontname="F0", fontfile=ff) if ff else dict(fontsize=12)
    page.insert_text(pymupdf.Point(20, 100), "huis", **kw)      # left cell, fits
    page.insert_text(pymupdf.Point(170, 100), "kat", **kw)      # right cell, fits
    doc.save(path); doc.close()


def make_bad(path):
    doc = pymupdf.open()
    page = doc.new_page(width=300, height=200)
    page.draw_line(pymupdf.Point(150, 20), pymupdf.Point(150, 180), width=1)
    ff = _font()
    kw = dict(fontsize=20, fontname="F0", fontfile=ff) if ff else dict(fontsize=20)
    page.insert_text(pymupdf.Point(120, 100), "tafeltennis", **kw)  # crosses x=150
    doc.save(path); doc.close()


if __name__ == "__main__":
    make_good(os.path.join(HERE, "gate_good.pdf"))
    make_bad(os.path.join(HERE, "gate_bad.pdf"))
    print("fixtures written:", os.path.join(HERE, "gate_good.pdf"), "+ gate_bad.pdf")



def make_centered(path):
    """Centred single-column text — must pass."""
    doc = pymupdf.open(); page = doc.new_page(width=300, height=160)
    ff = _font(); kw = dict(fontsize=14, fontname="F0", fontfile=ff) if ff else dict(fontsize=14)
    page.insert_text(pymupdf.Point(110, 80), "Titel", **kw)
    doc.save(path); doc.close()


def make_multicol(path):
    """Three-column layout with words inside each cell — must pass."""
    doc = pymupdf.open(); page = doc.new_page(width=360, height=200)
    for vx in (120, 240):
        page.draw_line(pymupdf.Point(vx, 20), pymupdf.Point(vx, 180), width=1)
    ff = _font(); kw = dict(fontsize=11, fontname="F0", fontfile=ff) if ff else dict(fontsize=11)
    page.insert_text(pymupdf.Point(20, 60), "huis", **kw)
    page.insert_text(pymupdf.Point(140, 60), "kat", **kw)
    page.insert_text(pymupdf.Point(260, 60), "boom", **kw)
    doc.save(path); doc.close()


def make_long_translation(path):
    """A very long word in a narrow column that crosses the border — must FAIL."""
    doc = pymupdf.open(); page = doc.new_page(width=200, height=140)
    page.draw_line(pymupdf.Point(100, 10), pymupdf.Point(100, 130), width=1)
    ff = _font(); kw = dict(fontsize=18, fontname="F0", fontfile=ff) if ff else dict(fontsize=18)
    page.insert_text(pymupdf.Point(70, 70), "Onafhanklikheidsverklaring", **kw)
    doc.save(path); doc.close()


if __name__ == "__main__":
    import os as _os
    here = _os.path.dirname(_os.path.abspath(__file__))
    make_centered(_os.path.join(here, "gate_centered.pdf"))
    make_multicol(_os.path.join(here, "gate_multicol.pdf"))
    make_long_translation(_os.path.join(here, "gate_longword.pdf"))
    print("layout-family fixtures written")
