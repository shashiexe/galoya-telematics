/// Wialon Telematics Engine - Map & Telemetry Layer Management
var StMap = window.StMap = window.StMap || {
  tileLayers: {
    osm: {
      name: 'OpenStreetMap',
      url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
      options: { maxZoom: 19, attribution: '&copy; OpenStreetMap' }
    },
    gurtam: {
      name: 'Gurtam Maps modern',
      url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
      options: { maxZoom: 19, attribution: '&copy; CartoDB &copy; OpenStreetMap' }
    },
    satellite: {
      name: 'Satellite (HD Imagery)',
      url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      options: { maxZoom: 19, attribution: 'Tiles &copy; Esri' }
    },
    google_hybrid: {
      name: 'Google Hybrid (Satellite + Roads)',
      url: 'https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
      options: { maxZoom: 20, attribution: '&copy; Google' }
    },
    topo: {
      name: 'OpenTopoMap (Terrain)',
      url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
      options: { maxZoom: 17, attribution: '&copy; OpenTopoMap' }
    }
  },

  create(el, center, zoom) {
    if (window.MAP_PROVIDER === 'google' && window.google && window.google.maps) {
      const m = new google.maps.Map(el, { center: { lat: center[0], lng: center[1] }, zoom });
      return { g: m, type: 'google' };
    }

    const map = L.map(el, { zoomControl: false }).setView(center, zoom);

    // Zoom control at bottom right like modern GIS
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    // Scale bar at bottom left like Wialon Screenshot (200 ft / 50 m)
    L.control.scale({ imperial: true, metric: true, position: 'bottomleft' }).addTo(map);

    // Default tile layer: Gurtam Maps modern / OSM
    let currentKey = localStorage.getItem('wialon_map_layer') || 'gurtam';
    if (!this.tileLayers[currentKey]) currentKey = 'osm';

    let currentTileLayer = L.tileLayer(this.tileLayers[currentKey].url, this.tileLayers[currentKey].options).addTo(map);

    // Layer switcher function
    map.switchLayer = (key) => {
      if (!this.tileLayers[key] || key === currentKey) return;
      map.removeLayer(currentTileLayer);
      currentTileLayer = L.tileLayer(this.tileLayers[key].url, this.tileLayers[key].options).addTo(map);
      currentKey = key;
      localStorage.setItem('wialon_map_layer', key);
      
      // Update checkmarks in UI
      document.querySelectorAll('.w-map-layer-item').forEach(item => {
        const isSelected = item.dataset.layer === key;
        item.classList.toggle('selected', isSelected);
        const check = item.querySelector('.chk-indicator');
        if (check) check.textContent = isSelected ? '✓' : '';
      });
    };

    map.getCurrentLayer = () => currentKey;

    // Attach Wialon floating toolbar to map container
    StMap.attachWialonToolbar(el.parentElement, map);

    return map;
  },

  // Vertical floating toolbar matching Wialon Screenshot 1, 2, 3, 4 & 6
  attachWialonToolbar(container, map) {
    if (!container || container.querySelector('.w-wialon-vtoolbar')) return;

    // Load persisted visibility settings
    window.wialonVisibility = Object.assign({
      monitoring: true,
      tracks: true,
      messages: false,
      reports: false,
      geofences: false,
      drivers: false,
      passengers: false,
      unit_trace: true,
      unit_name: true,
      driver_name: false,
      unit_direction: true
    }, JSON.parse(localStorage.getItem('wialon_visibility') || '{}'));

    const toolbar = document.createElement('div');
    toolbar.className = 'w-wialon-vtoolbar';
    toolbar.innerHTML = `
      <div class="w-vtool-btn active-tool" id="vtool-eye" title="View & Visibility Layers">
        <span>👁️</span>
      </div>
      <div class="w-vtool-btn" id="vtool-map" title="Switch Map Source">
        <span>🗺️</span>
      </div>
      <div class="w-vtool-btn" id="vtool-cluster" title="Overlapping Units">
        <span>🖧</span>
      </div>
      <div class="w-vtool-btn" id="vtool-fit" title="Fit all units on map">
        <span>⛶</span>
      </div>
      <div class="w-vtool-divider"></div>
      <div class="w-vtool-btn" id="vtool-geofence" title="Toggle Geofence Zones">
        <span>⬡</span>
      </div>
      <div class="w-vtool-btn" id="vtool-ruler" title="Distance Measurement">
        <span>📏</span>
      </div>
      <div class="w-vtool-btn" id="vtool-track" title="Route Playback">
        <span>↝</span>
      </div>
    `;

    // 1. Eye Popup Menu (Exact match to User Screenshot 6)
    const eyeMenu = document.createElement('div');
    eyeMenu.className = 'w-map-popup-menu w-eye-menu';
    eyeMenu.id = 'w-eye-layers-popup';
    eyeMenu.innerHTML = `
      <div class="w-eye-menu-inner">
        <label class="w-eye-item">
          <input type="checkbox" data-vis="monitoring" ${window.wialonVisibility.monitoring ? 'checked' : ''}>
          <span>Monitoring</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="tracks" ${window.wialonVisibility.tracks ? 'checked' : ''}>
          <span>Tracks</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="messages" ${window.wialonVisibility.messages ? 'checked' : ''}>
          <span>Messages</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="reports" ${window.wialonVisibility.reports ? 'checked' : ''}>
          <span>Reports</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="geofences" ${window.wialonVisibility.geofences ? 'checked' : ''}>
          <span>Geofences</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="drivers" ${window.wialonVisibility.drivers ? 'checked' : ''}>
          <span>Drivers</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="passengers" ${window.wialonVisibility.passengers ? 'checked' : ''}>
          <span>Passengers</span>
        </label>
        <div class="w-eye-divider"></div>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="unit_trace" ${window.wialonVisibility.unit_trace ? 'checked' : ''}>
          <span>Unit trace</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="unit_name" ${window.wialonVisibility.unit_name ? 'checked' : ''}>
          <span>Unit name</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="driver_name" ${window.wialonVisibility.driver_name ? 'checked' : ''}>
          <span>Driver's name</span>
        </label>
        <label class="w-eye-item">
          <input type="checkbox" data-vis="unit_direction" ${window.wialonVisibility.unit_direction ? 'checked' : ''}>
          <span>Unit movement direction</span>
        </label>
      </div>
    `;

    // 2. Map Switcher Popup Menu (Screenshot 2)
    const mapMenu = document.createElement('div');
    mapMenu.className = 'w-map-popup-menu';
    mapMenu.id = 'w-map-layers-popup';

    const currentKey = map.getCurrentLayer ? map.getCurrentLayer() : 'gurtam';
    let mapMenuHtml = '';
    for (const [key, conf] of Object.entries(StMap.tileLayers)) {
      const isSelected = (key === currentKey);
      mapMenuHtml += `
        <div class="w-map-layer-item ${isSelected ? 'selected' : ''}" data-layer="${key}">
          <span>${conf.name}</span>
          <span class="chk-indicator" style="font-weight:bold;color:#1976d2">${isSelected ? '✓' : ''}</span>
        </div>
      `;
    }
    mapMenu.innerHTML = mapMenuHtml;

    // 3. Overlapping Units Clustering Popup (Screenshot 3)
    const clusterMenu = document.createElement('div');
    clusterMenu.className = 'w-map-popup-menu w-cluster-popup';
    clusterMenu.id = 'w-cluster-layers-popup';
    const isClustering = localStorage.getItem('wialon_cluster_units') === 'true';
    clusterMenu.innerHTML = `
      <div style="padding:10px 14px;font-size:12px;color:#334155;white-space:nowrap">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="chk-wialon-cluster" ${isClustering ? 'checked' : ''} style="width:14px;height:14px;accent-color:#2563eb">
          <span>Display overlapping units in one icon</span>
        </label>
      </div>
    `;

    container.appendChild(toolbar);
    container.appendChild(eyeMenu);
    container.appendChild(mapMenu);
    container.appendChild(clusterMenu);

    // Helpers to position & toggle popups
    function positionMenu(btn, menuEl) {
      const rect = btn.getBoundingClientRect();
      const parentRect = container.getBoundingClientRect();
      menuEl.style.top = (rect.top - parentRect.top) + 'px';
      menuEl.style.left = (rect.right - parentRect.left + 8) + 'px';
    }

    function hideAllPopups() {
      eyeMenu.style.display = 'none';
      mapMenu.style.display = 'none';
      clusterMenu.style.display = 'none';
    }

    // Toggle Eye Menu
    const eyeBtn = toolbar.querySelector('#vtool-eye');
    eyeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = (eyeMenu.style.display === 'block');
      hideAllPopups();
      if (!isVisible) {
        eyeMenu.style.display = 'block';
        positionMenu(eyeBtn, eyeMenu);
      }
    });

    // Handle Eye Checkbox Changes
    eyeMenu.addEventListener('change', (e) => {
      const chk = e.target.closest('input[type="checkbox"]');
      if (!chk) return;
      const key = chk.dataset.vis;
      if (!key) return;
      window.wialonVisibility[key] = chk.checked;
      localStorage.setItem('wialon_visibility', JSON.stringify(window.wialonVisibility));

      // Trigger map visibility updates
      if (typeof window.applyVisibilitySettings === 'function') {
        window.applyVisibilitySettings(key, chk.checked);
      }
    });

    // Toggle Map Layers Menu
    const mapBtn = toolbar.querySelector('#vtool-map');
    mapBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = (mapMenu.style.display === 'block');
      hideAllPopups();
      if (!isVisible) {
        mapMenu.style.display = 'block';
        positionMenu(mapBtn, mapMenu);
      }
    });

    // Handle Map Layer selection
    mapMenu.addEventListener('click', (e) => {
      const item = e.target.closest('.w-map-layer-item');
      if (!item) return;
      const key = item.dataset.layer;
      if (map.switchLayer) map.switchLayer(key);
      mapMenu.style.display = 'none';
    });

    // Toggle Cluster Menu
    const clusterBtn = toolbar.querySelector('#vtool-cluster');
    clusterBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = (clusterMenu.style.display === 'block');
      hideAllPopups();
      if (!isVisible) {
        clusterMenu.style.display = 'block';
        positionMenu(clusterBtn, clusterMenu);
      }
    });

    clusterMenu.querySelector('#chk-wialon-cluster').addEventListener('change', (e) => {
      localStorage.setItem('wialon_cluster_units', e.target.checked ? 'true' : 'false');
      if (typeof window.updateMarkers === 'function') {
        window.updateMarkers();
      }
    });

    // Close all popups when clicking anywhere outside
    document.addEventListener('click', (e) => {
      if (!container.contains(e.target)) {
        hideAllPopups();
      } else if (!eyeMenu.contains(e.target) && !mapMenu.contains(e.target) && !clusterMenu.contains(e.target) &&
                 !eyeBtn.contains(e.target) && !mapBtn.contains(e.target) && !clusterBtn.contains(e.target)) {
        hideAllPopups();
      }
    });

    // Fit bounds button
    toolbar.querySelector('#vtool-fit').addEventListener('click', () => {
      const toolFit = document.getElementById('tool-fit');
      if (toolFit) toolFit.click();
    });

    // Geofences button
    const gfBtn = toolbar.querySelector('#vtool-geofence');
    if (gfBtn) {
      gfBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (typeof window.toggleMonitoringGeofences === 'function') {
          window.toggleMonitoringGeofences(gfBtn);
        } else {
          const toolGf = document.getElementById('tool-geofences');
          if (toolGf) toolGf.click();
        }
      });
    }
  }
};

// Real-time Clock in bottom status bar (e.g. 10:09:45 (+05:30) | 15/10/2026)
function startLiveClock() {
  const clockEl = document.getElementById('w-live-clock');
  if (!clockEl) return;

  function update() {
    const now = new Date();
    // Sri Lanka offset is UTC+5:30
    const slTime = new Date(now.toLocaleString("en-US", { timeZone: "Asia/Colombo" }));
    const pad = n => String(n).padStart(2, '0');
    
    const h = pad(slTime.getHours());
    const m = pad(slTime.getMinutes());
    const s = pad(slTime.getSeconds());
    const d = pad(slTime.getDate());
    const mo = pad(slTime.getMonth() + 1);
    const y = slTime.getFullYear();

    clockEl.textContent = `${h}:${m}:${s} (+05:30)  ${d}/${mo}/${y}`;
  }

  update();
  setInterval(update, 1000);
}

document.addEventListener('DOMContentLoaded', startLiveClock);
