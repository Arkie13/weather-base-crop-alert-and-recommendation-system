// Admin Map Functionality
let adminMap = null;
let farmerMarkers = [];
let adminMarkers = [];
let disasterMarkers = [];
let alertMarkers = [];
let disasterCircles = [];
let farmerLayerGroup = null;
let adminLayerGroup = null;
let disasterLayerGroup = null;
let alertLayerGroup = null;

// Initialize map when mapping section is shown
document.addEventListener('DOMContentLoaded', function() {
    // Wait for section navigation
    setTimeout(() => {
        const mappingSection = document.getElementById('mapping');
        if (mappingSection) {
            // Check if already active
            if (mappingSection.classList.contains('active')) {
                initAdminMap();
            }
            
            // Watch for section becoming active
            const observer = new MutationObserver((mutations) => {
                if (mappingSection.classList.contains('active')) {
                    if (!adminMap) {
                        // Small delay to ensure container is visible
                        setTimeout(() => {
                            initAdminMap();
                        }, 100);
                    } else {
                        // If map already exists, invalidate size to fix rendering
                        setTimeout(() => {
                            adminMap.invalidateSize();
                        }, 100);
                    }
                }
            });
            observer.observe(mappingSection, { attributes: true, attributeFilter: ['class'] });
        }
    }, 1000);
});

// Global function to initialize map (can be called from admin-app.js)
function initAdminMap() {
    try {
        const mapContainer = document.getElementById('adminMap');
        if (!mapContainer) {
            console.error('Admin map container not found');
            return;
        }
        
        // Check if map already exists
        if (adminMap) {
            adminMap.remove();
            adminMap = null;
        }
        
        // Initialize map centered on Philippines
        adminMap = L.map('adminMap', {
            zoomControl: true,
            attributionControl: true
        }).setView([14.5995, 120.9842], 6);
        
        // Add OpenStreetMap tiles with proper configuration
        // Using CartoDB as primary (more reliable) with OSM as fallback
        const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19,
            subdomains: ['a', 'b', 'c']
        });
        
        // Try CartoDB first (more reliable)
        const cartoLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors © <a href="https://carto.com/attributions">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 20
        });
        
        // Add CartoDB layer
        cartoLayer.addTo(adminMap);
        
        // If CartoDB fails, try OSM
        cartoLayer.on('tileerror', function(error, tile) {
            console.warn('CartoDB tile error, trying OSM fallback:', error);
            if (!adminMap.hasLayer(osmLayer)) {
                osmLayer.addTo(adminMap);
            }
        });
        
        // Create layer groups
        farmerLayerGroup = L.layerGroup().addTo(adminMap);
        adminLayerGroup = L.layerGroup().addTo(adminMap);
        disasterLayerGroup = L.layerGroup().addTo(adminMap);
        alertLayerGroup = L.layerGroup().addTo(adminMap);
        
        // Invalidate size after a short delay to ensure proper rendering
        setTimeout(() => {
            if (adminMap) {
                adminMap.invalidateSize();
                // Force a view reset to trigger tile loading
                adminMap.setView([14.5995, 120.9842], adminMap.getZoom());
            }
        }, 300);
        
        // Additional invalidation after tiles should have loaded
        setTimeout(() => {
            if (adminMap) {
                adminMap.invalidateSize();
            }
        }, 1000);
        
        // Load map data
        loadMapData();
        
        // Setup form handler
        setupDisasterForm();
        
        console.log('Admin map initialized successfully');
    } catch (error) {
        console.error('Error initializing admin map:', error);
    }
}

function loadMapData() {
    fetch('api/map-data.php?action=locations')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayLocations(data.data);
            }
        })
        .catch(error => {
            console.error('Error loading locations:', error);
        });
    
    fetch('api/map-data.php?action=disasters')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayDisasters(data.data);
                displayDisastersList(data.data);
            }
        })
        .catch(error => {
            console.error('Error loading disasters:', error);
        });
    
    fetch('api/map-data.php?action=alerts')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayAlerts(data.data);
            }
        })
        .catch(error => {
            console.error('Error loading alerts:', error);
        });
    
    // Load typhoon forecast data
    loadTyphoonForecast();
}

