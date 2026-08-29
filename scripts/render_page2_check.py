"""Render page 2 of the translated PDF for visual check."""
import pymupdf

doc = pymupdf.open(r'C:\Users\zande\Documents\Digital Bookstore\bookstore\storage\app\public\books\translated\2_af.pdf')
page = doc[1]  # Page 2
pix = page.get_pixmap(dpi=150)
pix.save(r'C:\Users\zande\Documents\Digital Bookstore\page2_translated_fixed.png')
print(f'Saved page 2 translated (fixed) - {pix.width}x{pix.height}')
doc.close()
