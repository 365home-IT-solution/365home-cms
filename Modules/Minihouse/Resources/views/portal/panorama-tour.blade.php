<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Sơ đồ 360° - {{ $building->name }}</title>
    {{-- Pannellum — thư viện xem ảnh 360° MÃ NGUỒN MỞ, rất nhẹ (~65KB cả CSS+JS nén gzip), không
    cần WebGL nặng/Three.js — đủ dùng cho nhu cầu "xem sơ đồ mượt mà" mà KHÔNG cần quét 3D/LiDAR (rất
    nặng, tốn thiết bị, đi ngược yêu cầu "hạn chế dung lượng" của người dùng). Tự lo chuyển cảnh mượt
    (crossfade) giữa các "scene" native, không cần code thêm. --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pannellum/2.5.6/pannellum.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pannellum/2.5.6/pannellum.js"></script>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; background: #000; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        #panorama { width: 100vw; height: 100vh; }

        .mh-tour-header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 10;
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 16px;
            background: linear-gradient(to bottom, rgba(0,0,0,0.55), rgba(0,0,0,0));
            pointer-events: none;
        }
        .mh-tour-header * { pointer-events: auto; }
        .mh-tour-title { color: #fff; font-size: 14px; font-weight: 600; text-shadow: 0 1px 3px rgba(0,0,0,0.5); }
        .mh-tour-scene-name { color: rgba(255,255,255,0.85); font-size: 12px; margin-top: 2px; text-shadow: 0 1px 3px rgba(0,0,0,0.5); }

        .mh-tour-loading, .mh-tour-error {
            position: fixed; inset: 0; z-index: 5;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: #fff; background: #000; text-align: center; padding: 24px;
        }
        .mh-tour-loading .mh-spinner {
            width: 32px; height: 32px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.25); border-top-color: #fff;
            animation: mh-spin 0.8s linear infinite; margin-bottom: 12px;
        }
        @keyframes mh-spin { to { transform: rotate(360deg); } }
        .mh-tour-error { display: none; }
        .mh-tour-error p { max-width: 320px; color: rgba(255,255,255,0.8); font-size: 14px; }
    </style>
</head>
<body>
    <div class="mh-tour-header">
        <div>
            <div class="mh-tour-title">{{ $building->name }}</div>
            <div class="mh-tour-scene-name" id="mh-scene-name"></div>
        </div>
        <a href="{{ route('minihouse.tour.floorplan', $building->id) }}" target="_blank"
           style="color:#fff; font-size:12px; text-decoration:none; border:1px solid rgba(255,255,255,.35); padding:6px 12px; border-radius:20px; background:rgba(0,0,0,.25); white-space:nowrap;">
            Sơ đồ tầng
        </a>
    </div>

    <div class="mh-tour-loading" id="mh-loading">
        <div class="mh-spinner"></div>
        <div>Đang tải sơ đồ 360°...</div>
    </div>

    <div class="mh-tour-error" id="mh-error">
        <p>Không tải được sơ đồ 360° — vui lòng thử lại sau hoặc liên hệ chủ nhà.</p>
    </div>

    <div id="panorama"></div>

    <script>
        // Tải riêng dữ liệu scenes qua JSON (xem PanoramaTourController::data()) thay vì nhúng thẳng
        // vào HTML — trình duyệt CACHE được endpoint này, bấm hotspot đổi điểm trong CÙNG 1 toà nhà
        // không cần tải lại cả trang.
        fetch(@json(route('minihouse.tour.data', $building->id)))
            .then(function (res) {
                if (! res.ok) { throw new Error('bad response'); }
                return res.json();
            })
            .then(function (data) {
                document.getElementById('mh-loading').style.display = 'none';

                var initialSceneId = 'scene_{{ $initialSceneId }}';

                if (! data.scenes[initialSceneId]) {
                    // Điểm chỉ định (VD từ link "Xem thử") đã bị ẩn/xoá — rơi về bất kỳ scene nào còn
                    // công khai thay vì hiện trang trắng.
                    initialSceneId = Object.keys(data.scenes)[0];
                }

                if (! initialSceneId) {
                    document.getElementById('mh-error').style.display = 'flex';

                    return;
                }

                var viewer = pannellum.viewer('panorama', {
                    default: {
                        firstScene: initialSceneId,
                        sceneFadeDuration: 800,
                        autoLoad: true,
                        compass: false,
                        showZoomCtrl: true,
                        showFullscreenCtrl: true,
                        hotSpotDebug: false,
                    },
                    scenes: data.scenes,
                });

                var updateSceneName = function () {
                    var scene = data.scenes[viewer.getScene()];
                    document.getElementById('mh-scene-name').textContent = scene ? scene.title : '';
                };

                viewer.on('scenechange', updateSceneName);
                updateSceneName();
            })
            .catch(function () {
                document.getElementById('mh-loading').style.display = 'none';
                document.getElementById('mh-error').style.display = 'flex';
            });
    </script>
</body>
</html>
