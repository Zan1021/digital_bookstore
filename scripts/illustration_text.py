#!/usr/bin/env python3
"""
illustration_text.py — TEXT-ON-ILLUSTRATION post-processor (deterministic half).

Spec: .kiro/specs/illustration-text-vision/{requirements,design}.md
Brief: Brief/kiro-pdf-translation-cover-fix.md §4 (text over illustrations), §5.

Handles text that is BAKED INTO a raster illustration (pixels, not PDF text objects),
which the V8 contract renderer cannot redact/replace. This script does the DETERMINISTIC
work only — no network, no numpy/cv2 (system python lacks them). GPT-4o detection and the
optional generative background reconstruction happen in PHP (IllustrationTextService),
which hands this script a validated regions.json.

Subcommands
-----------
  candidates --input <pdf>
      Print JSON list of pages (0-based) that contain raster image(s) AND have little/no
      native selectable text — the cheap pre-filter so vision calls are only spent where
      a page might have baked-in text.

  repair --input <pdf> --page N --regions <regions.json> --translations <t.json>
         --fonts-dir <dir> --output <pdf> [--ppi 300] [--generative-bg <png>]
      Erase the located text regions from page N's artwork (deterministic inpaint, reusing
      image_inpainting primitives) and overlay the translated text as live PDF vector text.
      When --generative-bg is supplied (a PHP-reconstructed background PNG for the whole
      page), it is composited via the region masks instead of interpolation.

regions.json schema (produced + validated by PHP; coords already in PIXEL space of a page
render at the given --ppi, top-left origin):
  {
    "ppi": 300,
    "regions": [
      {
        "source_text": "My Senses",
        "target_text": "My Sintuie",
        "bbox_px": [x0, y0, x1, y1],          # text glyph+shadow bounds, pixels
        "background_type": "flat|gradient|illustration",
        "color_rgb": [241, 86, 76],           # measured background colour (PHP-measured)
        "align": "left|center|right",
        "source_font_pt": 28.0                # source glyph size in PDF points (optional)
      }, ...
    ]
  }

All coordinates are validated by the caller; this script re-validates defensively.
Reports JSON to stderr; prints the output path to stdout on success.
"""

import argparse
import json
import os
import sys
import tempfile

import pymupdf

# Reuse the existing deterministic inpaint primitives (pure PyMuPDF, no numpy).
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from image_inpainting import create_text_mask, inpaint_region  # noqa: E402

try:
    from text_fit_solver import solve_text_fit, FitConstraints  # noqa: E402
    _HAS_FIT = True
except Exception:  # pragma: no cover - fit solver optional at import time
    _HAS_FIT = False


