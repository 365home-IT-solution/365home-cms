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
                WS_PORT: 3001,
                WS_INTERNAL_KEY: 'THAY_BANG_KEY_THAT_TRONG_ENV',
                WS_ALLOWED_ORIGIN: 'https://365home.vn',
            },
            error_file: '/home/dev/www/365home.vn/storage/logs/ws-error.log',
            out_file:   '/home/dev/www/365home.vn/storage/logs/ws-out.log',
            log_date_format: 'YYYY-MM-DD HH:mm:ss',
        },
    ],
};
