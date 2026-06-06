const { Jimp } = require('jimp');
const jsQR = require('jsqr');

async function scanQR(imagePath) {
    try {
        const image = await Jimp.read(imagePath);
        const W = image.width;
        const H = image.height;

        const attempts = [
            () => image.clone(),
            () => image.clone().greyscale().scale(2),
            () => image.clone().crop({ x: Math.floor(W*0.55), y: 0, w: Math.floor(W*0.45), h: Math.floor(H*0.60) }).greyscale().scale(4),
            () => image.clone().crop({ x: Math.floor(W*0.5), y: 0, w: Math.floor(W*0.5), h: H }).greyscale().scale(3),
            () => image.clone().crop({ x: Math.floor(W*0.60), y: 0, w: Math.floor(W*0.40), h: Math.floor(H*0.55) }).greyscale().scale(5),
            () => image.clone().greyscale().invert(),
            () => image.clone().normalize().greyscale().scale(2),
            () => image.clone().contrast(0.5).greyscale().scale(2),
            () => image.clone().normalize().contrast(0.3).greyscale().scale(3),
        ];

        for (let i = 0; i < attempts.length; i++) {
            const img = attempts[i]();
            const { data, width, height } = img.bitmap;
            const result = jsQR(data, width, height, { inversionAttempts: 'attemptBoth' });
            if (result && result.data) {
                process.stdout.write(result.data);
                return;
            }
        }

        process.stderr.write('QR_NOT_FOUND');
        process.exit(1);
    } catch (e) {
        process.stderr.write('ERROR: ' + e.message);
        process.exit(2);
    }
}

const imgPath = process.argv[2];
if (!imgPath) { process.stderr.write('Usage: node qr_scan.cjs <image_path>'); process.exit(1); }
scanQR(imgPath);
