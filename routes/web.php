<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    ?>

    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8" />
        <title>Stellar Notes Sync Tester</title>
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <style>
            :root { color-scheme: dark light; }
            body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 24px; line-height: 1.4; }
            h1 { font-size: 1.2rem; margin: 0 0 12px; }
            .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
            .card { border: 1px solid #4443; border-radius: 12px; padding: 16px; }
            label { display:block; font-size: .9rem; margin-bottom: 6px; opacity: .85; }
            input, textarea, button, select {
                width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 10px;
                border: 1px solid #4443; background: canvas; color: canvastext; font: inherit;
            }
            textarea { min-height: 160px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
            .row { display: flex; gap: 8px; }
            .row > * { flex: 1; }
            .btn { cursor: pointer; }
            pre { background: #0002; padding: 12px; border-radius: 10px; white-space: pre-wrap; word-break: break-word; }
            .muted { font-size: .85rem; opacity: .8; }
        </style>
    </head>
    <body>
    <h1>Stellar Private Notes — Sync API Tester</h1>

    <div class="grid">
        <div class="card">
            <label>Base URL</label>
            <input id="baseUrl" value="http://127.0.0.1:8000/api/v1/notecontroller" />

            <div class="row" style="margin-top:8px">
                <div>
                    <label>Bearer Token</label>
                    <input id="token" value="1" />
                </div>
                <div>
                    <label>Idempotency-Key (for Upload)</label>
                    <div class="row">
                        <input id="idem" placeholder="auto-generated if empty" />
                        <button class="btn" onclick="genIdem()">Generate</button>
                    </div>
                </div>
            </div>

            <p class="muted">Tip: leave Idempotency-Key blank to auto-generate per upload.</p>
        </div>

        <div class="card">
            <label>PLAN — notes (metadata only: id, last_modified, checksum_hmac?)</label>
            <textarea id="planNotes"></textarea>
            <div class="row" style="margin-top:8px">
                <button class="btn" onclick="loadSamplePlan()">Load Sample</button>
                <button class="btn" onclick="bumpSecondNote()">Bump 2nd note timestamp (+1000ms)</button>
                <button class="btn" onclick="callPlan()">POST /sync-plan</button>
            </div>
        </div>

        <div class="card">
            <label>UPLOAD — full notes</label>
            <textarea id="uploadNotes"></textarea>
            <div class="row" style="margin-top:8px">
                <button class="btn" onclick="loadSampleUpload()">Load Sample</button>
                <button class="btn" onclick="callUpload()">POST /upload</button>
            </div>
            <p class="muted">Each upload will use a fresh Idempotency-Key unless you fill it above.</p>
        </div>

        <div class="card">
            <label>DOWNLOAD — ids</label>
            <textarea id="downloadIds"></textarea>
            <div class="row" style="margin-top:8px">
                <button class="btn" onclick="loadSampleDownload()">Load Sample</button>
                <button class="btn" onclick="callDownload()">POST /download</button>
            </div>
        </div>

        <div class="card" style="grid-column: 1 / -1">
            <label>Response</label>
            <pre id="out">Responses will appear here…</pre>
        </div>
    </div>

    <script>
        // --- helpers ---
        const $ = (id) => document.getElementById(id);
        const nowMs = () => Math.floor(performance.timeOrigin + performance.now()); // more precise than Date.now()
        const uuid = () => {
            if (crypto.randomUUID) return crypto.randomUUID();
            // fallback
            const s4 = () => Math.floor((1 + Math.random()) * 0x10000).toString(16).substring(1);
            return `${s4()+s4()}-${s4()}-4${s4().substr(0,3)}-8${s4().substr(0,3)}-${s4()+s4()+s4()}`;
        };
        const show = (obj) => $('out').textContent = typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);

        function headers(extra = {}) {
            const h = {
                'Authorization': `Bearer ${$('token').value}`,
                'Content-Type': 'application/json'
            };
            return Object.assign(h, extra);
        }

        function genIdem() { $('idem').value = uuid(); }

        // --- sample payloads (your exact fields) ---
        function loadSamplePlan() {
            const sample = {
                notes: [
                    { id: "0bacf54a-54fa-48ee-a37b-017bbc2c0194", last_modified: 1758938283489 },
                    { id: "03a32b59-62cf-4d68-abf3-553b063eeb7d", last_modified: 1760026241405 }
                ],
                deleted_ids: []
            };
            $('planNotes').value = JSON.stringify(sample, null, 2);
        }

        function bumpSecondNote() {
            try {
                const obj = JSON.parse($('planNotes').value || '{"notes":[]}');
                if (obj.notes && obj.notes[1]) {
                    obj.notes[1].last_modified = (obj.notes[1].last_modified || nowMs()) + 1000;
                    $('planNotes').value = JSON.stringify(obj, null, 2);
                } else {
                    alert('Add at least two notes first (Load Sample).');
                }
            } catch (e) { alert('Invalid JSON in PLAN textarea'); }
        }

        function loadSampleUpload() {
            const sample = {
                notes: [
                    {
                        id: "0bacf54a-54fa-48ee-a37b-017bbc2c0194",
                        title: "lørdag den 27. sep. 2025 at 3.58 AM",
                        last_modified: 1758938283489,
                        text: "test test",
                        protected: false,
                        auto_wipe: true,
                        deleted: false
                    },
                    {
                        id: "03a32b59-62cf-4d68-abf3-553b063eeb7d",
                        title: "test",
                        last_modified: 1760026241405,
                        text: "teset 2",
                        protected: false,
                        auto_wipe: true,
                        deleted: false
                    }
                ]
            };
            $('uploadNotes').value = JSON.stringify(sample, null, 2);
        }

        function loadSampleDownload() {
            const sample = { ids: [
                    "0bacf54a-54fa-48ee-a37b-017bbc2c0194",
                    "03a32b59-62cf-4d68-abf3-553b063eeb7d"
                ]};
            $('downloadIds').value = JSON.stringify(sample, null, 2);
        }

        // --- API calls ---
        async function callPlan() {
            try {
                const body = $('planNotes').value.trim() || '{"notes":[]}';
                const res = await fetch(`${$('baseUrl').value}/sync-plan`, {
                    method: 'POST',
                    headers: headers(),
                    body
                });
                const data = await res.json();
                show(data);
            } catch (e) { show(String(e)); }
        }

        async function callUpload() {
            try {
                const body = $('uploadNotes').value.trim() || '{"notes":[]}';
                // Use provided Idempotency-Key or auto-generate one each time
                const idem = $('idem').value || uuid();
                const res = await fetch(`${$('baseUrl').value}/upload`, {
                    method: 'POST',
                    headers: headers({ 'Idempotency-Key': idem }),
                    body
                });
                const data = await res.json();
                show(data);
                // After a successful upload, generate a fresh key for next attempt
                $('idem').value = uuid();
            } catch (e) { show(String(e)); }
        }

        async function callDownload() {
            try {
                const body = $('downloadIds').value.trim() || '{"ids":[]}';
                const res = await fetch(`${$('baseUrl').value}/download`, {
                    method: 'POST',
                    headers: headers(),
                    body
                });
                const data = await res.json();
                show(data);
            } catch (e) { show(String(e)); }
        }

        // Initialize with samples
        loadSamplePlan();
        loadSampleUpload();
        loadSampleDownload();
        genIdem();
    </script>
    </body>
    </html>


    <?php
});