function loadTyphoonForecast() {
    // Get forecast for Philippines area - API will scan multiple locations automatically
    // Use center of Philippines as reference point
    const centerLat = 14.5995; // Manila (center of Philippines)
    const centerLng = 120.9842;
    
    fetch(`api/typhoon-forecast.php?action=forecast&latitude=${centerLat}&longitude=${centerLng}&days=7`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                // Display typhoon forecasts at their actual landfall locations
                displayTyphoonForecast(data.data);
            }
        })
        .catch(error => {
            console.error('Error loading typhoon forecast:', error);
        });
}

function displayTyphoonForecast(typhoonData) {
    if (!adminMap || !disasterLayerGroup) return;
    
    typhoonData.forEach(forecast => {
        if (forecast.type === 'typhoon' || forecast.type === 'tropical_storm') {
            // Use actual landfall location from forecast data
            const lat = forecast.latitude || forecast.coordinates?.lat;
            const lng = forecast.longitude || forecast.coordinates?.lng;
            
            // Skip if no valid coordinates
            if (!lat || !lng) {
                console.warn('Typhoon forecast missing coordinates:', forecast);
                return;
            }
            
            const radius = forecast.radius_km || 100;
            const severity = forecast.severity || 'medium';
            const category = forecast.category || 'Typhoon';
            const date = forecast.date || 'Unknown';
            const windSpeed = forecast.wind_speed || 0;
            const windGusts = forecast.wind_gusts || 0;
            const locationName = forecast.location_name || 'Unknown Location';
            
            // Create typhoon marker
            const typhoonIcon = L.divIcon({
                className: 'typhoon-marker',
                html: `<div class="typhoon-icon ${severity}">
                    <i class="fas fa-hurricane"></i>
                    <span class="typhoon-category">${category}</span>
                </div>`,
                iconSize: [60, 60],
                iconAnchor: [30, 30]
            });
            
            const marker = L.marker([lat, lng], { icon: typhoonIcon });
            
            marker.bindPopup(`
                <div class="typhoon-popup">
                    <h3><i class="fas fa-hurricane"></i> ${forecast.title || category}</h3>
                    <p><strong>Location:</strong> ${locationName}</p>
                    <p><strong>Date:</strong> ${date}</p>
                    <p><strong>Category:</strong> ${category}</p>
                    <p><strong>Wind Speed:</strong> ${windSpeed} km/h</p>
                    <p><strong>Wind Gusts:</strong> ${windGusts} km/h</p>
                    <p><strong>Affected Radius:</strong> ${radius} km</p>
                    <p><strong>Severity:</strong> <span class="severity-${severity}">${severity.toUpperCase()}</span></p>
                    <p>${forecast.description || ''}</p>
                </div>
            `);
            
            disasterLayerGroup.addLayer(marker);
            
            // Create affected area circle
            const circleColor = severity === 'critical' ? 'rgba(220, 20, 60, 0.6)' : 
                               severity === 'high' ? 'rgba(255, 69, 0, 0.6)' : 
                               'rgba(255, 152, 0, 0.6)';
            
            const circle = L.circle([lat, lng], {
                radius: radius * 1000, // Convert km to meters
                color: circleColor,
                fillColor: circleColor,
                fillOpacity: 0.3,
                weight: 2,
                dashArray: '10, 5'
            });
            
            circle.bindPopup(`
                <div class="typhoon-affected-area">
                    <h4>Affected Area</h4>
                    <p><strong>Location:</strong> ${locationName}</p>
                    <p><strong>Radius:</strong> ${radius} km</p>
                    <p><strong>Category:</strong> ${category}</p>
                    <p><strong>Date:</strong> ${date}</p>
                </div>
            `);
            
            disasterLayerGroup.addLayer(circle);
        }
    });
}

