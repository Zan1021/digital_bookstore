# Font Registry

All children's book text is rendered using **Playpen Sans** as the primary font.
This is a free, open-source variable handwriting font by TypeTogether that replaces
Comic Sans and all other legacy fonts.

## Primary Fonts

| Font | Weight | Usage |
|------|--------|-------|
| PlaypenSans-Regular.ttf | 400 | Normal story text |
| PlaypenSans-Medium.ttf | 500 | Decorative / craft text |
| PlaypenSans-SemiBold.ttf | 600 | Emphasis |
| PlaypenSans-Bold.ttf | 700 | Titles, headings |
| Grade1Font.ttf | 400 | Early reader books (grade 1) |

## Font Strategy

**ALL** story text (font size >= 12pt) is rendered with Playpen Sans, regardless of
what font the original PDF uses. This ensures:

- Consistent visual appearance across all books
- Proper SA language support (Pedi, Venda diacritics)
- Beautiful handwriting style appropriate for children's books
- No dependency on Google Fonts API or external downloads

## Font Resolution Order

1. Exact local file match (by PDF font name)
2. Known mapping → Playpen Sans variant (see font_resolver.py)
3. Default: PlaypenSans-Regular.ttf
4. Last resort: built-in Helvetica (should never reach this)

## Legacy Fonts (kept for backward compatibility)

- Edu-Aid.ttf — was primary story text in Kolulu books
- AdLibBT-Regular.ttf — title font
- OzHandicraftBT-Roman.ttf — publisher name
- ComicSansMS.ttf — copyright text
- Kalam.ttf, PatrickHand-Regular.ttf, Calibri.ttf, PlaywriteZA.ttf

## Language Support (Playpen Sans)

Supports 150+ Latin-based languages plus:
- Greek, Cyrillic, Arabic, Devanagari, Hebrew, Thai
- South African diacritics (Pedi, Venda)
- All 11 official SA languages

## Adding Custom Fonts

Place TTF/OTF files here. The font resolver matches by filename (case-insensitive).
Publishers can also upload fonts through the admin panel.
