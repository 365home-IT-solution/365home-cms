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
        <span class="hint">Chuột trái/1 ngón: xoay · Chuột phải/2 ngón: di chuyển · Cuộn/chụm 2 ngón: phóng to · Bấm 1 phòng để tới gần · Bấm đúp để mở tour 360° thật</span>
    </div>

    <div class="stage" id="stage">
        <div class="zoom-controls">
            <button type="button" id="btn-zoom-in" title="Phóng to">+</button>
            <button type="button" id="btn-zoom-out" title="Thu nhỏ">−</button>
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
        <b>Sơ đồ TỰ SINH từ đúng dữ liệu 360° hiện có</b> — vị trí từng phòng lấy từ toạ độ lưới thật (vị trí/thứ tự phòng đã khai báo), không phải suy từ ảnh chụp. Tường phòng được phủ ẢNH 360° THẬT của chính phòng đó (cắt từ ảnh chụp thật, không phải màu vẽ) nhưng do ảnh chụp không đo góc chính xác từng bức tường nên chỉ là XẤP XỈ — bấm đúp vào 1 phòng để xem đúng, đầy đủ bằng tour 360° thật. Không phải mô hình quét 3D có chiều sâu như Matterport — kích thước khối và nội thất mang tính minh hoạ.
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
        // ĐÁY đặt đúng groundY, cao h (mọc lên theo +Y).
        function addSimpleBox(w, h, d, x, groundY, z, colorHex, opts){
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), materialFor(colorHex, opts));
            mesh.position.set(x, groundY + h / 2, z);
            mesh.castShadow = true;
            mesh.receiveShadow = true;
            scene.add(mesh);
            return mesh;
        }

        // 1 PHÒNG/BUỒNG có 4 tường + sàn (+mái nếu hasRoof) — hasRoof=false (dùng cho khối Phòng
        // chính) để nhìn XUYÊN từ trên xuống thấy nội thất/buồng phụ bên trong, giống đúng kiểu "cắt
        // mái nhìn từ trên" (dollhouse view) của Matterport — KHÁC BoxGeometry đặc (không khoét được),
        // nên phải tự ghép rời sàn + 4 tường + mái tuỳ chọn.
        function addRoom3D(gx, gz, w, d, h, floorColor, wallColor, hasRoof, groundY){
            const WALL_T = 4, FLOOR_T = 3;
            addSimpleBox(w, FLOOR_T, d, gx, groundY, gz, floorColor);
            // Gom 4 tường theo ĐÚNG thứ tự sau/trước/trái/phải — applyRoomPhotoTexture() dựa vào thứ
            // tự này để biết mesh nào ứng với dải ảnh nào (xem giải thích ở đó).
            const wallMeshes = [
                addSimpleBox(w, h, WALL_T, gx, groundY, gz - d / 2, wallColor), // sau (-Z)
                addSimpleBox(w, h, WALL_T, gx, groundY, gz + d / 2, wallColor), // trước (+Z)
                addSimpleBox(WALL_T, h, d, gx - w / 2, groundY, gz, wallColor), // trái (-X)
                addSimpleBox(WALL_T, h, d, gx + w / 2, groundY, gz, wallColor), // phải (+X)
            ];
            if (hasRoof) {
                addSimpleBox(w, FLOOR_T, d, gx, groundY + h - FLOOR_T, gz, floorColor);
            }
            return { wallMeshes };
        }

        // ==================================================================================
        // ---- Phủ ẢNH 360° THẬT lên 4 tường phòng (thay màu phẳng) ----
        // Kỹ thuật: cắt ảnh gốc thành 4 dải dọc bằng nhau (mỗi dải ~90° ngang ảnh equirectangular),
        // lấy đúng DẢI GIỮA theo chiều cao (28%-72%) để tránh vùng cực trên/dưới của ảnh 360° (méo rất
        // nặng khi "duỗi phẳng" — nhìn sẽ vỡ hình nếu lấy nguyên ảnh). Đây là XẤP XỈ, KHÔNG PHẢI hiệu
        // chỉnh chính xác — ảnh chụp từ 1 điểm bất kỳ trong phòng, hệ thống không có dữ liệu đo góc
        // thật giữa ảnh và từng bức tường cụ thể, nên không đảm bảo đúng 100% nội dung của ĐÚNG bức
        // tường đó — nhưng vẫn là ẢNH THẬT của chính phòng này, thực hơn hẳn màu phẳng đơn sắc.
        // ==================================================================================
        function loadImageAsync(url){
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = reject;
                img.src = url;
            });
        }

        function cropWallTexture(img, quadrantIndex){
            const srcW = img.naturalWidth, srcH = img.naturalHeight;
            const bandW = srcW / 4;
            const srcX = quadrantIndex * bandW;
            const srcY = srcH * 0.28, srcH2 = srcH * 0.44;

            const canvas = document.createElement('canvas');
            canvas.width = 512; canvas.height = 256;
            canvas.getContext('2d').drawImage(img, srcX, srcY, bandW, srcH2, 0, 0, canvas.width, canvas.height);
            return new THREE.CanvasTexture(canvas);
        }

        // Tải ảnh 360° thật của phòng (n.thumbnail — PHP đã trả sẵn URL) rồi thay MATERIAL của 4
        // tường đã vẽ (màu phẳng) bằng texture cắt từ đúng ảnh đó — chạy BẤT ĐỒNG BỘ (ảnh tải qua
        // mạng), phòng vẫn hiện màu phẳng bình thường cho tới khi tải xong, KHÔNG chặn hiển thị sơ đồ.
        // Ảnh lỗi/không tải được -> giữ nguyên màu phẳng, không làm hỏng cả sơ đồ.
        async function applyRoomPhotoTexture(thumbnailUrl, wallMeshes){
            try {
                const img = await loadImageAsync(thumbnailUrl);
                wallMeshes.forEach((mesh, i) => {
                    mesh.material = new THREE.MeshStandardMaterial({ map: cropWallTexture(img, i), roughness: 0.9, metalness: 0.02 });
                });
            } catch (e) {
                // eslint-disable-next-line no-console
                console.warn('Không tải được ảnh 360° để phủ tường:', thumbnailUrl, e);
            }
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
        function addLabel3D(x, topY, z, text, maxDist){
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
            scene.add(sprite);
            labelSprites.push(sprite);
            return sprite;
        }

        // Chấm bấm mở tour 360° — Sprite hình tròn (vẽ tròn lên canvas) kèm userData.tourUrl để
        // raycaster xử lý khi bấm (xem phần "Bấm để xem 360°/di chuyển" phía dưới).
        const colorMap = { amber: '#f59e0b', teal: '#0d9488', pink: '#db2777', sky: '#0284c7' };
        function addDot3D(x, y, z, colorKey, label, sceneId){
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
        function drawStaircase3D(gx, zStart, zEnd, baseGroundY, totalRise, stepCount, stepWidth){
            const stepRun = (zEnd - zStart) / stepCount;
            const stepRise = totalRise / stepCount;
            const stepThickness = Math.max(4, totalRise / stepCount * 0.4);
            for (let i = 0; i < stepCount; i++){
                const stepZ = zStart + stepRun * (i + 0.5);
                const stepTopY = baseGroundY + stepRise * (i + 1);
                addSimpleBox(stepWidth, stepThickness, Math.abs(stepRun) + 2, gx, stepTopY - stepThickness, stepZ, 0xa8a29e);
            }
        }

        // Nội thất minh hoạ — GIỮ NGUYÊN Ý TƯỞNG bản CSS (mỗi món ghép từ 2-3 khối thành 1 hình dạng
        // riêng), chỉ đổi sang addSimpleBox() + có ánh sáng/đổ bóng thật thay vì màu phẳng.
        function drawFurnitureHint3D(kind, cx, cz, groundY, boxW, boxD){
            if (kind === 'wc') {
                addSimpleBox(Math.min(16, boxW * 0.4), 16, Math.min(14, boxD * 0.35), cx - boxW * 0.18, groundY, cz + boxD * 0.18, 0xffffff);
                addSimpleBox(Math.min(14, boxW * 0.3), 10, Math.min(10, boxD * 0.25), cx + boxW * 0.22, groundY, cz - boxD * 0.22, 0xffffff);
            } else if (kind === 'bedroom') {
                const bedW = boxW * 0.8, bedD = boxD * 0.75;
                addSimpleBox(bedW, 16, bedD, cx, groundY, cz, 0x93c5fd);
                addSimpleBox(bedW * 0.85, 20, bedD * 0.22, cx, groundY + 16, cz - bedD * 0.32, 0xeff6ff);
            } else if (kind === 'kitchen') {
                addSimpleBox(boxW * 0.85, 26, boxD * 0.35, cx - boxW * 0.05, groundY, cz - boxD * 0.25, 0x78716c);
                addSimpleBox(Math.min(18, boxW * 0.35), 34, Math.min(16, boxD * 0.35), cx + boxW * 0.28, groundY, cz + boxD * 0.22, 0xd4d4d8, { metalness: 0.4, roughness: 0.4 });
            } else if (kind === 'storage') {
                addSimpleBox(boxW * 0.85, 36, boxD * 0.6, cx, groundY, cz, 0xa16207);
            }
        }

        // Góc sinh hoạt (sofa/bàn trà/kệ TV) ngay trên sàn phòng — GIỮ NGUYÊN Ý TƯỞNG bản CSS.
        function drawLivingArea3D(gx, gz, r, groundY, insideSide){
            const farX = insideSide === 'left' ? gx + r.w * 0.22 : gx - r.w * 0.22;
            const sofaW = 44, sofaD = 22;
            addSimpleBox(sofaW, 14, sofaD, farX, groundY, gz, 0x44403c);
            addSimpleBox(sofaW, 24, sofaD * 0.35, farX, groundY + 14, gz - sofaD * 0.32, 0x57534e);
            addSimpleBox(20, 10, 14, farX, groundY, gz + sofaD * 0.9, 0x78350f);
            const tvX = insideSide === 'left' ? gx - r.w * 0.3 : gx + r.w * 0.3;
            addSimpleBox(10, 18, 30, tvX, groundY, gz, 0x1c1917);
        }

        // Cửa hé mở thật — dùng THREE.Group làm bản lề (pivot), xoay CẢ NHÓM quanh trục Y thật, đúng
        // vật lý 1 cánh cửa xoay quanh bản lề — chuẩn xác hơn hẳn cách "transform-origin" mô phỏng của
        // CSS trước đây.
        function addDoor3D(wallCenterX, wallCenterZ, groundY, doorW, doorH, facingSign, hingeSide){
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

                const mesh = addSimpleBox(boxW, boxH, boxD, cellX, groundY, edgeZ, meta.fill);
                mesh.userData.tourUrl = TOUR_BASE + '/' + s.id;
                mesh.userData.tooltip = s.label;
                addLabel3D(cellX, groundY + boxH + 8, edgeZ, meta.label || s.label, SATELLITE_LABEL_DIST);
                drawFurnitureHint3D(s.kind, cellX, edgeZ, groundY + boxH, boxW, boxD);

                if (s.kind === 'wc' || s.kind === 'bedroom') {
                    const doorWallZ = edgeZ - nearSign * (boxD / 2);
                    addDoor3D(cellX, doorWallZ, groundY, Math.min(20, boxW * 0.55), Math.min(boxH - 4, 34), -nearSign, -1);
                }
            });
        }

        // ---- 'elevated': Gác lửng — GIỮ NGUYÊN logic bản CSS (sàn nâng + lan can + cầu thang) ----
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

                const platMesh = addSimpleBox(platW, platH, platD, px, platGroundY, cz, meta.fill);
                platMesh.userData.tourUrl = TOUR_BASE + '/' + s.id;
                platMesh.userData.tooltip = s.label;

                const railH = 26;
                const railZ = cz - farSign * (platD / 2);
                addSimpleBox(platW, railH, 2, px, platGroundY + platH, railZ, 0x78716c, { transparent: true, opacity: 0.65 });

                addLabel3D(px, platGroundY + platH + railH + 10, cz, meta.label || s.label, SATELLITE_LABEL_DIST);
                drawFurnitureHint3D('bedroom', px, cz, platGroundY + platH, platW * 0.7, platD * 0.7);

                const stairZStart = pz + nearSign * (pr.d / 2 - 12);
                drawStaircase3D(px, stairZStart, railZ, groundY, rise, 8, Math.min(40, platW * 0.4));
            });
        }

        // ---- 'attached': Ban công/Sân phơi — GIỮ NGUYÊN logic bản CSS ----
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

                const mesh = addSimpleBox(boxW, platH, platD, cx, groundY, cz, meta.fill);
                mesh.userData.tourUrl = TOUR_BASE + '/' + s.id;
                mesh.userData.tooltip = s.label;
                addLabel3D(cx, groundY + platH + 8, cz, meta.label || s.label, SATELLITE_LABEL_DIST);
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
        const roomMeshes = [];
        layout.nodes.forEach(n => {
            const r = rectFor(n);
            const gx = n.x * SCALE, gz = n.y * SCALE;
            const groundY = groundYFor(n);
            const floorColor = n.isCommon ? 0xd6d3d1 : 0xfde68a;
            const wallColor = n.isCommon ? 0xa8a29e : 0xeab308;
            const built = addRoom3D(gx, gz, r.w, r.d, r.h, floorColor, wallColor, n.isCommon, groundY);
            // Phủ ảnh 360° THẬT của phòng lên 4 tường (thay màu phẳng) — chỉ áp dụng cho PHÒNG (không
            // phải hành lang, ít ý nghĩa hơn) và khi đã có ảnh (n.thumbnail luôn có nếu building có
            // dữ liệu 360°, nhưng vẫn kiểm tra cho chắc).
            if (! n.isCommon && n.thumbnail) {
                applyRoomPhotoTexture(n.thumbnail, built.wallMeshes);
            }

            // Lớp phủ TRONG SUỐT đúng diện tích sàn — vùng bấm (raycaster xử lý ở phần sự kiện click
            // bên dưới): BẤM 1 LẦN = di chuyển camera tới gần; BẤM ĐÚP = mở luôn tour 360° THẬT của
            // đúng phòng đó (Pannellum, ảnh chụp thật) — đúng ý "vào xem riêng từng phòng".
            const clickTarget = new THREE.Mesh(
                new THREE.PlaneGeometry(r.w, r.d),
                new THREE.MeshBasicMaterial({ visible: false })
            );
            clickTarget.rotation.x = -Math.PI / 2;
            clickTarget.position.set(gx, groundY + 4, gz);
            clickTarget.userData.flyTo = { x: gx, z: gz };
            clickTarget.userData.tourUrl = TOUR_BASE + '/' + n.id;
            scene.add(clickTarget);
            roomMeshes.push(clickTarget);

            if (! n.isCommon) {
                drawLivingArea3D(gx, gz, r, groundY, roomInsideSide[n.id] || 'right');

                const doorNearSign = n.y < 0 ? 1 : -1;
                const doorWallZ = gz + doorNearSign * (r.d / 2);
                const insideSide = roomInsideSide[n.id] || 'right';
                const doorSide = insideSide === 'left' ? 'right' : 'left';
                const doorX = doorSide === 'left' ? (gx - r.w / 2 + DOOR_RESERVE / 2) : (gx + r.w / 2 - DOOR_RESERVE / 2);
                const hingeSide = doorSide === 'left' ? -1 : 1;
                addDoor3D(doorX, doorWallZ, groundY, 24, 48, doorNearSign, hingeSide);
            }

            const roomLabel = (! n.isCommon && n.area) ? `${n.label} · ${n.area} m²` : n.label;
            addLabel3D(gx, groundY + r.h + 14, gz, roomLabel, ROOM_LABEL_DIST);
        });

        // ---- Cạnh nối (hành lang<->phòng cùng tầng, cầu thang khác tầng) ----
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

                const shaftMesh = addSimpleBox(SHAFT_W, shaftHeight, SHAFT_W, midX, shaftGroundY, midZ, 0xc4b5fd);
                shaftMesh.userData.tourUrl = TOUR_BASE + '/' + b.id;
                shaftMesh.userData.tooltip = e.label || ('Đến ' + b.label);
                addLabel3D(midX, shaftGroundY + shaftHeight + 10, midZ, 'Cầu thang', STAIR_LABEL_DIST);
                return;
            }

            const groundY = groundYFor(a);
            const midX = (a.x + b.x) / 2 * SCALE, midZ = (a.y + b.y) / 2 * SCALE;
            const midH = Math.max(rectFor(a).h, rectFor(b).h) * 0.5;
            addDot3D(midX, groundY + midH, midZ, e.kind === 'corridor' ? 'amber' : 'teal', e.label || ('Đến ' + b.label), b.id);
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
            byCategory('dot').forEach(s => addDot3D(s.x * SCALE, groundY + 34, s.y * SCALE, metaFor(s.kind).color, s.label, s.id));
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
        // ---- Bấm để mở tour 360° (WC/bếp/phòng ngủ/gác lửng/ban công/chấm nối) HOẶC "đi tới" 1
        // phòng (lia camera + zoom cận cảnh, xem controls.target) — raycaster tìm đối tượng bị bấm.
        // Phân biệt BẤM THẬT với KÉO CHUỘT (OrbitControls) bằng ngưỡng di chuyển nhỏ giữa
        // pointerdown/pointerup, tránh mở nhầm tour khi người dùng chỉ đang xoay/di chuyển camera.
        // ==================================================================================
        const raycaster = new THREE.Raycaster();
        const pointerNdc = new THREE.Vector2();
        let downX = 0, downY = 0;

        function setPointerFromEvent(e){
            const rect = renderer.domElement.getBoundingClientRect();
            pointerNdc.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
            pointerNdc.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
        }

        // Vệ tinh (WC/bếp/ban công...) CHỈ có tourUrl -> bấm 1 lần mở tour luôn (giữ nguyên hành vi
        // cũ). Phòng có CẢ HAI (flyTo + tourUrl) -> bấm 1 lần = tới gần (flyTo), bấm ĐÚP vào ĐÚNG
        // phòng đó trong khoảng 450ms mới mở tour thật — tránh mở tour ngay khi chỉ định xem sơ đồ.
        let lastClickObj = null, lastClickTime = 0;
        const DOUBLE_CLICK_MS = 450;

        renderer.domElement.addEventListener('pointerdown', e => { downX = e.clientX; downY = e.clientY; });
        renderer.domElement.addEventListener('pointerup', e => {
            if (Math.hypot(e.clientX - downX, e.clientY - downY) > 6) return; // đang kéo, không phải bấm

            setPointerFromEvent(e);
            raycaster.setFromCamera(pointerNdc, camera);
            const hits = raycaster.intersectObjects(scene.children, false);
            const hit = hits.find(h => h.object.userData && (h.object.userData.tourUrl || h.object.userData.flyTo));
            if (! hit) return;

            const obj = hit.object;
            const now = performance.now();
            const isDoubleClick = obj === lastClickObj && (now - lastClickTime) < DOUBLE_CLICK_MS;
            lastClickObj = obj;
            lastClickTime = now;

            if (obj.userData.flyTo && obj.userData.tourUrl) {
                if (isDoubleClick) {
                    window.open(obj.userData.tourUrl, '_blank');
                } else {
                    flyTo(obj.userData.flyTo.x, obj.userData.flyTo.z);
                }
            } else if (obj.userData.tourUrl) {
                window.open(obj.userData.tourUrl, '_blank');
            } else if (obj.userData.flyTo) {
                flyTo(obj.userData.flyTo.x, obj.userData.flyTo.z);
            }
        });

        // "Đi tới" 1 vị trí world — dịch cả target LẪN camera theo cùng 1 vector để giữ nguyên góc
        // nhìn hiện tại (chỉ đổi VỊ TRÍ đứng, không đổi HƯỚNG nhìn), đồng thời kéo gần camera lại
        // (dolly) để có hiệu ứng "tới gần xem cận cảnh" thay vì chỉ dịch ngang.
        function flyTo(worldX, worldZ){
            const newTarget = new THREE.Vector3(worldX, controls.target.y, worldZ);
            const offset = camera.position.clone().sub(controls.target);
            offset.multiplyScalar(0.55); // kéo gần lại ~45% khoảng cách hiện tại mỗi lần bấm
            if (offset.length() < controls.minDistance * 1.2) {
                offset.setLength(controls.minDistance * 1.2);
            }
            camera.position.copy(newTarget).add(offset);
            controls.target.copy(newTarget);
            controls.update();
        }

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
