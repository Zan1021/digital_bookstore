---
inclusion: always
---

# V8 Structure-Aware Engine — Steering

These are standing rules for all work on the Digital Bookstore V8 PDF translation
engine. They exist because the engine has repeatedly produced layout defects (mis-
grouped text, un-centered/overflowing headers, inconsistent sizes, wrong fonts) that a
human had to catch by eye. The fix is architectural: the engine must be STRUCTURE-AWARE
and COMPARE-DRIVEN.

## Core principles

1. **Structure before pixels.** Every rendered element must trace back to a SOURCE
   structural element with a stable ID and a role. Never render text whose identity and
   target box were guessed from line gaps alone.

2. **Compare the output to the source.** After rendering, the engine MUST verify each
   element against its source counterpart: same element present, inside the correct
   box, aligned as the source was (h + v), at a size consistent with its peers. Any
   deviation flags the page — the engine catches its own mistakes, not the user.

3. **Translation is per element, not per page.** The live render path is driven by the
   stable-ID contract (one translation per source unit). Flat per-page text is a
   deprecated fallback only; it must never silently re-segment by line.

4. **Book-agnostic, always (R1).** No logic keyed to a specific title, page number,
   language, coordinate, or sample file. Everything derives from the detected structure
   and geometry of the uploaded PDF. Must be verified on >=2 different books before any
   task is called done.

5. **No hand-styling (Captain Zan's rule).** The engine places elements by MIRRORING the
   source (font, weight, casing, alignment, size, cell box). We do not impose our own
   design choices or hardcoded positions.

6. **No legacy path leaking in (R2).** When a structure-driven path replaces a heuristic
   one, remove or fully isolate the old path. No two competing renderers/mappers live at
   once.

7. **Fail closed (§13).** If structure can't be resolved or the comparison gate fails,
   the page is NEEDS_LAYOUT_REVIEW and NOT publishable. Never silently approve.

8. **Reliable text primitive.** Use `insert_text` with an explicit font file for font-
   critical text (insert_htmlbox falls back to CharisSIL and/or corrupts the ToUnicode
   text layer on PyMuPDF 1.28.2). Correct glyphs AND a real, searchable text layer are
   both required.

## Definition of done (every V8 task)
- Book-agnostic (verified on >=2 books).
- The structural comparison gate passes for the affected element class, OR correctly
  flags a genuine defect (with a test proving both the pass and the flag).
- Full test suite green; a regression test added for the specific defect fixed.
- No hand-tuned constants tied to one book; no legacy path still live.
- Change described honestly: what was verified, what was not.

## Anti-patterns (do NOT do)
- Tuning a heuristic repeatedly to satisfy one screenshot. If a heuristic needs a 3rd
  tweak, step back and model the structure instead.
- Adding a fix that makes the current sample pass but has no gate/test to catch the next
  structural case.
- Re-segmenting flat translated text by line gaps.
