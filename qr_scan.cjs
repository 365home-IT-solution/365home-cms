const { Jimp } = require('jimp');
const jsQR = require('jsqr');

const MAX_BASE_PIXELS    = 2_000_000; // ~1414×1414 — cân bằng giữa chi tiết QR và tốc độ
const MAX_ATTEMPT_PIXELS = 4_000_000; // giới hạn mỗi lần scale()

/**
 * Load + downscale một ảnh. Trả về null nếu không đọc được.
 */
async function loadImage(imagePath) {
    try {
        let img = await Jimp.read(imagePath);
        const px = img.width * img.height;
        if (px > MAX_BASE_PIXELS) {
            img = img.scale(Math.sqrt(MAX_BASE_PIXELS / px));
        }
        return img;
    } catch {
        return null;
    }
}

/**
 * Thử decode QR từ một Jimp image. Trả về data string hoặc null.
 */
function tryDecode(img) {
    try {
        let target = img;
        if (target.width * target.height > MAX_ATTEMPT_PIXELS) {
            const r = Math.sqrt(MAX_ATTEMPT_PIXELS / (target.width * target.height));
            target = target.scale(r);
        }
        const { data, width, height } = target.bitmap;
        const result = jsQR(data, width, height, { inversionAttempts: 'attemptBoth' });
        return result?.data ?? null;
    } catch {
        return null;
    }
}

async function scanQR(imagePaths) {
    // Load tất cả ảnh đồng thời
    const images = (await Promise.all(imagePaths.map(loadImage))).filter(Boolean);

    if (!images.length) {
        process.stderr.write('NO_IMAGES');
        process.exit(2);
    }

    // Mỗi attempt factory nhận (image, W, H) và trả về Jimp image đã transform
    // Thứ tự: với mỗi attempt, thử trên TẤT CẢ ảnh trước khi chuyển attempt tiếp theo.
    // → Không cần biết QR ở mặt trước hay sau — tìm được ngay ở attempt đầu tiên.
    const attemptFns = [
        // ── Toàn ảnh ───────────────────────────────────────────────────────────
        (img)       => img.clone(),
        (img)       => img.clone().greyscale().scale(2),

        // ── Nửa phải + góc trên-phải — QR nằm đây trên mọi format CCCD ───────
        (img, W, H) => img.clone().crop({ x: Math.floor(W*0.55), y: 0,            w: Math.floor(W*0.45), h: Math.floor(H*0.60) }).greyscale().scale(4),
        (img, W, H) => img.clone().crop({ x: Math.floor(W*0.50), y: 0,            w: Math.floor(W*0.50), h: H                  }).greyscale().scale(3),
        (img, W, H) => img.clone().crop({ x: Math.floor(W*0.60), y: 0,            w: Math.floor(W*0.40), h: Math.floor(H*0.55) }).greyscale().scale(5),

        // ── Nửa trái — fallback nếu ảnh bị lật/xoay ───────────────────────────
        (img, W, H) => img.clone().crop({ x: 0,                  y: 0,            w: Math.floor(W*0.50), h: H                  }).greyscale().scale(3),
        (img, W, H) => img.clone().crop({ x: 0,                  y: Math.floor(H*0.40), w: Math.floor(W*0.50), h: Math.floor(H*0.60) }).greyscale().scale(4),

        // ── Nửa dưới — ảnh chụp dọc (portrait) ────────────────────────────────
        (img, W, H) => img.clone().crop({ x: 0,                  y: Math.floor(H*0.50), w: W,                  h: Math.floor(H*0.50) }).greyscale().scale(3),
        (img, W, H) => img.clone().crop({ x: 0,                  y: Math.floor(H*0.40), w: W,                  h: Math.floor(H*0.60) }).greyscale().scale(3),

        // ── Xử lý contrast/normalize toàn ảnh ─────────────────────────────────
        (img)       => img.clone().greyscale().invert(),
        (img)       => img.clone().normalize().greyscale().scale(2),
        (img)       => img.clone().contrast(0.5).greyscale().scale(2),
        (img)       => img.clone().normalize().contrast(0.3).greyscale().scale(3),

        // ── Ảnh mờ / chụp xa: scale cao + contrast mạnh SAU upscale ───────────
        // Áp contrast sau scale để làm nét cạnh QR bị blur do ảnh chụp xa.
        (img, W, H) => img.clone().crop({ x: Math.floor(W*0.55), y: 0, w: Math.floor(W*0.45), h: Math.floor(H*0.60) }).greyscale().scale(5).contrast(0.8),
        (img, W, H) => img.clone().crop({ x: Math.floor(W*0.60), y: 0, w: Math.floor(W*0.40), h: Math.floor(H*0.55) }).normalize().greyscale().scale(6).contrast(0.9),
        (img, W, H) => img.clone().crop({ x: Math.floor(W*0.58), y: 0, w: Math.floor(W*0.42), h: Math.floor(H*0.52) }).greyscale().normalize().scale(7).contrast(1.0),
        (img, W, H) => img.clone().crop({ x: Math.floor(W*0.55), y: 0, w: Math.floor(W*0.45), h: Math.floor(H*0.55) }).normalize().greyscale().scale(8).normalize(),
    ];

    for (const attemptFn of attemptFns) {
        for (const image of images) {
            const W = image.width;
            const H = image.height;
            const decoded = tryDecode(attemptFn(image, W, H));
            if (decoded) {
                process.stdout.write(decoded);
                return;
            }
        }
    }

    process.stderr.write('QR_NOT_FOUND');
    process.exit(1);
}

const imgPaths = process.argv.slice(2).filter(Boolean);
if (!imgPaths.length) {
    process.stderr.write('Usage: node qr_scan.cjs <image1> [image2]');
    process.exit(1);
}
scanQR(imgPaths);
