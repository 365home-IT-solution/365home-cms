<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sơ đồ tầng - {{ $building->name }}</title>
    {{-- 3D THẬT bằng CSS 3D transform thuần (translate3d/rotateX/rotateY dựng khối hộp 5 mặt) — không
    dùng Three.js/WebGL hay thư viện 3D nào cả, giữ đúng tinh thần "hạn chế dung lượng" ban đầu. Xoay
    được bằng kéo chuột/chạm, không phải ảnh isometric tĩnh cố định 1 góc như bản trước. Giao diện đổi
    sang tông sáng/trắng-xám khớp đúng theme Filament của panel quản trị, không còn phong cách nền tối
    tự thiết kế riêng như khi còn là artifact độc lập. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            --bg:#f4f5f7; --panel:#ffffff; --border:#e5e7eb;
            --text:#111827; --text-dim:#6b7280;
            --primary:#4f46e5; --primary-dark:#4338ca;
            --floor-corridor:#a8a29e; --floor-corridor-dk:#78716c;
            --floor-room:#fde68a; --floor-room-dk:#d4a72c;
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

        .toolbar{ display:flex; gap:8px; margin-bottom:10px; }
        .toolbar button{
            font-family:inherit; font-size:12.5px; font-weight:600; color:var(--text-dim);
            background:var(--panel); border:1px solid var(--border); border-radius:8px;
            padding:7px 12px; cursor:pointer;
        }
        .toolbar button:hover{ color:var(--primary); border-color:var(--primary); }
        .toolbar .hint{ font-size:12px; color:var(--text-dim); display:flex; align-items:center; gap:6px; }

        .stage{
            position:relative; background:var(--panel);
            border:1px solid var(--border); border-radius:16px;
            overflow:hidden; height:min(70vh, 620px);
            cursor:grab; touch-action:none;
        }
        .stage:active{ cursor:grabbing; }
        .stage::before{
            content:''; position:absolute; inset:0;
            background-image:
                linear-gradient(var(--border) 1px, transparent 1px),
                linear-gradient(90deg, var(--border) 1px, transparent 1px);
            background-size:36px 36px; opacity:.5; pointer-events:none;
        }
        .viewport3d{ position:absolute; inset:0; perspective:2600px; perspective-origin:50% 50%; }
        {{-- LỖI GỐC gây xoay lệch thành hình thoi, không bao giờ ra được góc thẳng đứng: #scene3d
        không set width/height (mọi khối con đều position:absolute nên không đóng góp kích thước cho
        nó) -> hộp chứa rộng 0x0 -> transform-origin mặc định "50% 50%" thực chất trở thành đúng góc
        (0,0) của nó chứ KHÔNG PHẢI tâm sơ đồ. Mọi phép xoay vì vậy xoay quanh sai điểm, khiến cả khối
        "văng" lệch thành hình thoi thay vì xoay quanh tâm. Ép thẳng transform-origin:0 0 để khớp đúng
        điểm gốc (0,0,0) mà mọi khối con đang dùng làm hệ quy chiếu khi tính translate3d. --}}
        #scene3d{ position:absolute; left:50%; top:56%; transform-style:preserve-3d; transform-origin:0 0; }

        .room-label{
            position:absolute; transform:translate(-50%,-100%);
            font-size:11.5px; font-weight:700; letter-spacing:.02em; color:var(--text);
            background:rgba(255,255,255,.85); padding:1px 6px; border-radius:5px;
            white-space:nowrap; pointer-events:none;
        }

        a.dot3d{ cursor:pointer; display:block; }

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
        <button type="button" id="btn-bottomup">Nhìn từ dưới lên</button>
        <span class="hint">Kéo chuột/chạm để xoay — cuộn để phóng to/thu nhỏ</span>
    </div>

    <div class="stage" id="stage">
        <div class="viewport3d">
            <div id="scene3d"></div>
        </div>
    </div>

    <div class="legend">
        <span><i style="background:var(--accent-amber)"></i>Chuyển sang điểm chung khác</span>
        <span><i style="background:var(--accent-teal)"></i>Cửa phòng</span>
        <span><i style="background:var(--accent-pink)"></i>Nhà vệ sinh</span>
        <span><i style="background:var(--accent-sky)"></i>Ban công / khác</span>
    </div>

    <div class="note">
        <b>Sơ đồ TỰ SINH từ đúng dữ liệu 360° hiện có</b> — vị trí từng phòng lấy từ toạ độ lưới thật (vị trí/thứ tự phòng đã khai báo), không phải suy từ ảnh chụp. Đây là khối 3D dựng bằng CSS thuần (kéo xoay được), không phải mô hình quét 3D thật như Matterport — kích thước khối mang tính tượng trưng.
    </div>
</div>

<script>
(function(){
    const layout = @json($layout);
    const TOUR_BASE = @json(route('minihouse.tour.show', $building->id));
    const scene = document.getElementById('scene3d');
    const stage = document.getElementById('stage');

    if (! layout.nodes.length) {
        stage.outerHTML = '<div class="empty">Chưa có dữ liệu để dựng sơ đồ.</div>';
        return;
    }

    try {
        const SCALE = 42; // px / 1 đơn vị lưới

        const nodeById = {};
        layout.nodes.forEach(n => nodeById[n.id] = n);

        // NHIỀU TẦNG: mỗi tầng xếp CHỒNG LÊN NHAU theo chiều dọc (Y) — tầng có số nhỏ nhất làm mặt
        // đất (groundY=0), tầng cao hơn đẩy lên thêm FLOOR_HEIGHT mỗi bậc, giống đúng 1 toà nhà thật.
        const FLOOR_HEIGHT = 110;
        const floorNumbers = layout.nodes.map(n => n.floor ?? 1);
        const minFloor = floorNumbers.length ? Math.min(...floorNumbers) : 1;
        function groundYFor(n){ return -((n.floor ?? 1) - minFloor) * FLOOR_HEIGHT; }

        const ROOM_W = 128, ROOM_D = 78, ROOM_H = 60;

        // Chiều dài mỗi đoạn hành lang PHẢI GIÃN theo đúng khoảng cách các phòng nó phục vụ — cố định
        // 130px như bản trước khiến mỗi đoạn chỉ là 1 ô nhỏ rời rạc, không nối liền thành 1 dải hành
        // lang dài như thực tế. Quét qua các cạnh CÙNG TẦNG (hành lang <-> phòng) để suy khoảng CỘT xa
        // nhất mà mỗi đoạn hành lang phải phủ tới, rồi CHỈ vẽ 1 LẦN dựa trên dữ liệu đó.
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
            if (! n.isCommon) {
                // Bề rộng phòng (w) gần khớp khoảng cách 1 cột lưới thật (COL_UNIT=3.2 * SCALE=42 ≈
                // 134px) -> phòng liền kề nhau, chỉ chừa đúng 1 khe mỏng làm ranh giới.
                return { w: ROOM_W, d: ROOM_D, h: ROOM_H };
            }

            const span = corridorSpan[n.id];
            // Không nối tới phòng nào (VD sảnh cô lập) -> giữ kích thước mặc định nhỏ gọn.
            const w = span ? (span.max - span.min) * SCALE + ROOM_W : 130;
            return { w, d: 46, h: 22 };
        }

        // ---- Dựng 1 khối hộp 3D thật (5 mặt: trên + 4 bên) bằng CSS translate3d/rotateX/rotateY ----
        // Quy ước trục: X = trái/phải (khớp lưới x), Z = tiến/lùi dọc hành lang (khớp lưới y), Y = độ
        // cao (âm = lên trên, theo đúng chiều dọc mặc định của CSS 3D).
        //
        // QUAN TRỌNG (sửa lỗi nhiều tầng đè sai lên nhau): mỗi mặt được gắn TRỰC TIẾP vào #scene3d —
        // KHÔNG bọc từng khối trong 1 <div preserve-3d> riêng như bản trước. Lý do: khi nhiều "khối 3D
        // lồng nhau" (mỗi khối là 1 ngữ cảnh preserve-3d riêng) làm ANH EM của nhau, trình duyệt KHÔNG
        // tính đúng độ sâu thật GIỮA các khối khác nhau (chỉ tính đúng độ sâu các mặt CÙNG 1 khối) — mà
        // vẽ chồng theo THỨ TỰ THÊM VÀO DOM. Đây chính là lý do phòng tầng 1 (thêm sau) đè nhầm lên
        // phòng tầng 2 dù tầng 2 ở cao hơn thật. Gộp TẤT CẢ mặt của TẤT CẢ khối vào ĐÚNG 1 ngữ cảnh 3D
        // duy nhất (#scene3d) để trình duyệt so đúng độ sâu toàn cục, không phân biệt khối nào.
        function addFaceAbs(fw, fh, gx, gy, gz, extraTransform, bg, tag = 'div'){
            const f = document.createElement(tag);
            f.style.position = 'absolute';
            f.style.left = '50%'; f.style.top = '50%';
            f.style.width = fw + 'px'; f.style.height = fh + 'px';
            f.style.marginLeft = (-fw / 2) + 'px';
            f.style.marginTop = (-fh / 2) + 'px';
            f.style.transform = `translate3d(${gx}px, ${gy}px, ${gz}px) ${extraTransform}`;
            f.style.background = bg;
            f.style.border = '1px solid rgba(0,0,0,.18)';
            f.style.boxSizing = 'border-box';
            scene.appendChild(f);
            return f;
        }

        // includeTop=false -> khối MỞ NÓC (chỉ 4 tường, không mái) — dùng cho khối PHÒNG để nhìn
        // xuyên xuống thấy được khối Nhà vệ sinh nằm BÊN TRONG nó (giống kiểu "cắt mái nhìn từ trên"
        // của Matterport dollhouse) — có mái kín thì khối WC bên trong sẽ bị mái che khuất hoàn toàn.
        function addBox(gx, gz, w, d, h, topColor, sideColor, includeTop = true, groundY = 0){
            const by = groundY - h / 2;

            if (includeTop) {
                addFaceAbs(w, d, gx, by, gz, `rotateX(90deg) translateZ(${h / 2}px)`, topColor);
            } else {
                // Không mái -> thêm mặt SÀN ở đáy khối (world Y=groundY, mặt đất tầng đó) để nhìn
                // xuyên nóc xuống vẫn thấy màu sàn phòng thay vì trống hoác/lộ tầng dưới.
                addFaceAbs(w, d, gx, by, gz, `rotateX(90deg) translateZ(${-h / 2}px)`, topColor);
            }
            addFaceAbs(w, h, gx, by, gz, `translateZ(${d / 2}px)`, sideColor);
            addFaceAbs(w, h, gx, by, gz, `rotateY(180deg) translateZ(${d / 2}px)`, sideColor);
            addFaceAbs(d, h, gx, by, gz, `rotateY(90deg) translateZ(${w / 2}px)`, sideColor);
            addFaceAbs(d, h, gx, by, gz, `rotateY(-90deg) translateZ(${w / 2}px)`, sideColor);
        }

        // Khối hộp NHỎ, BẤM ĐƯỢC (dùng cho Nhà vệ sinh) — mỗi mặt là 1 thẻ <a> riêng (thay vì 1 khối
        // bọc trong <a>) để vẫn nằm PHẲNG trong đúng 1 ngữ cảnh 3D chung như addBox() ở trên, tránh lặp
        // lại đúng lỗi vừa sửa.
        function addClickableBox(gx, gz, w, d, h, topColor, sideColor, url, label, groundY = 0){
            const by = groundY - h / 2;
            const faces = [
                addFaceAbs(w, d, gx, by, gz, `rotateX(90deg) translateZ(${h / 2}px)`, topColor, 'a'),
                addFaceAbs(w, h, gx, by, gz, `translateZ(${d / 2}px)`, sideColor, 'a'),
                addFaceAbs(w, h, gx, by, gz, `rotateY(180deg) translateZ(${d / 2}px)`, sideColor, 'a'),
                addFaceAbs(d, h, gx, by, gz, `rotateY(90deg) translateZ(${w / 2}px)`, sideColor, 'a'),
                addFaceAbs(d, h, gx, by, gz, `rotateY(-90deg) translateZ(${w / 2}px)`, sideColor, 'a'),
            ];
            faces.forEach(f => {
                f.href = url;
                f.target = '_blank';
                f.title = label + ' — xem 360°';
            });

            return faces;
        }

        function addLabel(gx, gz, topY, text){
            const el = document.createElement('div');
            el.className = 'room-label';
            el.textContent = text;
            el.style.transform = `translate3d(${gx}px, ${topY}px, ${gz}px) translate(-50%,-100%)`;
            el.style.transformStyle = 'preserve-3d';
            scene.appendChild(el);
        }

        const colorMap = { amber: '#f59e0b', teal: '#0d9488', pink: '#db2777', sky: '#0284c7' };

        function addDot(gx, gy, gz, colorKey, label, sceneId){
            const link = document.createElement('a');
            link.href = TOUR_BASE + '/' + sceneId;
            link.target = '_blank';
            link.className = 'dot3d';
            link.title = label + ' — xem 360°';
            link.style.position = 'absolute';
            link.style.left = '0'; link.style.top = '0';
            link.style.width = '14px'; link.style.height = '14px';
            link.style.borderRadius = '50%';
            link.style.background = colorMap[colorKey] || '#0284c7';
            link.style.border = '2px solid #fff';
            link.style.boxShadow = '0 1px 4px rgba(0,0,0,.35)';
            link.style.transformStyle = 'preserve-3d';
            link.style.transform = `translate3d(${gx}px, ${gy}px, ${gz}px) translate(-50%,-50%)`;
            scene.appendChild(link);
        }

        // ---- Vẽ khối chính (hành lang + phòng) ----
        // Hành lang: khối ĐẶC (có mái) — không có gì bên trong cần xem. Phòng: MỞ NÓC (includeTop=
        // false, xem addBox()) để nhìn xuyên xuống thấy khối Nhà vệ sinh nằm bên trong nó bên dưới.
        layout.nodes.forEach(n => {
            const r = rectFor(n);
            const gx = n.x * SCALE, gz = n.y * SCALE;
            const groundY = groundYFor(n);
            const [top, side] = n.isCommon ? ['#d6d3d1', '#a8a29e'] : ['#fde68a', '#eab308'];
            addBox(gx, gz, r.w, r.d, r.h, top, side, n.isCommon, groundY);
            addLabel(gx, gz, groundY - (r.h + 6), n.label);
        });

        // ---- Chấm nối giữa hành lang <-> phòng (CÙNG tầng) — Cầu thang (KHÁC tầng) vẽ khối riêng ----
        layout.edges.forEach(e => {
            const a = nodeById[e.fromId], b = nodeById[e.toId];
            if (! a || ! b) return;

            if (a.floor !== b.floor) {
                // NỐI KHÁC TẦNG (cầu thang/thang máy) -> vẽ hẳn 1 TRỤ ĐỨNG (khối 3D thật) nối liền mặt
                // sàn tầng dưới tới mặt sàn tầng trên. LƯU Ý: toạ độ (x,y) của chính "Sảnh - Đoạn 1"
                // vốn đã là TRUNG BÌNH CỘT của cụm phòng nó nối tới (xem gridPositions() phía PHP) —
                // tức nằm GIỮA cụm phòng, không phải đầu/cuối hành lang. Dùng thẳng toạ độ đó làm cầu
                // thang sẽ kẹt giữa 2 phòng như ảnh lỗi. Đẩy lệch ra khỏi mép TRÁI của khối sảnh (đầu
                // dãy) để cầu thang nằm NGOÀI cụm phòng, đúng vị trí 1 lồng cầu thang thật.
                const lo = a.floor < b.floor ? a : b;
                const hi = a.floor < b.floor ? b : a;
                const SHAFT_W = 40;
                // Tìm phòng NGOÀI CÙNG (cột nhỏ nhất) của đúng tầng này — đặt cầu thang NGOÀI cụm
                // phòng đó (trước cột đầu tiên), chứ không dùng toạ độ tâm sảnh (nằm giữa cụm phòng).
                const roomsOnFloor = layout.nodes.filter(n => ! n.isCommon && n.floor === lo.floor);
                const firstRoomX = roomsOnFloor.length ? Math.min(...roomsOnFloor.map(n => n.x)) * SCALE : lo.x * SCALE;
                const firstRoomRect = roomsOnFloor.length ? rectFor(roomsOnFloor[0]) : rectFor(lo);
                const midX = firstRoomX - (firstRoomRect.w / 2 + SHAFT_W / 2 + 10);
                const midZ = lo.y * SCALE;
                const shaftGroundY = groundYFor(lo);
                const shaftHeight = groundYFor(lo) - groundYFor(hi);
                addBox(midX, midZ, SHAFT_W, SHAFT_W, shaftHeight, '#c4b5fd', '#7c3aed', true, shaftGroundY);
                addLabel(midX, midZ, shaftGroundY - shaftHeight - 8, 'Cầu thang');
                addDot(midX, shaftGroundY - shaftHeight * 0.5, midZ, 'amber', e.label || ('Đến ' + b.label), b.id);
                return;
            }

            const ra = rectFor(a), rb = rectFor(b);
            const midX = (a.x + b.x) / 2 * SCALE, midZ = (a.y + b.y) / 2 * SCALE;
            const groundY = groundYFor(a);
            const midH = Math.max(ra.h, rb.h) * 0.5;
            addDot(midX, groundY - midH, midZ, e.kind === 'corridor' ? 'amber' : 'teal', e.label || ('Đến ' + b.label), b.id);
        });

        // ---- Nhà vệ sinh: khối hộp NẰM BÊN TRONG diện tích phòng cha (đúng góc gần hành lang) ----
        // Không dùng toạ độ x/y do PHP tính cho vệ tinh nữa (toạ độ đó đặt BÊN NGOÀI phòng) — tính lại
        // ngay tại đây, nép vào 1 góc trong đúng phạm vi khối phòng cha, để khối WC thật sự "ở trong
        // phòng" như bản vẽ mặt bằng thật, không tách rời ra bên ngoài.
        const kindColor = { wc: 'pink', balcony: 'sky', other: 'sky' };
        const WC_W = 34, WC_D = 34, WC_H = 40, WC_MARGIN = 5;
        layout.satellites.forEach(s => {
            const parent = nodeById[s.parentId];
            if (! parent) return;
            const groundY = groundYFor(parent);

            if (s.kind === 'wc') {
                const pr = rectFor(parent);
                const px = parent.x * SCALE, pz = parent.y * SCALE;
                // Góc "trái" của phòng (cố định, cho mọi phòng nhất quán 1 phía) + phía GẦN hành lang
                // (z=0) — tức ngược dấu với hướng phòng đang lệch khỏi trục giữa hành lang.
                const cornerX = px - (pr.w / 2 - WC_W / 2 - WC_MARGIN);
                const cornerZ = pz + (parent.y < 0 ? 1 : -1) * (pr.d / 2 - WC_D / 2 - WC_MARGIN);
                addClickableBox(cornerX, cornerZ, WC_W, WC_D, WC_H, '#fbcfe8', '#db2777', TOUR_BASE + '/' + s.id, s.label, groundY);
                addLabel(cornerX, cornerZ, groundY - (WC_H + 6), 'WC');
                return;
            }

            addDot(s.x * SCALE, groundY - 34, s.y * SCALE, kindColor[s.kind] || 'sky', s.label, s.id);
        });

        // ---- Điều khiển xoay bằng kéo chuột/chạm + phóng to bằng cuộn ----
        // pitch=90 la NHIN THANG TU TREN XUONG (dung theo dung toa do CSS 3D: rotateX(90deg) bien
        // truc "sau" (Z, doc hanh lang) thanh truc doc man hinh, dung 1 so do mat bang tu tren). pitch
        // =0 la nhin NGANG (nhu dung o hanh lang nhin thang ve phia truoc). Truoc do gioi han 15-85 lam
        // KHONG BAO GIO cham duoc dung 2 dau nay — mo het ve 0-90 va them nut bam thang toi.
        // Vào trang là thấy góc "chim bay" từ trên xuống ngay (yêu cầu người dùng) — CỐ Ý không dùng
        // đúng 90° tuyệt đối: ở đúng 90°, nhìn thẳng đứng từ trên xuống thì tường 2 bên mỗi khối biến
        // mất hoàn toàn theo đúng hình học (nhìn thẳng từ trên chỉ thấy đúng mặt trên) — ảnh nhìn quá
        // phẳng, dễ gây mơ hồ không phân biệt được trên/dưới. 70° vẫn là nhìn từ trên xuống nhưng lộ
        // ra 1 chút cạnh tường để mắt nhận ra rõ ràng đây là góc nhìn từ trên. Nút "Nhìn từ trên
        // xuống" vẫn bấm được ra đúng 90° tuyệt đối khi cần.
        const DEFAULT_YAW = -20, DEFAULT_PITCH = 70;
        let yaw = DEFAULT_YAW, pitch = DEFAULT_PITCH, zoom = 1;

        function applyTransform(){
            scene.style.transform = `scale(${zoom}) rotateX(${pitch}deg) rotateY(${yaw}deg)`;
        }
        applyTransform();

        let dragging = false, lastX = 0, lastY = 0;

        function pointerDown(x, y){ dragging = true; lastX = x; lastY = y; }
        function pointerMove(x, y){
            if (! dragging) return;
            yaw += (x - lastX) * 0.35;
            // Trước chỉ cho 0-90 (nhìn ngang -> nhìn thẳng từ trên) — kéo tiếp theo hướng ngược lại
            // (từ ngang muốn xem tiếp phía DƯỚI lên) bị chặn cứng ở 0. Mở rộng sang -90 để kéo liền
            // mạch qua được cả góc nhìn từ dưới lên, không bị "kẹt tường" giữa chừng nữa.
            pitch = Math.max(-90, Math.min(90, pitch - (y - lastY) * 0.35));
            lastX = x; lastY = y;
            applyTransform();
        }
        function pointerUp(){ dragging = false; }

        stage.addEventListener('mousedown', e => pointerDown(e.clientX, e.clientY));
        window.addEventListener('mousemove', e => pointerMove(e.clientX, e.clientY));
        window.addEventListener('mouseup', pointerUp);

        stage.addEventListener('touchstart', e => {
            const t = e.touches[0];
            pointerDown(t.clientX, t.clientY);
        }, { passive: true });
        stage.addEventListener('touchmove', e => {
            const t = e.touches[0];
            pointerMove(t.clientX, t.clientY);
        }, { passive: true });
        stage.addEventListener('touchend', pointerUp);

        stage.addEventListener('wheel', e => {
            e.preventDefault();
            zoom = Math.max(0.5, Math.min(2.2, zoom - e.deltaY * 0.001));
            applyTransform();
        }, { passive: false });

        document.getElementById('btn-reset').addEventListener('click', () => {
            yaw = DEFAULT_YAW; pitch = DEFAULT_PITCH; zoom = 1;
            applyTransform();
        });
        document.getElementById('btn-topdown').addEventListener('click', () => {
            yaw = 0; pitch = 90;
            applyTransform();
        });
        document.getElementById('btn-side').addEventListener('click', () => {
            yaw = 0; pitch = 0;
            applyTransform();
        });
        document.getElementById('btn-bottomup').addEventListener('click', () => {
            yaw = 0; pitch = -90;
            applyTransform();
        });
    } catch (err) {
        stage.innerHTML = '<div class="empty">Không dựng được sơ đồ 3D (' + (err && err.message ? err.message : 'lỗi không rõ') + ').</div>';
        // eslint-disable-next-line no-console
        console.error(err);
    }
})();
</script>
</body>
</html>
