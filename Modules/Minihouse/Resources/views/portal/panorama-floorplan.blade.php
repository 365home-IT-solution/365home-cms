<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sơ đồ tầng - {{ $building->name }}</title>
    {{-- 3D THẬT bằng Three.js/WebGL (đổi từ bản CSS 3D transform thuần trước đây theo yêu cầu — cần
    ánh sáng/đổ bóng thật để "chi tiết và chính xác nhất") — OrbitControls (thư viện điều khiển camera
    chuẩn của Three.js) đảm nhiệm toàn bộ xoay/di chuyển/zoom, thay cho phần tự viết tay bằng CSS
    transform trước đó. Dữ liệu $layout (nodes/satellites/edges) TỪ PHP KHÔNG ĐỔI — chỉ đổi cách vẽ. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <style>
        :root{
            --bg:#f4f5f7; --panel:#ffffff; --border:#e5e7eb;
            --text:#111827; --text-dim:#6b7280;
            --primary:#4f46e5; --primary-dark:#4338ca;
            --accent-amber:#f59e0b; --accent-teal:#0d9488; --accent-pink:#db2777; --accent-sky:#0284c7;
        }
        *{box-sizing:border-box;}
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Inter',system-ui,sans-serif; padding:20px 16px 40px; }
        h1,h2,h3{ text-wrap:balance; margin:0; }
        .wrap{ max-width:1220px; margin:0 auto; }
        header{ display:flex; flex-wrap:wrap; align-items:baseline; justify-content:space-between; gap:10px; margin-bottom:16px; }
        .eyebrow{ font-size:11px; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:var(--primary); }
        h1{ font-size:clamp(19px,3vw,24px); font-weight:700; letter-spacing:-0.01em; margin-top:2px; color:var(--text); }
        .back-link{ color:var(--text-dim); font-size:13px; text-decoration:none; border:1px solid var(--border); padding:7px 14px; border-radius:8px; background:var(--panel); }
        .back-link:hover{ color:var(--text); border-color:var(--primary); }

        .toolbar{ display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
        .toolbar button{
            font-family:inherit; font-size:12.5px; font-weight:600; color:var(--text-dim);
            background:var(--panel); border:1px solid var(--border); border-radius:8px;
            padding:7px 12px; cursor:pointer;
        }
        .toolbar button:hover{ color:var(--primary); border-color:var(--primary); }
        .toolbar .hint{ font-size:12px; color:var(--text-dim); display:flex; align-items:center; gap:6px; }

        .stage{
            position:relative; background:#dbeafe;
            border:1px solid var(--border); border-radius:16px;
            overflow:hidden; height:min(85vh, 860px);
        }
        .stage:fullscreen{ height:100vh; border-radius:0; }
        .stage canvas{ display:block; width:100%; height:100%; cursor:grab; touch-action:none; }
        .stage canvas:active{ cursor:grabbing; }

        .zoom-controls{
            position:absolute; right:12px; bottom:12px; z-index:6;
            display:flex; flex-direction:column; gap:6px;
        }
        .zoom-controls button{
            width:36px; height:36px; border-radius:8px; border:1px solid var(--border);
            background:var(--panel); color:var(--text); font-size:18px; font-weight:700;
            cursor:pointer; line-height:1; display:flex; align-items:center; justify-content:center;
            box-shadow:0 1px 4px rgba(0,0,0,.12);
        }
        .zoom-controls button:hover{ color:var(--primary); border-color:var(--primary); }

        .room-focus-bar{
            position:absolute; top:12px; left:12px; right:12px; z-index:6;
            display:flex; align-items:center; gap:10px; flex-wrap:wrap;
            background:rgba(255,255,255,.96); border:1px solid var(--border); border-radius:10px;
            padding:9px 12px; box-shadow:0 4px 14px rgba(15,23,42,.12);
        }
        .room-focus-bar strong{ flex:1; font-size:13.5px; color:var(--text); font-weight:700; }
        .room-focus-bar button{
            font-family:inherit; font-size:12.5px; font-weight:600; cursor:pointer;
            border-radius:7px; padding:6px 11px; border:1px solid var(--border); background:#fff; color:var(--text-dim);
        }
        .room-focus-bar button:hover{ color:var(--primary); border-color:var(--primary); }
        .room-focus-bar button.primary{ background:var(--primary); border-color:var(--primary); color:#fff; }
        .room-focus-bar button.primary:hover{ background:var(--primary-dark); border-color:var(--primary-dark); }

        .legend{ display:flex; flex-wrap:wrap; gap:16px; margin-top:16px; font-size:12.5px; color:var(--text-dim); }
        .legend span{ display:inline-flex; align-items:center; gap:7px; }
        .legend i{ width:9px; height:9px; border-radius:50%; display:inline-block; flex:none; }

        .note{ margin-top:16px; padding:14px 16px; border-radius:10px; background:var(--panel); border:1px solid var(--border); font-size:12.5px; color:var(--text-dim); line-height:1.6; }
        .note b{ color:var(--text); }
        .empty{ text-align:center; padding:60px 20px; color:var(--text-dim); }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <span class="eyebrow">Minihouse · Sơ đồ 360°</span>
            <h1>Sơ đồ tầng 3D — {{ $building->name }}</h1>
        </div>
        <a class="back-link" href="{{ route('minihouse.tour.show', $building->id) }}">← Xem tour 360°</a>
    </header>

    <div class="toolbar">
        <button type="button" id="btn-reset">Đặt lại góc nhìn</button>
        <button type="button" id="btn-topdown">Nhìn từ trên xuống</button>
        <button type="button" id="btn-side">Nhìn ngang</button>
        <button type="button" id="btn-fullscreen">Toàn màn hình</button>
        <span class="hint">Chuột trái/1 ngón: xoay · Chuột phải/2 ngón: di chuyển · Cuộn/chụm 2 ngón: phóng to · Bấm 1 phòng để vào xem riêng sơ đồ chi tiết phòng đó</span>
    </div>

    <div class="stage" id="stage">
        <div class="zoom-controls">
            <button type="button" id="btn-zoom-in" title="Phóng to">+</button>
            <button type="button" id="btn-zoom-out" title="Thu nhỏ">−</button>
        </div>
        <div class="room-focus-bar" id="room-focus-bar" hidden>
            <button type="button" id="room-focus-back">← Toàn bộ toà nhà</button>
            <strong id="room-focus-title"></strong>
            <button type="button" id="room-focus-tour" class="primary">Xem tour 360° thật</button>
        </div>
    </div>

    <div class="legend">
        <span><i style="background:var(--accent-amber)"></i>Chuyển sang điểm chung khác</span>
        <span><i style="background:var(--accent-teal)"></i>Điểm bấm xem 360°</span>
        <span><i style="background:#92400e"></i>Cửa ra vào (phòng/WC/phòng ngủ)</span>
        {{-- Các mục còn lại (WC/Phòng ngủ/Bếp/Gác lửng/Ban công...) được thêm ĐỘNG bằng JS bên dưới,
        đúng theo những loại không gian con THỰC SỰ có trong toà nhà này — xem usedKinds/KIND_META. --}}
    </div>

    <div class="note">
        <b>Sơ đồ TỰ SINH từ đúng dữ liệu 360° hiện có</b> — vị trí từng phòng lấy từ toạ độ lưới thật (vị trí/thứ tự phòng đã khai báo), không phải suy từ ảnh chụp. Bấm vào 1 phòng để vào xem RIÊNG sơ đồ chi tiết phòng đó (ẩn hết phòng/hành lang khác, lia camera cận cảnh) — bấm "Xem tour 360° thật" trong thanh công cụ hiện ra để xem đúng, đầy đủ bằng ảnh chụp thật. Không phải mô hình quét 3D có chiều sâu như Matterport — kích thước khối và nội thất mang tính minh hoạ, không phải đo đạc thật.
    </div>
</div>

<script>
(function(){
    const layout = @json($layout);
    const TOUR_BASE = @json(route('minihouse.tour.show', $building->id));
    const stage = document.getElementById('stage');

    if (! layout.nodes.length) {
        stage.outerHTML = '<div class="empty">Chưa có dữ liệu để dựng sơ đồ.</div>';
        return;
    }

    try {
        const SCALE = 42; // đơn vị world / 1 đơn vị lưới — GIỮ NGUYÊN đúng số như bản CSS trước để
        // mọi toạ độ x/y PHP đã tính (gridPositions()/yawBasedPositions()) không cần đổi gì.

        const nodeById = {};
        layout.nodes.forEach(n => nodeById[n.id] = n);

        // NHIỀU TẦNG: Three.js dùng trục Y-UP chuẩn (khác CSS 3D trước đây phải dùng Y ÂM = lên) —
        // tầng nhỏ nhất làm mặt đất (groundY=0), tầng cao hơn CỘNG THÊM FLOOR_HEIGHT mỗi bậc.
        const FLOOR_HEIGHT = 110;
        const floorNumbers = layout.nodes.map(n => n.floor ?? 1);
        const minFloor = floorNumbers.length ? Math.min(...floorNumbers) : 1;
        function groundYFor(n){ return ((n.floor ?? 1) - minFloor) * FLOOR_HEIGHT; }

        const ROOM_W = 128, ROOM_D = 78, ROOM_H = 60;
        const DOOR_RESERVE = 48;

        // Ngưỡng khoảng cách camera->nhãn để HIỆN/ẨN (xem addLabel3D + animate() phía dưới) — tên
        // phòng là thông tin ĐỊNH HƯỚNG chính nên hiện ở khoảng cách xa hơn (vẫn thấy khi nhìn tổng
        // thể); nhãn buồng phụ (WC/Bếp/Ban công/Gác lửng) và "Cầu thang" là chi tiết PHỤ, chỉ cần
        // hiện khi đã zoom sát vào đúng khu vực đó.
        const ROOM_LABEL_DIST = 900, STAIR_LABEL_DIST = 500, SATELLITE_LABEL_DIST = 260;

        const corridorSpan = {};
        layout.edges.forEach(e => {
            const a = nodeById[e.fromId], b = nodeById[e.toId];
            if (! a || ! b || a.floor !== b.floor) return;
            const corridor = a.isCommon && ! b.isCommon ? a : (b.isCommon && ! a.isCommon ? b : null);
            const room = corridor === a ? b : a;
            if (! corridor) return;
            const s = corridorSpan[corridor.id] ?? { min: room.x, max: room.x };
            s.min = Math.min(s.min, room.x);
            s.max = Math.max(s.max, room.x);
            corridorSpan[corridor.id] = s;
        });

        function rectFor(n){
            if (! n.isCommon) return { w: ROOM_W, d: ROOM_D, h: ROOM_H };
            const span = corridorSpan[n.id];
            const w = span ? (span.max - span.min) * SCALE + ROOM_W : 130;
            return { w, d: 46, h: 22 };
        }

        // ==================================================================================
        // ---- Khởi tạo scene/camera/renderer/ánh sáng/OrbitControls ----
        // ==================================================================================
        const scene = new THREE.Scene();
        scene.background = new THREE.Color(0xdbeafe);

        const camera = new THREE.PerspectiveCamera(50, 1, 1, 20000);

        const renderer = new THREE.WebGLRenderer({ antialias: true });
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        stage.appendChild(renderer.domElement);

        function currentSize(){
            return { w: stage.clientWidth || 800, h: stage.clientHeight || 600 };
        }
        function resizeRenderer(){
            const { w, h } = currentSize();
            renderer.setSize(w, h, false);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
        }
        resizeRenderer();

        // Ánh sáng THẬT (khác hẳn bản CSS trước — chỉ có màu phẳng, không có bóng đổ/khối sáng tối) —
        // Hemisphere làm nền dịu (trời/đất), Directional làm nguồn sáng chính có đổ bóng thật.
        scene.add(new THREE.HemisphereLight(0xffffff, 0xb0a99f, 0.65));
        const sun = new THREE.DirectionalLight(0xffffff, 0.9);
        sun.position.set(600, 900, 400);
        sun.castShadow = true;
        sun.shadow.mapSize.set(2048, 2048);
        sun.shadow.camera.left = -1200; sun.shadow.camera.right = 1200;
        sun.shadow.camera.top = 1200; sun.shadow.camera.bottom = -1200;
        sun.shadow.camera.far = 3000;
        scene.add(sun);
        scene.add(new THREE.AmbientLight(0xffffff, 0.25));

        // ---- OrbitControls: xoay (trái/1 ngón), di chuyển (phải/2 ngón), zoom (cuộn/chụm) — đúng
        // hành vi mặc định của thư viện, không cần tự viết tay như bản CSS trước ----
        const controls = new THREE.OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.12;
        controls.minDistance = 60;
        controls.maxDistance = 4000;
        controls.maxPolarAngle = Math.PI * 0.49; // không cho lật camera xuyên qua mặt đất

        const DEFAULT_TARGET = new THREE.Vector3(0, 40, 0);
        const DEFAULT_CAMERA_POS = new THREE.Vector3(420, 520, 620);
        camera.position.copy(DEFAULT_CAMERA_POS);
        controls.target.copy(DEFAULT_TARGET);
        controls.update();

        // ==================================================================================
        // ---- Khối 3D thật (BoxGeometry, có ánh sáng/đổ bóng) — thay cho div CSS trước đây ----
        // ==================================================================================
        const materialCache = {};
        function materialFor(colorHex, opts){
            const key = colorHex + JSON.stringify(opts || {});
            if (! materialCache[key]) {
                materialCache[key] = new THREE.MeshStandardMaterial(Object.assign({
                    color: colorHex, roughness: 0.85, metalness: 0.05,
                }, opts || {}));
            }
            return materialCache[key];
        }

        // 1 khối hộp ĐẶC đơn giản (nội thất, buồng phụ, sàn gác, bệ ban công...) — tâm world (x,z),
        // ĐÁY đặt đúng groundY, cao h (mọc lên theo +Y). roomId (tuỳ chọn): gắn userData.roomId để
        // chế độ "xem riêng 1 phòng" (setFocusRoom() phía dưới) biết object nào thuộc phòng nào mà
        // ẩn/hiện — truyền `null` cho các khối DÙNG CHUNG/nối giữa nhiều phòng (hành lang, cầu thang,
        // chấm nối...) để chúng luôn bị ẩn khi đang xem riêng 1 phòng bất kỳ; KHÔNG truyền gì (bỏ
        // qua tham số) cho các khối không liên quan tới phòng nào cả (ánh sáng, control...).
        function addSimpleBox(w, h, d, x, groundY, z, colorHex, opts, roomId){
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), materialFor(colorHex, opts));
            mesh.position.set(x, groundY + h / 2, z);
            mesh.castShadow = true;
            mesh.receiveShadow = true;
            if (roomId !== undefined) mesh.userData.roomId = roomId;
            scene.add(mesh);
            return mesh;
        }

        // 1 PHÒNG/BUỒNG có 4 tường + sàn (+mái nếu hasRoof) — hasRoof=false (dùng cho khối Phòng
        // chính) để nhìn XUYÊN từ trên xuống thấy nội thất/buồng phụ bên trong, giống đúng kiểu "cắt
        // mái nhìn từ trên" (dollhouse view) của Matterport — KHÁC BoxGeometry đặc (không khoét được),
        // nên phải tự ghép rời sàn + 4 tường + mái tuỳ chọn.
        function addRoom3D(gx, gz, w, d, h, floorColor, wallColor, hasRoof, groundY, roomId){
            const WALL_T = 4, FLOOR_T = 3;
            addSimpleBox(w, FLOOR_T, d, gx, groundY, gz, floorColor, null, roomId);
            // Gom 4 tường theo ĐÚNG thứ tự sau/trước/trái/phải.
            const wallMeshes = [
                addSimpleBox(w, h, WALL_T, gx, groundY, gz - d / 2, wallColor, null, roomId), // sau (-Z)
                addSimpleBox(w, h, WALL_T, gx, groundY, gz + d / 2, wallColor, null, roomId), // trước (+Z)
                addSimpleBox(WALL_T, h, d, gx - w / 2, groundY, gz, wallColor, null, roomId), // trái (-X)
                addSimpleBox(WALL_T, h, d, gx + w / 2, groundY, gz, wallColor, null, roomId), // phải (+X)
            ];
            if (hasRoof) {
                addSimpleBox(w, FLOOR_T, d, gx, groundY + h - FLOOR_T, gz, floorColor, null, roomId);
            }
            return { wallMeshes };
        }

        // ==================================================================================
        // ---- Cửa sổ trang trí gắn trên tường xa (đối diện cửa ra vào) ----
        // Trước đây thử cắt ảnh 360° thật dán lên tường (cắt 4 dải theo góc, lấy dải giữa để tránh méo
        // 2 cực) nhưng nhìn giống "dán ảnh ngoài vào" chứ không tự nhiên — người dùng phản hồi muốn
        // đẹp/chi tiết hơn theo đúng phong cách dựng hình (màu sắc + khối), không phải ảnh chụp cắt
        // dán. Bỏ hẳn kỹ thuật đó, thay bằng 1 khung cửa sổ kính (khối mờ + viền) gắn nổi trên mặt
        // tường — chi tiết kiến trúc đơn giản nhưng làm tường bớt trống trải, đúng tinh thần "dựng
        // hình" nhất quán với toàn bộ sơ đồ thay vì trộn lẫn ảnh thật.
        // ==================================================================================
        function addWindow3D(wallCenterX, wallCenterZ, groundY, roomH, w, faceAxis, roomId){
            const winW = Math.min(w * 0.42, 34), winH = roomH * 0.42;
            const winY = groundY + roomH * 0.52;
            const frameT = 3;
            const depth = faceAxis === 'x' ? [winW, frameT] : [frameT, winW];

            addSimpleBox(depth[0], winH, depth[1], wallCenterX, winY - winH / 2, wallCenterZ, 0x7dd3fc, { transparent: true, opacity: 0.55, roughness: 0.15, metalness: 0.2, emissive: 0x38bdf8, emissiveIntensity: 0.12 }, roomId);
            const frameOpts = { roughness: 0.5 };
            const fw = faceAxis === 'x' ? winW + 4 : frameT + 2;
            const fd = faceAxis === 'x' ? frameT + 2 : winW + 4;
            addSimpleBox(fw, 3, fd, wallCenterX, winY + winH / 2, wallCenterZ, 0xffffff, frameOpts, roomId);
            addSimpleBox(fw, 3, fd, wallCenterX, winY - winH / 2, wallCenterZ, 0xffffff, frameOpts, roomId);
        }

        // Nhãn chữ nổi (tên phòng, "WC", "Cầu thang"...) — Three.js không có text dựng sẵn nhẹ, dùng
        // kỹ thuật chuẩn: vẽ chữ lên <canvas> 2D rồi dùng làm texture cho 1 Sprite (luôn quay mặt về
        // camera tự động — đúng ý 1 nhãn nổi lơ lửng, không cần tự tính góc xoay).
        // Nhãn CHỈ hiện khi camera đủ gần (maxDist) — phản hồi: hiện HẾT mọi nhãn cùng lúc (tên phòng
        // + WC + Bếp + Cầu thang...) khi nhìn tổng thể toà nhà làm chồng chéo, rối mắt hoàn toàn (ảnh
        // chụp thật gửi kèm). Mọi sprite nhãn được gom vào labelSprites để vòng lặp render (animate())
        // tự bật/tắt .visible mỗi khung hình theo khoảng cách camera->nhãn — không cần đụng gì tới vị
        // trí/dữ liệu nhãn đã tính, chỉ thêm 1 lớp ẩn/hiện theo zoom.
        const labelSprites = [];
        function addLabel3D(x, topY, z, text, maxDist, roomId){
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const fontSize = 28;
            ctx.font = `700 ${fontSize}px Inter, sans-serif`;
            const textW = ctx.measureText(text).width;
            canvas.width = textW + 24;
            canvas.height = fontSize + 16;
            ctx.font = `700 ${fontSize}px Inter, sans-serif`;
            ctx.fillStyle = 'rgba(255,255,255,0.88)';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#111827';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, 12, canvas.height / 2);

            const texture = new THREE.CanvasTexture(canvas);
            const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, depthTest: false }));
            const scaleFactor = 0.45;
            sprite.scale.set(canvas.width * scaleFactor, canvas.height * scaleFactor, 1);
            sprite.position.set(x, topY, z);
            sprite.renderOrder = 999;
            sprite.userData.maxDist = maxDist ?? 900;
            if (roomId !== undefined) sprite.userData.roomId = roomId;
            scene.add(sprite);
            labelSprites.push(sprite);
            return sprite;
        }

        // Chấm bấm mở tour 360° — Sprite hình tròn (vẽ tròn lên canvas) kèm userData.tourUrl để
        // raycaster xử lý khi bấm (xem phần "Bấm để xem 360°/di chuyển" phía dưới).
        const colorMap = { amber: '#f59e0b', teal: '#0d9488', pink: '#db2777', sky: '#0284c7' };
        function addDot3D(x, y, z, colorKey, label, sceneId, roomId){
            const canvas = document.createElement('canvas');
            canvas.width = 64; canvas.height = 64;
            const ctx = canvas.getContext('2d');
            ctx.beginPath();
            ctx.arc(32, 32, 26, 0, Math.PI * 2);
            ctx.fillStyle = colorMap[colorKey] || colorKey || '#0284c7';
            ctx.fill();
            ctx.lineWidth = 6;
            ctx.strokeStyle = '#ffffff';
            ctx.stroke();

            const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: new THREE.CanvasTexture(canvas), depthTest: false }));
            sprite.scale.set(16, 16, 1);
            sprite.position.set(x, y, z);
            sprite.renderOrder = 999;
            sprite.userData.tourUrl = TOUR_BASE + '/' + sceneId;
            sprite.userData.tooltip = label;
            if (roomId !== undefined) sprite.userData.roomId = roomId;
            scene.add(sprite);
            return sprite;
        }

        // ==================================================================================
        // "Loại không gian con" — GIỮ NGUYÊN Y HỆT bản CSS trước (kind -> category/màu/nhãn), chỉ đổi
        // MÀU sang số hex (0xrrggbb) cho khớp kiểu Three.js material thay vì chuỗi CSS "#rrggbb".
        // ==================================================================================
        const KIND_META = {
            wc:          { category: 'inside',   fill: 0xfbcfe8, color: 0xdb2777, colorCss: '#db2777', label: 'WC' },
            bedroom:     { category: 'inside',   fill: 0xe0e7ff, color: 0x4f46e5, colorCss: '#4f46e5', label: 'Phòng ngủ' },
            kitchen:     { category: 'inside',   fill: 0xfed7aa, color: 0xea580c, colorCss: '#ea580c', label: 'Bếp' },
            storage:     { category: 'inside',   fill: 0xe7e5e4, color: 0x57534e, colorCss: '#57534e', label: 'Kho' },
            mezzanine:   { category: 'elevated', fill: 0xfde68a, color: 0xb45309, colorCss: '#b45309', label: 'Gác lửng' },
            balcony:     { category: 'attached', fill: 0xbae6fd, color: 0x0284c7, colorCss: '#0284c7', label: 'Ban công' },
            drying_yard: { category: 'attached', fill: 0x99f6e4, color: 0x0d9488, colorCss: '#0d9488', label: 'Sân phơi' },
            other:       { category: 'dot',      fill: null,     color: 0x0284c7, colorCss: '#0284c7', label: null },
        };
        function metaFor(kind){ return KIND_META[kind] || KIND_META.other; }

        // Cầu thang thật dạng bậc thang xiên — GIỮ NGUYÊN logic bản CSS, chỉ đổi Y-up (cộng thay vì trừ).
        function drawStaircase3D(gx, zStart, zEnd, baseGroundY, totalRise, stepCount, stepWidth, roomId){
            const stepRun = (zEnd - zStart) / stepCount;
            const stepRise = totalRise / stepCount;
            const stepThickness = Math.max(4, totalRise / stepCount * 0.4);
            for (let i = 0; i < stepCount; i++){
                const stepZ = zStart + stepRun * (i + 0.5);
                const stepTopY = baseGroundY + stepRise * (i + 1);
                addSimpleBox(stepWidth, stepThickness, Math.abs(stepRun) + 2, gx, stepTopY - stepThickness, stepZ, 0xa8a29e, null, roomId);
            }
        }

        // Nội thất minh hoạ — mỗi món ghép từ nhiều khối nhỏ thành 1 hình dạng dễ nhận ra hơn (thêm
        // gối/chăn cho giường, nắp/bồn cho WC, mặt bếp+máy hút mùi cho bếp...) — vẫn chỉ là khối minh
        // hoạ (không phải nội thất đo đạc thật), nhưng chi tiết hơn hẳn khối đặc đơn sắc trước đây.
        function drawFurnitureHint3D(kind, cx, cz, groundY, boxW, boxD, roomId){
            if (kind === 'wc') {
                // Bồn cầu: đế + nắp bệt 2 tầng.
                addSimpleBox(Math.min(16, boxW * 0.4), 12, Math.min(14, boxD * 0.35), cx - boxW * 0.18, groundY, cz + boxD * 0.18, 0xffffff, { roughness: 0.25 }, roomId);
                addSimpleBox(Math.min(13, boxW * 0.32), 6, Math.min(11, boxD * 0.28), cx - boxW * 0.18, groundY + 12, cz + boxD * 0.16, 0xf1f5f9, { roughness: 0.2 }, roomId);
                // Bồn rửa: mặt bồn + chân đỡ.
                addSimpleBox(Math.min(14, boxW * 0.3), 4, Math.min(10, boxD * 0.25), cx + boxW * 0.22, groundY + 20, cz - boxD * 0.22, 0xffffff, { roughness: 0.2 }, roomId);
                addSimpleBox(4, 20, 4, cx + boxW * 0.22, groundY, cz - boxD * 0.22, 0xd4d4d8, {}, roomId);
            } else if (kind === 'bedroom') {
                const bedW = boxW * 0.8, bedD = boxD * 0.75;
                addSimpleBox(bedW, 16, bedD, cx, groundY, cz, 0x93c5fd, {}, roomId); // nệm
                addSimpleBox(bedW, 4, bedD * 0.55, cx, groundY + 16, cz + bedD * 0.15, 0xfef3c7, { roughness: 0.6 }, roomId); // chăn kéo nửa dưới
                addSimpleBox(bedW * 0.85, 20, bedD * 0.22, cx, groundY + 16, cz - bedD * 0.32, 0xeff6ff, {}, roomId); // gối/đầu giường
                addSimpleBox(bedW * 0.22, 8, bedD * 0.18, cx - bedW * 0.3, groundY + 20, cz - bedD * 0.3, 0xffffff, {}, roomId); // gối nhỏ
            } else if (kind === 'kitchen') {
                addSimpleBox(boxW * 0.85, 26, boxD * 0.35, cx - boxW * 0.05, groundY, cz - boxD * 0.25, 0x78716c, {}, roomId); // mặt bếp
                addSimpleBox(boxW * 0.5, 3, boxD * 0.32, cx - boxW * 0.1, groundY + 26, cz - boxD * 0.25, 0x1c1917, { roughness: 0.3, metalness: 0.3 }, roomId); // bếp từ
                addSimpleBox(Math.min(18, boxW * 0.35), 34, Math.min(16, boxD * 0.35), cx + boxW * 0.28, groundY, cz + boxD * 0.22, 0xd4d4d8, { metalness: 0.4, roughness: 0.4 }, roomId); // tủ lạnh
                addSimpleBox(boxW * 0.4, 3, 10, cx - boxW * 0.05, groundY + 50, cz - boxD * 0.25, 0xa8a29e, { metalness: 0.5 }, roomId); // máy hút mùi
            } else if (kind === 'storage') {
                addSimpleBox(boxW * 0.85, 36, boxD * 0.6, cx, groundY, cz, 0xa16207, {}, roomId);
                addSimpleBox(2, 36, boxD * 0.6, cx, groundY, cz, 0x78350f, {}, roomId); // đường ghép cánh tủ
            }
        }

        // Góc sinh hoạt (sofa/bàn trà/kệ TV) ngay trên sàn phòng — thêm gối tựa + chân bàn cho chi
        // tiết hơn bản trước (chỉ 4 khối đặc).
        function drawLivingArea3D(gx, gz, r, groundY, insideSide, roomId){
            const farX = insideSide === 'left' ? gx + r.w * 0.22 : gx - r.w * 0.22;
            const sofaW = 44, sofaD = 22;
            addSimpleBox(sofaW, 14, sofaD, farX, groundY, gz, 0x44403c, {}, roomId);
            addSimpleBox(sofaW, 24, sofaD * 0.35, farX, groundY + 14, gz - sofaD * 0.32, 0x57534e, {}, roomId);
            addSimpleBox(10, 8, 8, farX - sofaW * 0.3, groundY + 14, gz + sofaD * 0.28, 0xc4b5fd, {}, roomId); // gối tựa
            addSimpleBox(10, 8, 8, farX + sofaW * 0.3, groundY + 14, gz + sofaD * 0.28, 0xfca5a5, {}, roomId); // gối tựa
            addSimpleBox(20, 8, 14, farX, groundY, gz + sofaD * 0.9, 0x78350f, {}, roomId); // mặt bàn trà
            addSimpleBox(2, 8, 2, farX - 7, groundY, gz + sofaD * 0.9 - 5, 0x44403c, {}, roomId); // chân bàn
            addSimpleBox(2, 8, 2, farX + 7, groundY, gz + sofaD * 0.9 + 5, 0x44403c, {}, roomId); // chân bàn
            const tvX = insideSide === 'left' ? gx - r.w * 0.3 : gx + r.w * 0.3;
            addSimpleBox(10, 18, 30, tvX, groundY, gz, 0x1c1917, { metalness: 0.3, roughness: 0.4 }, roomId);
            addSimpleBox(4, 4, 20, tvX, groundY - 2, gz, 0x57534e, {}, roomId); // chân kệ TV
        }

        // Cửa hé mở thật — dùng THREE.Group làm bản lề (pivot), xoay CẢ NHÓM quanh trục Y thật, đúng
        // vật lý 1 cánh cửa xoay quanh bản lề — chuẩn xác hơn hẳn cách "transform-origin" mô phỏng của
        // CSS trước đây.
        function addDoor3D(wallCenterX, wallCenterZ, groundY, doorW, doorH, facingSign, hingeSide, roomId){
            const hingeX = wallCenterX + hingeSide * (doorW / 2);
            const pivot = new THREE.Group();
            pivot.position.set(hingeX, groundY + doorH / 2, wallCenterZ);
            const openDeg = -facingSign * hingeSide * 55;
            pivot.rotation.y = THREE.MathUtils.degToRad(openDeg);

            const leaf = new THREE.Mesh(
                new THREE.BoxGeometry(doorW, doorH, 3),
                materialFor(0x92400e, { roughness: 0.6 })
            );
            leaf.position.x = hingeSide < 0 ? doorW / 2 : -doorW / 2;
            leaf.castShadow = true;
            leaf.receiveShadow = true;
            pivot.add(leaf);
            if (roomId !== undefined) pivot.userData.roomId = roomId;
            scene.add(pivot);
        }

        // ---- 'inside': WC/Phòng ngủ/Bếp/Kho — GIỮ NGUYÊN logic bố trí bản CSS ----
        function drawInsideCluster(list, parent, pr, px, pz, groundY, insideSide){
            if (! list.length) return;

            const MARGIN = 6, MAX_BOX_W = 40;
            const n = list.length;
            const boxD = Math.min(34, pr.d / 3);
            const boxH = 40;
            const nearSign = parent.y < 0 ? 1 : -1;
            const edgeZ = pz + nearSign * (pr.d / 2 - boxD / 2 - MARGIN);

            const usableW = pr.w - DOOR_RESERVE;
            const usableCenterX = insideSide === 'left' ? (px - DOOR_RESERVE / 2) : (px + DOOR_RESERVE / 2);
            const idealTotalW = n * MAX_BOX_W + (n - 1) * MARGIN;
            const boxW = idealTotalW > usableW ? Math.max(20, (usableW - MARGIN * (n - 1)) / n) : MAX_BOX_W;
            const totalW = n * boxW + (n - 1) * MARGIN;
            const startX = usableCenterX - totalW / 2 + boxW / 2;

            list.forEach((s, i) => {
                const meta = metaFor(s.kind);
                const cellX = startX + i * (boxW + MARGIN);

                const mesh = addSimpleBox(boxW, boxH, boxD, cellX, groundY, edgeZ, meta.fill, null, parent.id);
                mesh.userData.tourUrl = TOUR_BASE + '/' + s.id;
                mesh.userData.tooltip = s.label;
                addLabel3D(cellX, groundY + boxH + 8, edgeZ, meta.label || s.label, SATELLITE_LABEL_DIST, parent.id);
                drawFurnitureHint3D(s.kind, cellX, edgeZ, groundY + boxH, boxW, boxD, parent.id);

                if (s.kind === 'wc' || s.kind === 'bedroom') {
                    const doorWallZ = edgeZ - nearSign * (boxD / 2);
                    addDoor3D(cellX, doorWallZ, groundY, Math.min(20, boxW * 0.55), Math.min(boxH - 4, 34), -nearSign, -1, parent.id);
                }
            });
        }

        // ---- 'elevated': Gác lửng (sàn nâng + lan can + cầu thang) ----
        function drawElevatedCluster(list, parent, pr, px, pz, groundY){
            if (! list.length) return;
            const nearSign = parent.y < 0 ? 1 : -1;
            const farSign = -nearSign;

            list.forEach(s => {
                const meta = metaFor(s.kind);
                const platH = 10;
                const platD = pr.d * 0.55;
                const platW = pr.w * 0.92;
                const rise = ROOM_H * 0.5;
                const platGroundY = groundY + rise;
                const cz = pz + farSign * (pr.d / 2 - platD / 2 - 2);

                const platMesh = addSimpleBox(platW, platH, platD, px, platGroundY, cz, meta.fill, null, parent.id);
                platMesh.userData.tourUrl = TOUR_BASE + '/' + s.id;
                platMesh.userData.tooltip = s.label;

                const railH = 26;
                const railZ = cz - farSign * (platD / 2);
                addSimpleBox(platW, railH, 2, px, platGroundY + platH, railZ, 0x78716c, { transparent: true, opacity: 0.65 }, parent.id);

                addLabel3D(px, platGroundY + platH + railH + 10, cz, meta.label || s.label, SATELLITE_LABEL_DIST, parent.id);
                drawFurnitureHint3D('bedroom', px, cz, platGroundY + platH, platW * 0.7, platD * 0.7, parent.id);

                const stairZStart = pz + nearSign * (pr.d / 2 - 12);
                drawStaircase3D(px, stairZStart, railZ, groundY, rise, 8, Math.min(40, platW * 0.4), parent.id);
            });
        }

        // ---- 'attached': Ban công/Sân phơi ----
        function drawAttachedCluster(list, parent, pr, px, pz, groundY){
            if (! list.length) return;
            const n = list.length;
            const outwardSign = parent.y < 0 ? -1 : 1;
            const platH = 10, platD = 26;
            const cellW = pr.w / n;

            list.forEach((s, i) => {
                const meta = metaFor(s.kind);
                const cx = px - pr.w / 2 + cellW * i + cellW / 2;
                const cz = pz + outwardSign * (pr.d / 2 + platD / 2);
                const boxW = Math.max(30, cellW * 0.85);

                const mesh = addSimpleBox(boxW, platH, platD, cx, groundY, cz, meta.fill, null, parent.id);
                mesh.userData.tourUrl = TOUR_BASE + '/' + s.id;
                mesh.userData.tooltip = s.label;
                addLabel3D(cx, groundY + platH + 8, cz, meta.label || s.label, SATELLITE_LABEL_DIST, parent.id);
            });
        }

        // Gom vệ tinh theo phòng cha + suy phía WC/bếp/phòng ngủ thật từ góc yaw hotspot — GIỮ NGUYÊN
        // Y HỆT bản CSS (xem PanoramaFloorPlanLayoutService::build() -> 'side').
        const satellitesByParent = {};
        layout.satellites.forEach(s => {
            (satellitesByParent[s.parentId] = satellitesByParent[s.parentId] || []).push(s);
        });
        const roomInsideSide = {};
        Object.keys(satellitesByParent).forEach(parentId => {
            const insideList = satellitesByParent[parentId].filter(s => metaFor(s.kind).category === 'inside');
            const withSide = insideList.filter(s => s.side);
            const wcWithSide = withSide.find(s => s.kind === 'wc');
            roomInsideSide[parentId] = (wcWithSide || withSide[0])?.side || 'right';
        });

        // ---- Vẽ khối chính (hành lang + phòng) ----
        // roomCenters: lưu lại tâm/kích thước từng PHÒNG (không tính hành lang) để setFocusRoom() phía
        // dưới biết đặt camera vào đúng vị trí khi bấm "vào xem riêng phòng này".
        const roomMeshes = [];
        const roomCenters = {};
        layout.nodes.forEach(n => {
            const r = rectFor(n);
            const gx = n.x * SCALE, gz = n.y * SCALE;
            const groundY = groundYFor(n);
            const floorColor = n.isCommon ? 0xd6d3d1 : 0xfde68a;
            const wallColor = n.isCommon ? 0xa8a29e : 0xeab308;
            // Hành lang dùng roomId=null (luôn ẩn khi đang xem riêng 1 phòng bất kỳ); phòng dùng đúng
            // n.id để setFocusRoom() giữ lại được khi bấm vào chính phòng đó.
            addRoom3D(gx, gz, r.w, r.d, r.h, floorColor, wallColor, n.isCommon, groundY, n.isCommon ? null : n.id);

            // Lớp phủ TRONG SUỐT đúng diện tích sàn — vùng bấm (raycaster xử lý ở phần sự kiện click
            // bên dưới): bấm 1 phòng = vào xem RIÊNG sơ đồ chi tiết phòng đó (setFocusRoom()), không
            // phải chỉ lia camera lại gần trong lúc vẫn thấy cả toà nhà xung quanh.
            const clickTarget = new THREE.Mesh(
                new THREE.PlaneGeometry(r.w, r.d),
                new THREE.MeshBasicMaterial({ visible: false })
            );
            clickTarget.rotation.x = -Math.PI / 2;
            clickTarget.position.set(gx, groundY + 4, gz);
            if (! n.isCommon) {
                clickTarget.userData.enterRoom = n.id;
                clickTarget.userData.roomId = n.id;
            }
            scene.add(clickTarget);
            roomMeshes.push(clickTarget);

            if (! n.isCommon) {
                const insideSide = roomInsideSide[n.id] || 'right';
                drawLivingArea3D(gx, gz, r, groundY, insideSide, n.id);

                const doorNearSign = n.y < 0 ? 1 : -1;
                const doorWallZ = gz + doorNearSign * (r.d / 2);
                const doorSide = insideSide === 'left' ? 'right' : 'left';
                const doorX = doorSide === 'left' ? (gx - r.w / 2 + DOOR_RESERVE / 2) : (gx + r.w / 2 - DOOR_RESERVE / 2);
                const hingeSide = doorSide === 'left' ? -1 : 1;
                addDoor3D(doorX, doorWallZ, groundY, 24, 48, doorNearSign, hingeSide, n.id);

                // Cửa sổ trang trí trên tường XA (đối diện cửa ra vào) — xem addWindow3D().
                addWindow3D(gx, gz - doorNearSign * (r.d / 2), groundY, r.h, r.w, 'x', n.id);

                roomCenters[n.id] = { x: gx, y: groundY, z: gz, w: r.w, d: r.d, h: r.h };
            }

            const roomLabel = (! n.isCommon && n.area) ? `${n.label} · ${n.area} m²` : n.label;
            addLabel3D(gx, groundY + r.h + 14, gz, roomLabel, ROOM_LABEL_DIST, n.isCommon ? null : n.id);
        });

        // ---- Cạnh nối (hành lang<->phòng cùng tầng, cầu thang khác tầng) — luôn thuộc "chung", ẩn khi
        // đang xem riêng 1 phòng (roomId=null, xem setFocusRoom()) ----
        layout.edges.forEach(e => {
            const a = nodeById[e.fromId], b = nodeById[e.toId];
            if (! a || ! b) return;

            if (a.floor !== b.floor) {
                const lo = a.floor < b.floor ? a : b;
                const hi = a.floor < b.floor ? b : a;
                const SHAFT_W = 40;
                const roomsOnFloor = layout.nodes.filter(n => ! n.isCommon && n.floor === lo.floor);
                const firstRoomX = roomsOnFloor.length ? Math.min(...roomsOnFloor.map(n => n.x)) * SCALE : lo.x * SCALE;
                const firstRoomRect = roomsOnFloor.length ? rectFor(roomsOnFloor[0]) : rectFor(lo);
                const midX = firstRoomX - (firstRoomRect.w / 2 + SHAFT_W / 2 + 10);
                const midZ = lo.y * SCALE;
                const shaftGroundY = groundYFor(lo);
                const shaftHeight = groundYFor(hi) - groundYFor(lo);

                const shaftMesh = addSimpleBox(SHAFT_W, shaftHeight, SHAFT_W, midX, shaftGroundY, midZ, 0xc4b5fd, null, null);
                shaftMesh.userData.tourUrl = TOUR_BASE + '/' + b.id;
                shaftMesh.userData.tooltip = e.label || ('Đến ' + b.label);
                addLabel3D(midX, shaftGroundY + shaftHeight + 10, midZ, 'Cầu thang', STAIR_LABEL_DIST, null);
                return;
            }

            const groundY = groundYFor(a);
            const midX = (a.x + b.x) / 2 * SCALE, midZ = (a.y + b.y) / 2 * SCALE;
            const midH = Math.max(rectFor(a).h, rectFor(b).h) * 0.5;
            addDot3D(midX, groundY + midH, midZ, e.kind === 'corridor' ? 'amber' : 'teal', e.label || ('Đến ' + b.label), b.id, null);
        });

        // ---- Không gian con (WC/Phòng ngủ/Bếp/Kho/Gác lửng/Ban công/Sân phơi...) ----
        Object.keys(satellitesByParent).forEach(parentId => {
            const parent = nodeById[parentId];
            if (! parent) return;
            const list = satellitesByParent[parentId];
            const groundY = groundYFor(parent);
            const pr = rectFor(parent);
            const px = parent.x * SCALE, pz = parent.y * SCALE;
            const byCategory = (cat) => list.filter(s => metaFor(s.kind).category === cat);

            drawInsideCluster(byCategory('inside'), parent, pr, px, pz, groundY, roomInsideSide[parentId]);
            drawElevatedCluster(byCategory('elevated'), parent, pr, px, pz, groundY);
            drawAttachedCluster(byCategory('attached'), parent, pr, px, pz, groundY);
            byCategory('dot').forEach(s => addDot3D(s.x * SCALE, groundY + 34, s.y * SCALE, metaFor(s.kind).color, s.label, s.id, parent.id));
        });

        // Chú thích ĐỘNG theo đúng loại không gian con thực có trong toà nhà — GIỮ NGUYÊN ý tưởng
        // bản CSS.
        const usedKinds = new Set(layout.satellites.map(s => s.kind));
        const legendEl = document.querySelector('.legend');
        usedKinds.forEach(kind => {
            const meta = metaFor(kind);
            if (! meta.label || ! legendEl) return;
            const item = document.createElement('span');
            item.innerHTML = `<i style="background:${meta.colorCss}"></i>${meta.label}`;
            legendEl.appendChild(item);
        });

        // ==================================================================================
        // ---- Bấm để mở tour 360° (WC/bếp/phòng ngủ/gác lửng/ban công/chấm nối) HOẶC vào xem RIÊNG
        // sơ đồ chi tiết 1 phòng (setFocusRoom()) — raycaster tìm đối tượng bị bấm. Phân biệt BẤM THẬT
        // với KÉO CHUỘT (OrbitControls) bằng ngưỡng di chuyển nhỏ giữa pointerdown/pointerup, tránh
        // mở nhầm khi người dùng chỉ đang xoay/di chuyển camera.
        // ==================================================================================
        const raycaster = new THREE.Raycaster();
        const pointerNdc = new THREE.Vector2();
        let downX = 0, downY = 0;

        function setPointerFromEvent(e){
            const rect = renderer.domElement.getBoundingClientRect();
            pointerNdc.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
            pointerNdc.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
        }

        renderer.domElement.addEventListener('pointerdown', e => { downX = e.clientX; downY = e.clientY; });
        renderer.domElement.addEventListener('pointerup', e => {
            if (Math.hypot(e.clientX - downX, e.clientY - downY) > 6) return; // đang kéo, không phải bấm

            setPointerFromEvent(e);
            raycaster.setFromCamera(pointerNdc, camera);
            const hits = raycaster.intersectObjects(scene.children, false);
            const hit = hits.find(h => h.object.visible && h.object.userData && (h.object.userData.tourUrl || h.object.userData.enterRoom));
            if (! hit) return;

            const obj = hit.object;
            if (obj.userData.enterRoom) {
                setFocusRoom(obj.userData.enterRoom);
            } else if (obj.userData.tourUrl) {
                window.open(obj.userData.tourUrl, '_blank');
            }
        });

        // ==================================================================================
        // ---- Chế độ "xem riêng 1 phòng": ẩn hết phần còn lại của toà nhà (hành lang/phòng khác/cầu
        // thang), chỉ giữ lại đúng khối phòng đã bấm + nội thất/buồng phụ của nó, đồng thời lia camera
        // vào gần, đúng ý "vào xem sơ đồ từng phòng luôn, chứ không phải xem sơ đồ toàn bộ toà" — MỌI
        // object 3D thuộc về 1 phòng đều đã được gắn userData.roomId khi tạo (xem addSimpleBox()/
        // addLabel3D()/addDot3D()/addDoor3D() ở trên); object dùng chung (hành lang/cầu thang/chấm
        // nối) được gắn roomId=null nên luôn bị ẩn ở đây.
        // ==================================================================================
        let focusedRoomId = null;
        const focusBar = document.getElementById('room-focus-bar');
        const focusBarTitle = document.getElementById('room-focus-title');
        const focusTourBtn = document.getElementById('room-focus-tour');

        function setFocusRoom(roomId){
            const center = roomCenters[roomId];
            if (! center) return;
            focusedRoomId = roomId;

            scene.traverse(obj => {
                if (! obj.userData || ! ('roomId' in obj.userData)) return;
                obj.visible = obj.userData.roomId === roomId;
            });

            const node = nodeById[roomId];
            focusBarTitle.textContent = node ? ((node.area ? `${node.label} · ${node.area} m²` : node.label)) : '';
            focusTourBtn.onclick = () => window.open(TOUR_BASE + '/' + roomId, '_blank');
            focusBar.hidden = false;

            flyIntoRoom(center);
        }

        function exitFocusRoom(){
            focusedRoomId = null;
            scene.traverse(obj => {
                if (! obj.userData || ! ('roomId' in obj.userData)) return;
                obj.visible = true;
            });
            focusBar.hidden = true;
            controls.target.copy(DEFAULT_TARGET);
            camera.position.copy(DEFAULT_CAMERA_POS);
            controls.update();
        }

        // Đặt camera ở góc 3/4 cận cảnh, đúng khung riêng của phòng — đủ gần để thấy rõ nội thất/cửa
        // sổ vừa vẽ, không còn phòng/hành lang khác chen vào tầm nhìn.
        function flyIntoRoom(center){
            const target = new THREE.Vector3(center.x, center.y + center.h * 0.35, center.z);
            const dist = Math.max(center.w, center.d) * 1.15;
            camera.position.set(center.x + dist * 0.62, center.y + center.h * 1.7, center.z + dist * 0.78);
            controls.target.copy(target);
            controls.update();
        }

        document.getElementById('room-focus-back').addEventListener('click', exitFocusRoom);

        // ==================================================================================
        // ---- Nút điều khiển ----
        // ==================================================================================
        function setView(polarDeg, azimuthDeg, distance){
            const polar = THREE.MathUtils.degToRad(polarDeg);
            const azimuth = THREE.MathUtils.degToRad(azimuthDeg);
            const dist = distance ?? camera.position.distanceTo(controls.target);
            const offset = new THREE.Vector3().setFromSphericalCoords(dist, polar, azimuth);
            camera.position.copy(controls.target).add(offset);
            controls.update();
        }

        document.getElementById('btn-reset').addEventListener('click', () => {
            if (focusedRoomId) { exitFocusRoom(); return; }
            controls.target.copy(DEFAULT_TARGET);
            camera.position.copy(DEFAULT_CAMERA_POS);
            controls.update();
        });
        document.getElementById('btn-topdown').addEventListener('click', () => setView(2, 0));
        document.getElementById('btn-side').addEventListener('click', () => setView(80, 0));
        document.getElementById('btn-zoom-in').addEventListener('click', () => {
            const offset = camera.position.clone().sub(controls.target).multiplyScalar(0.8);
            camera.position.copy(controls.target).add(offset);
            controls.update();
        });
        document.getElementById('btn-zoom-out').addEventListener('click', () => {
            const offset = camera.position.clone().sub(controls.target).multiplyScalar(1.25);
            camera.position.copy(controls.target).add(offset);
            controls.update();
        });

        const fsBtn = document.getElementById('btn-fullscreen');
        fsBtn.addEventListener('click', () => {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (stage.requestFullscreen) {
                stage.requestFullscreen();
            }
        });
        document.addEventListener('fullscreenchange', () => {
            fsBtn.textContent = document.fullscreenElement ? 'Thoát toàn màn hình' : 'Toàn màn hình';
            // Fullscreen đổi kích thước khung ngay lập tức nhưng canvas WebGL cần resize thủ công.
            requestAnimationFrame(resizeRenderer);
        });
        window.addEventListener('resize', resizeRenderer);

        // ---- Vòng lặp render ----
        function updateLabelVisibility(){
            labelSprites.forEach(sprite => {
                sprite.visible = camera.position.distanceTo(sprite.position) <= sprite.userData.maxDist;
            });
        }

        function animate(){
            requestAnimationFrame(animate);
            controls.update();
            updateLabelVisibility();
            renderer.render(scene, camera);
        }
        animate();
    } catch (err) {
        stage.innerHTML = '<div class="empty">Không dựng được sơ đồ 3D (' + (err && err.message ? err.message : 'lỗi không rõ') + ').</div>';
        // eslint-disable-next-line no-console
        console.error(err);
    }
})();
</script>
</body>
</html>
