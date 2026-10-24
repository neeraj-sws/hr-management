<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $document->original_name }} · SWS HR</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; background: #EEF1F5; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #13254F; }
        header { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #13254F; color: #fff; }
        header strong { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 15px; }
        header a { color: #fff; border: 1px solid rgba(255,255,255,.45); border-radius: 8px; padding: 6px 12px; font-size: 13px; text-decoration: none; white-space: nowrap; }
        main { padding: 12px; display: grid; gap: 12px; justify-items: center; }
        canvas, img { display: block; max-width: 100%; height: auto; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        .note { color: #6b7a90; font-size: 14px; padding: 30px 10px; text-align: center; }
    </style>
</head>
<body>
    <header><strong>{{ $document->original_name }}</strong><a href="{{ $downloadUrl }}">Download</a></header>
    <main id="pages">
        @if($kind === 'image')
            <img src="{{ $fileUrl }}" alt="{{ $document->original_name }}">
        @else
            <p class="note" id="status">Opening the PDF…</p>
        @endif
    </main>
    @if($kind === 'pdf')
        {{-- PDF.js draws each page in the browser, so the phone shows the PDF instead of downloading it. --}}
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
        <script>
            (async () => {
                const status = document.getElementById('status');
                try {
                    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                    const pdf = await pdfjsLib.getDocument(@json($fileUrl)).promise;
                    status.remove();
                    const width = Math.min(document.body.clientWidth - 24, 1000);
                    for (let n = 1; n <= pdf.numPages; n++) {
                        const page = await pdf.getPage(n);
                        const base = page.getViewport({ scale: 1 });
                        const scale = (width / base.width) * (window.devicePixelRatio || 1);
                        const viewport = page.getViewport({ scale });
                        const canvas = document.createElement('canvas');
                        canvas.width = viewport.width; canvas.height = viewport.height;
                        canvas.style.width = width + 'px';
                        document.getElementById('pages').appendChild(canvas);
                        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                    }
                } catch (e) {
                    status.textContent = 'This PDF could not be shown here. Use Download to open it.';
                }
            })();
        </script>
    @endif
</body>
</html>
