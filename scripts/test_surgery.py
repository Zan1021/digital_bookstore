"""Test content-stream surgery on page 15."""
import sys, os, json
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf
from content_stream_surgery import analyze_page_content_stream, remove_spans_by_surgery

pdf_path = r'C:\Users\zande\Documents\Digital Bookstore\Kolulu Engl Series 3 - 2 - A Fun Place.pdf'
doc = pymupdf.open(pdf_path)
page = doc[14]  # page 15 (vocabulary)

print("=" * 50)
print("CONTENT STREAM ANALYSIS — Page 15")
print("=" * 50)

analysis = analyze_page_content_stream(page)
print(f"Content streams: {analysis['content_streams']}")
print(f"Total operators: {analysis['total_operators']}")
print(f"Text operators: {analysis['text_operators_count']}")
print(f"\nFirst 10 text operations:")
for i, op in enumerate(analysis['text_operations'][:10]):
    print(f"  {i+1}. [{op['operator']}] \"{op['text'][:50]}\"")

print(f"\nTotal text operations found: {len(analysis['text_operations'])}")
doc.close()
