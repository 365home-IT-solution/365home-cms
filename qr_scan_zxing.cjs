/**
 * Đọc QR CCCD bằng ZXing (cùng engine với bộ quét trên trình duyệt ở form đặt phòng Filament) — bổ sung cho qr_scan.cjs (jsQR).
 *
 * Vì sao cần: ảnh CCCD chụp/nén (QR nhỏ, vỡ nét) mà jsQR/zbar không giải mã được vẫn thường đọc được bằng ZXing nếu CẮT sát vùng QR
 * rồi PHÓNG TO (chiến lược crop + zoom giống cccd-scanner.blade.php). Nhờ đó không phải phụ thuộc OCR (chậm, kém chính xác, bị giới hạn).
 *
 * Dùng: node qr_scan_zxing.cjs <ảnh1> [ảnh2 ...]
 * Ra stdout: chuỗi QR CCCD (>= 6 dấu '|') + exit 0; không đọc được: "QR_NOT_FOUND" + exit 1.
 */
const fs = require('fs');
const path = require('path');
const { Jimp, JimpMime } = require('jimp');
const Z = require('@zxing/library');

const DEADLINE_MS = 5500;            // tổng thời gian tối đa (PHP còn tự ngắt thêm theo ngân sách)
const MAX_SOURCE_SIDE = 2600;        // ảnh gốc quá lớn thì thu nhỏ trước để giới hạn thời gian
const MAX_OUT_PIXELS = 9_000_000;    // trần số điểm ảnh mỗi lần giải mã
const MAX_CPP_PIXELS = 12_000_000;   // trần riêng cho zxing-cpp (cần phóng to cả ảnh nhỏ tới x4)
const started = Date.now();
const timeUp = () => Date.now() - started > DEADLINE_MS;

const hints = new Map([
    [Z.DecodeHintType.TRY_HARDER, true],
    [Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.QR_CODE]],
]);

function decodeBitmap(img) {
    const { data, width, height } = img.bitmap;
    const lum = new Uint8ClampedArray(width * height);
    for (let i = 0, p = 0; i < lum.length; i++, p += 4) {
        lum[i] = (data[p] * 299 + data[p + 1] * 587 + data[p + 2] * 114) / 1000;
    }
    const src = new Z.RGBLuminanceSource(lum, width, height);
    for (const Binarizer of [Z.HybridBinarizer, Z.GlobalHistogramBinarizer]) {
        try {
            const reader = new Z.QRCodeReader();
            return reader.decode(new Z.BinaryBitmap(new Binarizer(src)), hints).getText();
        } catch {
            // thử bộ nhị phân hoá tiếp theo
        }
    }
    return null;
}

const isCccd = (t) => !!t && (t.match(/\|/g) || []).length >= 6;

// zxing-cpp (WASM) — mạnh hơn hẳn bản ZXing JS ở trên: đọc được QR chỉ ~3px/ô trong ảnh đã bị nén/thu nhỏ (ảnh gửi qua Zalo/Messenger)
// mà ZXing JS lẫn jsQR đều bó tay. Nạp .wasm từ node_modules (mặc định thư viện tự tải từ CDN — chậm và phụ thuộc mạng).
// Thiếu gói (chưa npm install) → trả null, các chiến lược ZXing JS bên dưới vẫn chạy như cũ.
let cppReader;
async function loadCppReader() {
    if (cppReader !== undefined) return cppReader;
    try {
        const entry = require.resolve('zxing-wasm/reader');
        const wasm = fs.readFileSync(path.join(path.dirname(entry), '..', '..', 'reader', 'zxing_reader.wasm'));
        const { readBarcodes, prepareZXingModule } = require('zxing-wasm/reader');
        await prepareZXingModule({ overrides: { wasmBinary: wasm.buffer.slice(wasm.byteOffset, wasm.byteOffset + wasm.byteLength) }, fireImmediately: true });
        cppReader = readBarcodes;
    } catch {
        cppReader = null;
    }
    return cppReader;
}

