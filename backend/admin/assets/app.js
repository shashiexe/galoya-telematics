// Wialon Telematics Engine - Map & Telemetry Layer Management
const StMap = {
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

  // Vertical floating toolbar matching Wialon Screenshot 3 & 4
  attachWialonToolbar(container, map) {
    if (!container || container.querySelector('.w-wialon-vtoolbar')) return;

    const toolbar = document.createElement('div');
    toolbar.className = 'w-wialon-vtoolbar';
    toolbar.innerHTML = `
      <div class="w-vtool-btn" id="vtool-fit" title="Fit all units on map">
        <span>⛶</span>
      </div>
      <div class="w-vtool-btn" id="vtool-eye" title="Toggle Units Layer">
        <span>👁️</span>
      </div>
      <div class="w-vtool-btn active-tool" id="vtool-map" title="Switch Map Source">
        <span>🗺️</span>
      </div>
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

    // Map switcher popup menu matching user's screenshot!
    const menu = document.createElement('div');
    menu.className = 'w-map-popup-menu';
    menu.id = 'w-map-layers-popup';

    const currentKey = map.getCurrentLayer ? map.getCurrentLayer() : 'gurtam';

    let menuHtml = '';
    for (const [key, conf] of Object.entries(StMap.tileLayers)) {
      const isSelected = (key === currentKey);
      menuHtml += `
        <div class="w-map-layer-item ${isSelected ? 'selected' : ''}" data-layer="${key}">
          <span>${conf.name}</span>
          <span class="chk-indicator" style="font-weight:bold;color:#1976d2">${isSelected ? '✓' : ''}</span>
        </div>
      `;
    }
    menu.innerHTML = menuHtml;

    container.appendChild(toolbar);
    container.appendChild(menu);

    // Toggle map layers popup on click or hover of map icon
    const mapBtn = toolbar.querySelector('#vtool-map');
    mapBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = (menu.style.display === 'block');
      menu.style.display = isVisible ? 'none' : 'block';
      
      const rect = mapBtn.getBoundingClientRect();
      const parentRect = container.getBoundingClientRect();
      menu.style.top = (rect.top - parentRect.top) + 'px';
      menu.style.left = (rect.right - parentRect.left + 6) + 'px';
    });

    // Handle layer selection
    menu.addEventListener('click', (e) => {
      const item = e.target.closest('.w-map-layer-item');
      if (!item) return;
      const key = item.dataset.layer;
      if (map.switchLayer) map.switchLayer(key);
      menu.style.display = 'none';
    });

    // Close menu when clicking elsewhere
    document.addEventListener('click', (e) => {
      if (!menu.contains(e.target) && e.target !== mapBtn && !mapBtn.contains(e.target)) {
        menu.style.display = 'none';
      }
    });

    // Fit bounds button
    toolbar.querySelector('#vtool-fit').addEventListener('click', () => {
      const toolFit = document.getElementById('tool-fit');
      if (toolFit) toolFit.click();
    });

    // Geofences button (Toggle geofences layer on-demand from Wialon side toolbar)
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
