// Launcher cho dev local (Windows/Herd) — CHỈ nạp biến môi trường từ ../.env (gốc Laravel) rồi tự
// spawn `node server.js` làm tiến trình con với env đã gộp, để `npm start` là ĐỦ, không phải tự gõ
// $env:... trước mỗi lần chạy. KHÔNG dùng để chạy production — production dùng PM2 + ecosystem.
// config.js (đọc trực tiếp .env theo cùng cách, spawn server.js với biến môi trường CỦA PM2, không
// qua file này).
//
// Phải SPAWN TIẾN TRÌNH CON (không set process.env rồi require('./server.js') ngay trong tiến trình
// hiện tại) — NODE_EXTRA_CA_CERTS chỉ đáng tin cậy nếu có mặt trong environment NGAY LÚC tiến trình
// Node khởi động (một số bản Node chỉ đọc giá trị này 1 lần lúc process start), set muộn hơn trong
// cùng tiến trình có rủi ro không được áp dụng.
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

function readEnv(key) {
    try {
        const content = fs.readFileSync(path.resolve(__dirname, '../.env'), 'utf8');
        const match = content.match(new RegExp(`^${key}=(.*)$`, 'm'));
        return match ? match[1].trim().replace(/^["']|["']$/g, '') : undefined;
    } catch {
        return undefined;
    }
}

// Chỉ điền nếu CHƯA có sẵn trong environment hiện tại — người dùng tự set tay ($env:...) trước khi
// chạy vẫn được ưu tiên, không bị .env ghi đè.
const keys = [
    'WS_PORT', 'WS_INTERNAL_KEY', 'WS_ALLOWED_ORIGIN',
    'WS_TLS_CERT', 'WS_TLS_KEY', 'LARAVEL_INTERNAL_URL', 'NODE_EXTRA_CA_CERTS',
];

const env = { ...process.env };
const loaded = [];

for (const key of keys) {
    if (!env[key]) {
        const value = readEnv(key);
        if (value) {
            env[key] = value;
            loaded.push(key);
        }
    }
}

if (loaded.length > 0) {
    console.log(`[start] Đã nạp từ ../.env: ${loaded.join(', ')}`);
}

const result = spawnSync(process.execPath, ['server.js'], {
    cwd: __dirname,
    env,
    stdio: 'inherit',
});

process.exit(result.status === null ? 1 : result.status);
