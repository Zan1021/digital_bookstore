// Libraries for the PDF readers (reader, flipbook, page-curl prototype), bundled from npm
// instead of loaded from CDNs (task 4.2). Same versions the pages used before:
// pdf.js 3.11.174 (aliased as pdfjs-dist-v3, so server-side rendering keeps pdfjs-dist 4)
// and StPageFlip 2.0.7. Exposed under the globals the page scripts already use.
//
// This is a module, so it runs after the page's classic inline scripts; page scripts that
// use these libraries straight away must be <script type="module"> (they run after it).
import * as pdfjsLib from 'pdfjs-dist-v3/build/pdf.min.js';
import pdfWorkerUrl from 'pdfjs-dist-v3/build/pdf.worker.min.js?url';
import { PageFlip } from 'page-flip/dist/js/page-flip.module.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

window.pdfjsLib = pdfjsLib;
window.St = { PageFlip };
