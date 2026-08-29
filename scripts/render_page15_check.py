"""Render page 15 (vocabulary) of the translated PDF for visual check."""
import pymupdf

doc = pymupdf.open(r'C:\Users\zande\Documents\Digital Bookstore\bookstore\storage\app\public\books\translated\2_af.pdf')
page = doc[14]  # Page 15 (0-indexed)
pix = page.get_pixmap(dpi=150)
pix.save(r'C:\Users\zande\Documents\Digital Bookstore\page15_vocab_fixed.png')
print(f'Saved page 15 vocabulary (fixed) - {pix.width}x{pix.height}')
doc.close()
