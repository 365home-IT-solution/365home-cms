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

    // Authenticated: register for personal notifications
    if (customerId) {
        if (!connections.has(customerId)) {
            connections.set(customerId, new Set());
        }
        connections.get(customerId).add(socket);
        console.log(`[WS] Connected: customer=${customerId} total=${io.engine.clientsCount}`);
    } else {
        console.log(`[WS] Connected: guest total=${io.engine.clientsCount}`);
    }

    // Subscribe to slot availability for a room+date (no auth required)
    socket.on('subscribe:room', ({ room_id, date }) => {
        if (room_id && date) {
            socket.join(`room:${room_id}:${date}`);
        }
    });

    socket.on('unsubscribe:room', ({ room_id, date }) => {
        if (room_id && date) {
            socket.leave(`room:${room_id}:${date}`);
        }
    });

    // Subscribe PHỦ MỌI NGÀY của 1 phòng theo khung giờ — thay thế cho việc phải tự liệt kê/
    // subscribe:room theo từng ngày riêng (VD khách đang chọn khung giờ rải nhiều ngày cùng lúc
    // trên form đặt phòng, đổi ngày xem liên tục). Mọi event slot.updated/slot.hold.updated của
    // phòng này (bất kể ngày nào) đều bắn thêm vào đây — xem các endpoint /internal/slot-update,
    // /internal/slot-hold-update, /internal/slot-blocked-range bên dưới (dùng chung 1 emit tới cả 2
    // room cùng lúc để KHÔNG bắn trùng 2 lần cho client đã subscribe cả 2 kiểu).
    socket.on('subscribe:room-slots', ({ room_id }) => {
        if (room_id) {
            socket.join(`room-all:${room_id}`);
        }
    });

    socket.on('unsubscribe:room-slots', ({ room_id }) => {
        if (room_id) {
            socket.leave(`room-all:${room_id}`);
        }
    });

    // Subscribe to daily room hold events (no auth required)
    socket.on('subscribe:daily-room', ({ room_id }) => {
        if (room_id) {
            socket.join(`daily:${room_id}`);
        }
    });

    socket.on('unsubscribe:daily-room', ({ room_id }) => {
        if (room_id) {
            socket.leave(`daily:${room_id}`);
        }
    });

    // Subscribe to a specific order (guest + logged-in user đang xem đơn hàng)
    socket.on('subscribe:order', ({ order_code }) => {
        if (order_code) {
            socket.join(`order:${order_code}`);
        }
    });

    socket.on('unsubscribe:order', ({ order_code }) => {
        if (order_code) {
            socket.leave(`order:${order_code}`);
        }
    });

    // Subscribe to a specific conversation (customer + admin đang xem conv đó)
    socket.on('subscribe:chat', ({ conversation_id }) => {
        if (conversation_id) {
            socket.join(`chat:${conversation_id}`);
        }
    });

    socket.on('unsubscribe:chat', ({ conversation_id }) => {
        if (conversation_id) {
            socket.leave(`chat:${conversation_id}`);
        }
    });

    // Subscribe to admin-wide chat notifications (admin đang xem danh sách chat)
    socket.on('subscribe:chat-admin', () => {
        socket.join('chat:admin');
    });

    socket.on('unsubscribe:chat-admin', () => {
        socket.leave('chat:admin');
    });

    // Subscribe to admin notification bell (đơn hàng mới/đổi trạng thái...) — phòng CHUNG cho mọi
    // admin đang mở app/SPA riêng, không phân biệt ai xem được thông báo nào (REST API tự lọc đúng
    // theo user khi client gọi lại GET /api/admin/notifications sau khi nhận event này).
    socket.on('subscribe:admin-notifications', () => {
        socket.join('admin:notifications');
    });

    socket.on('unsubscribe:admin-notifications', () => {
        socket.leave('admin:notifications');
    });

    // Subscribe to admin order list/dashboard refresh signal (đơn mới/đổi trạng thái/xoá) — phòng
    // CHUNG, chỉ báo hiệu "có gì đổi", không kèm dữ liệu — client tự gọi lại REST API tương ứng
    // (GET /api/admin/orders, dashboard/kpi-stats...) để lấy đúng dữ liệu theo phạm vi quyền của
    // chính admin đó, cùng nguyên tắc admin:notifications ở trên.
    socket.on('subscribe:admin-orders', () => {
        socket.join('admin:orders');
    });

    socket.on('unsubscribe:admin-orders', () => {
        socket.leave('admin:orders');
    });

    socket.on('disconnect', () => {
        if (customerId) {
            const sockets = connections.get(customerId);
            if (sockets) {
                sockets.delete(socket);
                if (sockets.size === 0) connections.delete(customerId);
            }
        }
        console.log(`[WS] Disconnected: customer=${customerId || 'guest'}`);
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

// ── Slot availability update — broadcast to room+date channel ────────────────
app.post('/internal/slot-update', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { room_id, date, slot_ids, status } = req.body;
    if (!room_id || !date) {
        return res.status(422).json({ error: 'Missing room_id or date' });
    }

    const channel = `room:${room_id}:${date}`;
    io.to(channel).to(`room-all:${room_id}`).emit('slot.updated', { room_id, date, slot_ids, status: status || 'pending' });
    console.log(`[WS] Slot update: room=${room_id} date=${date} slots=[${slot_ids}] → ${channel}`);

    return res.json({ ok: true });
});

// ── Daily room booked — broadcast khi booking confirmed ──────────────────────
app.post('/internal/daily-booked', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { room_id, checkin, checkout } = req.body;
    if (!room_id || !checkin || !checkout) {
        return res.status(422).json({ error: 'Missing room_id, checkin or checkout' });
    }

    const channel = `daily:${room_id}`;
    io.to(channel).emit('daily.booked', { room_id, checkin, checkout });
    console.log(`[WS] Daily booked: room=${room_id} ${checkin}→${checkout} → ${channel}`);

    return res.json({ ok: true });
});

