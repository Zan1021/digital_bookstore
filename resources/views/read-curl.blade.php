<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $book->title }} — Page-Curl Prototype</title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { overflow: hidden; height: 100%; }
        body {
            background: #2b2b31; color: #eee; font-family: system-ui, sans-serif;
            height: 100vh; display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 18px; padding: 20px;
        }
        .proto-banner {
            position: fixed; top: 0; left: 0; right: 0; text-align: center;
            background: #fd5826; color: #fff; font-size: 12px; padding: 4px; letter-spacing: .04em;
        }
        #book-mount { touch-action: none; }
        /* StPageFlip pages: each holds a canvas that fills the page box. */
        .curl-page {
            background: #fff; overflow: hidden;
            position: relative;
        }
        .curl-page canvas { width: 100%; height: 100%; display: block; }
        /* Soft gutter shadow at the binding edge instead of a hard seam line.
           On the RIGHT page the spine is its LEFT edge; StPageFlip clones/flips pages,
           so we fade from a subtle shadow at both inner edges. */
        .curl-page::before {
            content: ""; position: absolute; top: 0; bottom: 0; left: 0; width: 22px;
            background: linear-gradient(to right, rgba(0,0,0,0.16), rgba(0,0,0,0));
            pointer-events: none; z-index: 2;
        }
        .curl-page::after {
            content: ""; position: absolute; top: 0; bottom: 0; right: 0; width: 22px;
            background: linear-gradient(to left, rgba(0,0,0,0.16), rgba(0,0,0,0));
            pointer-events: none; z-index: 2;
        }
        .controls { display: flex; gap: 12px; align-items: center; }
        .controls button {
            background: #3a3a42; color: #fff; border: 1px solid #55555f; border-radius: 8px;
            padding: 8px 16px; font-size: 14px; cursor: pointer;
        }
        .controls button:disabled { opacity: .35; cursor: default; }
        #page-label { font-size: 13px; color: #bbb; min-width: 120px; text-align: center; }
        #loading { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; background: #2b2b31; z-index: 10; }
    </style>
