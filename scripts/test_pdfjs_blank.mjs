// test_pdfjs_blank.mjs — Phase 6.4: prove the PDF.js harness flags a BLANK page and
// clears a content page. Builds two tiny PDFs with pdf-lib if available, else uses
// pdfjs raw. Keeps it dependency-light: we render via render_pdfjs.mjs and assert the
// `blank` flag. Run: node scripts/test_pdfjs_blank.mjs
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pdfjs-test-'));
let passed = 0, run = 0;
function check(name, cond) { run++; if (cond) { passed++; console.log(`  [PASS] ${name}`); } else { console.log(`  [FAIL] ${name}`); } }

// Minimal hand-written PDFs (one blank page; one with a big black rectangle).
function writePdf(file, withContent) {
  const content = withContent
    ? '0 0 0 rg 50 50 500 700 re f'   // fill a big black rectangle
    : '';                              // nothing => blank white page
  const stream = `<< /Length ${content.length} >>\nstream\n${content}\nendstream`;
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Contents 4 0 R /Resources << >> >>',
    stream,
  ];
  let pdf = '%PDF-1.4\n';
  const offsets = [];
  objs.forEach((o, i) => { offsets.push(pdf.length); pdf += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const xref = pdf.length;
  pdf += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n`;
  offsets.forEach((off) => { pdf += String(off).padStart(10, '0') + ' 00000 n \n'; });
  pdf += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
  fs.writeFileSync(file, pdf);
}

function renderBlankFlag(pdf) {
  const out = path.join(tmp, 'o.png');
  const stdout = execFileSync('node', [path.join(__dirname, 'render_pdfjs.mjs'), pdf, '0', out, '2.0'],
    { encoding: 'utf8' });
  return JSON.parse(stdout.trim());
}

try {
  const blankPdf = path.join(tmp, 'blank.pdf');
  const contentPdf = path.join(tmp, 'content.pdf');
  writePdf(blankPdf, false);
  writePdf(contentPdf, true);

  const b = renderBlankFlag(blankPdf);
  check('blank page flagged blank=true', b.blank === true);
  check('blank page is near-white', b.near_white_frac > 0.9);

  const c = renderBlankFlag(contentPdf);
  check('content page flagged blank=false', c.blank === false);
  check('content page has variance', c.variance > 25);
} catch (e) {
  console.log('  [FAIL] harness threw: ' + (e && e.message));
  run++;
} finally {
  fs.rmSync(tmp, { recursive: true, force: true });
}

console.log(`\n${passed}/${run} passed`);
process.exit(passed === run ? 0 : 1);