// ── Time-slot hold update — "đang chọn" tạm thời cho phòng theo khung giờ ────
// holds: danh sách {session_id, timeslot_id} CÒN GIỮ của ĐÚNG ngày `date` này (Laravel đã lọc sẵn
// theo date trước khi gọi, xem TimeSlotHoldController) — luôn bắn cho đúng 1 channel
// room:{room_id}:{date} (cùng channel với slot.updated), kể cả khi holds rỗng (vừa release hold
// cuối cùng của ngày đó) để client biết ngày này hết hold, không phải suy đoán từ việc "không thấy
// event nào nữa".
app.post('/internal/slot-hold-update', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { room_id, date, holds } = req.body;
    if (!room_id || !date) {
        return res.status(422).json({ error: 'Missing room_id or date' });
    }

    io.to(`room:${room_id}:${date}`).to(`room-all:${room_id}`).emit('slot.hold.updated', { room_id, date, holds: holds || [] });
    console.log(`[WS] Slot hold update: room=${room_id} date=${date} holds=${(holds || []).length}`);

    return res.json({ ok: true });
});

// ── Daily room hold update — broadcast đến clients đang xem phòng ────────────
app.post('/internal/daily-hold-update', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { room_id, holds } = req.body;
    if (!room_id) {
        return res.status(422).json({ error: 'Missing room_id' });
    }

    const channel = `daily:${room_id}`;
    io.to(channel).emit('daily.hold.updated', { room_id, holds: holds || [] });
    console.log(`[WS] Daily hold: room=${room_id} holds=${(holds || []).length} → ${channel}`);

    return res.json({ ok: true });
});

