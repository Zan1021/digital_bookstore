import json
with open(r'C:\Users\zande\Documents\Digital Bookstore\bookstore\storage\app\public\books\comparison\2_af\comparison_report.json') as f:
    r = json.load(f)
print(f"Pages: {r['pages_compared']} compared, {r['pages_passed']} passed, {r['pages_failed']} failed")
print()
for p in r['pages']:
    diff = p.get('pixel_diff_percent', 0)
    status = 'PASS' if p['status'] == 'passed' else 'FAIL'
    print(f"  Page {p['page_number']:2d}: {status} diff={diff:.1f}%")
