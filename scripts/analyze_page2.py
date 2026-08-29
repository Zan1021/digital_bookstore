"""Analyze page 2 layout of the original PDF."""
import pymupdf
import json

doc = pymupdf.open(r'C:\Users\zande\Documents\Digital Bookstore\Kolulu Engl Series 3 - 2 - A Fun Place.pdf')
page = doc[1]  # Page 2

print(f'Page size: {page.rect.width} x {page.rect.height}')
print()

# Get images
print('IMAGES:')
for img in page.get_image_info():
    print(f'  bbox: {img["bbox"]}  size: {img.get("width","?")}x{img.get("height","?")}')

print()

# Get text blocks with positions
print('TEXT SPANS:')
text_dict = page.get_text('dict', flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
for block in text_dict.get('blocks', []):
    if block.get('type') != 0:
        continue
    for line in block.get('lines', []):
        for span in line.get('spans', []):
            text = span['text'].strip()
            if text:
                bbox = span['bbox']
                print(f'  [{bbox[0]:.0f},{bbox[1]:.0f},{bbox[2]:.0f},{bbox[3]:.0f}] size={span["size"]:.1f} font={span["font"]} text="{text[:60]}"')

print()

# Get drawings (lines, rects)
print('DRAWINGS:')
for d in page.get_drawings():
    r = d.get('rect')
    print(f'  rect=({r.x0:.0f},{r.y0:.0f},{r.x1:.0f},{r.y1:.0f}) items={len(d.get("items",[]))}')

doc.close()
