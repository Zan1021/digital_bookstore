<!DOCTYPE html>
<html lang="{{ $book->language ?? 'en' }}" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>{{ $book->title }} — Smart Reader</title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <style>
        /* ===== RESET & BASE ===== */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { overflow: hidden; } /* Prevent scrollbar jump during page-curl */
        [x-cloak] { display: none !important; }
        
        :root {
            --brand-accent: #f97316; /* Mthombothi orange */
            --stage-bg: #1e1b2e;
            --toolbar-bg: rgba(15, 12, 30, 0.92);
            --toolbar-text: #e2e0ec;
            --page-shadow: rgba(0, 0, 0, 0.25);
            --gutter-color: rgba(0, 0, 0, 0.12);
            --progress-track: rgba(255, 255, 255, 0.15);
            --progress-fill: var(--brand-accent);
            --control-radius: 8px;
            --toolbar-height: 48px;
            --transition-fast: 200ms ease;
            --transition-normal: 300ms ease;
        }

        html, body {
            width: 100%; height: 100dvh;
            overflow: hidden;
            background: var(--stage-bg);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            color: var(--toolbar-text);
            -webkit-tap-highlight-color: transparent;
        }

        /* ===== READER SHELL ===== */
        .reader-shell {
            width: 100%; height: 100dvh;
            display: grid;
            grid-template-rows: var(--toolbar-height) 1fr var(--toolbar-height);
            position: relative;
        }

        .reader-shell.controls-hidden .reader-top-bar,
        .reader-shell.controls-hidden .reader-bottom-bar {
            opacity: 0;
            pointer-events: none;
        }
        .reader-shell.controls-hidden .reader-top-bar {
            transform: translateY(calc(-1 * var(--toolbar-height)));
        }
        .reader-shell.controls-hidden .reader-bottom-bar {
            transform: translateY(var(--toolbar-height));
        }
        .reader-shell.controls-hidden .reading-stage {
            grid-row: 1 / -1;
        }

        /* ===== TOP TOOLBAR ===== */
        .reader-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            background: var(--toolbar-bg);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            z-index: 100;
            transition: opacity var(--transition-normal), transform var(--transition-normal);
        }

        .top-bar-left {
            display: flex; align-items: center; gap: 12px;
            min-width: 0;
        }
        .book-title {
            font-size: 14px; font-weight: 600;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 240px;
        }

        .top-bar-center {
            display: flex; align-items: center; gap: 8px;
        }
        .top-bar-right {
            display: flex; align-items: center; gap: 6px;
        }

        /* Toolbar buttons */
        .tb-btn {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 36px; height: 36px;
            padding: 0 10px;
            border: none; border-radius: var(--control-radius);
            background: rgba(255,255,255,0.08);
            color: var(--toolbar-text);
            font-size: 13px; font-weight: 500;
            cursor: pointer;
            transition: background var(--transition-fast);
        }
        .tb-btn:hover { background: rgba(255,255,255,0.15); }
        .tb-btn:active { background: rgba(255,255,255,0.2); }
        .tb-btn.active { background: var(--brand-accent); color: white; }
        .tb-btn svg { width: 18px; height: 18px; }

        /* View mode toggle */
        .view-toggle {
            display: flex; border-radius: var(--control-radius);
            overflow: hidden; background: rgba(255,255,255,0.06);
        }
        .view-toggle .tb-btn {
            border-radius: 0; min-width: 32px;
        }

        /* Select */
        .tb-select {
            height: 36px; padding: 0 10px;
            border: none; border-radius: var(--control-radius);
            background: rgba(255,255,255,0.08);
            color: var(--toolbar-text);
            font-size: 12px;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            padding-right: 24px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23aaa' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
        }

        /* ===== READING STAGE ===== */
        .reading-stage {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 12px;
            perspective: 2400px; /* gives the page-turn snapshot overlay real 3D depth */
            min-height: 0;   /* allow the 1fr grid row to size correctly (was collapsing) */
            min-width: 0;
        }

        /* Book container — flex, CSS-sized (no JS pixel math).
           Fits within the stage; pages scale to fill available width/height. */
        .book-container {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            max-width: 100%;
            max-height: 100%;
            filter: drop-shadow(0 8px 32px var(--page-shadow));
        }

        /* Page surface holds one page canvas. In spread mode each page takes half
           the container; in single mode the one page is centered. Height is capped
           to the stage so portrait pages never overflow vertically. */
        .page-surface {
            background: white;
            position: relative;
            overflow: hidden;
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .page-surface canvas {
            display: block;
            max-width: 100%;
            max-height: calc(100dvh - 2 * var(--toolbar-height) - 24px);
            width: auto;
            height: auto;
            object-fit: contain;
        }
        .page-surface.left-page  { border-radius: 3px 0 0 3px; }
        .page-surface.right-page { border-radius: 0 3px 3px 0; }
        .page-surface.single-page { border-radius: 3px; }

        /* Gutter removed — spread pages butt directly together (flush, no line). */
        .gutter { display: none !important; }

        /* Page depth effect */
        .book-container::after {
            content: '';
            position: absolute;
            bottom: -3px; left: 4px; right: 4px;
            height: 3px;
            background: linear-gradient(to bottom, rgba(0,0,0,0.1), transparent);
            border-radius: 0 0 4px 4px;
        }

        /* Navigation arrows */
        .nav-arrow {
            position: absolute;
            top: 50%; transform: translateY(-50%);
            width: 44px; height: 44px;
            display: flex; align-items: center; justify-content: center;
            border: none; border-radius: 50%;
            background: rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.8);
            font-size: 20px;
            cursor: pointer;
            transition: all var(--transition-fast);
            z-index: 50;
            backdrop-filter: blur(4px);
        }
        .nav-arrow:hover { background: rgba(255,255,255,0.2); color: white; }
        .nav-arrow:disabled { opacity: 0.3; cursor: default; }
        .nav-arrow.prev { left: 12px; }
        .nav-arrow.next { right: 12px; }

        /* ===== FULL-WIDTH BOTTOM CONTROL BAR (FlipHTML5 model) ===== */
        .reader-bottom-bar {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 0 16px;
            background: var(--toolbar-bg);
            backdrop-filter: blur(12px);
            border-top: 1px solid rgba(255,255,255,0.06);
            z-index: 100;
            transition: opacity var(--transition-normal), transform var(--transition-normal);
        }
        .bottom-left  { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
        .bottom-center { display: flex; align-items: center; flex: 1; min-width: 0; }
        .bottom-right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

        .reader-bottom-bar .tb-btn.icon-btn {
            background: transparent;
            min-width: 34px; padding: 0 6px;
        }
        .reader-bottom-bar .tb-btn.icon-btn:hover { background: rgba(255,255,255,0.12); }
        .reader-bottom-bar .tb-btn.icon-btn.active { background: var(--brand-accent); color: #fff; }
        .reader-bottom-bar .narration-btn svg { width: 15px; height: 15px; }

        .page-indicator {
            font-size: 12px; color: rgba(255,255,255,0.75);
            white-space: nowrap; padding: 0 8px; cursor: pointer;
            font-variant-numeric: tabular-nums;
        }
        .page-indicator:hover { color: #fff; }

        /* Long full-width seek slider */
        .reader-bottom-bar .progress-track {
            flex: 1; height: 4px;
            background: var(--progress-track);
            border-radius: 2px; position: relative; cursor: pointer;
        }
        .reader-bottom-bar .progress-fill {
            height: 100%; background: var(--progress-fill); border-radius: 2px;
            transition: width var(--transition-fast);
        }
        .progress-knob {
            position: absolute; top: 50%;
            width: 12px; height: 12px; border-radius: 50%;
            background: #fff; border: 2px solid var(--brand-accent);
            transform: translate(-50%, -50%);
            box-shadow: 0 1px 4px rgba(0,0,0,0.4);
            transition: left var(--transition-fast);
            pointer-events: none;
        }

        /* Progress bar */
        .progress-container {
            display: flex; align-items: center; gap: 10px;
            max-width: 400px; flex: 1;
        }
        .progress-text {
            font-size: 12px; color: rgba(255,255,255,0.7);
            white-space: nowrap;
        }
        .progress-track {
            flex: 1; height: 4px;
            background: var(--progress-track);
            border-radius: 2px;
            position: relative;
            cursor: pointer;
            min-width: 80px;
        }
        .progress-fill {
            height: 100%;
            background: var(--progress-fill);
            border-radius: 2px;
            transition: width var(--transition-fast);
        }

        /* Narration play button */
        .narration-play {
            width: 36px; height: 36px;
            border: none; border-radius: 50%;
            background: var(--brand-accent);
            color: white;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: transform var(--transition-fast);
        }
        .narration-play:hover { transform: scale(1.08); }
        .narration-play svg { width: 16px; height: 16px; }

        /* ===== MOBILE RESPONSIVE ===== */
        @media (max-width: 768px) {
            .top-bar-center { display: none; }
            .book-title { max-width: 160px; font-size: 13px; }
            .reading-stage { padding: 8px; }
            .nav-arrow { width: 36px; height: 36px; font-size: 16px; }
            .nav-arrow.prev { left: 4px; }
            .nav-arrow.next { right: 4px; }
            .reader-bottom-bar { gap: 8px; padding: 0 8px; }
            .bottom-left { gap: 2px; }
            .page-indicator { padding: 0 4px; font-size: 11px; }
        }

        /* ===== LOADING ===== */
        .reader-loading {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            background: var(--stage-bg);
            z-index: 200;
            transition: opacity 0.5s ease;
        }
        .reader-loading.hidden { opacity: 0; pointer-events: none; }
        .spinner {
            width: 32px; height: 32px;
            border: 3px solid rgba(255,255,255,0.1);
            border-top-color: var(--brand-accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-label { font-size: 13px; color: rgba(255,255,255,0.5); margin-top: 12px; }

        /* ===== THUMBNAIL FILMSTRIP (horizontal, docked above bottom bar) ===== */
        .thumbnail-drawer {
            position: fixed;
            bottom: var(--toolbar-height);
            left: 0; right: 0;
            background: var(--toolbar-bg);
            backdrop-filter: blur(16px);
            border-top: 1px solid rgba(255,255,255,0.1);
            z-index: 150;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 10px 8px;
        }
        /* Side chevrons that scroll the strip */
        .filmstrip-nav {
            flex-shrink: 0;
            width: 30px; height: 96px;
            border: none; border-radius: 6px;
            background: rgba(255,255,255,0.06);
            color: rgba(255,255,255,0.8);
            font-size: 16px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background var(--transition-fast);
        }
        .filmstrip-nav:hover { background: rgba(255,255,255,0.15); color: #fff; }
        .thumbnail-grid {
            display: flex;
            gap: 20px;
            padding: 4px 6px;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            flex: 1;
            scrollbar-width: thin;
        }
        .thumbnail-item {
            flex-shrink: 0;
            width: 120px;
            display: flex; flex-direction: column; align-items: center; gap: 5px;
            cursor: pointer;
            transition: transform var(--transition-fast);
        }
        .thumbnail-item:hover { transform: scale(1.05); }
        .thumbnail-item.active .thumbnail-canvas {
            outline: 2px solid var(--brand-accent);
            outline-offset: 2px;
        }
        .thumbnail-canvas {
            max-width: 120px; height: auto;
            border-radius: 4px;
            background: rgba(255,255,255,0.05);
            box-shadow: 0 2px 8px rgba(0,0,0,0.35); /* separates each page from the strip bg */
            display: block;
        }
        .thumbnail-number {
            font-size: 11px; color: rgba(255,255,255,0.6);
        }
        .thumbnail-item.active .thumbnail-number {
            color: var(--brand-accent); font-weight: 600;
        }

        .drawer-enter { animation: slideUp 0.25s ease-out; }
        .drawer-leave { animation: slideDown 0.2s ease-in; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        @keyframes slideDown { from { transform: translateY(0); } to { transform: translateY(100%); } }

        /* ===== PAGE JUMP DIALOG ===== */
        .page-jump-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            display: flex; align-items: center; justify-content: center;
            z-index: 200;
        }
        .page-jump-dialog {
            background: #2a2640;
            border-radius: 12px;
            padding: 24px;
            min-width: 220px;
            display: flex; flex-direction: column; gap: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }
        .page-jump-label {
            font-size: 14px; font-weight: 600; color: rgba(255,255,255,0.9);
        }
        .page-jump-input {
            width: 100%; height: 40px;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: var(--control-radius);
            background: rgba(255,255,255,0.08);
            color: white;
            font-size: 18px; text-align: center;
            outline: none;
        }
        .page-jump-input:focus { border-color: var(--brand-accent); }
        .page-jump-actions {
            display: flex; gap: 8px; justify-content: flex-end;
        }

        /* ===== PAGE-TURN ANIMATIONS ===== */
        .book-container {
            perspective: 2000px;
        }

        /* Page-turn is now handled by a JS snapshot overlay (Web Animations API),
           see _snapshotStage()/_runTurn(). The old per-surface CSS flip/slide classes
           were removed because they raced the PDF.js re-render and never showed. */

        /* ===== STPAGEFLIP PAGE-CURL PAGES ===== */
        .stflip-page {
            background: #fff; overflow: hidden; position: relative;
        }
        .stflip-page canvas { width: 100%; height: 100%; display: block; }
        /* Soft gutter shadow at the binding edge (approved prototype). */
        .stflip-page::before {
            content: ""; position: absolute; top: 0; bottom: 0; left: 0; width: 22px;
            background: linear-gradient(to right, rgba(0,0,0,0.16), rgba(0,0,0,0));
            pointer-events: none; z-index: 2;
        }
        .stflip-page::after {
            content: ""; position: absolute; top: 0; bottom: 0; right: 0; width: 22px;
            background: linear-gradient(to left, rgba(0,0,0,0.16), rgba(0,0,0,0));
            pointer-events: none; z-index: 2;
        }

        /* ===== ENHANCED BOOK DEPTH ===== */
        .book-container {
            position: relative;
            display: flex;
            align-items: stretch;
            filter: drop-shadow(0 8px 32px var(--page-shadow))
                    drop-shadow(0 2px 8px rgba(0,0,0,0.15));
        }

        /* Page stack effect (subtle thickness below book) */
        .book-container::before {
            content: '';
            position: absolute;
            bottom: -2px; left: 3px; right: 3px;
            height: 2px;
            background: rgba(200, 195, 210, 0.3);
            border-radius: 0 0 2px 2px;
        }
        .book-container::after {
            content: '';
            position: absolute;
            bottom: -4px; left: 5px; right: 5px;
            height: 2px;
            background: rgba(200, 195, 210, 0.15);
            border-radius: 0 0 3px 3px;
        }

        /* Enhanced gutter with spine feel — DISABLED: spread must be flush (no seam). */
        .gutter {
            width: 6px;
            box-shadow: none;
        }

        /* Inner spine shadows removed: the two pages must butt together flush with no
           visible line/gutter between them (standing requirement). */

        /* ===== LOADING TRANSITIONS ===== */
        .page-surface canvas {
            transition: opacity 0.2s ease;
        }
        .page-surface.rendering canvas {
            opacity: 0.6;
        }

        /* ===== TOOLBAR AUTO-HIDE REFINEMENT ===== */
        .reader-top-bar,
        .reader-bottom-bar {
            transition: opacity 0.4s ease, transform 0.4s ease;
        }
        .reader-shell.controls-hidden .reader-top-bar {
            opacity: 0;
            transform: translateY(-100%);
            pointer-events: none;
        }
        .reader-shell.controls-hidden .reader-bottom-bar {
            opacity: 0;
            transform: translateY(100%);
            pointer-events: none;
        }

        /* Stage gradient behind top toolbar (fade to black) */
        .reader-top-bar::after {
            content: '';
            position: absolute;
            top: 100%; left: 0; right: 0;
            height: 30px;
            background: linear-gradient(to bottom, rgba(15,12,30,0.5), transparent);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .reader-shell:not(.controls-hidden) .reader-top-bar::after { opacity: 1; }

        /* ===== NARRATION HIGHLIGHTS ===== */
        .narration-highlight-layer {
            position: absolute; inset: 0;
            pointer-events: none;
            z-index: 10;
        }
        .word-highlight {
            position: absolute;
            background: rgba(249, 115, 22, 0.25);
            border-radius: 3px;
            transition: all 0.1s ease;
        }
        .sentence-highlight {
            position: absolute;
            background: rgba(249, 115, 22, 0.1);
            border-radius: 4px;
        }

        /* Narration state indicator */
        .narration-indicator {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; color: var(--brand-accent);
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
        .narration-indicator .dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--brand-accent);
        }

        /* ===== SEARCH PANEL ===== */
        .search-panel {
            position: fixed;
            top: var(--toolbar-height);
            right: 0;
            width: 320px;
            max-width: 90vw;
            max-height: calc(100vh - var(--toolbar-height) * 2);
            background: var(--toolbar-bg);
            backdrop-filter: blur(16px);
            border-left: 1px solid rgba(255,255,255,0.08);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            border-radius: 0 0 0 12px;
            z-index: 160;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .search-header {
            padding: 12px; display: flex; gap: 8px; align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .search-input {
            flex: 1; height: 36px;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: var(--control-radius);
            background: rgba(255,255,255,0.08);
            color: white; font-size: 13px;
            padding: 0 10px; outline: none;
        }
        .search-input:focus { border-color: var(--brand-accent); }
        .search-input::placeholder { color: rgba(255,255,255,0.4); }
        .search-results {
            flex: 1; overflow-y: auto; padding: 8px;
        }
        .search-result-item {
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            color: rgba(255,255,255,0.8);
            transition: background 0.15s;
        }
        .search-result-item:hover { background: rgba(255,255,255,0.08); }
        .search-result-item .page-num {
            font-size: 10px; color: var(--brand-accent); font-weight: 600;
        }
        .search-result-item .snippet {
            margin-top: 2px; color: rgba(255,255,255,0.6);
        }
        .search-result-item mark {
            background: rgba(249,115,22,0.3); color: white; border-radius: 2px; padding: 0 2px;
        }
        .search-count {
            padding: 8px 12px; font-size: 11px; color: rgba(255,255,255,0.5);
            border-top: 1px solid rgba(255,255,255,0.06);
        }

        /* ===== ZOOM ===== */
        .reading-stage.zoomed {
            cursor: grab;
            overflow: auto;
        }
        .reading-stage.zoomed:active { cursor: grabbing; }
        .reading-stage.zoomed .book-container {
            transform-origin: center center;
        }
        .zoom-controls {
            position: absolute;
            bottom: calc(var(--toolbar-height) + 16px); right: 16px;
            display: flex; flex-direction: column; gap: 4px;
            z-index: 60;
        }
        .zoom-btn {
            width: 36px; height: 36px;
            border: none; border-radius: 50%;
            background: rgba(0,0,0,0.6);
            color: white; font-size: 18px;
            cursor: pointer; backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            transition: background 0.15s;
        }
        .zoom-btn:hover { background: rgba(0,0,0,0.8); }

        /* ===== ACCESSIBILITY ===== */
        .sr-only {
            position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0,0,0,0); white-space: nowrap; border: 0;
        }
        :focus-visible {
            outline: 2px solid var(--brand-accent);
            outline-offset: 2px;
        }
        .tb-btn:focus-visible, .nav-arrow:focus-visible, .narration-play:focus-visible {
            outline: 2px solid var(--brand-accent);
            outline-offset: 2px;
        }

        @media (prefers-contrast: more) {
            .reader-top-bar {
                background: rgba(0,0,0,0.95);
                border-color: rgba(255,255,255,0.3);
            }
            .tb-btn { border: 1px solid rgba(255,255,255,0.3); }
            .nav-arrow { background: rgba(0,0,0,0.8); border: 1px solid rgba(255,255,255,0.5); }
        }
    </style>
</head>
<body>
    <div class="reader-shell" 
         x-data="smartBookReader" 
         @keydown.left.window="prevPage()"
         @keydown.right.window="nextPage()"
         @keydown.escape.window="close()"
         @click="showControls()"
         @mousemove="showControls()"
         :class="{ 'controls-hidden': !controlsVisible }">

        <!-- Loading overlay -->
        <div class="reader-loading" x-show="loading" x-transition.opacity>
            <div class="spinner"></div>
            <div class="loading-label">Loading book...</div>
        </div>

        <!-- ARIA live region for screen readers -->
        <div class="sr-only" aria-live="polite" aria-atomic="true" x-text="pageLabel"></div>

        <!-- TOP TOOLBAR -->
        <header class="reader-top-bar">
            <div class="top-bar-left">
                <span class="book-title">{{ $book->title }}</span>
            </div>

            <div class="top-bar-center">
                <!-- Language selector -->
                <select class="tb-select" x-model="language" @change="changeLanguage()">
                    <option value="en">English</option>
                    @foreach($book->translations as $t)
                        <option value="{{ $t->language_code }}">{{ $t->language_name }}</option>
                    @endforeach
                </select>

                <!-- View mode toggle -->
                <div class="view-toggle">
                    <button class="tb-btn" :class="{ active: viewMode === 'single' }" 
                            @click="setViewMode('single')" title="Single page">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="6" y="3" width="12" height="18" rx="1"/>
                        </svg>
                    </button>
                    <button class="tb-btn" :class="{ active: viewMode === 'auto' }" 
                            @click="setViewMode('auto')" title="Auto">
                        A
                    </button>
                    <button class="tb-btn" :class="{ active: viewMode === 'spread' }" 
                            @click="setViewMode('spread')" title="Two-page spread">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="3" width="9" height="18" rx="1"/>
                            <rect x="13" y="3" width="9" height="18" rx="1"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="top-bar-right">
                <a href="{{ url()->previous() }}" class="tb-btn" title="Close reader" @click.prevent="close()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </a>
            </div>
        </header>

        <!-- READING STAGE -->
        <main class="reading-stage" 
              @touchstart="touchStart($event); panStart($event)" 
              @touchmove="panMove($event)"
              @touchend="touchEnd($event); panEnd()"
              @mousedown="panStart($event)"
              @mousemove.window="panMove($event)"
              @mouseup.window="panEnd()"
              @dblclick="handleDoubleClick($event)"
              role="main"
              :aria-label="'Book reader - ' + pageLabel">

            <!-- Nav arrows -->
            <button class="nav-arrow prev" @click.stop="prevPage()" :disabled="currentPage <= 1" aria-label="Previous page">
                &#10094;
            </button>

            <!-- Book container — StPageFlip mount (page-curl prototype approved) -->
            <div id="stpageflip-mount" style="touch-action:none;"></div>

            <button class="nav-arrow next" @click.stop="nextPage()" :disabled="currentPage >= totalPages" aria-label="Next page">
                &#10095;
            </button>
        </main>

        <!-- FULL-WIDTH BOTTOM CONTROL BAR (FlipHTML5 model) -->
        <footer class="reader-bottom-bar">
            <!-- LEFT cluster: zoom, search, thumbnails, play, then page count -->
            <div class="bottom-left">
                <button class="tb-btn icon-btn" @click="zoomToggle()" :class="{ active: zoom > 1 }" title="Zoom">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
                        <path x-show="zoom <= 1" d="M11 8v6M8 11h6"/>
                        <path x-show="zoom > 1" d="M8 11h6"/>
                    </svg>
                </button>
                <button class="tb-btn icon-btn" @click="openSearch()" title="Search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                    </svg>
                </button>
                <button class="tb-btn icon-btn" @click="toggleThumbnails()" :class="{ active: thumbnailsOpen }" title="Pages" aria-label="Show page thumbnails">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
                    </svg>
                </button>
                <button class="tb-btn icon-btn narration-btn" 
                        @click="toggleNarration()" 
                        :class="{ active: narrationPlaying }"
                        :title="narrationPlaying ? 'Pause narration' : 'Play narration'"
                        :disabled="!narrationAvailable"
                        :style="!narrationAvailable && 'opacity:0.4;cursor:default'">
                    <svg x-show="!narrationPlaying" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                    <svg x-show="narrationPlaying" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M6 4h4v16H6zM14 4h4v16h-4z"/>
                    </svg>
                </button>
                <select class="tb-select" x-show="narrationPlaying" 
                        x-model.number="narrationSpeed" @change="setNarrationSpeed(narrationSpeed)"
                        style="width:52px;font-size:11px;height:28px;padding:0 4px;">
                    <option value="0.75">0.75x</option>
                    <option value="1">1x</option>
                    <option value="1.25">1.25x</option>
                    <option value="1.5">1.5x</option>
                </select>
                <span class="page-indicator" x-text="pageCounter" @click.stop="if(initialized) openPageJump()" title="Click to jump to page"></span>
            </div>

            <!-- CENTER: long full-width seek slider -->
            <div class="bottom-center">
                <div class="progress-track" @click="seekToPage($event)" title="Seek">
                    <div class="progress-fill" :style="{ width: progressPct + '%' }"></div>
                    <div class="progress-knob" :style="{ left: progressPct + '%' }"></div>
                </div>
            </div>

            <!-- RIGHT cluster: mute, fullscreen -->
            <div class="bottom-right">
                <button class="tb-btn icon-btn" @click="toggleMute()" x-show="narrationAvailable"
                        :title="narrationMuted ? 'Unmute' : 'Mute'">
                    <svg x-show="!narrationMuted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 010 7M19 5a9 9 0 010 14"/>
                    </svg>
                    <svg x-show="narrationMuted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M22 9l-6 6M16 9l6 6"/>
                    </svg>
                </button>
                <button class="tb-btn icon-btn" @click="toggleFullscreen()" title="Fullscreen">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/>
                    </svg>
                </button>
            </div>
        </footer>

    <!-- SEARCH PANEL -->
    <div class="search-panel" x-show="searchOpen" x-cloak x-transition>
        <div class="search-header">
            <input type="text" class="search-input" 
                   placeholder="Search text..." 
                   x-model="searchQuery"
                   @input.debounce.300ms="performSearch()"
                   @keydown.escape="searchOpen = false"
                   x-ref="searchInput">
            <button class="tb-btn" @click="searchOpen = false" style="min-width:28px;height:28px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="search-results">
            <template x-for="(result, idx) in searchResults" :key="idx">
                <div class="search-result-item" @click="goToPage(result.page); searchOpen = false;">
                    <span class="page-num" x-text="'Page ' + result.page"></span>
                    <div class="snippet" x-html="result.snippet"></div>
                </div>
            </template>
            <div x-show="searchQuery && searchResults.length === 0" style="padding:16px;text-align:center;font-size:12px;color:rgba(255,255,255,0.4);">
                No results found
            </div>
        </div>
        <div class="search-count" x-show="searchResults.length > 0" x-text="searchResults.length + ' results'"></div>
    </div>

    <!-- ZOOM: reset button floats bottom-right only while zoomed (zoom-in is in the bottom bar) -->
    <div class="zoom-controls" x-show="zoom > 1" x-cloak>
        <button class="zoom-btn" @click="resetZoom()" title="Reset zoom" style="font-size:12px;">1:1</button>
    </div>

    <!-- THUMBNAIL FILMSTRIP (docks above the bottom bar, horizontal scroll with chevrons) -->
    <div class="thumbnail-drawer" 
         x-show="thumbnailsOpen"
         x-cloak 
         x-transition:enter="drawer-enter"
         x-transition:leave="drawer-leave"
         @click.outside="thumbnailsOpen = false">
        <button class="filmstrip-nav prev" @click.stop="scrollThumbs(-1)" aria-label="Scroll thumbnails left">&#10094;</button>
        <div class="thumbnail-grid" x-ref="thumbnailGrid">
            <template x-for="page in totalPages" :key="page">
                <div class="thumbnail-item" 
                     :class="{ active: currentPage === page || (displayMode === 'spread' && page === currentPage + 1) }"
                     @click="goToPage(page); thumbnailsOpen = false;">
                    <canvas :id="'thumb-' + page" class="thumbnail-canvas" width="120" height="90"></canvas>
                    <span class="thumbnail-number" x-text="page"></span>
                </div>
            </template>
        </div>
        <button class="filmstrip-nav next" @click.stop="scrollThumbs(1)" aria-label="Scroll thumbnails right">&#10095;</button>
    </div>

    <!-- PAGE JUMP DIALOG -->
    <div class="page-jump-overlay" x-show="pageJumpOpen" x-cloak x-transition.opacity @click.self="pageJumpOpen = false">
        <div class="page-jump-dialog">
            <label class="page-jump-label">Go to page</label>
            <input type="number" class="page-jump-input" 
                   x-model.number="pageJumpValue" 
                   min="1" :max="totalPages"
                   @keydown.enter="goToPage(pageJumpValue); pageJumpOpen = false;"
                   x-ref="pageJumpInput">
            <div class="page-jump-actions">
                <button class="tb-btn" @click="pageJumpOpen = false">Cancel</button>
                <button class="tb-btn active" @click="goToPage(pageJumpValue); pageJumpOpen = false;">Go</button>
            </div>
        </div>
    </div>
    </div><!-- /.reader-shell (now wraps all panels so their @click bindings work) -->

    <!-- PDF.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <!-- StPageFlip 2.0.7 (pinned) — page-curl turn effect -->
    <script src="https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.min.js"></script>
    <script>
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    // Register with Alpine the officially-recommended way (Alpine.data on alpine:init).
    // This avoids the global-function x-data pitfalls that were preventing the reader
    // component's directives (@click etc.) from binding.
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('smartBookReader', smartBookReader);
    });

    function smartBookReader() {
        // Store PDF document OUTSIDE Alpine's reactive proxy (PDF.js uses private fields)
        let _pdfDoc = null;

        return {
            // === STATE ===
            loading: true,
            pdfDoc: null,  // Don't use this for PDF.js calls — use _pdfDoc instead
            totalPages: {{ $book->page_count }},
            currentPage: 1,
            bookId: {{ $book->id }},
            viewMode: 'auto',      // 'auto', 'single', 'spread'
            displayMode: 'single', // actual current mode: 'single' or 'spread'
            hasRightPage: false,   // whether the current spread has a right page to show
            language: '{{ $lang ?? "en" }}',
            controlsVisible: true,
            narrationPlaying: false,
            thumbnailsOpen: false,
            pageJumpOpen: false,
            pageJumpValue: 1,
            thumbnailsRendered: false,
            initialized: false,
            
            // Layout
            pageWidth: 0,
            pageHeight: 0,
            stageWidth: 0,
            stageHeight: 0,
            renderedPageWidth: 0,
            renderedPageHeight: 0,

            // Config
            pdfUrl: '{{ asset("storage/" . str_replace(" ", "%20", $pdfPath)) }}',
            minimumRenderedPageWidth: 120,
            controlsTimeout: null,
            // FlipHTML5-style book layout config (book-agnostic — no per-book constants).
            bookMargin: { top: 16, bottom: 16, left: 16, right: 16 }, // px gap: stage edge → book
            // Nav arrows are absolutely-positioned overlays (they float over the stage
            // edges), so they must NOT steal width from the book. Reserving 96px here was
            // what starved the windowed spread of horizontal room. Keep a small buffer so
            // the book doesn't sit flush under the arrow circles.
            arrowSpace: 40,           // small breathing room past the overlay arrows
            startOnDoublePage: false, // FlipHTML5 "Start on Double-page": pair from cover
            centerBook: true,         // FlipHTML5 "Center the Book"
            // Per-edge crop fractions of the MediaBox (book-agnostic, detected per PDF).
            // Zero on all edges = display as-is. No hardcoded percentages.
            cropBox: @json($book->getCropBoxFractions()),

            // Narration
            narrationAvailable: Object.keys(@json($pageAudioMap ?? [])).length > 0,

            // Animation
            pageAnimation: 'flip', // 'flip', 'slide', 'none'
            isAnimating: false,

            // Render concurrency guards (prevent PDF.js "same canvas" errors)
            _rendering: false,
            _renderPending: false,
            _resizeTimer: null,
            _layoutRetry: null,

            // Search
            searchOpen: false,
            searchQuery: '',
            searchResults: [],
            pageTextCache: {},  // page_num → extracted text

            // Zoom
            zoom: 1,
            maxZoom: 3,
            minZoom: 1,
            // Pan (drag when zoomed)
            _panX: 0,
            _panY: 0,
            _panning: false,
            _panStartX: 0,
            _panStartY: 0,

            // Preloading
            preloadedPages: {},  // page_num → rendered (true/false)
            renderQueue: [],
            pageAudioMap: @json($pageAudioMap ?? []),
            pageTimingMap: @json($pageTimingMap ?? []),
            narrationSpeed: 1.0,
            narrationVolume: 1.0,
            narrationMuted: false,
            autoPageTurn: true,
            highlightEnabled: true,
            currentWord: null,
            currentSentence: null,
            audioElement: null,
            highlightInterval: null,

            // Touch gesture tracking
            touchStartX: 0,
            touchStartY: 0,

            // === COMPUTED ===
            get pageLabel() {
                if (this.displayMode === 'spread' && this.currentPage < this.totalPages) {
                    const right = Math.min(this.currentPage + 1, this.totalPages);
                    return `Pages ${this.currentPage}-${right} of ${this.totalPages}`;
                }
                return `Page ${this.currentPage} of ${this.totalPages}`;
            },

            get progressPct() {
                return ((this.currentPage - 1) / Math.max(this.totalPages - 1, 1)) * 100;
            },

            // Compact "current/total" counter for the bottom bar (FlipHTML5 style).
            get pageCounter() {
                if (this.displayMode === 'spread' && this.hasRightPage) {
                    const right = Math.min(this.currentPage + 1, this.totalPages);
                    return `${this.currentPage}-${right}/${this.totalPages}`;
                }
                return `${this.currentPage}/${this.totalPages}`;
            },

            get pageStyle() {
                if (this.renderedPageWidth <= 0 || this.renderedPageHeight <= 0) {
                    return { width: '400px', height: '560px' }; // Fallback while loading
                }
                return {
                    width: this.renderedPageWidth + 'px',
                    height: this.renderedPageHeight + 'px',
                };
            },

            get isCoarsePointer() {
                return window.matchMedia('(pointer: coarse)').matches;
            },

            // === LIFECYCLE ===
            close() {
                // If we're in fullscreen, just exit fullscreen (don't leave the reader).
                if (document.fullscreenElement) {
                    document.exitFullscreen().catch(() => {});
                    return;
                }
                // Otherwise leave the reader: go back, or fall back to the store.
                if (window.history.length > 1) window.history.back();
                else window.location.href = '{{ route('store') }}';
            },

            async init() {
                try {
                    // Load PDF
                    console.log('[Reader] Loading PDF:', this.pdfUrl);
                    _pdfDoc = await pdfjsLib.getDocument(this.pdfUrl).promise;
                    console.log('[Reader] PDF loaded, pages:', _pdfDoc.numPages);
                    
                    // Get page dimensions from first page
                    const firstPage = await _pdfDoc.getPage(1);
                    const vp = firstPage.getViewport({ scale: 1 });
                    // Store CROPPED dimensions (actual visible page) using per-edge trim.
                    const cb = this.cropBox;
                    this.pageWidth = vp.width * (1 - cb.left - cb.right);
                    this.pageHeight = vp.height * (1 - cb.top - cb.bottom);
                    console.log('[Reader] Page dimensions (cropped):', this.pageWidth, 'x', this.pageHeight, 'cropBox:', cb);

                    // Calculate layout
                    this.calculateLayout();

                    // === StPageFlip integration (approved prototype, task 6) ===
                    // Build the page-curl flipbook. All pages rendered up front (small book).
                    await this._buildPageFlip();

                    // Debounced resize: StPageFlip is fixed-size so we don't re-layout, but
                    // we keep the handler alive so thumbnails/search/narration that depend on
                    // Alpine's layout flag still work.
                    window.addEventListener('resize', () => {
                        clearTimeout(this._resizeTimer);
                        this._resizeTimer = setTimeout(() => {
                            this.calculateLayout();
                        }, 150);
                    });
                    document.addEventListener('fullscreenchange', () => {
                        clearTimeout(this._resizeTimer);
                        this._resizeTimer = setTimeout(() => {
                            this.calculateLayout();
                        }, 120);
                    });

                    // Restore starting page (deep-link / saved position).
                    this.currentPage = this.resolveInitialPage();
                    if (this.currentPage > 1 && this._pageFlip) {
                        this._pageFlip.turnToPage(this.currentPage - 1); // 0-indexed
                    }

                    this.loading = false;
                    this.initialized = true;
                    console.log('[Reader] Ready with StPageFlip curl.');

                    // Start auto-hide timer (longer on first load)
                    this.startControlsTimer(8000);
                } catch (e) {
                    console.error('[Reader] Init failed:', e);
                    this.loading = false;
                }
            },

            // === STPAGEFLIP INTEGRATION (approved prototype, ported task 6) ===
            _pageFlip: null,

            async _buildPageFlip() {
                const mount = document.getElementById('stpageflip-mount');
                if (!mount || !_pdfDoc) return;

                const DPR = Math.min(window.devicePixelRatio || 1, 2);
                const cb = this.cropBox;
                const ratio = this.pageWidth / this.pageHeight;

                // Size the page so a two-up spread fits the viewport.
                const maxH = Math.min(window.innerHeight - 140, 900);
                const maxW = Math.min(window.innerWidth - 80, 1400);
                const pageFromH = Math.round(maxH);
                const pageFromW = Math.round(maxH * ratio);
                const spreadW = pageFromW * 2;
                let pageW, pageH;
                if (spreadW > maxW) {
                    pageW = Math.round(maxW / 2);
                    pageH = Math.round(pageW / ratio);
                } else {
                    pageH = pageFromH;
                    pageW = pageFromW;
                }

                // Build one .stflip-page per PDF page with a DPR-crisp canvas.
                for (let i = 1; i <= this.totalPages; i++) {
                    const el = document.createElement('div');
                    el.className = 'stflip-page';
                    el.setAttribute('data-density', (i === 1 || i === this.totalPages) ? 'hard' : 'soft');
                    const cvs = document.createElement('canvas');
                    cvs.id = 'stflip-canvas-' + i;
                    el.appendChild(cvs);
                    mount.appendChild(el);
                }

                // Render all pages (cropped, DPR-crisp).
                for (let i = 1; i <= this.totalPages; i++) {
                    const page = await _pdfDoc.getPage(i);
                    const baseVp = page.getViewport({ scale: 1 });
                    const scale = (pageW * DPR) / (baseVp.width * (1 - cb.left - cb.right));
                    const vp = page.getViewport({ scale });
                    const cvs = document.getElementById('stflip-canvas-' + i);
                    const ctx = cvs.getContext('2d');
                    cvs.width = Math.round(vp.width * (1 - cb.left - cb.right));
                    cvs.height = Math.round(vp.height * (1 - cb.top - cb.bottom));
                    ctx.save();
                    ctx.translate(-vp.width * cb.left, -vp.height * cb.top);
                    await page.render({ canvasContext: ctx, viewport: vp }).promise;
                    ctx.restore();
                }

                // Init StPageFlip (approved settings from prototype).
                this._pageFlip = new St.PageFlip(mount, {
                    width: pageW,
                    height: pageH,
                    size: 'fixed',
                    usePortrait: false,
                    showCover: true,
                    maxShadowOpacity: 0.5,
                    drawShadow: true,
                    flippingTime: 700,
                    useMouseEvents: true,
                    disableFlipByClick: true,
                });

                const self = this;
                this._pageFlip.on('flip', (e) => {
                    // StPageFlip page index is 0-based; our currentPage is 1-based.
                    self.currentPage = e.data + 1;
                    self.persistPosition();
                });

                this._pageFlip.loadFromHTML(mount.querySelectorAll('.stflip-page'));
                console.log('[Reader] StPageFlip initialized, pages:', this.totalPages);
            },

            // === LAYOUT (CSS-sized flex model — no stage measurement, no pixel math) ===
            // We no longer compute page pixel sizes in JS. CSS sizes the pages (flex +
            // max-width/max-height). This method only decides spread vs single, using the
            // window width (always available, never collapses → no loops, no timing bugs).
            calculateLayout() {
                this.displayMode = this.shouldUseSpread() ? 'spread' : 'single';
            },

            // Default Page View: Auto | single | spread. Decision uses window.innerWidth so
            // it's immediate and reliable (no reliance on measured element sizes).
            shouldUseSpread() {
                if (this.viewMode === 'single') return false;

                // Cover and back cover are ALWAYS shown alone (unless startOnDoublePage).
                if (!this.startOnDoublePage && this._isSoloPage(this.currentPage)) return false;

                // Manual spread override: always give a spread on a wide-enough window.
                if (this.viewMode === 'spread') return window.innerWidth >= 700;

                // AUTO: spread on landscape windows wide enough for two pages side by side.
                const w = window.innerWidth, h = window.innerHeight;
                const landscape = w >= h;
                return landscape && w >= 900;   // portrait books need a wide window for 2-up
            },

            // Pages that must render alone: the cover (1) and the back cover (last page).
            _isSoloPage(pageNum) {
                return pageNum === 1 || pageNum === this.totalPages;
            },

            // === PAGE PAIRING (pure — no side effects) ===
            // Returns [leftPage, rightPage|null] for the CURRENT page, following the
            // FlipHTML5 model: cover alone, then interior spreads 2-3, 4-5, ..., back cover alone.
            getSpreadPages() {
                const p = this.currentPage;

                // Cover always alone (unless explicitly starting on a double page).
                if (!this.startOnDoublePage && p === 1) return [1, null];
                // Back cover always alone.
                if (p === this.totalPages) return [this.totalPages, null];

                // Normalise to the left page of the spread this page belongs to.
                // With cover alone, interior spreads start on EVEN left pages (2-3, 4-5, ...).
                const left = (p % 2 === 0) ? p : p - 1;
                const right = left + 1;

                // Never pair with the back cover — show the left page alone instead.
                if (right >= this.totalPages) return [left, null];

                return [left, right];
            },

            // === RENDERING ===
            async renderCurrentView() {
                // Re-entrancy lock: if a render is already running, mark that another
                // is needed and return. PDF.js can't render the same canvas twice at once.
                if (this._rendering) { this._renderPending = true; return; }
                this._rendering = true;
                try {
                    do {
                        this._renderPending = false;
                        await this._renderCurrentViewInner();
                    } while (this._renderPending);
                } finally {
                    this._rendering = false;
                }
            },

            async _renderCurrentViewInner() {
                const canvasLeft = document.getElementById('canvas-left');
                const canvasRight = document.getElementById('canvas-right');

                if (this.displayMode === 'spread') {
                    const [leftNum, rightNum] = this.getSpreadPages();
                    await this.renderPage(leftNum, canvasLeft);
                    if (rightNum) {
                        // Right page visibility is driven by x-show="displayMode==='spread'"
                        // AND we only render into it when there's a right page. Use a data
                        // flag so the markup can hide the right surface on solo pages.
                        this.hasRightPage = true;
                        await this.$nextTick();
                        await this.renderPage(rightNum, canvasRight);
                    } else {
                        this.hasRightPage = false;
                    }
                } else {
                    this.hasRightPage = false;
                    await this.renderPage(this.currentPage, canvasLeft);
                }
                // Preload adjacent pages in background
                this.preloadAdjacentPages();
            },

            async renderPage(pageNum, canvas) {
                if (!_pdfDoc || !canvas || pageNum < 1 || pageNum > this.totalPages) {
                    console.warn('[Reader] renderPage skipped:', { hasPdf: !!_pdfDoc, hasCanvas: !!canvas, pageNum });
                    return;
                }

                // Cancel any render task still running on THIS canvas — PDF.js forbids
                // two concurrent render() calls on the same canvas (the init error).
                if (canvas._renderTask) {
                    try { canvas._renderTask.cancel(); } catch (e) { /* already done */ }
                    canvas._renderTask = null;
                }

                const page = await _pdfDoc.getPage(pageNum);
                const dpr = Math.min(window.devicePixelRatio || 1, 2); // cap DPR for memory

                // Get full viewport first
                const fullViewport = page.getViewport({ scale: 1 });
                const fw = fullViewport.width;
                const fh = fullViewport.height;

                // Calculate crop (remove bleed/cropmarks) — per-edge, asymmetric.
                const cb = this.cropBox;
                const cropL = fw * cb.left;
                const cropT = fh * cb.top;
                const cropR = fw * cb.right;
                const cropB = fh * cb.bottom;
                const croppedW = fw - cropL - cropR;
                const croppedH = fh - cropT - cropB;
                if (croppedW <= 0 || croppedH <= 0) return;

                // Target DISPLAY width: derive from the stage, letting CSS do the final fit.
                // In spread mode two pages share the width, so each gets ~half. We also cap
                // by height so tall portrait pages stay within the viewport. CSS
                // (max-width/height on the canvas) is the safety net — this just picks a
                // crisp render resolution. No fragile element measurement / no loops.
                const stage = document.querySelector('.reading-stage');
                const availW = (stage?.clientWidth || window.innerWidth) - 32;
                const availH = (stage?.clientHeight || (window.innerHeight - 2 * 48)) - 24;
                const perPageW = this.displayMode === 'spread' ? (availW - 8) / 2 : availW;
                // Fit the cropped page into (perPageW x availH) preserving aspect ratio.
                const fitScale = Math.min(perPageW / croppedW, availH / croppedH);
                const targetCssW = Math.max(40, Math.floor(croppedW * fitScale));

                // Render at target size * DPR for crispness; CSS shows it at CSS px.
                const scale = (targetCssW / croppedW) * dpr;
                const viewport = page.getViewport({ scale });

                canvas.width = Math.floor(croppedW * scale);
                canvas.height = Math.floor(croppedH * scale);
                // Let CSS size the display box (width:auto/height:auto + max-* caps).
                canvas.style.width = '';
                canvas.style.height = '';

                const ctx = canvas.getContext('2d');
                ctx.setTransform(1, 0, 0, 1, 0, 0);
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Offset so the trim box's top-left maps to canvas (0,0).
                const offsetX = -cropL * scale;
                const offsetY = -cropT * scale;
                ctx.translate(offsetX, offsetY);

                const task = page.render({ canvasContext: ctx, viewport });
                canvas._renderTask = task;
                try {
                    await task.promise;
                } catch (e) {
                    if (e && e.name === 'RenderingCancelledException') return; // expected on rapid nav/resize
                    throw e;
                } finally {
                    if (canvas._renderTask === task) canvas._renderTask = null;
                }

                ctx.setTransform(1, 0, 0, 1, 0, 0);
            },

            // === NAVIGATION (StPageFlip-driven page-curl) ===
            async nextPage() {
                if (this._pageFlip) {
                    this._pageFlip.flipNext('top');
                }
            },

            async prevPage() {
                if (this._pageFlip) {
                    this._pageFlip.flipPrev('top');
                }
            },

            // Advance to the first page of the NEXT visible unit (page or spread).
            _nextAnchor() {
                if (this.displayMode !== 'spread') {
                    return Math.min(this.currentPage + 1, this.totalPages);
                }
                const [left, right] = this.getSpreadPages();
                const last = right ?? left;               // last page currently visible
                return Math.min(last + 1, this.totalPages); // start of next unit
            },

            // Go back to the first page of the PREVIOUS visible unit.
            _prevAnchor() {
                if (this.displayMode !== 'spread') {
                    return Math.max(this.currentPage - 1, 1);
                }
                const [left] = this.getSpreadPages();
                return Math.max(left - 1, 1);              // step to page before this spread
            },

            // === PAGE-TURN ANIMATION (snapshot overlay) ===
            // Capture the currently-rendered book as an image overlay pinned over the stage.
            // Because it's a static bitmap, we can freely animate it OUT while the real
            // book re-renders the new page underneath — so the turn is always visible and
            // never races the PDF.js render. Returns the overlay element (or null).
            _snapshotStage(direction) {
                if (this.pageAnimation === 'none') return null;
                const container = document.querySelector('.book-container');
                const stage = document.querySelector('.reading-stage');
                if (!container || !stage) return null;

                const cRect = container.getBoundingClientRect();
                const sRect = stage.getBoundingClientRect();
                if (cRect.width < 2 || cRect.height < 2) return null;

                // Compose the visible canvases into one snapshot bitmap.
                const snapCanvas = document.createElement('canvas');
                const dpr = window.devicePixelRatio || 1;
                snapCanvas.width = Math.max(1, Math.floor(cRect.width * dpr));
                snapCanvas.height = Math.max(1, Math.floor(cRect.height * dpr));
                const ctx = snapCanvas.getContext('2d');
                ctx.scale(dpr, dpr);
                container.querySelectorAll('canvas').forEach((cv) => {
                    const r = cv.getBoundingClientRect();
                    if (r.width < 1 || r.height < 1) return;
                    try { ctx.drawImage(cv, r.left - cRect.left, r.top - cRect.top, r.width, r.height); }
                    catch (e) { /* tainted/empty canvas — skip */ }
                });

                const overlay = document.createElement('div');
                overlay.className = 'turn-overlay';
                overlay.style.cssText =
                    `position:absolute;left:${cRect.left - sRect.left}px;top:${cRect.top - sRect.top}px;` +
                    `width:${cRect.width}px;height:${cRect.height}px;z-index:70;pointer-events:none;` +
                    `will-change:transform,opacity;transform-origin:${direction === 'next' ? 'left center' : 'right center'};`;
                snapCanvas.style.cssText = 'width:100%;height:100%;display:block;box-shadow:0 8px 32px rgba(0,0,0,0.35);';
                overlay.appendChild(snapCanvas);
                overlay.dataset.direction = direction;
                stage.appendChild(overlay);
                return overlay;
            },

            // Animate the snapshot overlay OUT, then remove it. The real book (new page)
            // is already rendered beneath, so it's revealed as the overlay turns away.
            async _runTurn(overlay) {
                if (!overlay || this.pageAnimation === 'none') { if (overlay) overlay.remove(); return; }
                this.isAnimating = true;
                const direction = overlay.dataset.direction;
                const duration = this.pageAnimation === 'flip' ? 320 : 240;

                const keyframes = this.pageAnimation === 'flip'
                    ? (direction === 'next'
                        ? [{ transform: 'rotateY(0deg)', opacity: 1 }, { transform: 'rotateY(-95deg)', opacity: 0.4 }]
                        : [{ transform: 'rotateY(0deg)', opacity: 1 }, { transform: 'rotateY(95deg)', opacity: 0.4 }])
                    : (direction === 'next'
                        ? [{ transform: 'translateX(0)', opacity: 1 }, { transform: 'translateX(-40px)', opacity: 0 }]
                        : [{ transform: 'translateX(0)', opacity: 1 }, { transform: 'translateX(40px)', opacity: 0 }]);

                const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (prefersReduced || !overlay.animate) {
                    overlay.remove();
                    this.isAnimating = false;
                    return;
                }

                await new Promise((resolve) => {
                    const anim = overlay.animate(keyframes, { duration, easing: 'ease-in-out', fill: 'forwards' });
                    anim.onfinish = anim.oncancel = resolve;
                });
                overlay.remove();
                this.isAnimating = false;
            },

            // === DEEP-LINK + SAVED READING POSITION (premium brief) ===
            _positionKey() {
                return `reader:pos:${this.bookId}:${this.language}`;
            },

            resolveInitialPage() {
                // 1) Explicit deep-link: #page=N (preferred) or ?page=N.
                const fromHash = (location.hash.match(/page=(\d+)/) || [])[1];
                const fromQuery = new URLSearchParams(location.search).get('page');
                const deep = parseInt(fromHash || fromQuery || '', 10);
                if (!Number.isNaN(deep)) {
                    return Math.max(1, Math.min(deep, this.totalPages));
                }
                // 2) Saved last-read position for this book+language.
                try {
                    const saved = parseInt(localStorage.getItem(this._positionKey()) || '', 10);
                    if (!Number.isNaN(saved)) {
                        return Math.max(1, Math.min(saved, this.totalPages));
                    }
                } catch (e) { /* localStorage unavailable — ignore */ }
                return 1;
            },

            persistPosition() {
                // Save last-read page and reflect it in a shareable URL hash (no reload).
                try { localStorage.setItem(this._positionKey(), String(this.currentPage)); }
                catch (e) { /* ignore */ }
                try {
                    const url = new URL(location.href);
                    url.hash = `page=${this.currentPage}`;
                    history.replaceState(null, '', url);
                } catch (e) { /* ignore */ }
            },

            async goToPage(pageNum) {
                if (this.zoom > 1) this.resetZoom();
                this.currentPage = Math.max(1, Math.min(pageNum, this.totalPages));
                if (this._pageFlip) {
                    this._pageFlip.flip(this.currentPage - 1); // 0-indexed, animated curl
                }
                this.persistPosition();
            },

            seekToPage(event) {
                const track = event.currentTarget;
                const rect = track.getBoundingClientRect();
                const pct = (event.clientX - rect.left) / rect.width;
                const page = Math.max(1, Math.round(pct * this.totalPages));
                this.goToPage(page);
            },

            // === VIEW MODE ===
            async setViewMode(mode) {
                this.viewMode = mode;
                // If the user explicitly asks for a spread but is on the cover (which is
                // always shown alone), advance to page 2 so an actual 2-page spread appears.
                if (mode === 'spread' && this.currentPage === 1 && this.totalPages > 2) {
                    this.currentPage = 2;
                }
                this.calculateLayout();
                await this.renderCurrentView();
            },

            // === GESTURES ===
            touchStart(e) {
                this.touchStartX = e.touches[0].clientX;
                this.touchStartY = e.touches[0].clientY;
            },

            touchEnd(e) {
                // Don't page-turn while zoomed — that gesture is used for panning instead.
                if (this.zoom > 1) return;
                const dx = e.changedTouches[0].clientX - this.touchStartX;
                const dy = e.changedTouches[0].clientY - this.touchStartY;

                // Only trigger if horizontal swipe > 50px and not mostly vertical
                if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                    if (dx < 0) this.nextPage();
                    else this.prevPage();
                }
            },

            // === CONTROLS ===
            showControls() {
                this.controlsVisible = true;
                // Reset the auto-hide timer
                if (this.controlsTimeout) clearTimeout(this.controlsTimeout);
                this.controlsTimeout = setTimeout(() => {
                    if (!this.narrationPlaying && !this.searchOpen && !this.thumbnailsOpen && !this.pageJumpOpen) {
                        this.controlsVisible = false;
                    }
                }, 5000);
            },

            hideControls() {
                if (!this.narrationPlaying && !this.searchOpen && !this.thumbnailsOpen) {
                    this.controlsVisible = false;
                }
            },

            startControlsTimer(duration) {
                if (this.controlsTimeout) clearTimeout(this.controlsTimeout);
                this.controlsTimeout = setTimeout(() => {
                    if (!this.narrationPlaying) this.controlsVisible = false;
                }, duration || 4000);
            },

            // === FULLSCREEN ===
            toggleFullscreen() {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                } else {
                    document.documentElement.requestFullscreen().catch(() => {
                        // Fallback: try the reader shell
                        this.$el.requestFullscreen().catch(() => {});
                    });
                }
                // Re-measure + re-render after the viewport settles (covers both enter/exit).
                setTimeout(() => { this.calculateLayout(); this.renderCurrentView(); }, 300);
            },

            // === LANGUAGE ===
            changeLanguage() {
                const url = new URL(window.location);
                url.searchParams.set('lang', this.language);
                window.location.href = url.toString();
            },

            // === NARRATION ===
            toggleNarration() {
                if (!this.narrationAvailable) return;
                
                if (this.narrationPlaying) {
                    this.pauseNarration();
                } else {
                    this.playNarration();
                }
            },

            async playNarration() {
                const pageNum = this.currentPage;
                const audioUrl = this.pageAudioMap[pageNum];
                
                if (!audioUrl) {
                    // No audio for this page — try next narration-enabled page
                    return;
                }

                if (!this.audioElement) {
                    this.audioElement = new Audio();
                    this.audioElement.addEventListener('ended', () => this.onNarrationEnded());
                    this.audioElement.addEventListener('timeupdate', () => this.onNarrationTimeUpdate());
                }

                // Set source if different page
                if (this.audioElement.src !== audioUrl) {
                    this.audioElement.src = audioUrl;
                    this.audioElement.load();
                }

                this.audioElement.playbackRate = this.narrationSpeed;
                this.audioElement.volume = this.narrationMuted ? 0 : this.narrationVolume;
                
                try {
                    await this.audioElement.play();
                    this.narrationPlaying = true;
                    this.showControls(); // Keep controls visible during narration
                } catch (e) {
                    console.warn('Narration play failed:', e);
                }
            },

            pauseNarration() {
                if (this.audioElement) {
                    this.audioElement.pause();
                }
                this.narrationPlaying = false;
                this.currentWord = null;
                this.currentSentence = null;
            },

            stopNarration() {
                this.pauseNarration();
                if (this.audioElement) {
                    this.audioElement.currentTime = 0;
                }
            },

            onNarrationEnded() {
                this.narrationPlaying = false;
                this.currentWord = null;
                this.currentSentence = null;

                // Auto page-turn if enabled
                if (this.autoPageTurn && this.currentPage < this.totalPages) {
                    this.nextPage().then(() => {
                        // Continue narration on next page after a brief pause
                        setTimeout(() => {
                            if (this.pageAudioMap[this.currentPage]) {
                                this.playNarration();
                            }
                        }, 500);
                    });
                }
            },

            onNarrationTimeUpdate() {
                if (!this.highlightEnabled || !this.audioElement) return;

                const currentTime = this.audioElement.currentTime * 1000; // ms
                const timing = this.pageTimingMap[this.currentPage];
                
                if (!timing || !timing.words) return;

                // Find current word
                let foundWord = null;
                let foundSentence = null;

                for (const word of timing.words) {
                    if (currentTime >= word.start && currentTime <= word.end) {
                        foundWord = word;
                        break;
                    }
                }

                // Find current sentence (if sentence data exists)
                if (timing.sentences) {
                    for (const sentence of timing.sentences) {
                        if (currentTime >= sentence.start && currentTime <= sentence.end) {
                            foundSentence = sentence;
                            break;
                        }
                    }
                }

                this.currentWord = foundWord;
                this.currentSentence = foundSentence;
            },

            setNarrationSpeed(speed) {
                this.narrationSpeed = speed;
                if (this.audioElement) {
                    this.audioElement.playbackRate = speed;
                }
            },

            setNarrationVolume(vol) {
                this.narrationVolume = vol;
                if (this.audioElement) {
                    this.audioElement.volume = this.narrationMuted ? 0 : vol;
                }
            },

            toggleMute() {
                this.narrationMuted = !this.narrationMuted;
                if (this.audioElement) {
                    this.audioElement.volume = this.narrationMuted ? 0 : this.narrationVolume;
                }
            },

            // === THUMBNAILS ===
            async toggleThumbnails() {
                this.thumbnailsOpen = !this.thumbnailsOpen;
                if (this.thumbnailsOpen && !this.thumbnailsRendered) {
                    // Render thumbnails lazily on first open
                    this.$nextTick(() => this.renderThumbnails());
                }
                if (this.thumbnailsOpen) {
                    // Scroll to current page
                    this.$nextTick(() => {
                        const grid = this.$refs.thumbnailGrid;
                        const activeItem = grid?.querySelector('.thumbnail-item.active');
                        if (activeItem) activeItem.scrollIntoView({ behavior: 'smooth', inline: 'center' });
                    });
                }
            },

            // Scroll the horizontal filmstrip left/right by roughly one "page" of thumbs.
            scrollThumbs(dir) {
                const grid = this.$refs.thumbnailGrid;
                if (!grid) return;
                grid.scrollBy({ left: dir * Math.max(240, grid.clientWidth * 0.7), behavior: 'smooth' });
            },

            async renderThumbnails() {
                if (!_pdfDoc) return;
                const total = Math.min(this.totalPages, 50); // Cap at 50 for performance
                // Wait a tick so the x-for canvases exist in the DOM.
                await this.$nextTick();
                for (let i = 1; i <= total; i++) {
                    const canvas = document.getElementById('thumb-' + i);
                    if (!canvas) continue;
                    try {
                        const page = await _pdfDoc.getPage(i);
                        // Fit the page into a fixed thumbnail box, preserving aspect ratio.
                        const base = page.getViewport({ scale: 1 });
                        const boxW = 120, boxH = 90; // landscape-friendly box (picture books)
                        const dpr = window.devicePixelRatio || 1;
                        const fit = Math.min(boxW / base.width, boxH / base.height);
                        const dispW = Math.floor(base.width * fit);
                        const dispH = Math.floor(base.height * fit);
                        const vp = page.getViewport({ scale: fit * dpr });
                        // Backing store at DPR for crispness…
                        canvas.width = Math.floor(dispW * dpr);
                        canvas.height = Math.floor(dispH * dpr);
                        // …but DISPLAY at the fitted CSS size so the flex `gap` is preserved
                        // (previously the canvas was stretched to full width, eating the gap).
                        canvas.style.width = dispW + 'px';
                        canvas.style.height = dispH + 'px';
                        const ctx = canvas.getContext('2d');
                        await page.render({ canvasContext: ctx, viewport: vp }).promise;
                    } catch (e) { /* skip failed thumbnails */ }
                }
                this.thumbnailsRendered = true;
            },

            // === PAGE JUMP ===
            openPageJump() {
                this.pageJumpValue = this.currentPage;
                this.pageJumpOpen = true;
                this.$nextTick(() => {
                    const input = this.$refs.pageJumpInput;
                    if (input) { input.focus(); input.select(); }
                });
            },

            // === UTILITIES ===
            // === SEARCH ===
            openSearch() {
                this.searchOpen = true;
                this.$nextTick(() => {
                    const input = this.$refs.searchInput;
                    if (input) input.focus();
                });
            },

            async performSearch() {
                const query = this.searchQuery.trim().toLowerCase();
                if (!query || query.length < 2) {
                    this.searchResults = [];
                    return;
                }

                const results = [];
                
                // Search through all pages
                for (let pageNum = 1; pageNum <= this.totalPages; pageNum++) {
                    const text = await this.getPageText(pageNum);
                    if (!text) continue;

                    const lowerText = text.toLowerCase();
                    let idx = lowerText.indexOf(query);
                    
                    if (idx !== -1) {
                        // Build snippet with highlight
                        const start = Math.max(0, idx - 30);
                        const end = Math.min(text.length, idx + query.length + 30);
                        let snippet = text.substring(start, end);
                        
                        // Wrap match in <mark>
                        const matchStart = idx - start;
                        snippet = snippet.substring(0, matchStart) + 
                                  '<mark>' + snippet.substring(matchStart, matchStart + query.length) + '</mark>' +
                                  snippet.substring(matchStart + query.length);
                        
                        if (start > 0) snippet = '...' + snippet;
                        if (end < text.length) snippet += '...';

                        results.push({ page: pageNum, snippet });
                    }
                }

                this.searchResults = results;
            },

            async getPageText(pageNum) {
                // Cache extracted text per page
                if (this.pageTextCache[pageNum]) return this.pageTextCache[pageNum];
                
                if (!_pdfDoc) return '';
                
                try {
                    const page = await _pdfDoc.getPage(pageNum);
                    const textContent = await page.getTextContent();
                    const text = textContent.items.map(item => item.str).join(' ');
                    this.pageTextCache[pageNum] = text;
                    return text;
                } catch (e) {
                    return '';
                }
            },

            // === ZOOM ===
            // Single-step zoom: one press goes to a fixed zoomed level (not incremental).
            zoomStep: 2,   // fixed zoom level when zoomed in
            zoomIn() {
                this.zoom = this.zoomStep;
                this.applyZoom();
            },

            // One button toggles zoom on/off (matches FlipHTML5's single zoom control).
            zoomToggle() {
                if (this.zoom > 1) this.resetZoom();
                else this.zoomIn();
            },

            zoomOut() {
                this.zoom = 1;
                this._panX = 0;
                this._panY = 0;
                this.applyZoom();
            },

            resetZoom() {
                this.zoom = 1;
                this._panX = 0;
                this._panY = 0;
                this.applyZoom();
            },

            applyZoom() {
                const container = document.querySelector('.book-container');
                const stage = document.querySelector('.reading-stage');
                if (!container || !stage) return;   // guard: elements may not be present yet

                if (this.zoom > 1) {
                    container.style.transform =
                        `translate(${this._panX}px, ${this._panY}px) scale(${this.zoom})`;
                    stage.classList.add('zoomed');
                } else {
                    container.style.transform = '';
                    stage.classList.remove('zoomed');
                }
            },

            handleDoubleClick(event) {
                // Only zoom when the double-click lands INSIDE the rendered book, never on
                // the dark stage background, arrows, or empty space beside a cover.
                const container = document.querySelector('.book-container');
                if (!container) return;
                const r = container.getBoundingClientRect();
                const x = event.clientX, y = event.clientY;
                const insideBook = x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
                if (!insideBook) return; // clicked the background → do nothing

                if (this.zoom > 1) {
                    this.resetZoom();
                } else {
                    this.zoom = this.zoomStep;
                    this.applyZoom();
                }
            },

            // === PAN (drag the zoomed page with a hand cursor) ===
            panStart(e) {
                if (this.zoom <= 1) return;
                this._panning = true;
                const pt = e.touches ? e.touches[0] : e;
                this._panStartX = pt.clientX - this._panX;
                this._panStartY = pt.clientY - this._panY;
            },

            panMove(e) {
                if (!this._panning || this.zoom <= 1) return;
                const pt = e.touches ? e.touches[0] : e;
                this._panX = pt.clientX - this._panStartX;
                this._panY = pt.clientY - this._panStartY;
                this._clampPan();
                this.applyZoom();
                if (e.cancelable) e.preventDefault();
            },

            panEnd() {
                this._panning = false;
            },

            // Keep the zoomed book within reasonable bounds so it can't be dragged
            // entirely off-screen. Book-agnostic: derived from element sizes, not constants.
            _clampPan() {
                const container = document.querySelector('.book-container');
                const stage = document.querySelector('.reading-stage');
                if (!container || !stage) return;
                const sRect = stage.getBoundingClientRect();
                // Overflow beyond the stage on each axis after scaling.
                const overflowX = Math.max(0, (container.offsetWidth * this.zoom - sRect.width) / 2);
                const overflowY = Math.max(0, (container.offsetHeight * this.zoom - sRect.height) / 2);
                this._panX = Math.max(-overflowX, Math.min(overflowX, this._panX));
                this._panY = Math.max(-overflowY, Math.min(overflowY, this._panY));
            },

            // === PRELOADING (Stage 6) ===
            async preloadAdjacentPages() {
                // Preload next and previous pages in background
                const pagesToPreload = [];
                const step = this.displayMode === 'spread' ? 2 : 1;
                
                const nextPage = this.currentPage + step;
                const prevPage = this.currentPage - step;
                
                if (nextPage <= this.totalPages) pagesToPreload.push(nextPage);
                if (this.displayMode === 'spread' && nextPage + 1 <= this.totalPages) pagesToPreload.push(nextPage + 1);
                if (prevPage >= 1) pagesToPreload.push(prevPage);

                for (const pageNum of pagesToPreload) {
                    if (!this.preloadedPages[pageNum]) {
                        try {
                            await _pdfDoc.getPage(pageNum);
                            this.preloadedPages[pageNum] = true;
                        } catch (e) { /* silent */ }
                    }
                }
            },
        };
    }
    </script>
    <!-- Alpine.js — loaded AFTER component function is defined -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
