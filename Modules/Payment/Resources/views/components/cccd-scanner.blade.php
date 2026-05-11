{{--
    CCCD Scanner — ZXing engine
    Decode QR từ ảnh đã upload (canvas crop + zoom) hoặc camera live.
    Script không dùng defer để ZXing sẵn sàng ngay khi Alpine init.
--}}
@once
    <script src="https://unpkg.com/@zxing/library@0.18.6/umd/index.min.js"></script>
@endonce

{{-- Camera viewport (tạo video element động khi mở modal) --}}
<div id="cccd-cam-vp" style="display:none;"></div>

@once
<script>
function cccdImageScanner() {
    return {
        // ═══ State chính ═══
        state:      'idle',   // idle | scanning | result | error
        scanMsg:    '',
        errMsg:     '',
        scanned:    null,
        fromLabel:  '',
        rows:       [],

        // ═══ Camera ═══
        cameraOpen:  false,
        zxingReader: null,
        camErr:      '',

        // ═══ Test mode ═══
        testOpen:       false,
        testState:      'idle',
        testMsg:        '',
        testImgSrc:     '',
        testImgInfo:    '',
        testUrl:        '',
        testRows:       [],
        testRaw:        '',
        testLog:        [],
        testFoundLabel: '',

        // ═══════════════════════════════════════════════════════════════
        // MAIN SCAN
        // ═══════════════════════════════════════════════════════════════
        async runScan() {
            if (!this._libOk()) { this.fail('Thư viện ZXing chưa tải xong. Bấm lại sau vài giây.'); return; }
            this.state = 'scanning'; this.errMsg = '';

            const srcs = this._collectSrcs();
            if (!srcs.length) {
                this.fail('Chưa tìm thấy ảnh CCCD nào. Hãy upload ảnh trước, hoặc dùng camera.');
                return;
            }
            const ordered = srcs.length >= 2 ? [srcs[1], srcs[0], ...srcs.slice(2)] : srcs;
            let rawQR = null; let foundIdx = -1;
            for (let i = 0; i < ordered.length; i++) {
                this.scanMsg = `Đang phân tích ảnh ${i + 1} / ${ordered.length}…`;
                const txt = await this._decodeUrl(ordered[i]);
                if (txt && this._isCCCD(txt)) { rawQR = txt; foundIdx = i; break; }
            }
            if (!rawQR) {
                this.fail('Không tìm thấy mã QR CCCD trong ảnh. Ảnh có thể mờ hoặc góc nghiêng — thử tải lại ảnh rõ hơn hoặc dùng camera.');
                return;
            }
            this.setResult(rawQR, srcs.length >= 2 && foundIdx === 0 ? 'Mặt sau' : 'Mặt trước');
        },

        // ═══════════════════════════════════════════════════════════════
        // DECODE: fetch → Blob URL → Image → Canvas crop+zoom
        // ═══════════════════════════════════════════════════════════════
        async _decodeUrl(url) {
            try {
                const res = await fetch(url, { cache: 'no-cache' });
                if (!res.ok) return null;
                const objUrl = URL.createObjectURL(await res.blob());
                try {
                    const img = await this._loadImg(objUrl);
                    return img ? this._tryAllCrops(img) : null;
                } finally { URL.revokeObjectURL(objUrl); }
            } catch { return null; }
        },

        async _decodeBlob(blob) {
            const objUrl = URL.createObjectURL(blob);
            try {
                const img = await this._loadImg(objUrl);
                return img ? this._tryAllCrops(img) : null;
            } finally { URL.revokeObjectURL(objUrl); }
        },

        _loadImg(objUrl) {
            return new Promise(resolve => {
                const img = new Image(); let done = false;
                const fin  = () => { if (!done) { done = true; resolve(img.complete && img.naturalWidth > 0 ? img : null); } };
                const timer = setTimeout(() => { done = true; resolve(null); }, 12000);
                img.onload  = () => { clearTimeout(timer); fin(); };
                img.onerror = () => { clearTimeout(timer); done = true; resolve(null); };
                img.src = objUrl;
            });
        },

        _tryAllCrops(img) {
            const W = img.naturalWidth || img.width || 800;
            const H = img.naturalHeight || img.height || 500;
            for (const c of this._crops(W, H)) {
                if (c.sw < 8 || c.sh < 8) continue;
                const cv = this._crop(img, c.sx, c.sy, c.sw, c.sh, c.scale);
                if (!cv) continue;
                const txt = this._scanCanvas(cv);
                if (txt) return txt;
            }
            return null;
        },

        async _tryAllCropsLogged(img) {
            const W = img.naturalWidth || img.width || 800;
            const H = img.naturalHeight || img.height || 500;
            this.testLog.push(`📐 Kích thước: ${W} × ${H} px`);
            const crops = this._crops(W, H);
            this.testLog.push(`🔍 Thử ${crops.length} chiến lược crop & zoom:`);

            for (let i = 0; i < crops.length; i++) {
                const c = crops[i];
                const ow = Math.round(c.sw * c.scale), oh = Math.round(c.sh * c.scale);
                const ln = `[${i+1}/${crops.length}] ${c.label}  (src ${c.sw}×${c.sh} → out ${ow}×${oh}px)`;
                this.testMsg = ln; this.testLog.push(`→ ${ln}`);
                await new Promise(r => setTimeout(r, 8));

                if (c.sw < 8 || c.sh < 8) { this.testLog.push('  ✗ vùng quá nhỏ'); continue; }
                const cv = this._crop(img, c.sx, c.sy, c.sw, c.sh, c.scale);
                if (!cv) { this.testLog.push('  ✗ canvas crop thất bại'); continue; }

                const { txt, bz } = this._scanCanvasLogged(cv);
                if (txt) { this.testLog.push(`  ✓ THÀNH CÔNG (${bz})`); return { txt, label: c.label }; }
                this.testLog.push('  ✗ không có QR (cả 2 binarizer)');
            }
            return null;
        },

        _crops(W, H) {
            const r = n => Math.max(1, Math.round(n));
            return [
                { sx:0,        sy:0,         sw:W,        sh:H,        scale:1, label:'Toàn ảnh ×1' },
                { sx:0,        sy:0,         sw:W,        sh:H,        scale:2, label:'Toàn ảnh ×2' },
                { sx:r(W*.45), sy:0,         sw:r(W*.55), sh:H,        scale:2, label:'Nửa phải ×2' },
                { sx:r(W*.55), sy:0,         sw:r(W*.45), sh:r(H*.60), scale:3, label:'Góc trên-phải ×3' },
                { sx:r(W*.60), sy:r(H*.02),  sw:r(W*.38), sh:r(H*.48), scale:4, label:'Góc trên-phải ×4' },
                { sx:r(W*.64), sy:r(H*.03),  sw:r(W*.34), sh:r(H*.42), scale:5, label:'Góc trên-phải ×5 (deep)' },
                { sx:0,        sy:0,         sw:W,        sh:r(H*.55), scale:2, label:'Nửa trên ×2' },
                { sx:0,        sy:0,         sw:r(W*.50), sh:r(H*.55), scale:3, label:'Góc trên-trái ×3' },
                { sx:0,        sy:0,         sw:W,        sh:H,        scale:3, label:'Toàn ảnh ×3' },
            ];
        },

        _crop(img, sx, sy, sw, sh, scale) {
            try {
                const cv = document.createElement('canvas');
                cv.width  = Math.round(sw * scale);
                cv.height = Math.round(sh * scale);
                const ctx = cv.getContext('2d');
                ctx.imageSmoothingEnabled = true;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, sx, sy, sw, sh, 0, 0, cv.width, cv.height);
                return cv;
            } catch { return null; }
        },

        // ═══════════════════════════════════════════════════════════════
        // ZXing decode — HTMLCanvasElementLuminanceSource
        // ═══════════════════════════════════════════════════════════════
        _scanCanvas(canvas) {
            if (!this._libOk() || canvas.width < 8 || canvas.height < 8) return null;
            try {
                const hints = new Map([
                    [ZXing.DecodeHintType.TRY_HARDER, true],
                    [ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.QR_CODE]],
                ]);
                const reader = new ZXing.MultiFormatReader();
                reader.setHints(hints);
                try {
                    const src = new ZXing.HTMLCanvasElementLuminanceSource(canvas);
                    return reader.decode(new ZXing.BinaryBitmap(new ZXing.HybridBinarizer(src))).getText();
                } catch {}
                try {
                    const src = new ZXing.HTMLCanvasElementLuminanceSource(canvas);
                    return reader.decode(new ZXing.BinaryBitmap(new ZXing.GlobalHistogramBinarizer(src))).getText();
                } catch {}
            } catch {}
            return null;
        },

        _scanCanvasLogged(canvas) {
            if (!this._libOk()) return { txt: null, bz: '' };
            try {
                const hints = new Map([
                    [ZXing.DecodeHintType.TRY_HARDER, true],
                    [ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.QR_CODE]],
                ]);
                const reader = new ZXing.MultiFormatReader();
                reader.setHints(hints);
                try {
                    const src = new ZXing.HTMLCanvasElementLuminanceSource(canvas);
                    const txt = reader.decode(new ZXing.BinaryBitmap(new ZXing.HybridBinarizer(src))).getText();
                    this.testLog.push('  ├ HybridBinarizer         → ✓');
                    return { txt, bz: 'HybridBinarizer' };
                } catch { this.testLog.push('  ├ HybridBinarizer         → ✗'); }
                try {
                    const src = new ZXing.HTMLCanvasElementLuminanceSource(canvas);
                    const txt = reader.decode(new ZXing.BinaryBitmap(new ZXing.GlobalHistogramBinarizer(src))).getText();
                    this.testLog.push('  └ GlobalHistogramBinarizer → ✓');
                    return { txt, bz: 'GlobalHistogramBinarizer' };
                } catch { this.testLog.push('  └ GlobalHistogramBinarizer → ✗'); }
            } catch {}
            return { txt: null, bz: '' };
        },

        // ═══════════════════════════════════════════════════════════════
        // Thu thập src ảnh từ DOM
        // ═══════════════════════════════════════════════════════════════
        _collectSrcs() {
            const found = [], seen = new Set();
            const add = el => {
                const src = el?.getAttribute('src') || el?.src || '';
                if (!src || src.startsWith('data:') || seen.has(src)) return;
                const low = src.toLowerCase();
                if (low.endsWith('.svg') || low.includes('/icons/')) return;
                seen.add(src); found.push(src);
            };
            let scope = null;
            document.querySelectorAll('.fi-section').forEach(sec => {
                if (scope) return;
                const t = sec.querySelector('.fi-section-header-heading,[class*="heading"],h2,h3')?.textContent || '';
                if (t.includes('CCCD') || t.includes('CMND') || t.includes('Upload')) scope = sec;
            });
            if (scope) scope.querySelectorAll('img[src]').forEach(add);
            if (!found.length) {
                document.querySelectorAll('img[src]').forEach(img => {
                    const s = img.src || '';
                    if (s.includes('/storage/') || s.includes('livewire-tmp')) add(img);
                });
            }
            return found;
        },

        // ═══════════════════════════════════════════════════════════════
        // Parse & hiển thị
        // ═══════════════════════════════════════════════════════════════
        _isCCCD(txt)  { return (txt || '').split('|').length >= 7; },

        _parse(txt) {
            const p = txt.split('|');
            if (p.length < 7) return null;
            const [cccd, oldId, name, dobRaw, gender, address, issuedRaw] = p;
            return {
                cccd:  (cccd||'').trim(),  old_id: (oldId||'').trim(),
                name:  (name||'').trim(),  gender: (gender||'').trim(),
                addr:  (address||'').trim(),
                dob:   this._fmt(dobRaw),  dob_iso: this._iso(dobRaw),
                issue: this._fmt(issuedRaw),
            };
        },

        _iso(s) { s=(s||'').trim(); return s.length>=8 ? `${s.slice(4,8)}-${s.slice(2,4)}-${s.slice(0,2)}` : ''; },
        _fmt(s) { s=(s||'').trim(); return s.length>=8 ? `${s.slice(0,2)}/${s.slice(2,4)}/${s.slice(4,8)}` : s; },

        setResult(raw, label) {
            const d = this._parse(raw);
            if (!d) { this.fail('Đọc được mã nhưng không phải định dạng CCCD Việt Nam.'); return; }
            this.scanned = d; this.fromLabel = label;
            this.rows = [
                { label:'Số CCCD',   val: d.cccd  }, { label:'Họ và tên', val: d.name  },
                { label:'Ngày sinh', val: d.dob   }, { label:'Giới tính', val: d.gender },
                { label:'Địa chỉ',  val: d.addr  }, { label:'Ngày cấp', val: d.issue  },
            ];
            this.state = 'result';
        },

        fail(msg) { this.errMsg = msg; this.state = 'error'; },

        // ═══════════════════════════════════════════════════════════════
        // Áp dụng vào form Filament / Livewire
        // ═══════════════════════════════════════════════════════════════
        apply() {
            if (!this.scanned) return;
            const wireEl = document.querySelector('[wire\\:id]');
            if (!wireEl) { alert('Không tìm thấy form Livewire.'); return; }
            const comp = window.Livewire.find(wireEl.getAttribute('wire:id'));
            if (!comp)  { alert('Không kết nối được Livewire component.'); return; }
            const d = this.scanned;
            if (d.name) comp.set('data.buyer_name',    d.name);
            if (d.addr) comp.set('data.buyer_address', d.addr);
            comp.set('data.cccd_data', {
                cccd: d.cccd, old_id: d.old_id, full_name: d.name,
                dob: d.dob, gender: d.gender, address: d.addr, issued_date: d.issue,
            });
            comp.set('data.note_for_admin', [
                `Số CCCD:   ${d.cccd}`,
                d.old_id ? `CMND cũ:   ${d.old_id}` : null,
                `Họ tên:    ${d.name}`,  `Ngày sinh: ${d.dob}`,
                `Giới tính: ${d.gender}`, `Địa chỉ:  ${d.addr}`,
                `Ngày cấp: ${d.issue}`,
            ].filter(Boolean).join('\n'));
            this.state = 'idle'; this.scanned = null; this.rows = [];
            window.dispatchEvent(new CustomEvent('filament-notification', {
                detail: { id:'cccd-ok', status:'success', title:'Đã điền thông tin CCCD',
                          body:`${d.name} · Xem chi tiết tại tab Thanh toán` }
            }));
        },

        // ═══════════════════════════════════════════════════════════════
        // TEST MODE
        // ═══════════════════════════════════════════════════════════════
        openTest()  { this.testOpen = true;  this.resetTest(); },
        closeTest() { this.testOpen = false; },
        resetTest() {
            this.testState = 'idle'; this.testLog = []; this.testRows = [];
            this.testRaw = ''; this.testMsg = ''; this.testFoundLabel = ''; this.testImgInfo = '';
            if (this.testImgSrc?.startsWith('blob:')) URL.revokeObjectURL(this.testImgSrc);
            this.testImgSrc = '';
        },

        async testFromFile(event) {
            const file = event.target.files?.[0]; if (!file) return;
            event.target.value = '';
            if (!this._libOk()) { this.testMsg = 'ZXing chưa tải.'; this.testState = 'error'; return; }
            if (this.testImgSrc?.startsWith('blob:')) URL.revokeObjectURL(this.testImgSrc);
            this.testImgSrc  = URL.createObjectURL(file);
            this.testImgInfo = `${file.name}  ·  ${(file.size/1024).toFixed(0)} KB`;
            this.testState   = 'scanning';
            this.testLog     = [`📁 File: ${file.name}  (${(file.size/1024).toFixed(0)} KB)`];
            this.testRows = []; this.testRaw = '';
            const img = await this._loadImg(this.testImgSrc);
            if (!img) { this.testMsg = 'Không đọc được ảnh.'; this.testState = 'error'; return; }
            await this._runTestWith(img);
        },

        async testFromUrl() {
            const url = (this.testUrl || '').trim(); if (!url) return;
            if (!this._libOk()) { this.testMsg = 'ZXing chưa tải.'; this.testState = 'error'; return; }
            this.testState = 'scanning'; this.testMsg = 'Đang tải ảnh…';
            this.testLog = [`🔗 URL: ${url}`]; this.testRows = []; this.testRaw = '';
            if (this.testImgSrc?.startsWith('blob:')) { URL.revokeObjectURL(this.testImgSrc); this.testImgSrc = ''; }
            try {
                const res  = await fetch(url, { cache:'no-cache' });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const blob = await res.blob();
                this.testImgSrc  = URL.createObjectURL(blob);
                this.testImgInfo = `${(blob.size/1024).toFixed(0)} KB  ·  ${blob.type||'image'}`;
                const img = await this._loadImg(this.testImgSrc);
                if (!img) throw new Error('Không giải mã được ảnh.');
                await this._runTestWith(img);
            } catch (e) { this.testMsg = `Lỗi tải ảnh: ${e.message}`; this.testState = 'error'; }
        },

        async _runTestWith(img) {
            const res = await this._tryAllCropsLogged(img);
            if (!res) { this.testMsg = 'Không tìm thấy mã QR CCCD nào.'; this.testState = 'notfound'; return; }
            this.testRaw = res.txt; this.testFoundLabel = res.label;
            const d = this._parse(res.txt);
            this.testRows = d && this._isCCCD(res.txt) ? [
                { label:'Số CCCD', val:d.cccd }, { label:'Họ và tên', val:d.name },
                { label:'Ngày sinh', val:d.dob }, { label:'Giới tính', val:d.gender },
                { label:'Địa chỉ', val:d.addr }, { label:'Ngày cấp', val:d.issue },
            ] : [{ label:'Raw QR (không chuẩn CCCD)', val:res.txt }];
            this.testMsg = ''; this.testState = 'result';
        },

        // ═══════════════════════════════════════════════════════════════
        // CAMERA — ZXing BrowserQRCodeReader
        // ═══════════════════════════════════════════════════════════════
        async openCamera() {
            if (!this._libOk()) { this.fail('ZXing chưa tải xong.'); return; }
            this.cameraOpen = true; this.camErr = '';
            await this.$nextTick();
            const vp = document.getElementById('cccd-cam-vp');
            if (!vp) return;
            const video = document.createElement('video');
            Object.assign(video.style, { width:'100%', height:'100%', objectFit:'cover',
                                         borderRadius:'.5rem', display:'block' });
            video.muted = true; video.setAttribute('playsinline','');
            vp.style.display = 'block'; vp.innerHTML = ''; vp.appendChild(video);
            try {
                this.zxingReader = new ZXing.BrowserQRCodeReader();
                await this.zxingReader.decodeFromVideoDevice(null, video, (result) => {
                    if (result) {
                        const txt = result.getText();
                        if (this._isCCCD(txt)) { this.closeCamera(); this.setResult(txt, 'Camera'); }
                    }
                });
            } catch (e) { this.camErr = 'Không thể mở camera: ' + e.message; }
        },

        closeCamera() {
            try { this.zxingReader?.reset?.(); } catch {}
            this.zxingReader = null; this.cameraOpen = false;
            const vp = document.getElementById('cccd-cam-vp');
            if (vp) { vp.innerHTML = ''; vp.style.display = 'none'; }
        },

        _libOk() {
            return typeof window.ZXing !== 'undefined'
                && typeof ZXing.MultiFormatReader !== 'undefined'
                && typeof ZXing.HTMLCanvasElementLuminanceSource !== 'undefined';
        },
    };
}
</script>
@endonce

