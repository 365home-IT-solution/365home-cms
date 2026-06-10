const express = require('express');
const http    = require('http');
const { Server } = require('socket.io');

const app    = express();
const server = http.createServer(app);

const WS_PORT       = process.env.WS_PORT       || 3001;
const INTERNAL_KEY  = process.env.WS_INTERNAL_KEY || 'change-me-in-env';
const ALLOWED_ORIGIN = process.env.WS_ALLOWED_ORIGIN || '*';

const io = new Server(server, {
    cors: {
        origin: ALLOWED_ORIGIN,
        methods: ['GET', 'POST'],
    },
});

app.use(express.json());

// customer_id → Set of sockets (1 customer có thể dùng nhiều thiết bị)
const connections = new Map();

// ── WebSocket ────────────────────────────────────────────────────────────────
io.on('connection', (socket) => {
    const customerId = socket.handshake.auth.customer_id;

    if (!customerId) {
        socket.disconnect(true);
        return;
    }

    if (!connections.has(customerId)) {
        connections.set(customerId, new Set());
    }
    connections.get(customerId).add(socket);

    console.log(`[WS] Connected: customer=${customerId} total=${io.engine.clientsCount}`);

    socket.on('disconnect', () => {
        const sockets = connections.get(customerId);
        if (sockets) {
            sockets.delete(socket);
            if (sockets.size === 0) connections.delete(customerId);
        }
        console.log(`[WS] Disconnected: customer=${customerId}`);
    });
});

// ── Internal HTTP — Laravel gọi khi tạo thông báo ───────────────────────────
app.post('/internal/notify', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { customer_id, notification } = req.body;

    if (!customer_id || !notification) {
        return res.status(422).json({ error: 'Missing customer_id or notification' });
    }

    const sockets = connections.get(String(customer_id));

    if (sockets && sockets.size > 0) {
        sockets.forEach((socket) => socket.emit('notification.new', notification));
        console.log(`[WS] Pushed to customer=${customer_id} (${sockets.size} socket(s))`);
    } else {
        console.log(`[WS] customer=${customer_id} not connected — skipped`);
    }

    return res.json({ ok: true, delivered: sockets ? sockets.size : 0 });
});

// ── Broadcast đến tất cả customer đang kết nối ───────────────────────────────
app.post('/internal/notify-all', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { notification } = req.body;
    if (!notification) {
        return res.status(422).json({ error: 'Missing notification' });
    }

    io.emit('notification.new', notification);
    console.log(`[WS] Broadcast to all (${io.engine.clientsCount} clients)`);

    return res.json({ ok: true, delivered: io.engine.clientsCount });
});

// ── Health check ─────────────────────────────────────────────────────────────
app.get('/health', (_req, res) => {
    res.json({ status: 'ok', connections: io.engine.clientsCount });
});

server.listen(WS_PORT, () => {
    console.log(`[WS] Server running on port ${WS_PORT}`);
});
