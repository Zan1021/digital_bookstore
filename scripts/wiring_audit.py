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

pyfiles = {f[:-3]: os.path.join(SCRIPTS, f)
           for f in os.listdir(SCRIPTS) if f.endswith(".py")}
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

# 1. LIVE-IMPORT: reachable from the two engine entrypoints PHP invokes.
entry = {"pdf_translate_v8", "page_manifest"}
live_import = set()
dq = deque(entry)
while dq:
    cur = dq.popleft()
    if cur in live_import:
        continue
    live_import.add(cur)
    dq.extend(graph.get(cur, ()))

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

# 3. TEST-ONLY
tests = {m for m in modnames if m.startswith("test_")}
test_imports = set()
for t in tests:
    test_imports |= graph.get(t, set())

helpers = {m for m in modnames if m.startswith("_")}

prod = sorted(m for m in modnames if m not in tests and m not in helpers)

rows = []
for m in prod:
    if m in live_import:
        tier = "LIVE-IMPORT"
        note = "engine entrypoint" if m in entry else ""
    elif m in subproc:
        gates = sorted({g for _f, g in subproc[m] if g})
        callers = sorted({f for f, _g in subproc[m]})
        tier = "LIVE-SUBPROC"
        note = ("gated:" + ",".join(gates) + " " if gates else "") + "via " + ",".join(callers)
    elif m in test_imports:
        tier = "TEST-ONLY"
        note = ""
    else:
        tier = "DEAD"
        note = ""
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