const cppOptions = { formats: ['QRCode'], tryHarder: true, tryRotate: true, tryInvert: true, tryDownscale: true, maxNumberOfSymbols: 1 };

async function decodeCpp(img) {
    const readBarcodes = await loadCppReader();
    if (!readBarcodes) return null;
    try {
        const { data, width, height } = img.bitmap;
        const pixels = new Uint8ClampedArray(data.buffer, data.byteOffset, data.byteLength);
        const found = await readBarcodes({ data: pixels, width, height, colorSpace: 'srgb' }, cppOptions);
        return found.length ? found[0].text : null;
    } catch {
        return null;
    }
}

// Ảnh đủ nét thì đọc ngay ở toàn ảnh x1. Ảnh nhỏ/nén thì QR chỉ ra ở VÀI tổ hợp vùng cắt + độ phóng (thực nghiệm: không tổ hợp nào
// luôn trúng) nên phải thử cả lưới — mỗi lần giải mã chỉ vài chục ms. Vùng nhỏ trước (rẻ), toàn ảnh sau.
function cppStrategies() {
    const crops = [
        { x: 0.64, y: 0.03, w: 0.34, h: 0.42 },
        { x: 0.55, y: 0.00, w: 0.45, h: 0.60 },
        { x: 0.45, y: 0.00, w: 0.55, h: 1.00 },
        { x: 0.00, y: 0.00, w: 1.00, h: 1.00 },
    ];
    const list = [{ x: 0, y: 0, w: 1, h: 1, z: 1 }];
    for (const z of [4, 5, 3, 2, 6]) {
        for (const c of crops) list.push({ ...c, z });
    }
    return list;
}

// Dò vùng "trông giống QR" trên ảnh gốc: chia ảnh thành ô vuông nhỏ, ô nào tương phản mạnh, đen/trắng xấp xỉ cân bằng và đổi màu
// dày theo CẢ hai chiều thì đánh dấu; cụm ô liền nhau gần vuông và đặc là ứng viên. Chỉ vài chục ms cho cả ảnh.
// Nhờ đó cắt sát được QR dù thẻ nằm ở đâu / xoay hướng nào trong ảnh (vd ảnh chụp dọc, thẻ nằm ngang giữa ảnh) — lưới vùng cắt cố định
// ở cppStrategies() chỉ nhắm góc trên-phải nên trượt các ảnh này, còn phóng cả ảnh thì vượt trần điểm ảnh.
function locateQrCandidates(img) {
    const { data, width: W, height: H } = img.bitmap;
    const B = Math.max(8, Math.round(Math.min(W, H) / 70)); // cạnh ô
    const bw = Math.floor(W / B), bh = Math.floor(H / B);
    if (bw < 3 || bh < 3) return [];

    const lum = new Uint8Array(W * H);
    for (let i = 0, p = 0; i < lum.length; i++, p += 4) {
        lum[i] = (data[p] * 77 + data[p + 1] * 150 + data[p + 2] * 29) >> 8;
    }

    const flag = new Uint8Array(bw * bh);
    for (let by = 0; by < bh; by++) {
        for (let bx = 0; bx < bw; bx++) {
            const x0 = bx * B, y0 = by * B;
            let min = 255, max = 0;
            for (let y = y0; y < y0 + B; y++) {
                for (let x = x0; x < x0 + B; x++) {
                    const v = lum[y * W + x];
                    if (v < min) min = v;
                    if (v > max) max = v;
                }
            }
            if (max - min < 60) continue;

            const mid = (min + max) >> 1;
            let dark = 0, flipsX = 0, flipsY = 0;
            for (let y = y0; y < y0 + B; y++) {
                for (let x = x0; x < x0 + B; x++) {
                    const d = lum[y * W + x] < mid;
                    if (d) dark++;
                    if (x > x0 && (lum[y * W + x - 1] < mid) !== d) flipsX++;
                    if (y > y0 && (lum[(y - 1) * W + x] < mid) !== d) flipsY++;
                }
            }
            const darkRatio = dark / (B * B);
            if (darkRatio > 0.25 && darkRatio < 0.75 && flipsX >= B * 1.5 && flipsY >= B * 1.5) flag[by * bw + bx] = 1;
        }
    }

    const seen = new Uint8Array(bw * bh);
    const found = [];
    for (let start = 0; start < flag.length; start++) {
        if (!flag[start] || seen[start]) continue;

        const stack = [start];
        seen[start] = 1;
        let count = 0, minX = bw, maxX = 0, minY = bh, maxY = 0;
        while (stack.length) {
            const cur = stack.pop();
            const cx = cur % bw, cy = (cur - cx) / bw;
            count++;
            if (cx < minX) minX = cx;
            if (cx > maxX) maxX = cx;
            if (cy < minY) minY = cy;
            if (cy > maxY) maxY = cy;
            for (let dy = -1; dy <= 1; dy++) {
                for (let dx = -1; dx <= 1; dx++) {
                    const nx = cx + dx, ny = cy + dy;
                    if (nx < 0 || ny < 0 || nx >= bw || ny >= bh) continue;
                    const k = ny * bw + nx;
                    if (flag[k] && !seen[k]) {
                        seen[k] = 1;
                        stack.push(k);
                    }
                }
            }
        }

        const w = maxX - minX + 1, h = maxY - minY + 1;
        if (count < 9 || w / h < 0.6 || w / h > 1.67 || count / (w * h) < 0.55) continue; // quá nhỏ / không vuông / rỗng (chữ, hoa văn)
        found.push({ x: minX * B, y: minY * B, w: w * B, h: h * B, count });
    }

    return found.sort((a, b) => b.count - a.count).slice(0, 3);
}