<div x-data="cccdImageScanner()">

    {{-- ══ IDLE ══ --}}
    <div x-show="state === 'idle'" style="display:flex;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <button type="button" @click="runScan()"
            style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;
                   background:linear-gradient(135deg,#2563eb,#3b82f6);color:#fff;font-size:.8125rem;
                   font-weight:600;border:none;border-radius:.4375rem;cursor:pointer;
                   box-shadow:0 2px 8px rgba(59,130,246,.3);transition:opacity .15s;"
            onmouseenter="this.style.opacity='.85'" onmouseleave="this.style.opacity='1'">
            <svg xmlns="http://www.w3.org/2000/svg" style="width:.875rem;height:.875rem;" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3 9V5a2 2 0 012-2h4M3 15v4a2 2 0 002 2h4m10-14h-4a2 2 0 00-2 2v4m6 4h-4a2 2 0 00-2 2v4"/>
            </svg>
            Quét QR từ ảnh CCCD
        </button>
        <span style="font-size:.75rem;color:#9ca3af;">Tự động đọc từ ảnh đã upload</span>
        <button type="button" @click="openCamera()"
            style="padding:.35rem .7rem;background:transparent;border:1px solid #e5e7eb;
                   color:#6b7280;font-size:.75rem;border-radius:.375rem;cursor:pointer;">
            📷 Dùng camera
        </button>
    </div>

    {{-- ══ SCANNING ══ --}}
    <div x-show="state === 'scanning'"
         style="display:inline-flex;align-items:center;gap:.5rem;padding:.45rem .875rem;
                background:#eff6ff;border:1px solid #bfdbfe;border-radius:.4375rem;">
        <svg style="width:1rem;height:1rem;color:#2563eb;animation:cccd-spin 1s linear infinite;flex-shrink:0;"
             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" style="opacity:.2;"/>
            <path stroke="currentColor" stroke-width="3" stroke-linecap="round" d="M12 2a10 10 0 0110 10"/>
        </svg>
        <span style="font-size:.8125rem;color:#1d4ed8;font-weight:500;" x-text="scanMsg"></span>
    </div>

    {{-- ══ ERROR ══ --}}
    <div x-show="state === 'error'"
         style="display:flex;align-items:flex-start;gap:.625rem;padding:.625rem .875rem;
                background:#fef2f2;border:1px solid #fecaca;border-radius:.4375rem;">
        <svg xmlns="http://www.w3.org/2000/svg" style="width:1rem;height:1rem;color:#ef4444;flex-shrink:0;margin-top:.125rem;"
             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
        </svg>
        <div style="flex:1;">
            <p style="margin:0;font-size:.8125rem;color:#dc2626;" x-text="errMsg"></p>
            <div style="margin-top:.35rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                <button type="button" @click="state='idle'"
                    style="font-size:.75rem;color:#6b7280;background:none;border:none;cursor:pointer;text-decoration:underline;padding:0;">
                    Thử lại
                </button>
                <button type="button" @click="openCamera()"
                    style="font-size:.75rem;color:#2563eb;background:none;border:none;cursor:pointer;text-decoration:underline;padding:0;">
                    Dùng camera
                </button>
            </div>
        </div>
    </div>

    {{-- ══ RESULT ══ --}}
    <div x-show="state === 'result'"
         style="background:#f0fdf4;border:1px solid #86efac;border-radius:.5rem;padding:.875rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.625rem;">
            <div style="display:flex;align-items:center;gap:.4rem;">
                <div style="width:1.375rem;height:1.375rem;background:#16a34a;border-radius:50%;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:.75rem;height:.75rem;color:#fff;"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <span style="font-weight:700;color:#15803d;font-size:.875rem;">Đọc QR thành công</span>
            </div>
            <span style="font-size:.7rem;color:#6b7280;background:#dcfce7;padding:.15rem .5rem;border-radius:9999px;"
                  x-text="fromLabel"></span>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:.75rem;">
            <tbody>
            <template x-for="r in rows" :key="r.label">
                <tr style="border-bottom:1px solid #bbf7d0;">
                    <td style="padding:.3rem .4rem .3rem 0;color:#6b7280;white-space:nowrap;width:30%;vertical-align:top;"
                        x-text="r.label + ':'"></td>
                    <td style="padding:.3rem 0;color:#111827;font-weight:600;" x-text="r.val"></td>
                </tr>
            </template>
            </tbody>
        </table>
        <div style="display:flex;gap:.5rem;">
            <button type="button" @click="state='idle';scanned=null"
                style="padding:.375rem .75rem;background:#fff;border:1px solid #d1d5db;
                       border-radius:.375rem;font-size:.8rem;color:#374151;cursor:pointer;">
                ↩ Quét lại
            </button>
            <button type="button" @click="apply()"
                style="padding:.375rem 1rem;background:linear-gradient(135deg,#16a34a,#22c55e);
                       border:none;border-radius:.375rem;font-size:.8rem;font-weight:700;
                       color:#fff;cursor:pointer;box-shadow:0 1px 5px rgba(22,163,74,.25);">
                ✓ Áp dụng vào form
            </button>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════
         CAMERA MODAL
    ══════════════════════════════════════════════════════════ --}}
    <div x-show="cameraOpen" x-cloak @click.self="closeCamera()" @keydown.escape.window="closeCamera()"
         style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);
                display:flex;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:#fff;border-radius:.75rem;width:100%;max-width:480px;
                    max-height:92vh;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,.3);">
            <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:.875rem 1.125rem;border-bottom:1px solid #e5e7eb;">
                <span style="font-weight:700;font-size:.9rem;">📷 Quét QR bằng Camera</span>
                <button type="button" @click="closeCamera()"
                    style="width:1.75rem;height:1.75rem;border:none;background:#f3f4f6;
                           border-radius:.375rem;cursor:pointer;color:#6b7280;font-size:1.1rem;
                           line-height:1;font-weight:700;">×</button>
            </div>
            <div style="padding:1rem;">
                <p style="margin:0 0 .75rem;font-size:.8rem;color:#1d4ed8;background:#eff6ff;
                          border-radius:.375rem;padding:.5rem .75rem;text-align:center;">
                    Hướng camera vào mã QR mặt sau CCCD gắn chip
                </p>
                <div style="width:100%;border-radius:.5rem;overflow:hidden;background:#000;
                            aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;">
                    <span style="color:#9ca3af;font-size:.8rem;" x-show="!zxingReader">Đang khởi động camera…</span>
                </div>
                <p x-show="camErr" x-text="camErr"
                   style="margin:.5rem 0 0;font-size:.8rem;color:#dc2626;background:#fef2f2;
                          padding:.4rem .6rem;border-radius:.25rem;"></p>
            </div>
        </div>
    </div>

    {{-- Test Scanner Modal đã được ẩn (production mode) --}}
    <div x-show="false" x-cloak

            <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:.875rem 1.25rem;border-bottom:2px solid #fde68a;
                        background:#fffbeb;border-radius:.875rem .875rem 0 0;
                        position:sticky;top:0;z-index:1;">
                <div style="display:flex;align-items:center;gap:.5rem;">
                    <span style="font-size:1.2rem;line-height:1;">🧪</span>
                    <div>
                        <p style="margin:0;font-weight:700;font-size:.9rem;color:#92400e;">Test Scanner QR — ZXing Engine</p>
                        <p style="margin:0;font-size:.7rem;color:#b45309;">
                            Chọn ảnh bất kỳ → xem log từng chiến lược crop &amp; zoom (Hybrid + GlobalHistogram binarizer).
                        </p>
                    </div>
                </div>
                <button type="button" @click="closeTest()"
                    style="width:1.875rem;height:1.875rem;border:none;background:#fef3c7;border-radius:.375rem;
                           cursor:pointer;color:#92400e;font-size:1.2rem;line-height:1;font-weight:700;">×</button>
            </div>

            <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;flex:1;">

                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.875rem;">
                    <p style="margin:0 0 .625rem;font-size:.8rem;font-weight:700;color:#374151;">📷 Chọn ảnh để kiểm tra:</p>
                    <div style="display:flex;flex-direction:column;gap:.5rem;">
                        <label style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .875rem;
                                      background:#fff;border:1px solid #d1d5db;border-radius:.375rem;cursor:pointer;
                                      font-size:.8rem;color:#374151;width:fit-content;"
                               onmouseenter="this.style.borderColor='#6b7280'"
                               onmouseleave="this.style.borderColor='#d1d5db'">
                            📁 Chọn ảnh từ máy tính
                            <input type="file" accept="image/*" style="display:none;" @change="testFromFile($event)">
                        </label>
                        <div style="display:flex;gap:.4rem;">
                            <input type="text" x-model="testUrl"
                                   placeholder="Dán URL: https://... hoặc /storage/cccd/abc.jpg"
                                   style="flex:1;padding:.45rem .625rem;border:1px solid #d1d5db;
                                          border-radius:.375rem;font-size:.8rem;outline:none;"
                                   @focus="this.style.borderColor='#3b82f6'"
                                   @blur="this.style.borderColor='#d1d5db'"
                                   @keydown.enter.prevent="testFromUrl()">
                            <button type="button" @click="testFromUrl()"
                                    style="padding:.45rem 1rem;background:#2563eb;color:#fff;border:none;
                                           border-radius:.375rem;font-size:.8rem;cursor:pointer;
                                           white-space:nowrap;">Quét URL</button>
                        </div>
                    </div>
                </div>

                <div x-show="testImgSrc"
                     style="border:1px solid #e5e7eb;border-radius:.5rem;overflow:hidden;background:#f9fafb;">
                    <img :src="testImgSrc" style="width:100%;max-height:270px;object-fit:contain;display:block;">
                    <p x-show="testImgInfo" x-text="testImgInfo"
                       style="margin:0;padding:.3rem .75rem;font-size:.7rem;color:#9ca3af;
                              background:#f3f4f6;border-top:1px solid #e5e7eb;"></p>
                </div>

                <div x-show="testState !== 'idle'" style="display:flex;flex-direction:column;gap:.5rem;">

                    <div x-show="testState === 'scanning'"
                         style="display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;
                                background:#eff6ff;border:1px solid #bfdbfe;border-radius:.375rem;">
                        <svg style="width:.875rem;height:.875rem;color:#2563eb;
                                    animation:cccd-spin 1s linear infinite;flex-shrink:0;"
                             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" style="opacity:.2;"/>
                            <path stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                  d="M12 2a10 10 0 0110 10"/>
                        </svg>
                        <span style="font-size:.8rem;color:#1d4ed8;font-weight:500;"
                              x-text="testMsg || 'Đang phân tích…'"></span>
                    </div>

                    <div x-show="testLog.length > 0"
                         style="background:#0f172a;border-radius:.5rem;padding:.75rem 1rem;
                                max-height:220px;overflow-y:auto;">
                        <p style="margin:0 0 .35rem;font-size:.65rem;color:#64748b;
                                  font-weight:700;letter-spacing:.06em;text-transform:uppercase;">
                            📋 ZXing Debug Log
                        </p>
                        <template x-for="(ln, i) in testLog" :key="i">
                            <p style="margin:.08rem 0;font-size:.73rem;font-family:'Courier New',Courier,monospace;line-height:1.55;"
                               :style="ln.startsWith('  ✓') ? 'color:#4ade80' :
                                       ln.startsWith('  ✗') ? 'color:#f87171' :
                                       ln.startsWith('→')   ? 'color:#fbbf24' :
                                       ln.startsWith('  ├') || ln.startsWith('  └') ? 'color:#38bdf8' :
                                       '📐📁🔗🔍'.split('').some(c => ln.startsWith(c)) ? 'color:#a78bfa' :
                                       'color:#94a3b8'"
                               x-text="ln"></p>
                        </template>
                    </div>

                    <div x-show="testState === 'error' || testState === 'notfound'"
                         style="padding:.625rem .875rem;background:#fef2f2;
                                border:1px solid #fecaca;border-radius:.375rem;">
                        <p style="margin:0;font-size:.825rem;color:#dc2626;font-weight:700;"
                           x-text="testMsg || 'Không tìm thấy QR trong ảnh.'"></p>
                        <p style="margin:.3rem 0 0;font-size:.75rem;color:#9ca3af;">
                            Gợi ý: chụp thẳng, đủ sáng, không mờ. QR CCCD ở góc <strong>trên-phải</strong> thẻ.
                        </p>
                    </div>

                    <div x-show="testState === 'result'"
                         style="background:#f0fdf4;border:1px solid #86efac;border-radius:.5rem;padding:1rem;">
                        <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.625rem;">
                            <div style="width:1.375rem;height:1.375rem;background:#16a34a;border-radius:50%;
                                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <svg xmlns="http://www.w3.org/2000/svg" style="width:.75rem;height:.75rem;color:#fff;"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span style="font-weight:700;color:#15803d;font-size:.875rem;">✅ Quét thành công!</span>
                            <span style="font-size:.7rem;color:#6b7280;background:#dcfce7;
                                         padding:.15rem .5rem;border-radius:9999px;margin-left:auto;"
                                  x-text="testFoundLabel"></span>
                        </div>
                        <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:.625rem;">
                            <tbody>
                            <template x-for="r in testRows" :key="r.label">
                                <tr style="border-bottom:1px solid #bbf7d0;">
                                    <td style="padding:.3rem .4rem .3rem 0;color:#6b7280;white-space:nowrap;width:28%;vertical-align:top;"
                                        x-text="r.label + ':'"></td>
                                    <td style="padding:.3rem 0;color:#111827;font-weight:600;" x-text="r.val"></td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                        <details>
                            <summary style="font-size:.75rem;color:#6b7280;cursor:pointer;user-select:none;">Xem dữ liệu thô QR</summary>
                            <pre style="margin:.375rem 0 0;font-size:.7rem;background:#f1f5f9;padding:.5rem .75rem;
                                        border-radius:.375rem;overflow-x:auto;white-space:pre-wrap;
                                        word-break:break-all;color:#374151;" x-text="testRaw"></pre>
                        </details>
                    </div>
                </div>

                <div x-show="testState !== 'idle'" style="display:flex;justify-content:flex-end;">
                    <button type="button" @click="resetTest()"
                        style="padding:.35rem .75rem;background:#fff;border:1px solid #e5e7eb;
                               border-radius:.375rem;font-size:.75rem;color:#6b7280;cursor:pointer;">
                        ↺ Thử lại
                    </button>
                </div>

            </div>
        </div>
    </div>

</div>

<style>
@keyframes cccd-spin { to { transform: rotate(360deg); } }

</style>
