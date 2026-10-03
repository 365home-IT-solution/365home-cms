// Lớp bản đồ nền dùng chung: ưu tiên file PMTiles tự phục vụ (MAP_PMTILES_URL) với bảng màu giống bản đồ OpenStreetMap ban đầu
// (nền be, nước xanh nhạt, công viên xanh, đường trắng/cam/hồng, nhãn đen đậm); không có/lỗi thì dùng ảnh OpenStreetMap công cộng.
(function () {
    var cfg = window.__mapConfig || {};
    var attribution = '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
    var OSM_FLAVOR = {"background": "#aad3df", "earth": "#f2efe9", "park_a": "#c8facc", "park_b": "#b6e3b6", "hospital": "#ffffe5", "industrial": "#ebdbe8", "school": "#ffffe5", "wood_a": "#add19e", "wood_b": "#9fc98d", "pedestrian": "#ededed", "scrub_a": "#c8d7ab", "scrub_b": "#b8c996", "glacier": "#ddecec", "sand": "#f5e9c6", "beach": "#fff1ba", "aerodrome": "#e9e7e2", "runway": "#bbbbcc", "water": "#aad3df", "zoo": "#c8facc", "military": "#ece3e0", "tunnel_other_casing": "#c9c9c9", "tunnel_minor_casing": "#c9c9c9", "tunnel_link_casing": "#c9c9c9", "tunnel_major_casing": "#c9c9c9", "tunnel_highway_casing": "#c9c9c9", "tunnel_other": "#eeeeee", "tunnel_minor": "#f4f4f4", "tunnel_link": "#f4d1c6", "tunnel_major": "#f4e0c6", "tunnel_highway": "#efc0cb", "pier": "#f2efe9", "buildings": "#d9d0c9", "minor_service_casing": "#bbbbbb", "minor_casing": "#bbbbbb", "link_casing": "#d9897a", "major_casing_late": "#dfa863", "highway_casing_late": "#dc2a67", "other": "#d4cfc6", "minor_service": "#ffffff", "minor_a": "#ffffff", "minor_b": "#ffffff", "link": "#f9c7b6", "major_casing_early": "#dfa863", "major": "#fcd6a4", "highway_casing_early": "#dc2a67", "highway": "#e892a2", "railway": "#8f8f8f", "boundaries": "#b07db0", "bridges_other_casing": "#bbbbbb", "bridges_minor_casing": "#bbbbbb", "bridges_link_casing": "#d9897a", "bridges_major_casing": "#dfa863", "bridges_highway_casing": "#dc2a67", "bridges_other": "#d4cfc6", "bridges_minor": "#ffffff", "bridges_link": "#f9c7b6", "bridges_major": "#fcd6a4", "bridges_highway": "#e892a2", "roads_label_minor": "#333333", "roads_label_minor_halo": "#ffffff", "roads_label_major": "#222222", "roads_label_major_halo": "#ffffff", "ocean_label": "#4a7ba6", "subplace_label": "#4d4d4d", "subplace_label_halo": "#f2efe9", "city_label": "#111111", "city_label_halo": "#ffffff", "state_label": "#8a8a8a", "state_label_halo": "#f2efe9", "country_label": "#555555", "address_label": "#555555", "address_label_halo": "#ffffff", "pois": {"blue": "#2b7bb8", "green": "#20834D", "lapis": "#315BCF", "pink": "#EF56BA", "red": "#d6453d", "slategray": "#6A5B8F", "tangerine": "#CB6704", "turquoise": "#0aa5b5"}};

    // Đường: vẽ riêng theo kiểu OpenStreetMap — lòng đường màu + viền đậm hơn, bề rộng tăng theo mức zoom (mặc định Protomaps chỉ có đường mảnh không viền).
    function roadRules(p) {
        var LS = p.LineSymbolizer;
        var wid = function (base, add) { return function (z) { return z < 5 ? 0 : (base * Math.pow(2, (z - 14) * 0.72)) + (add || 0); }; };
        var kind = function (k, details) { return function (z, f) { var q = f.props || {}; return q.kind === k && (!details || details.indexOf(q.kind_detail) >= 0); }; };
        var classes = [
            { f: kind('path'), fill: '#d9b8a5', cas: null, base: 1.0, min: 15, dash: [3, 2] },
            { f: kind('other'), fill: '#dcd5c8', cas: null, base: 1.0, min: 15 },
            { f: kind('minor_road', ['service', 'pedestrian', 'track', 'alley', 'driveway', 'parking_aisle']), fill: '#ffffff', cas: '#bbbbbb', base: 1.3, min: 14 },
            { f: function (z, f) { var q = f.props || {}; return q.kind === 'minor_road' && ['service', 'pedestrian', 'track', 'alley', 'driveway', 'parking_aisle'].indexOf(q.kind_detail) < 0; }, fill: '#ffffff', cas: '#b9b5ab', base: 2.4, min: 12 },
            { f: kind('medium_road'), fill: '#ffffff', cas: '#a9a59a', base: 4.6, min: 11 },
            { f: kind('major_road', ['tertiary', 'tertiary_link']), fill: '#ffffff', cas: '#a9a59a', base: 5.0, min: 10 },
            { f: kind('major_road', ['secondary', 'secondary_link']), fill: '#f7fabf', cas: '#bcbc6b', base: 6.2, min: 9 },
            { f: kind('major_road', ['primary', 'primary_link']), fill: '#fcd6a4', cas: '#d99a53', base: 7.0, min: 8 },
            { f: kind('highway', ['trunk', 'trunk_link']), fill: '#f9b29c', cas: '#d9805c', base: 7.4, min: 6 },
            { f: kind('highway'), fill: '#e892a2', cas: '#dc2a67', base: 7.8, min: 5 }
        ];
        var cas = [], fill = [];
        classes.forEach(function (c) {
            if (c.cas) {
                cas.push({ dataLayer: 'roads', minzoom: c.min, filter: c.f, symbolizer: new LS({ color: c.cas, width: wid(c.base, 1.1), lineCap: 'butt', lineJoin: 'round' }) });
            }
            fill.push({ dataLayer: 'roads', minzoom: c.min, filter: c.f, symbolizer: new LS({ color: c.fill, width: wid(c.base), dash: c.dash || undefined, lineCap: c.dash ? 'butt' : 'round', lineJoin: 'round' }) });
        });
        return cas.concat(fill).concat([{ dataLayer: 'roads', minzoom: 11, filter: kind('rail'), symbolizer: new LS({ color: '#8f8f8f', width: wid(0.9), dash: [4, 3] }) }]);
    }

    window.createBaseLayer = function () {
        var p = window.protomapsL;
        if (cfg.pmtilesUrl && p && typeof p.leafletLayer === 'function') {
            try {
                var opts = { url: cfg.pmtilesUrl, lang: 'vi', attribution: attribution };
                if (cfg.flavor === 'osm' && typeof p.paintRules === 'function' && typeof p.labelRules === 'function') {
                    var base = p.paintRules(OSM_FLAVOR).filter(function (r) { return r.dataLayer !== 'roads'; });
                    var at = 0;
                    base.forEach(function (r, i) { if (r.dataLayer === 'buildings') { at = i + 1; } });
                    opts.paintRules = base.slice(0, at).concat(roadRules(p), base.slice(at));
                    opts.labelRules = p.labelRules(OSM_FLAVOR, 'vi');
                    opts.backgroundColor = OSM_FLAVOR.background;
                } else {
                    opts.flavor = cfg.flavor || 'light';
                }
                return p.leafletLayer(opts);
            } catch (e) { /* rơi xuống OSM */ }
        }

        return L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: attribution });
    };
})();