async function decodeCppPiece(base, x, y, w, h, z) {
    let piece = base;
    if (w !== base.width || h !== base.height) piece = base.clone().crop({ x, y, w, h });
    if (z !== 1) piece = (piece === base ? base.clone() : piece).resize({ w: w * z, h: h * z });

    const text = await decodeCpp(piece);
    return isCccd(text) ? text : null;
}

// Cắt quanh từng ứng viên rồi phóng to. Lề rộng trước (thực nghiệm: QR ~2.6px/ô đọc ổn nhất khi chừa lề ~50% mỗi phía), vùng cắt nhỏ
// nên mỗi lần phóng + giải mã chỉ vài chục ms.
async function scanLocatedCpp(base) {
    const W = base.width, H = base.height;
    for (const c of locateQrCandidates(base)) {
        for (const margin of [0.5, 0.3, 0.15]) {
            const mx = Math.round(c.w * margin), my = Math.round(c.h * margin);
            const x = Math.max(0, c.x - mx), y = Math.max(0, c.y - my);
            const w = Math.min(W - x, c.w + 2 * mx), h = Math.min(H - y, c.h + 2 * my);

            for (const z of [3, 4, 2, 5]) {
                if (timeUp()) return null;
                if (Math.max(w, h) * z > 2000) continue; // QR đã đủ lớn thì toàn ảnh x1 đã thử rồi, không cần phóng thêm

                const text = await decodeCppPiece(base, x, y, w, h, z);
                if (text) return text;
            }
        }
    }

    return null;
}

