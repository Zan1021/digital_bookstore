#!/usr/bin/env python3
"""
Build a clean, normalized per-language wordlist asset for the phonics validity gate.

Input : a Hunspell .dic file (first line is a count; each entry is "word/FLAGS").
Output: a plain UTF-8 text file, one lowercase word per line, sorted & de-duplicated,
        containing only single alphabetic tokens (no numbers, no multi-word phrases,
        no proper-noun-only artefacts beyond what the dictionary itself carries).

This is intentionally a VALIDITY/FLAGGING asset, not an authority: the phonics gate
treats a word absent from the list as "flag for human review", never as a hard reject,
so imperfect coverage degrades gracefully.

Usage:
    python build_phonics_wordlist.py <input.dic> <output.txt>
"""
import sys
import re
import unicodedata

# Afrikaans letters incl. accented vowels (ë, ê, ï, î, ô, û, á, é, í, ó, ú, à, è).
WORD_RE = re.compile(r"^[a-zàáâäèéêëìíîïòóôöùúûü'-]+$")


def normalize(line: str) -> str | None:
    # Hunspell entries: "word/FLAGS" or "word". Strip the affix flags.
    word = line.split("/", 1)[0].strip()
    if not word:
        return None
    # NFC-normalize so composed/decomposed accents compare equal.
    word = unicodedata.normalize("NFC", word).lower()
    # Keep only single alphabetic tokens (drop numbers, abbreviations w/ dots, phrases).
    if " " in word or "." in word:
        return None
    if not WORD_RE.match(word):
        return None
    if len(word) < 2:  # drop single letters
        return None
    return word


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: build_phonics_wordlist.py <input.dic> <output.txt>", file=sys.stderr)
        return 2
    src, dst = sys.argv[1], sys.argv[2]
    words: set[str] = set()
    with open(src, "r", encoding="utf-8", errors="replace") as fh:
        lines = fh.read().splitlines()
    # First line of a .dic is a word count — skip if it's purely numeric.
    start = 1 if lines and lines[0].strip().isdigit() else 0
    for line in lines[start:]:
        w = normalize(line)
        if w:
            words.add(w)
    out = sorted(words)
    with open(dst, "w", encoding="utf-8") as fh:
        fh.write("\n".join(out) + "\n")
    print(f"Wrote {len(out)} words to {dst}")
    # Sanity: report presence of common children's phonics words.
    probes = ["koek", "boek", "soek", "huis", "muis", "duif", "hout", "koud",
              "reis", "meisie", "reus", "neus", "deur", "maan", "kraal",
              "lekker", "bakker", "trein", "eier", "slang", "lang"]
    present = [p for p in probes if p in words]
    missing = [p for p in probes if p not in words]
    print(f"probe present ({len(present)}/{len(probes)}): {', '.join(present)}")
    print(f"probe MISSING: {', '.join(missing) if missing else '(none)'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