// ── Order update — broadcast khi admin cập nhật đơn hàng ─────────────────────
app.post('/internal/order-update', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { order_code, customer_id, order } = req.body;
    if (!order_code) {
        return res.status(422).json({ error: 'Missing order_code' });
    }

    const payload = { order_code, order };

    // Broadcast đến tất cả client đang subscribe đơn này (guest + user)
    io.to(`order:${order_code}`).emit('order.updated', payload);

    // Push thêm cho customer nếu biết customer_id (logged-in user trên app)
    if (customer_id) {
        const sockets = connections.get(String(customer_id));
        if (sockets && sockets.size > 0) {
            sockets.forEach((s) => s.emit('order.updated', payload));
        }
    }

    console.log(`[WS] Order update: order=${order_code} customer=${customer_id || 'guest'}`);
    return res.json({ ok: true });
});

// ── Access code changed — thông báo mã cổng/phòng thay đổi ───────────────────
app.post('/internal/order-code-changed', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { order_code, customer_id, new_code, type } = req.body;
    if (!order_code || !new_code) {
        return res.status(422).json({ error: 'Missing order_code or new_code' });
    }

    // type: 'manual' | 'ttlock'
    const payload = { order_code, new_code, type: type || 'manual' };

    io.to(`order:${order_code}`).emit('order.code_changed', payload);

    if (customer_id) {
        const sockets = connections.get(String(customer_id));
        if (sockets && sockets.size > 0) {
            sockets.forEach((s) => s.emit('order.code_changed', payload));
        }
    }

    console.log(`[WS] Code changed: order=${order_code} type=${type} customer=${customer_id || 'guest'}`);
    return res.json({ ok: true });
});

// ── Chat message — broadcast đến cả khách và admin đang xem conversation ─────
app.post('/internal/chat-message', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { conversation_id, message } = req.body;
    if (!conversation_id || !message) {
        return res.status(422).json({ error: 'Missing conversation_id or message' });
    }

    const channel = `chat:${conversation_id}`;
    io.to(channel).emit('chat.message', { conversation_id, message });
    console.log(`[WS] Chat message: conv=${conversation_id} sender=${message.sender_type} → ${channel}`);

    return res.json({ ok: true });
});

// ── Chat: cập nhật danh sách conversation cho admin ──────────────────────────
app.post('/internal/chat-list-update', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { conversation_id, last_message_preview, last_message_at, admin_unread, customer } = req.body;
    if (!conversation_id) {
        return res.status(422).json({ error: 'Missing conversation_id' });
    }

    io.to('chat:admin').emit('chat.list_update', {
        conversation_id,
        last_message_preview,
        last_message_at,
        admin_unread,
        customer,
    });
    console.log(`[WS] Chat list update: conv=${conversation_id} admin_unread=${admin_unread}`);

    return res.json({ ok: true });
});

// ── Chat: read receipt ────────────────────────────────────────────────────────
app.post('/internal/chat-read', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { conversation_id, read_by } = req.body;
    if (!conversation_id || !read_by) {
        return res.status(422).json({ error: 'Missing conversation_id or read_by' });
    }

    const channel = `chat:${conversation_id}`;
    io.to(channel).emit('chat.read', { conversation_id, read_by });
    console.log(`[WS] Chat read: conv=${conversation_id} read_by=${read_by}`);

    return res.json({ ok: true });
});

// ── Admin notification — báo "có thông báo mới", client tự gọi lại REST API để lấy nội dung ──
app.post('/internal/admin-notify', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { notification } = req.body;
    if (!notification) {
        return res.status(422).json({ error: 'Missing notification' });
    }

    io.to('admin:notifications').emit('admin_notification.new', notification);
    console.log('[WS] Admin notification pushed to admin:notifications');

    return res.json({ ok: true });
});