async function scanImageCpp(base) {
    if (!(await loadCppReader())) return null;

    const W = base.width, H = base.height;
    const [whole, ...grid] = cppStrategies();

    const direct = await decodeCppPiece(base, 0, 0, W, H, whole.z);
    if (direct) return direct;

    const located = await scanLocatedCpp(base);
    if (located) return located;

    for (const s of grid) {
        if (timeUp()) return null;

        const cx = Math.round(W * s.x), cy = Math.round(H * s.y);
        const cw = Math.min(W - cx, Math.round(W * s.w)), ch = Math.min(H - cy, Math.round(H * s.h));
        if (cw < 24 || ch < 24) continue;
        if (cw * s.z * ch * s.z > MAX_CPP_PIXELS) continue; // ảnh gốc đã lớn thì không cần (và không nên) phóng thêm

        const text = await decodeCppPiece(base, cx, cy, cw, ch, s.z);
        if (text) return text;
    }

    return null;
}

// Chiến lược crop+zoom: ưu tiên vùng QR góc trên-phải (CCCD gắn chip, mặt trước), rồi mở rộng. Toạ độ theo tỉ lệ ảnh.
function strategies() {
    return [
        { x: 0.80, y: 0.06, w: 0.19, h: 0.32, z: 2 },   // sát QR (hiệu quả nhất với ảnh nén/vỡ nét)
        { x: 0.78, y: 0.04, w: 0.21, h: 0.36, z: 2 },
        { x: 0.80, y: 0.06, w: 0.19, h: 0.32, z: 3 },
        { x: 0.76, y: 0.02, w: 0.23, h: 0.40, z: 2 },
        { x: 0.64, y: 0.03, w: 0.34, h: 0.42, z: 3 },
        { x: 0.60, y: 0.02, w: 0.38, h: 0.48, z: 2 },
        { x: 0.00, y: 0.00, w: 1.00, h: 1.00, z: 1 },   // toàn ảnh (mặt sau CCCD cũ có QR cỡ lớn)
        { x: 0.45, y: 0.00, w: 0.55, h: 1.00, z: 2 },
        { x: 0.55, y: 0.00, w: 0.45, h: 0.60, z: 3 },
        { x: 0.00, y: 0.00, w: 0.50, h: 0.55, z: 2 },   // góc trên-trái
        { x: 0.00, y: 0.00, w: 1.00, h: 0.55, z: 2 },
        { x: 0.00, y: 0.00, w: 1.00, h: 1.00, z: 2 },
    ];
}

async function scanImage(file) {
    let base;
    try {
        base = await Jimp.read(file);
    } catch {
        return null;
    }

    const longest = Math.max(base.width, base.height);
    if (longest > MAX_SOURCE_SIDE) {
        const k = MAX_SOURCE_SIDE / longest;
        base.resize({ w: Math.round(base.width * k), h: Math.round(base.height * k) });
    }

    const viaCpp = await scanImageCpp(base);
    if (viaCpp) return viaCpp;

    const W = base.width, H = base.height;
    for (const s of strategies()) {
        if (timeUp()) return null;

        const cx = Math.max(0, Math.round(W * s.x)), cy = Math.max(0, Math.round(H * s.y));
        const cw = Math.min(W - cx, Math.round(W * s.w)), ch = Math.min(H - cy, Math.round(H * s.h));
        if (cw < 24 || ch < 24) continue;

        let piece = base.clone().crop({ x: cx, y: cy, w: cw, h: ch });
        if (s.z !== 1) {
            let ow = Math.round(cw * s.z), oh = Math.round(ch * s.z);
            if (ow * oh > MAX_OUT_PIXELS) {
                const k = Math.sqrt(MAX_OUT_PIXELS / (ow * oh));
                ow = Math.round(ow * k);
                oh = Math.round(oh * k);
            }
            piece = piece.resize({ w: ow, h: oh });
        }

        const text = decodeBitmap(piece);
        if (isCccd(text)) return text;
    }

    return null;
}

(async () => {
    const files = process.argv.slice(2);
    for (const f of files) {
        if (timeUp()) break;
        const text = await scanImage(f);
        if (text) {
            process.stdout.write(text);
            process.exit(0);
        }
    }
    process.stdout.write('QR_NOT_FOUND');
    process.exit(1);
})();
