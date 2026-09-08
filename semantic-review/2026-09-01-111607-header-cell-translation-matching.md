# Source-span assembly for header-cell translation matching

The proposed fix targets a real, correctly-diagnosed bug: detected header cells carry *grouped* source text (`"HIGH FREQUENCY WORDS"`) while the translation contract is keyed by *individual* source spans (`"HIGH FREQUENCY"`, `"WORDS"`). The grouped key misses `src_to_trans`, `_translation_for_cell` falls through to reading-order (`header_translations[order_idx]`), and reading order does not correspond to cell order once merged/multi-line cells exist — producing the observed swap. Assembling a cell's translation from the contract entries of the individual source spans that physically fall inside the cell is the right shape of fix: it matches on the same unit the contract is keyed by, and it is book-agnostic because it uses glyph geometry rather than header strings. The approach is sound. Whether it actually renders as two lines, and whether the span→translation matching is unambiguous, are where the risk lives.

**Watch for:** the renderer (`draw_paragraph_text`) collapses newlines via `text.split()`, so "join multi-line spans as separate lines" will not preserve the source's two-line structure without a renderer change (confirmed); duplicate `"WORDS"` consumed by `pop(0)` off a page-global bucket can hand the wrong translation to the wrong cell (confirmed); and per-span matching must reuse the same normalization + ordered-consumption discipline the cell-level path uses or duplicates will double-count (likely).

**Verdict:** NEEDS_CHANGES

## High-level view

The matching layer is correct in principle once you assemble per-span. The contract bucket (`_contract_source_to_translation`) is already an *ordered list per normalized source string*, built to support duplicates, and `_place_vocab_headers` already reuses those same buckets for the manifest bridge in `render_vocabulary_page_v8`. That shared mutable state is the main hazard: two independent consumers (`_translation_for_cell` and the manifest bridge) each `pop`/index the same buckets. Assembling headers from constituent spans multiplies the number of `pop(0)` calls against `"words"`, so the disambiguation between the big centered `"WORDS"` and the stacked `"...WORDS"` cannot be left to arrival order — it must be resolved by geometry (which span sits in which cell) before consuming a translation.

The line-structure requirement is not satisfiable at the matching layer alone. `draw_paragraph_text` splits on whitespace and re-wraps to width, so a `"HIGH FREQUENCY\nWORDS"` string renders identically to `"HIGH FREQUENCY WORDS"` — the newline is lost. Preserving the source's two-line structure requires either a renderer that honors hard breaks, or driving line count through the existing `_count_source_lines` / `FitConstraints.max_lines` path rather than through embedded newlines. The current code already computes `src_lines` and feeds `max_lines`, but that only *permits* wrapping; it does not *force* the break at the source boundary.

Span-to-cell assignment needs an explicit tie-break for spans on a cell boundary and for ordering the assembled lines top-to-bottom then left-to-right, mirroring what `_cluster_header_spans` already does when it builds the grouped label. Reusing that ordering keeps assembly consistent with detection.

<details>
<summary>Issues (5)</summary>

1. **Newlines don't survive rendering** — `draw_paragraph_text` does `text.split()` and re-wraps by width, so assembled `\n`-joined lines collapse into one wrapped paragraph. Preserve two-line structure by forcing the break through `max_lines`/a hard-break-aware render path, not by embedding `\n`.
2. **Shared bucket double-consumption** — `_contract_source_to_translation` buckets are consumed by both `_translation_for_cell` (`pop(0)`) and the manifest bridge in `render_vocabulary_page_v8` (indexed via `used`). Per-span assembly adds more consumers of the same `"words"` bucket; build a private copy for header assembly or reconcile the two consumers so a translation isn't handed out twice.
3. **Duplicate `WORDS` picks wrong translation** — `pop(0)` returns translations in insertion order, which need not match cell geometry, so the big centered `WORDS` and the stacked `...WORDS` can swap. Resolve which span belongs to which cell by geometry first, then consume that span's specific contract entry.
4. **Boundary spans** — a span whose center sits within `tolerance` of a shared edge can be claimed by two cells or none. Use a single deterministic rule (center-x strictly inside `[cx0, cx1)`, same tolerance as `detect_header_cells`) and assign each span to exactly one cell.
5. **Assembled-line ordering** — join order must be top-to-bottom then left-to-right to match the source, i.e. the same sort `_cluster_header_spans` uses (`round(y0/4), x0`). Sort assembled lines by span geometry, not by contract/dict order.

</details>

<details>
<summary>Details</summary>

### Newlines are lost in `draw_paragraph_text`

`draw_paragraph_text` normalizes its input with `text = (text or "").strip()` and then, inside the fit loop and again for the final render, `words = text.split()` followed by `_wrap_paragraph(words, ...)`. `str.split()` with no argument splits on any whitespace run including `\n`, so a payload like `"HIGH FREQUENCY\nWORDS"` becomes `["HIGH", "FREQUENCY", "WORDS"]` and is re-wrapped purely by width. The source's deliberate two-line stack is discarded; the header will wrap only if it happens not to fit on one line at the fitted size.

This means the "join multi-line spans as separate lines to preserve the source's line structure" half of the proposal cannot work through the current renderer. Two viable paths:

