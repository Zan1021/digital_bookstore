// render_pdfjs.mjs — ACCEPTANCE renderer for the cover-retypeset spec.
//
// Renders a page of a PDF to PNG using the SAME engine as the reader (PDF.js /
// pdfjs-dist), so verification matches what the browser actually shows. This is
// the fix for the whole-session trap: PyMuPDF get_pixmap FLATTENS soft masks and
// showed the cover "clean" while PDF.js rendered a washed box. Chrome --screenshot
// on a PDF is blank in headless. pdfjs-dist + a canvas backend reproduces the
// browser render deterministically, headless, offline.
//
// Usage:  node scripts/render_pdfjs.mjs <input.pdf> <pageIndex0Based> <output.png> [scale]
// Exit 0 on success (prints "OK <w>x<h> -> <out>"), non-zero on failure.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createCanvas } from '@napi-rs/canvas';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

async function main() {
  const [, , inPath, pageArg, outPath, scaleArg] = process.argv;
  if (!inPath || pageArg === undefined || !outPath) {
    console.error('usage: node render_pdfjs.mjs <input.pdf> <pageIndex0Based> <output.png> [scale]');
    process.exit(2);
  }
  const pageIndex = parseInt(pageArg, 10) || 0;
  const scale = scaleArg ? parseFloat(scaleArg) : 2.0;

  if (!fs.existsSync(inPath)) {
    console.error(`input not found: ${inPath}`);
    process.exit(3);
  }

  // pdfjs-dist legacy build works in Node without a DOM. Import dynamically so a
  // missing/incompatible build fails with a clear message rather than at load.
  const pdfjsLib = await import('pdfjs-dist/legacy/build/pdf.mjs');

  const data = new Uint8Array(fs.readFileSync(inPath));
  const loadingTask = pdfjsLib.getDocument({
    data,
    // Disable worker (single-process, deterministic in Node).
    disableWorker: true,
    isEvalSupported: false,
    // Use the shipped standard fonts so embedded/standard fonts resolve.
    standardFontDataUrl: pathToFileURL(
      path.join(__dirname, '..', 'node_modules', 'pdfjs-dist', 'standard_fonts') + path.sep
    ).href,
  });

  const doc = await loadingTask.promise;
  if (pageIndex < 0 || pageIndex >= doc.numPages) {
    console.error(`pageIndex ${pageIndex} out of range (numPages=${doc.numPages})`);
    process.exit(4);
  }

  const page = await doc.getPage(pageIndex + 1); // pdf.js is 1-based
  const viewport = page.getViewport({ scale });

  const canvas = createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
  const ctx = canvas.getContext('2d');

  // Match the reader's white/opaque page compositing.
  ctx.fillStyle = 'white';
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  await page.render({
    canvasContext: ctx,
    viewport,
    // background left as painted; honour transparency/soft masks like the browser.
  }).promise;

  const buf = canvas.toBuffer('image/png');
  fs.writeFileSync(outPath, buf);
  console.log(`OK ${canvas.width}x${canvas.height} -> ${outPath}`);
  process.exit(0);
}

main().catch((err) => {
  console.error('render_pdfjs failed:', err && err.stack ? err.stack : String(err));
  process.exit(1);
});
