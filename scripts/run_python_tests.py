"""
Run every standalone Python test script in scripts/ (scripts/test_*.py).

Each test is its own script that exits non-zero on failure, so each one runs in a
separate process with the repo root as working directory. Prints pass/fail and
duration per file, then a total; exits 1 if any file failed or timed out.

Run:
    python scripts/run_python_tests.py              # all tests
    python scripts/run_python_tests.py font policy  # only files whose name contains a term
    python scripts/run_python_tests.py --timeout 300 --verbose
"""
import argparse
import glob
import os
import subprocess
import sys
import time

SCRIPTS = os.path.dirname(os.path.abspath(__file__))
BASE = os.path.dirname(SCRIPTS)
TAIL_LINES = 25
SKIP_EXIT = 77  # a test that cannot run here (e.g. corpus book absent); see corpus_fixtures


def find_tests(terms):
    files = sorted(glob.glob(os.path.join(SCRIPTS, "test_*.py")))
    if terms:
        files = [f for f in files if any(t in os.path.basename(f) for t in terms)]
    return files


def run_one(path, timeout):
    env = dict(os.environ, PYTHONIOENCODING="utf-8", PYTHONUTF8="1")
    start = time.perf_counter()
    try:
        proc = subprocess.run(
            [sys.executable, path],
            cwd=BASE,
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            encoding="utf-8",
            errors="replace",
        )
        if proc.returncode == 0:
            status = "PASS"
        elif proc.returncode == SKIP_EXIT:
            status = "SKIP"
        else:
            status = f"FAIL (exit {proc.returncode})"
        output = proc.stdout or ""
    except subprocess.TimeoutExpired as exc:
        status = f"TIMEOUT (>{timeout}s)"
        output = exc.stdout or ""
        if isinstance(output, bytes):
            output = output.decode("utf-8", "replace")
    return status, time.perf_counter() - start, output


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("terms", nargs="*", help="only run test files whose name contains one of these")
    parser.add_argument("--timeout", type=int, default=600, help="seconds per test file (default 600)")
    parser.add_argument("--verbose", "-v", action="store_true", help="print full output of every test")
    args = parser.parse_args()

    tests = find_tests(args.terms)
    if not tests:
        print("No test files matched.")
        return 1

    results = []
    width = max(len(os.path.basename(t)) for t in tests)
    for path in tests:
        name = os.path.basename(path)
        status, seconds, output = run_one(path, args.timeout)
        results.append((name, status, seconds))
        print(f"{name:<{width}}  {status:<22} {seconds:7.1f}s", flush=True)
        if args.verbose:
            print(output)
        elif status == "SKIP":
            print(f"    | {output.strip().splitlines()[-1] if output.strip() else ''}")
        elif status != "PASS":
            lines = output.rstrip().splitlines()
            for line in lines[-TAIL_LINES:]:
                print(f"    | {line}")

    failed = [r for r in results if r[1] not in ("PASS", "SKIP")]
    skipped = [r for r in results if r[1] == "SKIP"]
    passed = len(results) - len(failed) - len(skipped)
    total_time = sum(r[2] for r in results)
    print("=" * 60)
    print(f"PYTHON TESTS: {passed} passed, {len(failed)} failed, {len(skipped)} skipped, "
          f"{len(results)} files, {total_time:.1f}s")
    for name, status, _ in failed + skipped:
        print(f"  {status:<22} {name}")
    print("=" * 60)
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