</head>
<body>
    <div class="proto-banner">PAGE-CURL PROTOTYPE — /read-curl/{{ $book->id }} — not the live reader</div>
    <div id="loading">Loading “{{ $book->title }}”…</div>

    <div id="book-mount"></div>

    <div class="controls">
        <button id="prev-btn">‹ Prev</button>
        <span id="page-label">–</span>
        <button id="next-btn">Next ›</button>
    </div>

    <!-- PDF.js (same version as the live reader) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';</script>
    <!-- StPageFlip 2.0.7 (pinned) -->
    <script src="https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.min.js"></script>

    <script>
    (async function () {
        const PDF_URL = '{{ asset("storage/" . str_replace(" ", "%20", $pdfPath)) }}';
        const CROP = @json($book->getCropBoxFractions()); // {left,top,right,bottom}
        const BOOK_ID = {{ $book->id }};
        const LANG = '{{ $lang }}';
        const DPR = Math.min(window.devicePixelRatio || 1, 2); // cap DPR at 2 (matches reader)

        // 1) Load the PDF and measure the CROPPED first-page ratio (book-agnostic sizing).
        const pdf = await pdfjsLib.getDocument(PDF_URL).promise;
        const total = pdf.numPages;
        const first = await pdf.getPage(1);
        const vp1 = first.getViewport({ scale: 1 });
        const cropW = vp1.width * (1 - CROP.left - CROP.right);
        const cropH = vp1.height * (1 - CROP.top - CROP.bottom);
        const ratio = cropW / cropH;

        // 2) Choose an on-screen page box that fits the viewport, preserving the ratio.
        // In spread mode (showCover + landscape), two pages sit side-by-side, so the
        // total book width = 2 × pageW. Size so the SPREAD fits the window.
        const maxH = Math.min(window.innerHeight - 140, 900);
        const maxW = Math.min(window.innerWidth - 80, 1400);
        // Two-up spread constraint: each page at most half the available width.
        const pageFromH = Math.round(maxH);
        const pageFromW = Math.round(maxH * ratio);
        const spreadWidth = pageFromW * 2;
        let pageW, pageH;
        if (spreadWidth > maxW) {
            // Width-constrained: each page = half the available width.
            pageW = Math.round(maxW / 2);
            pageH = Math.round(pageW / ratio);
        } else {
            // Height-constrained.
            pageH = pageFromH;
            pageW = pageFromW;
        }

        // 3) Build one .curl-page div per PDF page, each with a DPR-crisp canvas.
        const mount = document.getElementById('book-mount');
        const pageEls = [];
        for (let i = 1; i <= total; i++) {
            const el = document.createElement('div');
            el.className = 'curl-page';
            // Cover + back: hard (shown solo by showCover, stiffer flip like a real book).
            // Interior pages: soft (full magazine curl).
            el.setAttribute('data-density', (i === 1 || i === total) ? 'hard' : 'soft');
            const canvas = document.createElement('canvas');
            canvas.id = 'curl-canvas-' + i;
            el.appendChild(canvas);
            mount.appendChild(el);
            pageEls.push(el);
        }

        // 4) Render a single PDF page into its canvas, cropped + at DPR for crispness.
        async function renderPage(num) {
            const page = await pdf.getPage(num);
            const baseVp = page.getViewport({ scale: 1 });
            // scale so the CROPPED page maps onto pageW*DPR px wide.
            const scale = (pageW * DPR) / (baseVp.width * (1 - CROP.left - CROP.right));
            const vp = page.getViewport({ scale });
            const canvas = document.getElementById('curl-canvas-' + num);
            const ctx = canvas.getContext('2d');
            // Crop by offsetting the canvas + clipping to the cropped region.
            canvas.width = Math.round(vp.width * (1 - CROP.left - CROP.right));
            canvas.height = Math.round(vp.height * (1 - CROP.top - CROP.bottom));
            ctx.save();
            ctx.translate(-vp.width * CROP.left, -vp.height * CROP.top);
            await page.render({ canvasContext: ctx, viewport: vp }).promise;
            ctx.restore();
        }

        // Render all pages up front (book 2 is small, 16 pages). Good enough for a prototype.
        for (let i = 1; i <= total; i++) await renderPage(i);

        // 5) Init StPageFlip in HTML mode on our page divs.
        const pageFlip = new St.PageFlip(mount, {
            width: pageW,
            height: pageH,
            size: 'fixed',
            usePortrait: false,         // allow landscape/spread when window is wide
            showCover: true,            // cover + back shown SOLO; inner pages as spreads
            maxShadowOpacity: 0.5,
            drawShadow: true,
            flippingTime: 700,
            useMouseEvents: true,       // corner-drag peel
            disableFlipByClick: true,   // clicking only turns near a CORNER, not anywhere
        });

        const label = document.getElementById('page-label');
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');

        function resolveStartPage() {
            const fromHash = (location.hash.match(/page=(\d+)/) || [])[1];
            const fromQuery = new URLSearchParams(location.search).get('page');
            const deep = parseInt(fromHash || fromQuery || '', 10);
            if (!Number.isNaN(deep)) return Math.max(0, Math.min(deep - 1, total - 1));
            try {
                const saved = parseInt(localStorage.getItem(`curl:pos:${BOOK_ID}:${LANG}`) || '', 10);
                if (!Number.isNaN(saved)) return Math.max(0, Math.min(saved, total - 1));
            } catch (e) {}
            return 0;
        }

        function updateChrome(idx) {
            label.textContent = `Page ${idx + 1} of ${total}`;
            prevBtn.disabled = idx <= 0;
            nextBtn.disabled = idx >= total - 1;
            try { localStorage.setItem(`curl:pos:${BOOK_ID}:${LANG}`, String(idx)); } catch (e) {}
            try { const u = new URL(location.href); u.hash = `page=${idx + 1}`; history.replaceState(null, '', u); } catch (e) {}
        }

        pageFlip.on('flip', (e) => updateChrome(e.data));
        pageFlip.on('init', (e) => updateChrome(e.data.page));

        pageFlip.loadFromHTML(document.querySelectorAll('.curl-page'));

        // Jump to the restored/deep-linked start page (no animation).
        const start = resolveStartPage();
        if (start > 0) pageFlip.turnToPage(start);
        updateChrome(pageFlip.getCurrentPageIndex());

        // 6) Navigation: buttons + keyboard drive the CURL animation.
        nextBtn.addEventListener('click', () => pageFlip.flipNext('top'));
        prevBtn.addEventListener('click', () => pageFlip.flipPrev('top'));
        window.addEventListener('keydown', (ev) => {
            if (ev.key === 'ArrowRight') pageFlip.flipNext('top');
            if (ev.key === 'ArrowLeft') pageFlip.flipPrev('top');
        });

        document.getElementById('loading').style.display = 'none';
    })().catch(err => {
        console.error('[curl-proto] failed:', err);
        document.getElementById('loading').textContent = 'Failed: ' + err.message;
    });
    </script>
</body>
</html>
