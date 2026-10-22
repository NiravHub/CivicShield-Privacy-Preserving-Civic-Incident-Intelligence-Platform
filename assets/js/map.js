/**
 * CivicShield - Leaflet.js Mapping Utility
 * Provides privacy-preserving geospatial incident rendering,
 * obfuscated coordinate rendering and displacement-radius visualization, and category filtering.
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

            // 1. Draw the configured coordinate-displacement radius.
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

            // Escape HTML for safe insertion
                const escapeHTML = (str) => {
                    if (typeof str !== 'string') return '';
                    return str.replace(/[&<>\'\"]/g, (c) => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        "'": '&#39;',
                        '"': '&quot;'
                    })[c]);
                };
                const popupDiv = document.createElement('div');
                popupDiv.style.fontFamily = 'inherit';
                popupDiv.style.fontSize = '0.85rem';
                popupDiv.style.minWidth = '200px';
                // header line
                const headerDiv = document.createElement('div');
                headerDiv.style.display = 'flex';
                headerDiv.style.justifyContent = 'space-between';
                headerDiv.style.alignItems = 'center';
                headerDiv.style.marginBottom = '4px';
                const badgeSpan = document.createElement('span');
                badgeSpan.className = `cs-badge ${badgeClass}`;
                badgeSpan.textContent = item.status.replace('_', ' ');
                const refStrong = document.createElement('strong');
                refStrong.style.color = '#64748b';
                refStrong.style.fontSize = '0.75rem';
                refStrong.textContent = item.reference;
                headerDiv.appendChild(badgeSpan);
                headerDiv.appendChild(refStrong);
                popupDiv.appendChild(headerDiv);
                // title
                const titleH6 = document.createElement('h6');
                titleH6.style.margin = '0 0 4px 0';
                titleH6.style.fontSize = '0.9rem';
                titleH6.style.fontWeight = '700';
                titleH6.style.color = '#0f172a';
                titleH6.textContent = item.title;
                popupDiv.appendChild(titleH6);
                // neighborhood line
                const neighDiv = document.createElement('div');
                neighDiv.style.color = '#475569';
                neighDiv.style.fontSize = '0.78rem';
                neighDiv.style.marginBottom = '6px';
                neighDiv.innerHTML = `<i class="bi bi-geo-alt"></i> ${escapeHTML(item.neighborhood)}, Surat`;
                popupDiv.appendChild(neighDiv);
                // privacy note
                const privacyDiv = document.createElement('div');
                privacyDiv.style.background = '#f8fafc';
                privacyDiv.style.borderTop = '1px solid #e2e8f0';
                privacyDiv.style.paddingTop = '4px';
                privacyDiv.style.fontSize = '0.72rem';
                privacyDiv.style.color = '#0284c7';
                privacyDiv.innerHTML = `<i class="bi bi-shield-lock-fill"></i> Obfuscated location; circle shows configured displacement radius`;
                popupDiv.appendChild(privacyDiv);
                const popupContent = popupDiv.outerHTML;

            marker.bindPopup(popupContent);
            marker.category = item.category;
            markers.push(marker);
        });

        return markers;
    }
};