// ── Admin order list/dashboard refresh signal — Laravel gọi khi đơn tạo/đổi trạng thái/xoá ──
app.post('/internal/admin-order-changed', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { order_code, event } = req.body;
    if (!order_code || !event) {
        return res.status(422).json({ error: 'Missing order_code or event' });
    }

    io.to('admin:orders').emit('admin_order.changed', { order_code, event });
    console.log(`[WS] Admin order changed (${event}) pushed to admin:orders — order_code=${order_code}`);

    return res.json({ ok: true });
});

// ── Slot blocked range — admin tô đen/gỡ tô đen nhiều ngày cùng lúc ─────────
app.post('/internal/slot-blocked-range', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { room_id, dates, slot_ids, status, source } = req.body;
    if (!room_id || !Array.isArray(dates) || dates.length === 0) {
        return res.status(422).json({ error: 'Missing room_id or dates' });
    }

    for (const date of dates) {
        io.to(`room:${room_id}:${date}`).to(`room-all:${room_id}`).emit('slot.updated', {
            room_id,
            date,
            slot_ids: slot_ids || [],
            status: status || 'blocked',
            // Truyền lại nguyên trạng cho client web (resources/js/ws-client.js) — phía đó dựa vào
            // field này để bỏ qua việc ép Livewire re-render toàn bộ khi Reverb đã vá trực tiếp rồi
            // (xem SlotRealtimeService::broadcastBlockedRange()). Field phụ, không phá hợp đồng cũ
            // với app RN/Filament (chỉ đọc thêm nếu có, bỏ qua nếu không).
            ...(source ? { source } : {}),
        });
    }

    console.log(`[WS] Slot blocked range: room=${room_id} dates=${dates.length} status=${status}`);
    return res.json({ ok: true });
});

// ── Daily blocked — admin khóa/gỡ khoảng ngày cho phòng theo ngày ────────────
app.post('/internal/daily-blocked', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { room_id, blocked_ranges } = req.body;
    if (!room_id) {
        return res.status(422).json({ error: 'Missing room_id' });
    }

    const channel = `daily:${room_id}`;
    io.to(channel).emit('daily.blocked', { room_id, blocked_ranges: blocked_ranges || [] });
    console.log(`[WS] Daily blocked: room=${room_id} ranges=${(blocked_ranges || []).length} → ${channel}`);

    return res.json({ ok: true });
});

// ── Order deleted — admin xóa đơn, app ẩn đơn đó ngay ───────────────────────
app.post('/internal/order-deleted', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { order_code, customer_id } = req.body;
    if (!order_code) {
        return res.status(422).json({ error: 'Missing order_code' });
    }

    const payload = { order_code };

    io.to(`order:${order_code}`).emit('order.deleted', payload);

    if (customer_id) {
        const sockets = connections.get(String(customer_id));
        if (sockets && sockets.size > 0) {
            sockets.forEach((s) => s.emit('order.deleted', payload));
        }
    }

    console.log(`[WS] Order deleted: order=${order_code} customer=${customer_id || 'guest'}`);
    return res.json({ ok: true });
});

// ── Order check-in/out — xác nhận mở cổng thành công ─────────────────────────
app.post('/internal/order-checkin', (req, res) => {
    const key = req.headers['x-internal-key'];
    if (key !== INTERNAL_KEY) {
        return res.status(403).json({ error: 'Forbidden' });
    }

    const { order_code, customer_id, type, checked_in_at } = req.body;
    if (!order_code || !type) {
        return res.status(422).json({ error: 'Missing order_code or type' });
    }

    // type: 'checkin' | 'checkout'
    const payload = { order_code, type, checked_in_at: checked_in_at || null };

    io.to(`order:${order_code}`).emit('order.checkin', payload);

    if (customer_id) {
        const sockets = connections.get(String(customer_id));
        if (sockets && sockets.size > 0) {
            sockets.forEach((s) => s.emit('order.checkin', payload));
        }
    }

    console.log(`[WS] Order checkin: order=${order_code} type=${type} customer=${customer_id || 'guest'}`);
    return res.json({ ok: true });
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
