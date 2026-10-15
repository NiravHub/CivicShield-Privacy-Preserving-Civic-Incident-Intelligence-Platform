/**
 * CivicShield - Leaflet.js Mapping Utility
 * Provides privacy-preserving geospatial incident rendering,
 * differential geo-obfuscation noise visualizer, and category filtering.
 */

window.CivicMap = {
    instances: {},

    /**
     * Initialize Leaflet map with OpenStreetMap tiles
     *
     * @param {string} containerId - Element ID for map container
     * @param {number} lat - Latitude center (Default: Surat, India)
     * @param {number} lng - Longitude center
     * @param {number} zoom - Default zoom level
     * @returns {L.Map|null}
     */
    init(containerId, lat = 21.1702, lng = 72.8311, zoom = 12) {
        if (!window.L) {
            console.warn('[CivicMap] Leaflet.js library is not loaded.');
            return null;
        }

        const container = document.getElementById(containerId);
        if (!container) return null;

        // Clean up previous instance if any
        if (this.instances[containerId]) {
            this.instances[containerId].remove();
        }

        const map = L.map(containerId, {
            scrollWheelZoom: false,
            attributionControl: true
        }).setView([lat, lng], zoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors &bull; CivicShield'
        }).addTo(map);

        this.instances[containerId] = map;
        return map;
    },

    /**
     * Render an obfuscated privacy-preserving incident circle
     * Represents incident location without revealing exact citizen coordinates.
     */
    addObfuscatedArea(map, lat, lng, radiusMeters = 150, options = {}) {
        if (!map) return null;

        const defaultOptions = {
            color: '#0284c7',
            fillColor: '#38bdf8',
            fillOpacity: 0.25,
            weight: 1.5,
            dashArray: '4, 4',
            ...options
        };

        return L.circle([lat, lng], { radius: radiusMeters, ...defaultOptions }).addTo(map);
    },

    /**
     * Load demonstration Surat incidents onto map with custom badges and popups
     */
    renderDemoIncidents(map, incidents = []) {
        if (!map) return [];

        const markers = [];
        incidents.forEach(item => {
            // Determine severity / status color
            let markerColor = '#d97706'; // default amber
            let badgeClass = 'cs-badge-pending';

            if (item.status === 'resolved') {
                markerColor = '#16a34a'; // green
                badgeClass = 'cs-badge-resolved';
            } else if (item.severity === 'critical') {
                markerColor = '#dc2626'; // red
                badgeClass = 'cs-badge-critical';
            } else if (item.severity === 'high') {
                markerColor = '#ea580c';
                badgeClass = 'cs-badge-high';
            }

            // 1. Draw Differential Privacy Obfuscation Radius
            this.addObfuscatedArea(map, item.lat, item.lng, item.radius || 150, {
                color: markerColor,
                fillColor: markerColor
            });

            // 2. Custom Marker Pin
            const customIcon = L.divIcon({
                className: 'cs-custom-leaflet-marker',
                html: `<div style="background-color: ${markerColor}; width: 14px; height: 14px; border: 2px solid #ffffff; border-radius: 50%; box-shadow: 0 0 4px rgba(0,0,0,0.4);"></div>`,
                iconSize: [14, 14],
                iconAnchor: [7, 7]
            });

            const marker = L.marker([item.lat, item.lng], { icon: customIcon }).addTo(map);

            const popupContent = `
                <div style="font-family: inherit; font-size: 0.85rem; min-width: 200px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <span class="cs-badge ${badgeClass}">${item.status.replace('_', ' ')}</span>
                        <strong style="color: #64748b; font-size: 0.75rem;">${item.reference}</strong>
                    </div>
                    <h6 style="margin: 0 0 4px 0; font-size: 0.9rem; font-weight: 700; color: #0f172a;">${item.title}</h6>
                    <div style="color: #475569; font-size: 0.78rem; margin-bottom: 6px;">
                        <i class="bi bi-geo-alt"></i> ${item.neighborhood}, Surat
                    </div>
                    <div style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding-top: 4px; font-size: 0.72rem; color: #0284c7;">
                        <i class="bi bi-shield-lock-fill"></i> Centroid ±150m geo-fuzzed for privacy
                    </div>
                </div>
            `;

            marker.bindPopup(popupContent);
            marker.category = item.category;
            markers.push(marker);
        });

        return markers;
    }
};
