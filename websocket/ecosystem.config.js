const fs   = require('fs');
const path = require('path');

function readEnv(key) {
    try {
        const content = fs.readFileSync(path.resolve(__dirname, '../.env'), 'utf8');
        const match   = content.match(new RegExp(`^${key}=(.*)$`, 'm'));
        return match ? match[1].trim().replace(/^["']|["']$/g, '') : undefined;
    } catch {
        return undefined;
    }
}

module.exports = {
    apps: [
        {
            name: '365home-ws',
            script: 'server.js',
            cwd: '/home/dev/www/365home.vn/websocket',
            instances: 1,
            exec_mode: 'fork',
            autorestart: true,
            watch: false,
            max_memory_restart: '200M',
            env: {
                NODE_ENV: 'production',
                WS_PORT:          readEnv('WS_PORT')          || 3001,
                WS_INTERNAL_KEY:  readEnv('WS_INTERNAL_KEY'),
                WS_ALLOWED_ORIGIN: readEnv('WS_ALLOWED_ORIGIN') || 'https://365home.vn',
            },
            error_file: '/home/dev/.pm2/logs/365home-ws-error.log',
            out_file:   '/home/dev/.pm2/logs/365home-ws-out.log',
            log_date_format: 'YYYY-MM-DD HH:mm:ss',
        },
    ],
};
