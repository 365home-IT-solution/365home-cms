/*
 * Bản đồ GPS 365home (Leaflet + OpenStreetMap). Chỉ vẽ dữ liệu do server 365home cung cấp — KHÔNG bao giờ gọi Motrak từ trình duyệt.
 *
 * Dùng trong Blade:
 *   <div wire:ignore x-data="motrakMap({ markers: [...], polyline: [[lat,lng],...], wireProperty: 'markers', height: 480 })"> <div x-ref="map"></div> </div>
 *
 * marker: { id, plate, lat, lng, speed, state: moving|stopped|stale|disconnected|no_data, state_label, address, area, gps_time, google_maps_url, url }
 * polyline: mảng [lat, lng] theo thứ tự thời gian (điểm đầu = xanh, điểm cuối = đỏ).
 */
(function () {
    var LEAFLET_JS = '/vendor/leaflet/leaflet.js';
    var OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    var COLORS = { moving: '#16a34a', stopped: '#2563eb', stale: '#6b7280', disconnected: '#dc2626', no_data: '#6b7280' };

    function esc(value) {
        var d = document.createElement('div');
        d.textContent = value == null ? '' : String(value);
        return d.innerHTML;
    }

    window.motrakLoadLeaflet = function () {
        if (window.L) { return Promise.resolve(); }
        if (window.__motrakLeaflet) { return window.__motrakLeaflet; }
        window.__motrakLeaflet = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = LEAFLET_JS;
            s.onload = function () { window.L.Icon.Default.prototype.options.imagePath = '/vendor/leaflet/images/'; resolve(); };
            s.onerror = function () { reject(new Error('Không tải được thư viện bản đồ')); };
            document.head.appendChild(s);
        });

        return window.__motrakLeaflet;
    };

    function popupHtml(m) {
        var rows = ['<b>' + esc(m.plate) + '</b>'];
        rows.push(esc(m.state_label || ''));
        if (m.speed != null) { rows.push('Tốc độ: ' + esc(m.speed) + ' km/h'); }
        if (m.address) { rows.push(esc(m.address) + (m.area ? ' (' + esc(m.area) + ')' : '')); }
        if (m.gps_time) { rows.push('GPS: ' + esc(m.gps_time)); }
        var links = [];
        if (m.google_maps_url) { links.push('<a href="' + esc(m.google_maps_url) + '" target="_blank" rel="noopener noreferrer">Mở Google Maps</a>'); }
        if (m.url) { links.push('<a href="' + esc(m.url) + '">Chi tiết</a>'); }
        if (links.length) { rows.push(links.join(' · ')); }

        return rows.join('<br>');
    }

    function icon(m) {
        var color = COLORS[m.state] || COLORS.stopped;
        var html = '<div style="display:flex;flex-direction:column;align-items:center;transform:translate(-50%,-100%)">'
            + '<div style="background:' + color + ';color:#fff;font:600 11px/1 system-ui,sans-serif;padding:3px 6px;border-radius:4px;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.4)">' + esc(m.plate) + '</div>'
            + '<div style="width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-top:7px solid ' + color + '"></div></div>';

        return window.L.divIcon({ html: html, className: 'motrak-marker', iconSize: [0, 0] });
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.data('motrakMap', function (cfg) {
            return {
                map: null,
                layer: null,
                failed: false,
                init: function () {
                    var self = this;
                    this.$refs.map.style.height = (cfg.height || 480) + 'px';
                    window.motrakLoadLeaflet().then(function () { self.build(); }).catch(function () { self.failed = true; self.$refs.map.textContent = 'Không tải được thư viện bản đồ.'; });
                    if (cfg.wireProperty) {
                        this.$watch('$wire.' + cfg.wireProperty, function (v) { if (self.map) { self.render(v, false); } });
                    }
                },
                build: function () {
                    var L = window.L;
                    this.map = L.map(this.$refs.map, { scrollWheelZoom: true }).setView([10.03, 105.77], 11);
                    L.tileLayer(OSM_TILES, { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>' }).addTo(this.map);
                    this.layer = L.layerGroup().addTo(this.map);
                    this.render(cfg.markers || [], true);
                    var map = this.map;
                    setTimeout(function () { map.invalidateSize(); }, 200);
                },
                render: function (markers, fit) {
                    var L = window.L;
                    var bounds = [];
                    this.layer.clearLayers();

                    (markers || []).forEach(function (m) {
                        if (m.lat == null || m.lng == null) { return; }
                        L.marker([m.lat, m.lng], { icon: icon(m), title: m.plate }).bindPopup(popupHtml(m)).addTo(this.layer);
                        bounds.push([m.lat, m.lng]);
                    }, this);

                    var line = cfg.polyline || [];
                    if (line.length > 1) {
                        L.polyline(line, { color: '#2563eb', weight: 4, opacity: 0.85 }).addTo(this.layer);
                        L.circleMarker(line[0], { radius: 7, color: '#fff', weight: 2, fillColor: '#16a34a', fillOpacity: 1 }).bindTooltip('Điểm đầu').addTo(this.layer);
                        L.circleMarker(line[line.length - 1], { radius: 7, color: '#fff', weight: 2, fillColor: '#dc2626', fillOpacity: 1 }).bindTooltip('Điểm cuối').addTo(this.layer);
                        line.forEach(function (p) { bounds.push(p); });
                    }

                    if (fit && bounds.length) {
                        if (bounds.length === 1) { this.map.setView(bounds[0], 15); } else { this.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 }); }
                    }
                }
            };
        });
    });
})();
