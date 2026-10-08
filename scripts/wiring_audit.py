"""
Wiring audit (repeatable) — Digital Bookstore render engine.

Answers "which scripts/ modules are actually used, and how?" MECHANICALLY, so the
answer never depends on anyone's memory. Classifies every scripts/*.py module as:

  LIVE-IMPORT   reachable by Python `import` from the engine entrypoints
  LIVE-SUBPROC  invoked as a subprocess from the PHP app (new Process([... 'scripts/x.py']))
                — may be gated behind a config flag (noted)
  TEST-ONLY     imported only by test_*.py (has coverage, but no production caller)
  DEAD          neither imported, subprocessed, nor tested

Two engine entrypoints are seeded (what PHP calls directly): pdf_translate_v8, page_manifest.
Run:  python scripts/wiring_audit.py            # prints report
      python scripts/wiring_audit.py --md FILE  # also writes a markdown report
Pure static scan; no rendering, no API. Re-run any time (CI-friendly).
"""
import os, re, sys
from collections import deque

SCRIPTS = os.path.dirname(os.path.abspath(__file__))
APP = os.path.normpath(os.path.join(SCRIPTS, "..", "app"))

# Scan scripts/ RECURSIVELY (top-level AND subdirs like scripts/legacy/) so an orphaned
# legacy tree can't hide from the audit. Module key is the path relative to scripts/,
# without extension, using the bare stem for import matching (python import uses the stem).
pyfiles = {}
rel_of = {}
for root, _dirs, files in os.walk(SCRIPTS):
    if "__pycache__" in root:
        continue
    for f in files:
        if f.endswith(".py"):
            stem = f[:-3]
            full = os.path.join(root, f)
            rel = os.path.relpath(full, SCRIPTS).replace("\\", "/")
            # keep first occurrence of a stem for the import graph; track rel for reporting
            pyfiles.setdefault(stem, full)
            rel_of[stem] = rel
modnames = set(pyfiles)
imp_re = re.compile(r'^\s*(?:from\s+(\w+)\s+import|import\s+(\w+))', re.M)


def local_imports(path):
    try:
        src = open(path, encoding="utf-8").read()
    except Exception:
        return set()
    return {m.group(1) or m.group(2) for m in imp_re.finditer(src)
            if (m.group(1) or m.group(2)) in modnames}


graph = {m: local_imports(p) for m, p in pyfiles.items()}

# 1. LIVE-IMPORT: reachable by import from a live caller. A module is a live caller if it
#    is one of the two engine entrypoints PHP invokes directly, OR it is itself invoked as a
#    PHP subprocess (computed in step 2 below). Liveness propagates TRANSITIVELY through the
#    local import graph: if a live module imports module X, X is live too — even if X's only
#    other references are from test_*.py. (Earlier versions seeded ONLY the two entrypoints,
#    so modules reached exclusively through a LIVE-SUBPROC module — e.g. the illustration
#    repair trio reached via illustration_text — were misreported TEST-ONLY/DEAD.)
entry = {"pdf_translate_v8", "page_manifest"}

# 2. LIVE-SUBPROC: any scripts/<name>.py referenced in a PHP Process([...]) call.
subproc = {}  # module -> list of (php_file, gated_by_config?)
php_proc_re = re.compile(r"scripts/([A-Za-z0-9_]+)\.py")
cfg_re = re.compile(r"config\(['\"]bookstore\.([a-z_.]+)")
for root, _dirs, files in os.walk(APP):
    for fn in files:
        if not fn.endswith(".php"):
            continue
        p = os.path.join(root, fn)
        try:
            src = open(p, encoding="utf-8").read()
        except Exception:
            continue
        for m in php_proc_re.finditer(src):
            name = m.group(1)
            if name in modnames:
                # crude gating hint: is there a config(bookstore.*) within 400 chars before?
                near = src[max(0, m.start() - 400):m.start()]
                gate = cfg_re.search(near)
                subproc.setdefault(name, []).append((fn, gate.group(1) if gate else None))

# Transitive import-liveness: seed the BFS from the engine entrypoints AND every module PHP
# invokes as a subprocess, then walk the local import graph. Everything reached is live.
live_import = set()
dq = deque(entry | set(subproc))
while dq:
    cur = dq.popleft()
    if cur in live_import:
        continue
    live_import.add(cur)
    dq.extend(graph.get(cur, ()))

# 3. TEST-ONLY
tests = {m for m in modnames if m.startswith("test_")}
test_imports = set()
for t in tests:
    test_imports |= graph.get(t, set())

helpers = {m for m in modnames if m.startswith("_")}

prod = sorted(m for m in modnames if m not in tests and m not in helpers)

rows = []
for m in prod:
    rel = rel_of.get(m, m + ".py")
    loc = "" if "/" not in rel else f" [{rel}]"
    if m in subproc:
        gates = sorted({g for _f, g in subproc[m] if g})
        callers = sorted({f for f, _g in subproc[m]})
        tier = "LIVE-SUBPROC"
        note = ("gated:" + ",".join(gates) + " " if gates else "") + "via " + ",".join(callers) + loc
    elif m in live_import:
        tier = "LIVE-IMPORT"
        note = ("engine entrypoint" if m in entry else "transitively imported by a live module") + loc
    elif m in test_imports:
        tier = "TEST-ONLY"
        note = loc.strip()
    else:
        tier = "DEAD"
        note = loc.strip()
    rows.append((tier, m, note))

order = {"LIVE-IMPORT": 0, "LIVE-SUBPROC": 1, "TEST-ONLY": 2, "DEAD": 3}
rows.sort(key=lambda r: (order[r[0]], r[1]))

from collections import Counter
counts = Counter(r[0] for r in rows)
lines = []
lines.append("# Engine Wiring Audit (mechanical)")
lines.append("")
lines.append(f"Entry points (invoked by PHP): {sorted(entry)}")
lines.append(f"Totals: " + ", ".join(f"{k}={counts.get(k,0)}" for k in order))
lines.append("")
lines.append("| Tier | Module | Note |")
lines.append("|------|--------|------|")
for tier, m, note in rows:
    lines.append(f"| {tier} | {m} | {note} |")
report = "\n".join(lines)
print(report)

if "--md" in sys.argv:
    out = sys.argv[sys.argv.index("--md") + 1]
    open(out, "w", encoding="utf-8").write(report + "\n")
    print(f"\n[written] {out}")