function displayLocations(data) {
    // Clear existing markers
    farmerMarkers = [];
    adminMarkers = [];
    
    // Display farmers
    if (data.farmers && data.farmers.length > 0) {
        data.farmers.forEach(farmer => {
            if (farmer.latitude && farmer.longitude) {
                const marker = L.marker([farmer.latitude, farmer.longitude], {
                    icon: L.divIcon({
                        className: 'custom-marker farmer-marker',
                        html: '<i class="fas fa-user"></i>',
                        iconSize: [30, 30]
                    })
                });
                
                marker.bindPopup(`
                    <strong>${farmer.name || 'Farmer'}</strong><br>
                    Location: ${farmer.location || 'N/A'}<br>
                    Phone: ${farmer.phone || 'N/A'}
                `);
                
                farmerLayerGroup.addLayer(marker);
                farmerMarkers.push(marker);
            }
        });
    }
    
    // Display users (admins)
    if (data.users && data.users.length > 0) {
        data.users.forEach(user => {
            if (user.role === 'admin' && user.latitude && user.longitude) {
                const marker = L.marker([user.latitude, user.longitude], {
                    icon: L.divIcon({
                        className: 'custom-marker admin-marker',
                        html: '<i class="fas fa-user-shield"></i>',
                        iconSize: [30, 30]
                    })
                });
                
                marker.bindPopup(`
                    <strong>${user.name || 'Admin'}</strong><br>
                    Location: ${user.location || 'N/A'}<br>
                    Email: ${user.email || 'N/A'}
                `);
                
                adminLayerGroup.addLayer(marker);
                adminMarkers.push(marker);
            }
        });
    }
}

function displayDisasters(disasters) {
    // Clear existing disaster markers
    disasterMarkers = [];
    disasterCircles = [];
    
    disasters.forEach(disaster => {
        if (disaster.center_latitude && disaster.center_longitude) {
            // Create disaster marker
            const iconColor = getDisasterColor(disaster.severity);
            const marker = L.marker([disaster.center_latitude, disaster.center_longitude], {
                icon: L.divIcon({
                    className: 'custom-marker disaster-marker',
                    html: `<i class="fas fa-exclamation-triangle" style="color: ${iconColor}"></i>`,
                    iconSize: [35, 35]
                })
            });
            
            marker.bindPopup(`
                <strong>${disaster.name}</strong><br>
                Type: ${disaster.type}<br>
                Severity: ${disaster.severity}<br>
                Status: ${disaster.status}<br>
                ${disaster.description ? `<p>${disaster.description}</p>` : ''}
            `);
            
            disasterLayerGroup.addLayer(marker);
            disasterMarkers.push(marker);
            
            // Create affected area circle if radius is provided
            if (disaster.affected_radius_km) {
                const circle = L.circle([disaster.center_latitude, disaster.center_longitude], {
                    radius: disaster.affected_radius_km * 1000, // Convert km to meters
                    color: iconColor,
                    fillColor: iconColor,
                    fillOpacity: 0.3,
                    weight: 2
                });
                
                circle.bindPopup(`
                    <strong>${disaster.name}</strong><br>
                    Affected Radius: ${disaster.affected_radius_km} km
                `);
                
                disasterLayerGroup.addLayer(circle);
                disasterCircles.push(circle);
            }
            
            // Draw affected area polygon if coordinates are provided
            if (disaster.affected_area_coordinates && disaster.affected_area_coordinates.length > 0) {
                const coordinates = disaster.affected_area_coordinates.map(coord => 
                    [coord.latitude, coord.longitude]
                );
                
                const polygon = L.polygon(coordinates, {
                    color: iconColor,
                    fillColor: iconColor,
                    fillOpacity: 0.35,
                    weight: 2
                });
                
                polygon.bindPopup(`
                    <strong>${disaster.name}</strong><br>
                    Affected Area
                `);
                
                disasterLayerGroup.addLayer(polygon);
            }
        }
    });
}

