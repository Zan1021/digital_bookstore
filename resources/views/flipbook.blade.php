<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>{{ $book->title }}</title>
    <style>
        /* Playpen Sans — Primary children's book font */
        @font-face {
            font-family: 'Playpen Sans';
            src: url('/fonts/PlaypenSans-Regular.ttf') format('truetype');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Playpen Sans';
            src: url('/fonts/PlaypenSans-Medium.ttf') format('truetype');
            font-weight: 500;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Playpen Sans';
            src: url('/fonts/PlaypenSans-SemiBold.ttf') format('truetype');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Playpen Sans';
            src: url('/fonts/PlaypenSans-Bold.ttf') format('truetype');
            font-weight: 700;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Grade 1';
            src: url('/fonts/Grade1Font.ttf') format('truetype');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            width: 100%;
            height: 100dvh;
            overflow: hidden;
            background: #1a1a2e;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            user-select: none;
        }

        /* Main layout */
        .book-viewport {
            width: 100%;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        #flipbook-container {
            position: relative;
        }

        /* No gap between pages */
        .stf__wrapper { margin: 0 !important; }

        .page-content {
            width: 100%;
            height: 100%;
            background: white;
            position: relative;
            overflow: hidden;
        }

        .page-content img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            pointer-events: none;
            display: block;
        }

        /* Play button — center horizontal, 20px above page bottom (stays on the page) */
        .page-play-btn {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(99, 102, 241, 0.9);
            border: 3px solid rgba(255, 255, 255, 0.95);
            color: white;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            z-index: 20;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
        }

        .page-play-btn:hover {
            background: rgba(79, 70, 229, 1);
            transform: translateX(-50%) scale(1.1);
        }

        .page-play-btn.playing {
            background: rgba(239, 68, 68, 0.9);
            animation: pulse-ring 1.5s infinite;
        }

        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        /* Word highlight overlay */
        .text-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            padding: 12px 16px;
            display: none;
            z-index: 15;
        }

        .text-overlay.visible { display: block; }

        .text-overlay .word {
            display: inline;
            color: rgba(255, 255, 255, 0.6);
            font-family: 'Playpen Sans', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            transition: color 0.1s;
        }

        .text-overlay .word.active {
            color: #fbbf24;
            font-weight: 600;
        }

        /* Navigation arrows */
        .nav-btn {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            width: 44px;
            height: 70px;
            background: rgba(255, 255, 255, 0.08);
            border: none;
            color: rgba(255, 255, 255, 0.6);
            font-size: 22px;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-btn:hover { background: rgba(255, 255, 255, 0.2); color: white; }
        .nav-btn:disabled { opacity: 0; pointer-events: none; }
        .nav-btn.prev { left: 8px; }
        .nav-btn.next { right: 8px; }

        /* Page dots + counter — bottom */
        .page-nav-bar {
            position: fixed;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 6px;
            z-index: 50;
            background: rgba(0, 0, 0, 0.4);
            padding: 6px 14px;
            border-radius: 20px;
        }

        .page-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: all 0.2s;
        }

        .page-dot.active {
            background: #6366f1;
            transform: scale(1.3);
        }

        .page-dot:hover { background: rgba(255, 255, 255, 0.6); }

        .page-nav-prev, .page-nav-next {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
            cursor: pointer;
            padding: 2px 6px;
        }

        .page-nav-prev:hover, .page-nav-next:hover { color: white; }

        /* Top bar — language switch + fullscreen */
        .top-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 40px;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 100;
            opacity: 0;
            transition: opacity 0.3s;
        }

        body:hover .top-bar { opacity: 1; }

        .top-bar .title {
            color: rgba(255, 255, 255, 0.7);
            font-size: 13px;
        }

        .top-bar .controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-bar select, .top-bar button {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
        }

        .top-bar select:hover, .top-bar button:hover { background: rgba(255, 255, 255, 0.25); }

        /* Audio progress — gradient bar ABOVE the book */
        .audio-progress {
            position: fixed;
            top: 40px;
            left: 0;
            right: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.05);
            z-index: 110;
            display: none;
        }

        .audio-progress.visible { display: block; }

        .audio-progress-fill {
            height: 100%;
            border-radius: 0;
            width: 0%;
            transition: width 0.2s linear;
            background: linear-gradient(to right, #22c55e, #84cc16, #eab308, #f97316, #ef4444);
            background-size: 100vw 3px;
        }

        /* Loading */
        .loading-screen {
            position: fixed;
            inset: 0;
            background: #1a1a2e;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            transition: opacity 0.5s;
        }

        .loading-screen.fade-out { opacity: 0; pointer-events: none; }

        .spinner {
            width: 36px;
            height: 36px;
            border: 3px solid rgba(99, 102, 241, 0.3);
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .loading-text {
            color: rgba(255, 255, 255, 0.5);
            margin-top: 12px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <!-- Loading -->
    <div class="loading-screen" id="loading-screen">
        <div class="spinner"></div>
        <div class="loading-text">Loading book...</div>
    </div>

    <!-- Top bar -->
    <div class="top-bar">
        <span class="title">{{ $book->title }}</span>
        <div class="controls">
            <select id="language-select">
                <option value="en">English</option>
                @foreach($book->translations as $t)
                    <option value="{{ $t->language_code }}">{{ $t->language_name }}</option>
                @endforeach
            </select>
            <button id="fs-btn">&#x26F6; Fullscreen</button>
            <a href="{{ route('admin.dashboard') }}" style="background: #4f46e5; color: white; text-decoration: none; font-size: 12px; padding: 5px 12px; border-radius: 5px;">Close</a>
        </div>
    </div>

    <!-- Book viewport -->
    <div class="book-viewport">
        <div id="flipbook-container"></div>
    </div>

    <!-- Nav arrows -->
    <button class="nav-btn prev" id="prev-btn" disabled>&#10094;</button>
    <button class="nav-btn next" id="next-btn">&#10095;</button>

    <!-- Page dots navigation -->
    <div class="page-nav-bar" id="page-nav-bar">
        <button class="page-nav-prev" id="dot-prev">&#10094;</button>
        <div id="page-dots"></div>
        <button class="page-nav-next" id="dot-next">&#10095;</button>
    </div>

    <!-- Audio progress -->
    <div class="audio-progress" id="audio-progress">
        <div class="audio-progress-fill" id="audio-progress-fill"></div>
    </div>

    <!-- Audio elements -->
    <audio id="audio-player" preload="none"></audio>
    <audio id="page-turn-sound" preload="auto" src="{{ asset('sounds/page-turn.mp3') }}"></audio>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://unpkg.com/page-flip@2.0.7/dist/js/page-flip.browser.js"></script>

    <script>
    (function() {
        'use strict';

        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        const CONFIG = {
            pdfUrl: '{{ asset("storage/" . $pdfPath) }}',
            cropPercent: {{ $book->crop_enabled ? $book->crop_percent : 0 }},
            totalPages: {{ $book->page_count }},
            narrationStartPage: {{ $book->narration_start_page }},
            narrationEndPage: {{ $book->narration_end_page ?? $book->page_count - 1 }},
        };

        // Page audio map
        const pageAudioMap = @json(
            collect($book->narrations->where('status', 'completed')->first()?->page_audio_paths ?? [])
                ->mapWithKeys(fn($path, $page) => [(int)$page => asset('storage/' . $path)])
                ->toArray()
        );

        // Page timing data for word-level sync
        const pageTimingMap = @json($pageTimingMap ?? []);

        let pdfDoc = null;
        let pageFlip = null;
        let audioPlayer = document.getElementById('audio-player');
        let currentPlayingPage = null;
        let activePlayBtn = null;
        let wordHighlightInterval = null;

        // ===== PDF LOADING =====
        async function loadPdf() {
            pdfDoc = await pdfjsLib.getDocument(CONFIG.pdfUrl).promise;

            const pages = [];
            for (let i = 1; i <= pdfDoc.numPages; i++) {
                pages.push(await renderPageToDataUrl(i));
            }
            initFlipbook(pages);
        }

        async function renderPageToDataUrl(pageNum) {
            const page = await pdfDoc.getPage(pageNum);
            const viewport = page.getViewport({ scale: 2.5 });
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');

            if (CONFIG.cropPercent > 0) {
                const cropX = viewport.width * (CONFIG.cropPercent / 100);
                const cropY = viewport.height * (CONFIG.cropPercent / 100);
                canvas.width = viewport.width - (cropX * 2);
                canvas.height = viewport.height - (cropY * 2);
                ctx.translate(-cropX, -cropY);
            } else {
                canvas.width = viewport.width;
                canvas.height = viewport.height;
            }

            await page.render({ canvasContext: ctx, viewport }).promise;
            return canvas.toDataURL('image/jpeg', 0.90);
        }

        // ===== FLIPBOOK =====
        function initFlipbook(pageImages) {
            const container = document.getElementById('flipbook-container');
            const availW = window.innerWidth - 120;
            const availH = window.innerHeight - 80; // Extra space for dots bar

            const pageWidth = Math.floor(Math.min(availW / 2, availH * 0.7));
            const pageHeight = Math.floor(Math.min(availH, pageWidth / 0.7));

            pageFlip = new St.PageFlip(container, {
                width: pageWidth,
                height: pageHeight,
                size: 'fixed',
                showCover: true,
                maxShadowOpacity: 0.4,
                mobileScrollSupport: false,
                flippingTime: 700,
                useMouseEvents: true,
                swipeDistance: 30,
                drawShadow: true,
                autoSize: false,
            });

            const pagesHtml = pageImages.map((img, i) => {
                const pageNum = i + 1;
                const div = document.createElement('div');
                div.className = 'page-content';
                div.dataset.density = (i === 0 || i === pageImages.length - 1) ? 'hard' : 'soft';
                div.dataset.pageNum = pageNum;

                const imgEl = document.createElement('img');
                imgEl.src = img;
                imgEl.draggable = false;
                div.appendChild(imgEl);

                // Play button on narration-eligible pages
                if (pageNum >= CONFIG.narrationStartPage && pageNum <= CONFIG.narrationEndPage && pageAudioMap[pageNum]) {
                    const playBtn = document.createElement('button');
                    playBtn.className = 'page-play-btn';
                    playBtn.innerHTML = '&#9654;';
                    playBtn.title = 'Read this page aloud';
                    playBtn.dataset.pageNum = pageNum;
                    playBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        togglePageAudio(pageNum, playBtn);
                    });
                    div.appendChild(playBtn);

                    // Text overlay for word highlighting
                    const textOverlay = document.createElement('div');
                    textOverlay.className = 'text-overlay';
                    textOverlay.id = `text-overlay-${pageNum}`;
                    div.appendChild(textOverlay);
                }

                return div;
            });

            pageFlip.loadFromHTML(pagesHtml);

            pageFlip.on('flip', (e) => {
                const pageNum = e.data + 1;
                updatePageDots(pageNum);
                updateNavButtons();
                if (currentPlayingPage !== null) stopAudio();
            });

            // Fire page turn sound at the START of the flip animation
            pageFlip.on('changeState', (e) => {
                if (e.data === 'flipping') {
                    playPageTurnSound();
                }
            });

            buildPageDots();
            updatePageDots(1);
            updateNavButtons();

            const ls = document.getElementById('loading-screen');
            ls.classList.add('fade-out');
            setTimeout(() => ls.style.display = 'none', 500);
        }

        // ===== PAGE DOTS =====
        function buildPageDots() {
            const container = document.getElementById('page-dots');
            container.style.display = 'flex';
            container.style.gap = '4px';
            container.style.alignItems = 'center';

            for (let i = 0; i < CONFIG.totalPages; i++) {
                const dot = document.createElement('div');
                dot.className = 'page-dot';
                dot.dataset.page = i;
                dot.addEventListener('click', () => {
                    if (pageFlip) pageFlip.flip(i);
                });
                container.appendChild(dot);
            }
        }

        function updatePageDots(pageNum) {
            const dots = document.querySelectorAll('.page-dot');
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === pageNum - 1);
            });
        }

        // ===== PAGE TURN SOUND — Web Audio API for INSTANT playback =====
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        let pageTurnBuffer = null;

        // Pre-decode the mp3 into a buffer (instant playback, zero latency)
        fetch('{{ asset("sounds/page-turn.mp3") }}')
            .then(r => r.arrayBuffer())
            .then(data => audioCtx.decodeAudioData(data))
            .then(buffer => { pageTurnBuffer = buffer; })
            .catch(() => {});

        function playPageTurnSound() {
            if (!pageTurnBuffer) return;
            if (audioCtx.state === 'suspended') audioCtx.resume();

            const source = audioCtx.createBufferSource();
            source.buffer = pageTurnBuffer;
            source.playbackRate.value = 2.5; // Even faster
            const gain = audioCtx.createGain();
            gain.gain.value = 0.5;
            source.connect(gain);
            gain.connect(audioCtx.destination);
            source.start(0); // Start immediately, no delay
        }

        // ===== PER-PAGE AUDIO + WORD HIGHLIGHTING =====
        function togglePageAudio(pageNum, btn) {
            if (currentPlayingPage === pageNum) {
                stopAudio();
            } else {
                stopAudio();
                const audioUrl = pageAudioMap[pageNum];
                if (audioUrl) {
                    audioPlayer.src = audioUrl;
                    audioPlayer.playbackRate = 0.85; // Slower for kids
                    audioPlayer.play().then(() => {
                        currentPlayingPage = pageNum;
                        activePlayBtn = btn;
                        btn.classList.add('playing');
                        btn.innerHTML = '&#9646;&#9646;';
                        document.getElementById('audio-progress').classList.add('visible');
                        startWordHighlight(pageNum);
                    }).catch(() => {});
                }
            }
        }

        function stopAudio() {
            audioPlayer.pause();
            audioPlayer.currentTime = 0;
            currentPlayingPage = null;
            if (activePlayBtn) {
                activePlayBtn.classList.remove('playing');
                activePlayBtn.innerHTML = '&#9654;';
                activePlayBtn = null;
            }
            document.getElementById('audio-progress').classList.remove('visible');
            document.getElementById('audio-progress-fill').style.width = '0%';
            stopWordHighlight();
        }

        // Word highlighting with real timestamps from ElevenLabs
        function startWordHighlight(pageNum) {
            const timing = pageTimingMap[pageNum];
            const overlay = document.getElementById(`text-overlay-${pageNum}`);
            if (!overlay) return;

            if (timing && timing.characters && timing.character_start_times_seconds) {
                // Build words from character-level timing
                const chars = timing.characters;
                const times = timing.character_start_times_seconds;

                // Group characters into words
                const words = [];
                let currentWord = '';
                let wordStartTime = times[0] || 0;

                for (let i = 0; i < chars.length; i++) {
                    if (chars[i] === ' ' || chars[i] === '\n') {
                        if (currentWord.trim()) {
                            words.push({ text: currentWord, start: wordStartTime });
                        }
                        currentWord = '';
                        wordStartTime = times[i + 1] || times[i];
                    } else {
                        if (currentWord === '') wordStartTime = times[i];
                        currentWord += chars[i];
                    }
                }
                if (currentWord.trim()) {
                    words.push({ text: currentWord, start: wordStartTime });
                }

                // Build word spans
                overlay.innerHTML = words.map((w, i) => `<span class="word" data-idx="${i}" data-time="${w.start}">${w.text} </span>`).join('');
                overlay.classList.add('visible');

                const wordSpans = overlay.querySelectorAll('.word');

                wordHighlightInterval = setInterval(() => {
                    if (!audioPlayer.duration || audioPlayer.paused) return;
                    const currentTime = audioPlayer.currentTime;

                    // Find the current word based on real timestamps
                    let activeIdx = 0;
                    for (let i = 0; i < words.length; i++) {
                        if (currentTime >= words[i].start) {
                            activeIdx = i;
                        } else {
                            break;
                        }
                    }

                    wordSpans.forEach((s, i) => s.classList.toggle('active', i === activeIdx));
                }, 50); // Check every 50ms for smooth tracking
            } else {
                // Fallback: approximate timing (no timestamp data available)
                const text = overlay.dataset.text || '';
                if (!text) { overlay.classList.remove('visible'); return; }

                const words = text.split(/\s+/);
                overlay.innerHTML = words.map((w, i) => `<span class="word" data-idx="${i}">${w} </span>`).join('');
                overlay.classList.add('visible');

                const wordSpans = overlay.querySelectorAll('.word');
                wordHighlightInterval = setInterval(() => {
                    if (!audioPlayer.duration || audioPlayer.paused) return;
                    const progress = audioPlayer.currentTime / audioPlayer.duration;
                    const targetWord = Math.floor(progress * words.length);
                    wordSpans.forEach((s, i) => s.classList.toggle('active', i === targetWord));
                }, 100);
            }
        }

        function stopWordHighlight() {
            if (wordHighlightInterval) {
                clearInterval(wordHighlightInterval);
                wordHighlightInterval = null;
            }
            document.querySelectorAll('.text-overlay').forEach(el => {
                el.classList.remove('visible');
                el.innerHTML = '';
            });
        }

        // Audio progress
        audioPlayer.addEventListener('timeupdate', () => {
            if (audioPlayer.duration) {
                document.getElementById('audio-progress-fill').style.width =
                    (audioPlayer.currentTime / audioPlayer.duration * 100) + '%';
            }
        });

        // Auto-advance when audio ends
        audioPlayer.addEventListener('ended', () => {
            const finishedPage = currentPlayingPage;
            stopAudio();

            if (pageFlip && finishedPage) {
                setTimeout(() => {
                    pageFlip.flipNext();
                    setTimeout(() => {
                        const nextPage = finishedPage + 1;
                        if (nextPage >= CONFIG.narrationStartPage && nextPage <= CONFIG.narrationEndPage && pageAudioMap[nextPage]) {
                            const nextBtn = document.querySelector(`.page-play-btn[data-page-num="${nextPage}"]`);
                            if (nextBtn) togglePageAudio(nextPage, nextBtn);
                        }
                    }, 800);
                }, 400);
            }
        });

        // ===== NAVIGATION =====
        function updateNavButtons() {
            if (!pageFlip) return;
            const current = pageFlip.getCurrentPageIndex();
            document.getElementById('prev-btn').disabled = current <= 0;
            document.getElementById('next-btn').disabled = current >= CONFIG.totalPages - 1;
        }

        document.getElementById('prev-btn').addEventListener('click', () => { if (pageFlip) pageFlip.flipPrev(); });
        document.getElementById('next-btn').addEventListener('click', () => { if (pageFlip) pageFlip.flipNext(); });
        document.getElementById('dot-prev').addEventListener('click', () => { if (pageFlip) pageFlip.flipPrev(); });
        document.getElementById('dot-next').addEventListener('click', () => { if (pageFlip) pageFlip.flipNext(); });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') { if (pageFlip) pageFlip.flipPrev(); }
            if (e.key === 'ArrowRight') { if (pageFlip) pageFlip.flipNext(); }
            if (e.key === 'f' || e.key === 'F') { toggleFullscreen(); }
            if (e.key === ' ') { e.preventDefault(); if (currentPlayingPage) stopAudio(); }
        });

        // ===== FULLSCREEN =====
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen();
            }
        }
        document.getElementById('fs-btn').addEventListener('click', toggleFullscreen);

        // ===== LANGUAGE SWITCH =====
        // Translation data per page per language
        const translationsData = @json(
            $book->translations->mapWithKeys(fn($t) => [
                $t->language_code => $t->translatedPages->mapWithKeys(fn($tp) => [$tp->page_number => $tp->translated_text])->toArray()
            ])->toArray()
        );

        let currentLanguage = 'en';

        document.getElementById('language-select').addEventListener('change', (e) => {
            currentLanguage = e.target.value;
            updateLanguageOverlay();
        });

        function updateLanguageOverlay() {
            // Remove any existing translation overlays
            document.querySelectorAll('.translation-overlay').forEach(el => el.remove());

            if (currentLanguage === 'en') return;

            const langData = translationsData[currentLanguage];
            if (!langData) return;

            // Add translation overlay to all visible pages
            document.querySelectorAll('.page-content').forEach(pageDiv => {
                const pageNum = parseInt(pageDiv.dataset.pageNum);
                const translatedText = langData[pageNum];
                if (!translatedText) return;

                const overlay = document.createElement('div');
                overlay.className = 'translation-overlay';
                overlay.style.cssText = 'position:absolute; bottom:0; left:0; right:0; background:rgba(0,0,0,0.8); color:white; padding:10px 14px; font-size:13px; line-height:1.5; z-index:12; max-height:40%; overflow-y:auto; backdrop-filter:blur(3px);';

                const label = document.createElement('div');
                label.style.cssText = 'font-size:10px; color:#a5b4fc; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px; font-weight:600;';
                label.textContent = document.getElementById('language-select').selectedOptions[0].text;
                overlay.appendChild(label);

                const text = document.createElement('div');
                text.textContent = translatedText;
                overlay.appendChild(text);

                pageDiv.appendChild(overlay);
            });
        }

        // ===== INIT =====
        loadPdf().catch(err => {
            document.getElementById('loading-screen').innerHTML = `
                <div style="color: #ef4444; text-align: center;">
                    <p style="font-size: 18px; margin-bottom: 8px;">Failed to load book</p>
                    <p style="font-size: 13px; opacity: 0.7;">${err.message}</p>
                </div>`;
        });
    })();
    </script>
</body>
</html>