# --------------------------------------------------------------------------- #
# Robust inpaint (halo-free) — local to this module so the shared
# image_inpainting.py used elsewhere is untouched.
# --------------------------------------------------------------------------- #
def _median(vals):
    s = sorted(vals)
    n = len(s)
    if n == 0:
        return 0
    return s[n // 2] if n % 2 else (s[n // 2 - 1] + s[n // 2]) // 2


def _robust_fill(pix, x0, y0, x1, y1, ppi):
    """Fill [x0..x1, y0..y1] cleanly, avoiding the glyph-shadow halo the naive edge-fill
    produced. Strategy:
      - Sample a ring OFFSET well outside the box (gap = shadow-safe margin), not the
        adjacent row, so glyph shadows don't contaminate the sample.
      - Use per-channel MEDIAN (rejects dark shadow outliers).
      - If the ring is uniform -> solid median fill (best for signs/flat panels).
      - If textured -> vertical interpolation between CLEAN top/bottom offset strips.
    """
    gap = max(3, int(ppi / 40))         # push sampling away from the shadow
    band = max(2, int(ppi / 120))       # thickness of the sampled strip
    W, H = pix.width, pix.height

    def strip(y):
        cols = []
        for x in range(max(0, x0), min(W, x1 + 1)):
            cols.append(pix.pixel(x, max(0, min(H - 1, y))))
        return cols

    top_ys = [y0 - gap - i for i in range(band)]
    bot_ys = [y1 + gap + i for i in range(band)]
    top_samples, bot_samples = [], []
    for yy in top_ys:
        if 0 <= yy < H:
            top_samples += strip(yy)
    for yy in bot_ys:
        if 0 <= yy < H:
            bot_samples += strip(yy)
    ring = top_samples + bot_samples
    if not ring:
        # fall back to the module's simple solid fill
        inpaint_region(pix, x0, y0, x1, y1, method="solid_fill")
        return

    med = (_median([c[0] for c in ring]), _median([c[1] for c in ring]),
           _median([c[2] for c in ring]))

    top_med = None
    bot_med = None
    if top_samples:
        top_med = (_median([c[0] for c in top_samples]), _median([c[1] for c in top_samples]),
                   _median([c[2] for c in top_samples]))
    if bot_samples:
        bot_med = (_median([c[0] for c in bot_samples]), _median([c[1] for c in bot_samples]),
                   _median([c[2] for c in bot_samples]))

    # If the top and bottom rings are very different, a real EDGE runs through the box
    # (e.g. the sign's bottom plank meets the grass). Do NOT blend across it — that makes a
    # muddy band. Instead fill from whichever side is the LARGER uniform area (the sign),
    # i.e. extend the dominant side's colour across the whole box.
    if top_med and bot_med:
        side_dist = sum(abs(top_med[i] - bot_med[i]) for i in range(3))
    else:
        side_dist = 0

    # dispersion within the whole ring: uniform background?
    spread = 0
    for ch in range(3):
        vals = [c[ch] for c in ring]
        spread += (max(vals) - min(vals))
    uniform = spread < 90

    if side_dist > 120 and (top_med or bot_med):
        # Edge through the box: pick the side with the LARGER clean strip and extend it.
        chosen = top_med if len(top_samples) >= len(bot_samples) else bot_med
        chosen = chosen or top_med or bot_med
        for y in range(y0, y1 + 1):
            for x in range(x0, x1 + 1):
                if 0 <= x < W and 0 <= y < H:
                    pix.set_pixel(x, y, chosen)
        return

    if uniform or not top_med or not bot_med:
        fill = top_med or bot_med or med
        for y in range(y0, y1 + 1):
            for x in range(x0, x1 + 1):
                if 0 <= x < W and 0 <= y < H:
                    pix.set_pixel(x, y, fill)
        return

    # textured but continuous: interpolate between the CLEAN offset strips
    h = max(1, y1 - y0)
    for y in range(y0, y1 + 1):
        t = (y - y0) / h
        col = (int(top_med[0] * (1 - t) + bot_med[0] * t),
               int(top_med[1] * (1 - t) + bot_med[1] * t),
               int(top_med[2] * (1 - t) + bot_med[2] * t))
        for x in range(x0, x1 + 1):
            if 0 <= x < W and 0 <= y < H:
                pix.set_pixel(x, y, col)


# --------------------------------------------------------------------------- #
# candidates
# --------------------------------------------------------------------------- #
def find_candidate_pages(pdf_path):
    """Pages (0-based) that have >=1 raster image and little native text. These are the
    only pages worth a vision detect call. Book-agnostic heuristic."""
    doc = pymupdf.open(pdf_path)
    out = []
    for i in range(doc.page_count):
        page = doc[i]
        images = page.get_images()
        if not images:
            continue
        # Native selectable text length — a page dense with real text is handled by the
        # contract renderer, not here. Low/zero text + images = baked-text candidate.
        text_len = len(page.get_text().strip())
        # image area fraction: does an image dominate the page?
        page_area = abs(page.rect.width * page.rect.height) or 1.0
        img_area = 0.0
        for img in images:
            for r in page.get_image_rects(img[0]):
                img_area += abs(r.width * r.height)
        frac = min(img_area / page_area, 1.0)
        if frac >= 0.25 and text_len < 400:
            out.append({"page": i, "image_area_frac": round(frac, 3), "native_text_len": text_len})
    doc.close()
    return out


# --------------------------------------------------------------------------- #
# repair
# --------------------------------------------------------------------------- #
def _validate_region(r, pw, ph):
    """Defensive re-validation of a caller-supplied region. Returns cleaned bbox or None."""
    b = r.get("bbox_px")
    if not (isinstance(b, (list, tuple)) and len(b) == 4):
        return None
    x0, y0, x1, y1 = (float(v) for v in b)
    if x1 <= x0 or y1 <= y0:
        return None
    # clamp to image bounds
    x0 = max(0.0, min(x0, pw - 1))
    y0 = max(0.0, min(y0, ph - 1))
    x1 = max(0.0, min(x1, pw - 1))
    y1 = max(0.0, min(y1, ph - 1))
    if x1 <= x0 or y1 <= y0:
        return None
    return [x0, y0, x1, y1]


def repair_page(input_pdf, page_index, regions_data, translations, fonts_dir,
                output_pdf, ppi=300, generative_bg=None):
    report = {"page_index": page_index, "ppi": ppi, "regions_in": 0,
              "regions_applied": 0, "skipped": [], "overflow": [], "modified": False}

    regions = regions_data.get("regions", [])
    report["regions_in"] = len(regions)

    same_path = os.path.abspath(input_pdf) == os.path.abspath(output_pdf)
    fd, tmp_out = tempfile.mkstemp(
        suffix=".pdf", dir=os.path.dirname(os.path.abspath(output_pdf)) or None)
    os.close(fd)

    doc = pymupdf.open(input_pdf)
    if page_index < 0 or page_index >= doc.page_count:
        report["error"] = f"page_index {page_index} out of range ({doc.page_count})"
        doc.close()
        os.remove(tmp_out)
        print(json.dumps(report), file=sys.stderr)
        print(output_pdf)
        return report

    page = doc[page_index]
    rect = page.rect
    zoom = ppi / 72.0
    mat = pymupdf.Matrix(zoom, zoom)

    # Render the page to an opaque raster we will repair, then re-embed.
    pix = page.get_pixmap(matrix=mat, alpha=False)
    pw, ph = pix.width, pix.height

    # ERASE FIRST, ALWAYS (deterministic). This GUARANTEES the source text is gone
    # regardless of whether a generative background is supplied — the earlier bug was
    # overlaying translated text without a reliable erase. Generative is a refinement
    # layered on top of this clean base, never the sole eraser.
    for r in regions:
        bbox = _validate_region(r, pw, ph)
        if bbox is None:
            report["skipped"].append({"reason": "invalid bbox", "region": r.get("source_text")})
            continue
        bg_type = r.get("background_type", "illustration")
        if bg_type == "uncertain":
            report["skipped"].append({"reason": "uncertain background", "region": r.get("source_text")})
            continue
        mask = create_text_mask(pix, [bbox], padding=max(4, int(ppi / 60)))
        for region in mask:
            if bg_type == "flat" and r.get("color_rgb"):
                x0, y0, x1, y1 = region
                col = tuple(int(c) for c in r["color_rgb"])
                for y in range(y0, y1 + 1):
                    for x in range(x0, x1 + 1):
                        if 0 <= x < pix.width and 0 <= y < pix.height:
                            pix.set_pixel(x, y, col)
            else:
                # halo-free robust fill (offset ring + median), not naive edge_fill
                _robust_fill(pix, region[0], region[1], region[2], region[3], ppi)
    report["erased"] = True

    # OPTIONAL generative refinement: blend the model-reconstructed background over the
    # already-erased regions for nicer texture. The text is ALREADY gone, so even a poor
    # generative result cannot re-introduce it. Composited only inside the region boxes.
    if generative_bg and os.path.isfile(generative_bg):
        try:
            gen = pymupdf.Pixmap(generative_bg)
            if gen.alpha:
                gen = pymupdf.Pixmap(gen, 0)
            applied = 0
            for r in regions:
                bbox = _validate_region(r, pw, ph)
                if bbox is None or r.get("background_type") == "uncertain":
                    continue
                pad = max(2, int(ppi / 100))
                x0 = max(0, int(bbox[0]) - pad); y0 = max(0, int(bbox[1]) - pad)
                x1 = min(pw - 1, int(bbox[2]) + pad); y1 = min(ph - 1, int(bbox[3]) + pad)
                # only copy generative pixels that are within the returned image bounds
                if x1 < gen.width and y1 < gen.height:
                    for y in range(y0, y1 + 1):
                        for x in range(x0, x1 + 1):
                            pix.set_pixel(x, y, gen.pixel(x, y))
                    applied += 1
            report["generative_bg"] = True
            report["generative_regions"] = applied
        except Exception as e:
            report["skipped"].append(f"generative composite failed: {e}; kept deterministic erase")

    img_bytes = pix.tobytes("png")

    # Rebuild the page from the repaired raster (guarantees no baked English survives),
    # preserving geometry & rotation.
    new_doc = pymupdf.open()
    if page.rotation in (90, 270):
        npw, nph = rect.height, rect.width
    else:
        npw, nph = rect.width, rect.height
    new_page = new_doc.new_page(width=npw, height=nph)
    new_page.insert_image(new_page.rect, stream=img_bytes, keep_proportion=False)

    # Overlay translated vector text on each region.
    px_to_pt = 72.0 / ppi
    for r in regions:
        bbox = _validate_region(r, pw, ph)
        if bbox is None or r.get("background_type") == "uncertain":
            continue
        target = (r.get("target_text") or translations.get(r.get("source_text", ""), "")).strip()
        if not target:
            continue
        # region bounds in PDF points
        x0, y0, x1, y1 = (v * px_to_pt for v in bbox)
        cw, ch = (x1 - x0), (y1 - y0)
        src_pt = float(r.get("source_font_pt") or max(8.0, ch * 0.8))
        align = r.get("align", "left")

        font_path = _pick_font(fonts_dir)
        color = _normalize_color(r.get("text_color_rgb") or r.get("color_text") or [255, 245, 200])

        if _HAS_FIT:
            # Per-line region: force SINGLE line (no wrap/hyphenation), fit to the box.
            fit = solve_text_fit(
                target,
                FitConstraints(container_width=cw, container_height=ch,
                               source_font_size=src_pt, alignment=align,
                               allow_multiline=False, single_word=True,
                               min_font_size=6.0, max_shrink_ratio=0.9),
                font_path=font_path,
            )
            if fit.overflow:
                report["overflow"].append({"region": r.get("source_text"), "text": target})
            font_size = fit.font_size
            # never wrap a single detected line — draw it as one line
            lines = [target]
        else:
            font_size = src_pt
            lines = [target]

        _draw_lines(new_page, lines, x0, y0, cw, ch, font_size, font_path, color, align)
        report["regions_applied"] += 1

    # Replace the page.
    doc.delete_page(page_index)
    doc.insert_pdf(new_doc, from_page=0, to_page=0, start_at=page_index)
    new_doc.close()
    report["modified"] = report["regions_applied"] > 0 or bool(report.get("generative_bg"))

    doc.save(tmp_out, garbage=4, deflate=True)
    doc.close()
    os.replace(tmp_out, output_pdf)

    print(json.dumps(report, ensure_ascii=False), file=sys.stderr)
    print(output_pdf)
    return report


def _pick_font(fonts_dir):
    """Pick a usable embedded font with Latin/Afrikaans glyphs from fonts_dir."""
    if fonts_dir and os.path.isdir(fonts_dir):
        prefer = ("comic", "sans", "regular", "book", "text")
        cands = [f for f in os.listdir(fonts_dir) if f.lower().endswith((".ttf", ".otf"))]
        cands.sort(key=lambda f: (0 if any(p in f.lower() for p in prefer) else 1, f))
        if cands:
            return os.path.join(fonts_dir, cands[0])
    return None


def _normalize_color(c):
    try:
        r, g, b = (int(v) for v in c[:3])
        return (r / 255.0, g / 255.0, b / 255.0)
    except Exception:
        return (1.0, 0.96, 0.78)


def _draw_lines(page, lines, x0, y0, cw, ch, font_size, font_path, color, align):
    """Draw lines inside the box (x0,y0,cw,ch), vertically centred, honouring alignment."""
    font = pymupdf.Font(fontfile=font_path) if (font_path and os.path.isfile(font_path)) else pymupdf.Font("helv")
    fontname = "F_ill"
    try:
        page.insert_font(fontname=fontname, fontfile=font_path) if font_path else None
    except Exception:
        fontname = "helv"
    line_h = font_size * 1.3
    total_h = line_h * len(lines)
    # vertical centring: first baseline
    y = y0 + max(font_size, (ch - total_h) / 2.0 + font_size)
    for ln in lines:
        w = font.text_length(ln, fontsize=font_size)
        if align == "center":
            x = x0 + (cw - w) / 2.0
        elif align == "right":
            x = x0 + (cw - w)
        else:
            x = x0
        page.insert_text((x, y), ln, fontsize=font_size,
                         fontname=fontname if font_path else "helv",
                         fontfile=font_path if font_path else None, color=color)
        y += line_h


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #
def main():
    ap = argparse.ArgumentParser(description="Text-on-illustration deterministic post-processor")
    sub = ap.add_subparsers(dest="command")

    c = sub.add_parser("candidates")
    c.add_argument("--input", "-i", required=True)

    r = sub.add_parser("repair")
    r.add_argument("--input", "-i", required=True)
    r.add_argument("--page", "-p", type=int, required=True, help="0-based page index")
    r.add_argument("--regions", required=True, help="regions.json path")
    r.add_argument("--translations", help="translations json (source->target fallback)")
    r.add_argument("--fonts-dir", default="")
    r.add_argument("--output", "-o", required=True)
    r.add_argument("--ppi", type=int, default=300)
    r.add_argument("--generative-bg", default=None)

    args = ap.parse_args()

    if args.command == "candidates":
        print(json.dumps(find_candidate_pages(args.input), ensure_ascii=False))
    elif args.command == "repair":
        with open(args.regions, "r", encoding="utf-8") as f:
            regions_data = json.load(f)
        translations = {}
        if args.translations and os.path.isfile(args.translations):
            with open(args.translations, "r", encoding="utf-8") as f:
                translations = json.load(f)
        repair_page(args.input, args.page, regions_data, translations,
                    args.fonts_dir, args.output, ppi=args.ppi,
                    generative_bg=args.generative_bg)
    else:
        ap.print_help()


if __name__ == "__main__":
    main()