function displayAlerts(alerts) {
    // Clear existing alert markers
    alertMarkers = [];
    
    alerts.forEach(alert => {
        if (alert.center_latitude && alert.center_longitude) {
            const marker = L.marker([alert.center_latitude, alert.center_longitude], {
                icon: L.divIcon({
                    className: 'custom-marker alert-marker',
                    html: '<i class="fas fa-bell"></i>',
                    iconSize: [25, 25]
                })
            });
            
            marker.bindPopup(`
                <strong>${alert.type}</strong><br>
                Severity: ${alert.severity}<br>
                ${alert.description ? `<p>${alert.description}</p>` : ''}
            `);
            
            alertLayerGroup.addLayer(marker);
            alertMarkers.push(marker);
        }
    });
}

function displayDisastersList(disasters) {
    const container = document.getElementById('disastersList');
    if (!container) return;
    
    const activeDisasters = disasters.filter(d => d.status === 'active' || d.status === 'warning');
    
    if (activeDisasters.length === 0) {
        container.innerHTML = '<h3>Active Disasters/Typhoons</h3><div class="no-alerts">No active disasters</div>';
        return;
    }
    
    container.innerHTML = '<h3>Active Disasters/Typhoons</h3>' + 
        activeDisasters.map(disaster => `
            <div class="disaster-item ${disaster.severity}">
                <div class="disaster-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="disaster-content">
                    <h4>${disaster.name}</h4>
                    <p><strong>Type:</strong> ${disaster.type} | <strong>Severity:</strong> ${disaster.severity}</p>
                    <p>${disaster.description || ''}</p>
                    <span class="disaster-time">Created: ${new Date(disaster.created_at).toLocaleString()}</span>
                </div>
            </div>
        `).join('');
}

function getDisasterColor(severity) {
    const colors = {
        'low': 'rgba(154, 205, 50, 0.7)',      // Yellow-green (lightest)
        'medium': 'rgba(255, 152, 0, 0.7)',    // Orange
        'high': 'rgba(255, 69, 0, 0.7)',       // Orange-red
        'critical': 'rgba(220, 20, 60, 0.7)'   // Crimson red (strongest)
    };
    return colors[severity] || 'rgba(102, 102, 102, 0.7)';
}

function updateMapLayers() {
    const showFarmers = document.getElementById('showFarmers').checked;
    const showDisasters = document.getElementById('showDisasters').checked;
    const showAlerts = document.getElementById('showAlerts').checked;
    
    if (showFarmers) {
        adminMap.addLayer(farmerLayerGroup);
        adminMap.addLayer(adminLayerGroup);
    } else {
        adminMap.removeLayer(farmerLayerGroup);
        adminMap.removeLayer(adminLayerGroup);
    }
    
    if (showDisasters) {
        adminMap.addLayer(disasterLayerGroup);
    } else {
        adminMap.removeLayer(disasterLayerGroup);
    }
    
    if (showAlerts) {
        adminMap.addLayer(alertLayerGroup);
    } else {
        adminMap.removeLayer(alertLayerGroup);
    }
}

function refreshMap() {
    if (adminMap) {
        // Clear all layers
        farmerLayerGroup.clearLayers();
        adminLayerGroup.clearLayers();
        disasterLayerGroup.clearLayers();
        alertLayerGroup.clearLayers();
        
        // Invalidate size to fix rendering
        adminMap.invalidateSize();
        
        // Reload data
        loadMapData();
    } else {
        // If map doesn't exist, initialize it
        initAdminMap();
    }
}

function showAddDisasterModal() {
    document.getElementById('addDisasterModal').style.display = 'block';
}

function setupDisasterForm() {
    const form = document.getElementById('addDisasterForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                name: document.getElementById('disasterName').value,
                type: document.getElementById('disasterType').value,
                severity: document.getElementById('disasterSeverity').value,
                description: document.getElementById('disasterDescription').value,
                center_latitude: document.getElementById('disasterCenterLat').value || null,
                center_longitude: document.getElementById('disasterCenterLng').value || null,
                affected_radius_km: document.getElementById('disasterRadius').value || null
            };
            
            fetch('api/map-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Disaster added successfully!');
                    closeModal('addDisasterModal');
                    form.reset();
                    refreshMap();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to add disaster');
            });
        });
    }
}

// Make functions globally available
window.refreshMap = refreshMap;
window.showAddDisasterModal = showAddDisasterModal;
window.updateMapLayers = updateMapLayers;