- Drive line count through the existing machinery. `_place_vocab_headers` already computes `src_lines = _count_source_lines(cell_spans)` and passes `max_lines=max(3, src_lines + 1)` into `FitConstraints`. But `max_lines` only *allows* multiline; it does not force a break at the source boundary. To honor a source two-line header you need the renderer to break at a specified point, not merely permit wrapping.
- Teach `draw_paragraph_text` (or a small wrapper) to treat `\n` as a hard break: split on `\n` first, wrap each segment independently, concatenate the resulting line lists. This is the minimal change that makes assembled multi-line strings render as intended, and it stays book-agnostic. Note the fit loop's `widest`/`block_h` accounting must then include the forced breaks.

Without one of these, matching can be fixed but the rendered output still won't match the source's line structure — a partial fix that looks correct at the data layer and wrong on the page.

### Duplicate `WORDS` and the shared `pop(0)` bucket

`_contract_source_to_translation` returns `{normalized_source: [translation, ...]}`, ordered "to support duplicate source words." For page 15 the `"words"` bucket holds (at least) two entries: the big centered `WORDS` and the `WORDS` line under `HIGH FREQUENCY`. `_translation_for_cell` does `src_to_trans[key].pop(0)`. Insertion order is contract iteration order over `id_to_translation.items()`, which is not guaranteed to align with left-to-right / top-to-bottom cell order. So `pop(0)` can hand the centered cell the stacked cell's translation and vice-versa — the same class of swap the fix is trying to kill, just relocated from reading-order fallback into the bucket.

Per-span assembly makes this sharper: the stacked `"HIGH FREQUENCY WORDS"` cell will itself `pop` `"words"` once, and the standalone `"WORDS"` cell pops it again. If both pop from the same shared bucket in an order decoupled from geometry, they can trade translations. The disambiguation must be geometric: determine which *source span* (by its bbox) belongs to which cell, then consume that span's own contract entry. If the contract carried a per-span id you could match directly; since it's keyed by normalized text, the safest ordering is to consume the bucket in the same top-to-bottom/left-to-right order you assign spans to cells, so bucket position and geometric position stay locked together.

Also note the bucket is mutated in place and `_place_vocab_headers` is not the only consumer: `render_vocabulary_page_v8` builds its own `contract_map = _contract_source_to_translation(...)` and consumes it via a `used` index for the manifest bridge. These are separate dict instances per call, so they don't currently collide — but if any refactor shares one map between header placement and the manifest bridge, `pop(0)` on one side corrupts the index on the other. Assemble headers from a private, freshly-built (or copied) bucket to keep header consumption isolated.

### Span-to-cell assignment and boundary spans

`_place_vocab_headers` already computes `cell_spans` by center-x containment with a `±1` fudge:

```python
cell_spans = [s for s in header_spans_all
              if cb[0] - 1 <= (s["bbox"][0] + s["bbox"][2]) / 2 <= cb[2] + 1
              and ry0 - 1 <= ... <= ry1 + 1]
```

The `-1 / +1` on both sides means a span centered exactly on a shared edge between two adjacent cells satisfies *both* cells' predicates and would be assembled into both — double-consuming its translation. `detect_header_cells` uses `tolerance=6.0` for the same containment decision, so the two layers disagree on the boundary rule. Pick one deterministic assignment: center-x in a half-open interval `[cx0, cx1)` with a single tolerance, and assign each span to exactly one cell (first match wins, or nearest-center if none). This guarantees a partition, which is what "assemble from the spans within the cell" requires to be well-defined.

### Ordering of assembled lines

When a cell contains multiple source spans (the stacked case), the assembled translation must join them top-to-bottom, then left-to-right, to mirror the source. `_cluster_header_spans` already establishes this order when it builds the grouped label:

```python
parts = sorted(g["parts"], key=lambda z: (round(z["bbox"][1] / 4), z["bbox"][0]))
```

Reuse the identical sort key for assembling the per-span translations. Joining in contract/dict order (or arbitrary `header_spans_all` order) risks emitting `"WORDS\nHIGH FREQUENCY"` — right words, wrong stack — which is a subtle regression that passes a "did the right words appear" check but fails the layout requirement.

### Fallback interaction

The reading-order fallback (`header_translations[order_idx]`) should remain only as a last resort when neither the grouped key nor any constituent span resolves. Once per-span assembly lands, ensure a *partial* assembly (some spans matched, some didn't) has a defined behavior: emitting a half-assembled header silently is worse than routing the page to `report["review_pages"]`, which is the established fail-closed convention elsewhere in this file (`CONTRACT_BRIDGE_MISS`). Prefer fail-closed on partial header assembly for consistency with the contract-bridge path.

</details>

<details>
<summary>File map</summary>

- `scripts/pdf_translate_v8.py` — `_place_vocab_headers` / `_translation_for_cell` (matching logic under review), `_contract_source_to_translation` (ordered per-source buckets), `_count_source_lines` (source line inference), `draw_paragraph_text` (renderer that collapses newlines).
- `scripts/universal_containers.py` — `detect_header_cells` / `_cluster_header_spans` (produce grouped cell `text`, `cell_box`, `source_box`, and the canonical span ordering).

No diff was generated; this is a design-level review of proposed changes against the current source.

</details>
