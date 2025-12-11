class FarmerApp {
    constructor() {
        this.currentUser = null;
        this.weatherData = null;
        this.searchedLocation = null; // Store searched location
        this.isSearchingLocation = false; // Track if viewing searched location
        this.init();
    }

    async init() {
        await this.checkAuthentication();
        this.setupEventListeners();
        this.updateCurrentDate();
        await this.loadInitialData();
        this.startAutoRefresh();
    }
    
    startAutoRefresh() {
        // Refresh alerts every 5 minutes (300000 ms)
        // This ensures forecast alerts stay up-to-date
        setInterval(async () => {
            // Only refresh if alerts section is visible or dashboard is active
            const alertsSection = document.getElementById('alerts');
            const dashboardSection = document.getElementById('dashboard');
            
            if (alertsSection && alertsSection.classList.contains('active')) {
                await this.loadAlerts();
            } else if (dashboardSection && dashboardSection.classList.contains('active')) {
                // Refresh dashboard alerts when dashboard is active
                await this.loadAlerts();
            }
        }, 300000); // 5 minutes = 300000 milliseconds
        
        // Also refresh weather data every 15 minutes
        setInterval(async () => {
            const dashboardSection = document.getElementById('dashboard');
            if (dashboardSection && dashboardSection.classList.contains('active')) {
                await this.fetchWeatherData();
            }
        }, 900000); // 15 minutes = 900000 milliseconds
    }

    async checkAuthentication() {
        try {
            const response = await fetch('api/auth.php');
            const data = await response.json();

            if (data.success && data.authenticated) {
                this.currentUser = data.user;
                // Store user data in localStorage for consistency
                localStorage.setItem('currentUser', JSON.stringify(data.user));
                if (data.user.role !== 'farmer') {
                    window.location.href = 'admin-dashboard.html';
                    return;
                }
                this.updateUserInfo();
            } else {
                window.location.href = 'login.html';
                return;
            }
        } catch (error) {
            console.error('Auth check failed:', error);
            window.location.href = 'login.html';
        }
    }

    getGreeting() {
        const hour = new Date().getHours();
        let greeting;
        
        if (hour < 12) {
            greeting = 'Good morning';
        } else if (hour < 17) {
            greeting = 'Good afternoon';
        } else {
            greeting = 'Good evening';
        }
        
        return greeting;
    }

    updateUserInfo() {
        if (this.currentUser) {
            // Update greeting
            const greetingElement = document.getElementById('userGreeting');
            if (greetingElement) {
                const greeting = this.getGreeting();
                const firstName = this.currentUser.full_name ? this.currentUser.full_name.split(' ')[0] : 'Farmer';
                greetingElement.textContent = `${greeting}, ${firstName}!`;
            }
            
            const farmerNameEl = document.getElementById('farmerName');
            const farmerLocationEl = document.getElementById('farmerLocation');
            const profileNameEl = document.getElementById('profileName');
            const profileLocationEl = document.getElementById('profileLocation');
            const profileUsernameEl = document.getElementById('profileUsername');
            
            if (farmerNameEl) farmerNameEl.textContent = this.currentUser.full_name;
            if (farmerLocationEl) farmerLocationEl.textContent = this.currentUser.location;
            if (profileNameEl) profileNameEl.textContent = this.currentUser.full_name;
            if (profileLocationEl) profileLocationEl.textContent = this.currentUser.location;
            if (profileUsernameEl) profileUsernameEl.textContent = this.currentUser.username;
        }
    }

    setupEventListeners() {
        // Navigation - Support both old nav-link and new sidebar-link
        document.querySelectorAll('.nav-link, .sidebar-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.showSection(link.dataset.section);
                // Close sidebar on mobile after navigation
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });
        
        // Quick action cards navigation
        document.querySelectorAll('.quick-action-card[data-section]').forEach(card => {
            card.addEventListener('click', (e) => {
                e.preventDefault();
                const section = card.dataset.section;
                if (section) {
                    this.showSection(section);
                    // Close sidebar on mobile after navigation
                    if (window.innerWidth <= 768) {
                        toggleSidebar();
                    }
                }
            });
        });

        // Add crop form
        document.getElementById('addCropForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.addCrop();
        });

        // Edit profile form
        document.getElementById('editProfileForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.updateProfile();
        });

        // Edit crop form
        document.getElementById('editCropForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.updateCrop();
        });

        // Location search input - Enter key support
        const locationSearchInput = document.getElementById('locationSearchInput');
        if (locationSearchInput) {
            locationSearchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    searchLocationWeather();
                }
            });
        }
    }

    showSection(sectionId) {
        // Hide all sections
        document.querySelectorAll('.content-section').forEach(section => {
            section.classList.remove('active');
        });

        // Remove active class from nav links - Support both old nav-link and new sidebar-link
        document.querySelectorAll('.nav-link, .sidebar-link').forEach(link => {
            link.classList.remove('active');
        });

        // Show selected section
        document.getElementById(sectionId).classList.add('active');
        const activeLink = document.querySelector(`[data-section="${sectionId}"]`);
        if (activeLink) {
            activeLink.classList.add('active');
        }

        // Load section-specific data
        this.loadSectionData(sectionId);
    }

    async loadSectionData(sectionId) {
        switch (sectionId) {
            case 'dashboard':
                await this.loadDashboardData();
                break;
            case 'crops':
                await this.loadCrops();
                break;
            case 'alerts':
                // Ensure alerts section is visible before loading
                setTimeout(async () => {
                    await this.loadAlerts();
                }, 100);
                break;
            case 'weather-history':
                await this.loadWeatherHistory();
                break;
            case 'market-timing':
                await this.loadMarketTiming();
                break;
            case 'market-prices':
                await this.loadMarketPrices();
                break;
            case 'harvest-prescription':
                await this.loadHarvestPrescriptions();
                break;
            case 'profile':
                await this.loadProfile();
                break;
        }
    }

    async loadInitialData() {
        await this.loadDashboardData();
        // Load external weather alerts on page load
        await this.loadExternalWeatherAlerts();
    }

    async loadDashboardData() {
        try {
            // Update user location display
            this.updateLocationDisplay();
            
            // Load weather data
            await this.fetchWeatherData();
            
            // Load alerts
            await this.loadAlerts();
            
            // Load crop recommendations
            await this.loadCropRecommendations();
            
            // Load farmer stats
            await this.loadFarmerStats();
            
            // Track user activity
            await this.trackActivity('dashboard_view');
        } catch (error) {
            console.error('Error loading dashboard data:', error);
        }
    }
    
    updateLocationDisplay() {
        // Use this.currentUser first, then fallback to localStorage
        let user = this.currentUser;
        if (!user) {
            const storedUser = localStorage.getItem('currentUser');
            if (storedUser) {
                try {
                    user = JSON.parse(storedUser);
                } catch (e) {
                    console.error('Error parsing user data:', e);
                }
            }
        }
        
        const locationElement = document.getElementById('farmerLocation');
        if (locationElement && user && user.location) {
            locationElement.textContent = user.location;
        }
    }

    async fetchWeatherData(locationName = null, latitude = null, longitude = null) {
        try {
            // If location search parameters provided, use them
            if (locationName && latitude && longitude) {
                this.isSearchingLocation = true;
                this.searchedLocation = locationName;
                
                // Update location display
                const locationElement = document.getElementById('farmerLocation');
                if (locationElement) {
                    locationElement.textContent = locationName;
                }
                
                // Show reset button
                const resetBtn = document.getElementById('resetLocationBtn');
                if (resetBtn) {
                    resetBtn.style.display = 'flex';
                }
            } else {
                // Get user location first - use this.currentUser if available, otherwise check localStorage
                let user = this.currentUser;
                if (!user) {
                    const storedUser = localStorage.getItem('currentUser');
                    if (storedUser) {
                        try {
                            user = JSON.parse(storedUser);
                        } catch (e) {
                            console.error('Error parsing user data:', e);
                        }
                    }
                }
                
                let location = 'Manila, Philippines';
                let lat = 14.5995;
                let lng = 120.9842;
                
                if (user && user.location) {
                    location = user.location;
                    if (user.latitude && user.longitude) {
                        lat = user.latitude;
                        lng = user.longitude;
                    } else {
                        // Try to geocode location
                        const geocodeResult = await APIUtils.geocode(user.location);
                        if (geocodeResult.success && geocodeResult.data) {
                            lat = geocodeResult.data.latitude;
                            lng = geocodeResult.data.longitude;
                        }
                    }
                }
                
                latitude = lat;
                longitude = lng;
                this.isSearchingLocation = false;
                this.searchedLocation = null;
                
                // Update location display
                const locationElement = document.getElementById('farmerLocation');
                if (locationElement) {
                    locationElement.textContent = location;
                }
                
                // Hide reset button
                const resetBtn = document.getElementById('resetLocationBtn');
                if (resetBtn) {
                    resetBtn.style.display = 'none';
                }
            }
            
            // Try to get weather using coordinates
            const weatherResult = await APIUtils.getCurrentWeather(latitude, longitude);
            const forecastResult = await APIUtils.getWeatherForecast(latitude, longitude, 5);
            
            if (weatherResult.success) {
                this.weatherData = {
                    current: weatherResult.data,
                    forecast: forecastResult.success ? forecastResult.data : [],
                    location: {
                        name: locationName || this.currentUser?.location || 'Your Location',
                        latitude: latitude,
                        longitude: longitude
                    }
                };
                this.displayCurrentWeather();
                this.displayForecast();
            } else {
                // Fallback to API endpoint
                const response = await fetch('api/weather.php');
                const data = await response.json();
                
                if (data.success) {
                    this.weatherData = data.data;
                    this.displayCurrentWeather();
                    this.displayForecast();
                } else {
                    throw new Error(data.message || 'Failed to fetch weather data');
                }
            }
        } catch (error) {
            console.error('Error fetching weather data:', error);
            // Show error message
            const container = document.getElementById('currentWeather');
            if (container) {
                container.innerHTML = `
                    <div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Failed to load weather data. Please try again later.</p>
                    </div>
                `;
            }
        }
    }

    displayCurrentWeather() {
        const container = document.getElementById('currentWeather');
        if (!container) return;
        
        if (!this.weatherData || !this.weatherData.current) {
            container.innerHTML = '<div class="no-data">No weather data available</div>';
            return;
        }

        const weather = this.weatherData.current;
        const weatherIcon = this.getWeatherIcon(weather.condition || 'Clear Sky');
        
        container.innerHTML = `
            <div class="current-weather-grid">
                <div class="weather-primary">
                    <div class="weather-icon-large">${weatherIcon}</div>
                    <div class="weather-temp-large">${weather.temperature || weather.temp || 'N/A'}°C</div>
                    <div class="weather-condition-text">${weather.condition || 'N/A'}</div>
                </div>
                <div class="weather-secondary">
                    <div class="weather-metric">
                        <div class="metric-icon">
                            <i class="fas fa-tint"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-value">${weather.humidity || 'N/A'}%</div>
                            <div class="metric-label">Humidity</div>
                        </div>
                    </div>
                    <div class="weather-metric">
                        <div class="metric-icon">
                            <i class="fas fa-cloud-rain"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-value">${weather.rainfall || weather.precipitation || '0'}mm</div>
                            <div class="metric-label">Rainfall</div>
                        </div>
                    </div>
                    <div class="weather-metric">
                        <div class="metric-icon">
                            <i class="fas fa-wind"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-value">${weather.wind_speed || 'N/A'} km/h</div>
                            <div class="metric-label">Wind Speed</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    displayForecast() {
        const container = document.getElementById('weatherForecast');
        if (!container) return;
        
        if (!this.weatherData || !this.weatherData.forecast || this.weatherData.forecast.length === 0) {
            container.innerHTML = '<div class="no-data">No forecast data available</div>';
            return;
        }

        const forecast = this.weatherData.forecast;
        container.innerHTML = forecast.map(day => {
            // Handle different date formats
            let dateStr = day.date;
            let dateObj;
            if (typeof dateStr === 'string') {
                dateObj = new Date(dateStr);
            } else {
                dateObj = new Date();
            }
            
            // Handle temperature - could be temperature_max or temperature
            const temp = day.temperature_max || day.temperature || day.temp || 'N/A';
            const condition = day.condition || 'N/A';
            
            return `
                <div class="forecast-item">
                    <div class="date">${dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })}</div>
                    <div class="temp">${temp}°C</div>
                    <div class="condition">${condition}</div>
                </div>
            `;
        }).join('');
    }

    getWeatherIcon(condition) {
        const conditionLower = condition.toLowerCase();
        
        if (conditionLower.includes('rain') && conditionLower.includes('storm')) {
            return '<i class="fas fa-bolt"></i>';
        } else if (conditionLower.includes('rain')) {
            return '<i class="fas fa-cloud-rain"></i>';
        } else if (conditionLower.includes('cloudy') || conditionLower.includes('overcast')) {
            return '<i class="fas fa-cloud"></i>';
        } else if (conditionLower.includes('partly') || conditionLower.includes('partially')) {
            return '<i class="fas fa-cloud-sun"></i>';
        } else if (conditionLower.includes('sunny') || conditionLower.includes('clear')) {
            return '<i class="fas fa-sun"></i>';
        } else {
            return '<i class="fas fa-cloud-sun"></i>';
        }
    }

    async loadCropRecommendations() {
        const container = document.getElementById('cropRecommendations');
        if (!container) return;

        try {
            const response = await fetch('api/crop-recommendation.php');
            const data = await response.json();

            if (data.success && data.recommendations) {
                const recommendations = data.recommendations.slice(0, 4); // Show top 4
                
                container.innerHTML = recommendations.map(crop => `
                    <div class="crop-card">
                        <div class="crop-name">${crop.crop_name}</div>
                        <div class="crop-suitability ${crop.suitability.toLowerCase().replace(' ', '-')}">${crop.suitability}</div>
                        <div class="crop-score">Score: ${Math.round(crop.score)}%</div>
                    </div>
                `).join('');
            } else {
                // Fallback to static recommendations
                const recommendations = [
                    { crop_name: 'Rice', suitability: 'Excellent', score: 85 },
                    { crop_name: 'Corn', suitability: 'Good', score: 78 },
                    { crop_name: 'Tomato', suitability: 'Good', score: 72 },
                    { crop_name: 'Eggplant', suitability: 'Fair', score: 65 }
                ];

                container.innerHTML = recommendations.map(crop => `
                    <div class="crop-card">
                        <div class="crop-name">${crop.crop_name}</div>
                        <div class="crop-suitability ${crop.suitability.toLowerCase()}">${crop.suitability}</div>
                        <div class="crop-score">Score: ${crop.score}%</div>
                    </div>
                `).join('');
            }
        } catch (error) {
            console.error('Error loading crop recommendations:', error);
            container.innerHTML = '<div class="no-data">Failed to load recommendations</div>';
        }
    }

    async loadFarmerStats() {
        // Mock farmer stats - in real app, this would come from API
        const myCropsEl = document.getElementById('myCrops');
        const activeAlertsEl = document.getElementById('activeAlerts');
        const daysSinceRegEl = document.getElementById('daysSinceReg');
        const cropHealthEl = document.getElementById('cropHealth');
        
        if (myCropsEl) myCropsEl.textContent = '2';
        if (activeAlertsEl) activeAlertsEl.textContent = '1';
        if (daysSinceRegEl) daysSinceRegEl.textContent = '30';
        if (cropHealthEl) cropHealthEl.textContent = 'Good';
    }

    async loadCrops() {
        const cropsGrid = document.getElementById('cropsGrid');
        const noCropsMessage = document.getElementById('noCropsMessage');
        const cropsLoading = document.getElementById('cropsLoading');
        
        if (!cropsGrid) return;
        
        // Show loading state
        if (cropsLoading) cropsLoading.style.display = 'block';
        if (noCropsMessage) noCropsMessage.style.display = 'none';
        
        try {
            const response = await fetch('api/user-crops.php');
            const data = await response.json();
            
            if (cropsLoading) cropsLoading.style.display = 'none';
            
            if (data.success) {
                if (data.data && data.data.length > 0) {
                    this.displayCrops(data.data);
                } else {
                    // Show no crops message
                    if (noCropsMessage) noCropsMessage.style.display = 'block';
                }
            } else {
                console.error('Error loading crops:', data.message);
                if (noCropsMessage) noCropsMessage.style.display = 'block';
            }
        } catch (error) {
            console.error('Error loading crops:', error);
            if (cropsLoading) cropsLoading.style.display = 'none';
            if (noCropsMessage) noCropsMessage.style.display = 'block';
        }
    }
    
    displayCrops(crops) {
        const cropsGrid = document.getElementById('cropsGrid');
        const noCropsMessage = document.getElementById('noCropsMessage');
        
        if (!cropsGrid) return;
        
        // Hide no crops message
        if (noCropsMessage) {
            noCropsMessage.style.display = 'none';
        }
        
        // Display crops
        cropsGrid.innerHTML = crops.map(crop => `
            <div class="crop-card">
                <div class="crop-header">
                    <h3>${crop.crop_name}</h3>
                    <span class="crop-status ${this.getCropStatusClass(crop.status)}">${this.getCropStatusText(crop.status)}</span>
                </div>
                <div class="crop-details">
                    <p><strong>Planted:</strong> ${crop.days_planted} days ago</p>
                    ${crop.days_to_harvest ? `<p><strong>Expected Harvest:</strong> ${crop.days_to_harvest} days</p>` : ''}
                    <p><strong>Area:</strong> ${crop.area_hectares} hectares</p>
                    <p><strong>Health:</strong> ${this.getHealthStatusText(crop.health_status)}</p>
                    ${crop.variety ? `<p><strong>Variety:</strong> ${crop.variety}</p>` : ''}
                </div>
                <div class="crop-actions">
                    <button class="btn btn-sm btn-primary" onclick="editCrop(${crop.id})">Update</button>
                    <button class="btn btn-sm btn-secondary" onclick="viewCropDetails(${crop.id})">Details</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteCrop(${crop.id})">Delete</button>
                </div>
            </div>
        `).join('');
    }
    
    getCropStatusClass(status) {
        switch (status) {
            case 'planted': return 'info';
            case 'growing': return 'good';
            case 'harvesting': return 'warning';
            case 'harvested': return 'success';
            case 'failed': return 'danger';
            default: return 'info';
        }
    }
    
    getCropStatusText(status) {
        switch (status) {
            case 'planted': return 'Planted';
            case 'growing': return 'Growing Well';
            case 'harvesting': return 'Harvesting';
            case 'harvested': return 'Harvested';
            case 'failed': return 'Failed';
            default: return 'Unknown';
        }
    }
    
    getHealthStatusText(health) {
        switch (health) {
            case 'excellent': return 'Excellent';
            case 'good': return 'Good';
            case 'fair': return 'Fair';
            case 'poor': return 'Poor';
            case 'critical': return 'Critical';
            default: return 'Unknown';
        }
    }

    async loadAlerts() {
        let externalAlerts = [];
        
        try {
            // Load external weather alerts (typhoon, storm warnings) with timeout
            try {
                externalAlerts = await Promise.race([
                    this.loadExternalWeatherAlerts(),
                    new Promise((resolve) => setTimeout(() => resolve([]), 10000)) // 10 second timeout
                ]);
            } catch (error) {
                console.error('Error loading external weather alerts:', error);
                externalAlerts = [];
            }
            
            // Get current user ID from session or localStorage
            const userId = this.getCurrentUserId();
            if (!userId) {
                console.error('No user ID found');
                // Still show external alerts even if user ID is not found
                this.displayAlerts(externalAlerts || []);
                this.displayDashboardAlerts(externalAlerts || []);
                return;
            }

            try {
                // Load all active alerts for the Alerts section (no location filtering)
                // User wants to see all active alerts from all areas, even if far away
                const response = await fetch(`api/user-alerts.php?user_id=${userId}&status=active&limit=500`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                console.log('Alerts API response:', data); // Debug log

                if (data.success) {
                    const databaseAlerts = data.data || [];
                    console.log(`Loaded ${databaseAlerts.length} alerts from database, ${externalAlerts.length} external alerts`);
                    
                    // Combine external alerts with database alerts
                    const allAlerts = this.combineAlerts(databaseAlerts, externalAlerts || []);
                    console.log(`Total combined alerts: ${allAlerts.length}`);
                    
                    this.displayAlerts(allAlerts);
                    this.displayDashboardAlerts(allAlerts);
                } else {
                    console.warn('API returned success=false:', data.message);
                    // If database fails, still show external alerts
                    this.displayAlerts(externalAlerts || []);
                    this.displayDashboardAlerts(externalAlerts || []);
                }
            } catch (error) {
                console.error('Error loading database alerts:', error);
                // Show external alerts even if database fails
                this.displayAlerts(externalAlerts || []);
                this.displayDashboardAlerts(externalAlerts || []);
            }
        } catch (error) {
            console.error('Error loading alerts:', error);
            // Always clear loading state, even on error
            this.displayAlerts([]);
            this.displayDashboardAlerts([]);
        }
    }
    
    async loadExternalWeatherAlerts() {
        try {
            // Get user's location from current user data
            const userLocation = this.getUserLocation();
            let latitude = 14.5995; // Default Manila
            let longitude = 120.9842;
            
            if (userLocation && userLocation.latitude && userLocation.longitude) {
                latitude = userLocation.latitude;
                longitude = userLocation.longitude;
            } else if (this.currentUser && this.currentUser.location) {
                // Try to geocode the location string
                const geocodeResult = await APIUtils.geocode(this.currentUser.location);
                if (geocodeResult.success && geocodeResult.data) {
                    latitude = geocodeResult.data.latitude;
                    longitude = geocodeResult.data.longitude;
                }
            }
            
            const result = await APIUtils.getSevereWeatherAlerts(latitude, longitude);
            
            if (result.success && result.data && result.data.length > 0) {
                // Display external alerts prominently in banner
                this.displayExternalAlerts(result.data);
                
                // Convert external alerts to format compatible with displayAlerts
                return this.formatExternalAlerts(result.data);
            }
            
            return [];
        } catch (error) {
            console.error('Error loading external weather alerts:', error);
            return [];
        }
    }
    
    formatExternalAlerts(externalAlerts) {
        // Convert external alert format to match database alert format
        return externalAlerts.map((alert, index) => ({
            id: `external_${Date.now()}_${index}`,
            type: alert.type || 'severe_weather',
            severity: alert.severity || 'medium',
            description: alert.description || alert.title || 'Severe weather alert',
            message: alert.description || alert.title || 'Severe weather alert',
            status: 'active',
            created_at: alert.effective || new Date().toISOString(),
            is_external: true,
            urgency: alert.urgency || 'expected',
            category: alert.category || 'severe_weather',
            title: alert.title || alert.type || 'Severe Weather Alert',
            icon: this.getAlertIcon(alert),
            time_ago: this.getTimeAgo(alert.effective || new Date().toISOString())
        }));
    }
    
    combineAlerts(databaseAlerts, externalAlerts) {
        // Combine and sort alerts by created_at (most recent first)
        const allAlerts = [...databaseAlerts, ...externalAlerts];
        
        // Sort by created_at descending
        allAlerts.sort((a, b) => {
            const dateA = new Date(a.created_at || a.time_ago || 0);
            const dateB = new Date(b.created_at || b.time_ago || 0);
            return dateB - dateA;
        });
        
        return allAlerts;
    }
    
    displayExternalAlerts(alerts) {
        // Display alerts in a prominent banner or notification area
        const alertBanner = document.getElementById('weatherAlertsBanner');
        if (!alertBanner) {
            // Create banner if it doesn't exist
            const banner = document.createElement('div');
            banner.id = 'weatherAlertsBanner';
            banner.className = 'weather-alerts-banner';
            document.body.insertBefore(banner, document.body.firstChild);
        }
        
        const banner = document.getElementById('weatherAlertsBanner');
        const highPriorityAlerts = alerts.filter(a => a.severity === 'high' || a.urgency === 'immediate');
        
        if (highPriorityAlerts.length > 0) {
            banner.innerHTML = `
                <div class="alert-banner-content">
                    <div class="alert-banner-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="alert-banner-text">
                        <strong>Severe Weather Alert:</strong> ${highPriorityAlerts[0].title} - ${highPriorityAlerts[0].description}
                    </div>
                    <button class="alert-banner-close" onclick="this.parentElement.parentElement.style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            banner.style.display = 'block';
        } else {
            banner.style.display = 'none';
        }
    }
    
    getUserLocation() {
        // Try to get location from session storage or current user
        try {
            const stored = sessionStorage.getItem('userLocation');
            if (stored) {
                return JSON.parse(stored);
            }
            
            // Try to get from current user data
            if (this.currentUser && this.currentUser.latitude && this.currentUser.longitude) {
                return {
                    latitude: parseFloat(this.currentUser.latitude),
                    longitude: parseFloat(this.currentUser.longitude)
                };
            }
        } catch (e) {
            // Ignore
        }
        return null;
    }
    
    getAlertIcon(alert) {
        const type = (alert.type || '').toLowerCase();
        const category = (alert.category || '').toLowerCase();
        const weatherCondition = (alert.weather_condition || '').toLowerCase();
        
        // Check for forecast weather conditions first
        if (weatherCondition === 'sunny' || (type === 'forecast' && weatherCondition.includes('sunny'))) {
            return 'fas fa-sun';
        } else if (weatherCondition === 'slight_rain' || weatherCondition.includes('slight')) {
            return 'fas fa-cloud-sun-rain';
        } else if (weatherCondition === 'moderate_rain' || weatherCondition.includes('moderate')) {
            return 'fas fa-cloud-rain';
        } else if (weatherCondition === 'strong_rain' || weatherCondition.includes('strong')) {
            return 'fas fa-cloud-showers-heavy';
        } else if (type.includes('typhoon') || type.includes('storm') || category.includes('typhoon')) {
            return 'fas fa-hurricane';
        } else if (type.includes('flood') || type.includes('rain') || category.includes('flood')) {
            return 'fas fa-cloud-rain';
        } else if (type.includes('wind') || category.includes('wind')) {
            return 'fas fa-wind';
        } else if (type.includes('heat') || type.includes('temperature')) {
            return 'fas fa-thermometer-half';
        } else if (type.includes('drought')) {
            return 'fas fa-sun';
        } else if (type.includes('frost')) {
            return 'fas fa-snowflake';
        } else if (type === 'forecast' || category === 'weather_forecast') {
            return 'fas fa-calendar-day';
        } else {
            return 'fas fa-exclamation-triangle';
        }
    }
    
    getTimeAgo(datetime) {
        if (!datetime) return 'Just now';
        
        const time = new Date() - new Date(datetime);
        const seconds = Math.floor(time / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        
        if (days > 0) return `${days} day${days > 1 ? 's' : ''} ago`;
        if (hours > 0) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
        if (minutes > 0) return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
        return 'Just now';
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    displayAlerts(alerts) {
        const container = document.querySelector('#alerts .alerts-container');
        if (!container) {
            console.error('Alerts container not found: #alerts .alerts-container');
            return;
        }
        
        // Ensure alerts is an array
        if (!alerts || !Array.isArray(alerts)) {
            console.warn('Alerts is not an array:', alerts);
            alerts = [];
        }
        
        console.log(`Displaying ${alerts.length} alerts in container`);
        
        if (alerts.length === 0) {
            container.innerHTML = `
                <div class="no-alerts" style="text-align: center; padding: 2rem;">
                    <i class="fas fa-check-circle" style="font-size: 3rem; color: #28a745; margin-bottom: 1rem;"></i>
                    <h3 style="margin-bottom: 0.5rem;">No Active Weather Alerts</h3>
                    <p style="color: #666; margin-bottom: 1rem;">There are currently no active weather alerts in the system.</p>
                    <p style="color: #666; margin-bottom: 0.5rem; font-size: 0.9rem;">All active alerts from all areas are displayed here to keep you informed about weather conditions across different regions.</p>
                    <p style="color: #666; margin-bottom: 1.5rem;">Check <a href="#weather-history" onclick="app.showSection('weather-history')" style="color: var(--primary-color); text-decoration: underline;">Weather History</a> to view all past alerts and weather records.</p>
                </div>
            `;
            return;
        }

        // Store alerts for modal access
        this.currentAlertsList = alerts;
        
        container.innerHTML = alerts.map((alert, index) => {
            const icon = alert.icon || this.getAlertIcon(alert);
            const isExternal = alert.is_external || false;
            const isForecast = alert.type === 'forecast' || alert.category === 'weather_forecast';
            const alertType = alert.type || 'severe_weather';
            const alertDescription = alert.description || alert.message || 'No description available';
            const alertTitle = alert.title || alert.type || 'Alert';
            const timeAgo = alert.time_ago || this.getTimeAgo(alert.created_at);
            
            return `
            <div class="alert-item ${alert.severity} ${isExternal ? 'external-alert' : ''} ${isForecast ? 'forecast-alert' : ''}" onclick="app.showAlertDetailFromList(${index})">
                <div class="alert-icon">
                    <i class="${icon}"></i>
                </div>
                <div class="alert-content">
                    <h3>${this.escapeHtml(alertTitle)} ${isExternal ? '<span class="external-badge">Live</span>' : ''} ${isForecast ? '<span class="forecast-badge" style="background: #2196f3; color: white; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.75rem; margin-left: 0.5rem;">Forecast</span>' : ''}</h3>
                    <p>${this.escapeHtml(alertDescription)}</p>
                    <span class="alert-time">
                        <i class="fas fa-clock"></i> ${timeAgo}
                        ${isExternal && alert.urgency ? ` • <span class="urgency-badge ${alert.urgency}">${alert.urgency}</span>` : ''}
                        ${isForecast && alert.forecast_date ? ` • <span class="forecast-date-badge">${alert.forecast_date}</span>` : ''}
                    </span>
                </div>
            </div>
            `;
        }).join('');
    }
    
    displayDashboardAlerts(alerts) {
        // Display alerts in the dashboard alerts section
        const container = document.getElementById('activeAlerts');
        if (!container) return;
        
        // Ensure alerts is an array
        if (!alerts || !Array.isArray(alerts)) {
            alerts = [];
        }
        
        // Show high priority alerts AND forecast alerts (tomorrow's weather) on dashboard (max 5)
        // Priority: high severity alerts first, then forecast alerts
        const highPriorityAlerts = alerts.filter(alert => 
            alert.severity === 'high' || alert.urgency === 'immediate'
        );
        
        const forecastAlerts = alerts.filter(alert => 
            alert.type === 'forecast' || alert.category === 'weather_forecast'
        );
        
        // Combine: high priority first, then forecast alerts
        const displayAlerts = [...highPriorityAlerts, ...forecastAlerts].slice(0, 5);
        
        if (displayAlerts.length === 0) {
            container.innerHTML = '<div class="no-alerts">No active alerts</div>';
            return;
        }
        
        // Store alerts for modal access
        this.currentAlerts = displayAlerts;
        
        container.innerHTML = displayAlerts.map((alert, index) => {
            const icon = alert.icon || this.getAlertIcon(alert);
            const isExternal = alert.is_external || false;
            const isForecast = alert.type === 'forecast' || alert.category === 'weather_forecast';
            const alertTitle = alert.title || alert.type || 'Alert';
            const alertDescription = alert.description || alert.message || 'No description available';
            const alertId = alert.id || `alert_${index}`;
            
            return `
            <div class="alert-item ${alert.severity} ${isExternal ? 'external-alert' : ''} ${isForecast ? 'forecast-alert' : ''}" style="margin-bottom: 0.75rem;" onclick="app.showAlertDetail(${index})">
                <div class="alert-icon">
                    <i class="${icon}"></i>
                </div>
                <div class="alert-content">
                    <h4 style="margin: 0 0 0.25rem 0; font-size: 0.9rem;">${this.escapeHtml(alertTitle)} ${isExternal ? '<span class="external-badge" style="font-size: 0.6rem; padding: 0.15rem 0.4rem;">Live</span>' : ''} ${isForecast ? '<span class="forecast-badge" style="font-size: 0.6rem; padding: 0.15rem 0.4rem; background: #2196f3; color: white; border-radius: 3px;">Forecast</span>' : ''}</h4>
                    <p style="margin: 0; font-size: 0.85rem; color: #666;">${this.escapeHtml(alertDescription.substring(0, 100))}${alertDescription.length > 100 ? '...' : ''}</p>
                </div>
            </div>
            `;
        }).join('');
    }

    async loadWeatherHistory(limit = null) {
        const container = document.getElementById('weatherHistoryContainer');
        if (!container) return;
        
        // Show appropriate loading message
        const loadingMsg = (limit === 'all') ? 'Loading all weather history...' : 'Loading weather history...';
        container.innerHTML = `<div class="loading">${loadingMsg}</div>`;
        
        // Try multiple methods to get user ID
        let userId = this.getCurrentUserId();
        
        // If not found, try to get from currentUser
        if (!userId && this.currentUser) {
            userId = this.currentUser.id;
        }
        
        // If still not found, try localStorage
        if (!userId) {
            const storedUser = localStorage.getItem('currentUser');
            if (storedUser) {
                try {
                    const user = JSON.parse(storedUser);
                    userId = user.id;
                } catch (e) {
                    console.error('Error parsing stored user:', e);
                }
            }
        }
        
        if (!userId) {
            container.innerHTML = '<div class="no-alerts">User ID not found. Please log in again.</div>';
            console.error('User ID not found. Current user:', this.currentUser);
            return;
        }
        
        console.log('Loading weather history for user ID:', userId);

        try {
            const statusFilter = document.getElementById('historyStatusFilter')?.value || '';
            // When limit is explicitly 'all', use it; otherwise use limit param, input value, or default 500
            const limitValue = (limit === 'all') ? 'all' : (limit || document.getElementById('historyLimitInput')?.value || 500);
            
            let url = `api/user-alerts.php?user_id=${userId}`;
            // If no status filter, get all alerts (active, resolved, cancelled)
            if (statusFilter) {
                url += `&status=${statusFilter}`;
            } else {
                // Pass empty status to get all alerts
                url += `&status=`;
            }
            // Always add limit parameter - use 'all' string when limitValue is 'all'
            if (limitValue === 'all') {
                url += `&limit=all`;
            } else {
                url += `&limit=${limitValue}`;
            }

            console.log('Fetching weather history from:', url);
            const response = await fetch(url);
            const data = await response.json();
            
            console.log('Weather history response:', data);

            if (data.success) {
                const alerts = data.data || [];
                console.log(`Loaded ${alerts.length} alerts (total: ${data.total || 'unknown'})`);
                this.displayWeatherHistory(alerts, data.total, data.count);
            } else {
                console.error('Failed to load weather history:', data.message);
                container.innerHTML = `<div class="no-alerts">Failed to load weather history: ${data.message || 'Unknown error'}</div>`;
            }
        } catch (error) {
            console.error('Error loading weather history:', error);
            container.innerHTML = `<div class="no-alerts">Error loading weather history: ${error.message}. Please check the console for details.</div>`;
        }
    }

    displayWeatherHistory(alerts, totalCount = null, displayedCount = null) {
        const container = document.getElementById('weatherHistoryContainer');
        const infoDiv = document.getElementById('weatherHistoryInfo');
        
        if (!container) return;
        
        // Show/hide info div
        if (infoDiv && totalCount !== null) {
            document.getElementById('totalAlertsCount').textContent = totalCount;
            document.getElementById('displayedAlertsCount').textContent = displayedCount || alerts.length;
            infoDiv.style.display = 'block';
        } else if (infoDiv) {
            infoDiv.style.display = 'none';
        }
        
        // Ensure alerts is an array
        if (!alerts || !Array.isArray(alerts)) {
            alerts = [];
        }
        
        if (alerts.length === 0) {
            container.innerHTML = '<div class="no-alerts">No weather history found</div>';
            return;
        }

        // Apply severity filter if set
        const severityFilter = document.getElementById('historySeverityFilter')?.value || '';
        let filteredAlerts = alerts;
        if (severityFilter) {
            filteredAlerts = alerts.filter(alert => alert.severity === severityFilter);
        }

        if (filteredAlerts.length === 0 && severityFilter) {
            container.innerHTML = `<div class="no-alerts">No alerts found with severity: ${severityFilter}</div>`;
            return;
        }

        container.innerHTML = filteredAlerts.map(alert => {
            const icon = alert.icon || this.getAlertIcon(alert);
            const alertType = alert.type || 'severe_weather';
            const alertDescription = alert.description || alert.message || 'No description available';
            const alertTitle = alert.title || alert.type || 'Alert';
            const timeAgo = alert.time_ago || this.getTimeAgo(alert.created_at);
            const createdDate = alert.created_at ? new Date(alert.created_at).toLocaleString() : 'Unknown';
            
            return `
            <div class="alert-item ${alert.severity}" onclick="app.showAlertDetailFromHistory(${filteredAlerts.indexOf(alert)})">
                <div class="alert-icon">
                    <i class="${icon}"></i>
                </div>
                <div class="alert-content">
                    <h3>${this.escapeHtml(alertTitle)}</h3>
                    <p>${this.escapeHtml(alertDescription)}</p>
                    <span class="alert-time">
                        <i class="fas fa-clock"></i> ${timeAgo} (${createdDate})
                        <span class="badge ${alert.status}" style="margin-left: 0.5rem; padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                            ${alert.status.toUpperCase()}
                        </span>
                    </span>
                </div>
            </div>
            `;
        }).join('');
    }

    filterWeatherHistory() {
        this.loadWeatherHistory();
    }

    refreshWeatherHistory() {
        this.loadWeatherHistory();
    }

    getCurrentUserId() {
        // Try to get user ID from localStorage first
        const storedUser = localStorage.getItem('currentUser');
        if (storedUser) {
            const user = JSON.parse(storedUser);
            return user.id;
        }
        
        // Fallback: try to get from session or other sources
        // This would need to be implemented based on your authentication system
        return null;
    }

    async trackActivity(activityType) {
        try {
            const userId = this.getCurrentUserId();
            if (!userId) return;

            await fetch('api/track-activity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    user_id: userId,
                    activity_type: activityType
                })
            });
        } catch (error) {
            // Silently fail - activity tracking shouldn't break the app
            console.log('Activity tracking failed:', error);
        }
    }

    async loadMarketTiming() {
        const container = document.getElementById('marketTimingContainer');
        if (!container) return;

        try {
            container.innerHTML = '<div class="loading">Loading market timing recommendations...</div>';
            
            // Include credentials to send session cookie
            const response = await fetch('api/market-timing.php', {
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('Market Timing API Response:', data); // Debug log

            if (data.success) {
                if (data.data && data.data.crops && data.data.crops.length > 0) {
                    this.renderMarketTiming(data.data);
                } else {
                    container.innerHTML = `
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            ${data.message || data.data?.message || 'No crops approaching harvest. Add crops to get market timing recommendations.'}
                        </div>
                    `;
                }
            } else {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        ${data.message || 'Failed to load market timing recommendations.'}
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading market timing:', error);
            container.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load market timing data: ${error.message}. Please try again later.
                </div>
            `;
        }
    }

    async loadHarvestPrescriptions(forceRefresh = false) {
        const container = document.getElementById('harvestPrescriptionsContainer');
        if (!container) return;

        try {
            if (!forceRefresh) {
                container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Analyzing your crops and generating harvest recommendations...</div>';
            }
            
            const response = await fetch('api/harvest-decision-prescription.php', {
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // Get response text first to handle potential JSON parsing errors
            const responseText = await response.text();
            let data;
            
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError);
                console.error('Response Text:', responseText.substring(0, 500)); // Log first 500 chars
                throw new Error(`Invalid JSON response: ${parseError.message}`);
            }
            
            console.log('Harvest Prescription API Response:', data);

            if (data.success) {
                if (data.data && data.data.length > 0) {
                    this.renderHarvestPrescriptions(data.data);
                } else {
                    container.innerHTML = `
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            No active crops found. Add crops to get harvest decision prescriptions.
                        </div>
                    `;
                }
            } else {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        ${data.message || 'Failed to load harvest prescriptions.'}
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading harvest prescriptions:', error);
            container.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    Failed to load harvest prescriptions. Please try again later.
                </div>
            `;
        }
    }

    renderHarvestPrescriptions(prescriptions) {
        const container = document.getElementById('harvestPrescriptionsContainer');
        if (!container) return;

        container.innerHTML = prescriptions.map(prescription => {
            const priorityClass = prescription.recommendation.priority === 'high' ? 'high-priority' : 
                                 prescription.recommendation.priority === 'medium' ? 'medium-priority' : 'low-priority';
            
            const priorityIcon = prescription.recommendation.priority === 'high' ? 'fa-exclamation-circle' : 
                                prescription.recommendation.priority === 'medium' ? 'fa-exclamation-triangle' : 'fa-info-circle';
            
            const riskLevel = prescription.weather_risks.overall_risk_level;
            const riskColor = riskLevel === 'high' ? '#f44336' : riskLevel === 'medium' ? '#ff9800' : '#4caf50';
            
            let scenariosHtml = '';
            if (prescription.scenarios) {
                scenariosHtml = `
                    <div class="scenarios-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                        ${prescription.scenarios.harvest_now ? `
                            <div class="scenario-card" style="border: 1px solid #ddd; padding: 1rem; border-radius: 8px;">
                                <h4 style="margin: 0 0 0.5rem 0; color: #2196f3;">Harvest Now</h4>
                                <p style="margin: 0.25rem 0;"><strong>Maturity:</strong> ${prescription.scenarios.harvest_now.maturity_percent}%</p>
                                <p style="margin: 0.25rem 0;"><strong>Yield:</strong> ${prescription.scenarios.harvest_now.yield_tons} tons</p>
                                <p style="margin: 0.25rem 0;"><strong>Value:</strong> ₱${prescription.scenarios.harvest_now.value_php.toLocaleString()}</p>
                            </div>
                        ` : ''}
                        ${prescription.scenarios.optimal_harvest ? `
                            <div class="scenario-card" style="border: 2px solid #4caf50; padding: 1rem; border-radius: 8px; background: #f1f8f4;">
                                <h4 style="margin: 0 0 0.5rem 0; color: #4caf50;"><i class="fas fa-star"></i> Optimal Harvest</h4>
                                <p style="margin: 0.25rem 0;"><strong>Date:</strong> ${prescription.scenarios.optimal_harvest.date}</p>
                                <p style="margin: 0.25rem 0;"><strong>Maturity:</strong> ${prescription.scenarios.optimal_harvest.maturity_percent}%</p>
                                <p style="margin: 0.25rem 0;"><strong>Yield:</strong> ${prescription.scenarios.optimal_harvest.yield_tons} tons</p>
                                <p style="margin: 0.25rem 0;"><strong>Value:</strong> ₱${prescription.scenarios.optimal_harvest.value_php.toLocaleString()}</p>
                            </div>
                        ` : ''}
                        ${prescription.scenarios.wait_full_maturity ? `
                            <div class="scenario-card" style="border: 1px solid #ddd; padding: 1rem; border-radius: 8px;">
                                <h4 style="margin: 0 0 0.5rem 0; color: #666;">Wait (No Risk)</h4>
                                <p style="margin: 0.25rem 0;"><strong>Days to Maturity:</strong> ${prescription.scenarios.wait_full_maturity.days_to_maturity}</p>
                                <p style="margin: 0.25rem 0;"><strong>Yield:</strong> ${prescription.scenarios.wait_full_maturity.yield_tons} tons</p>
                                <p style="margin: 0.25rem 0;"><strong>Value:</strong> ₱${prescription.scenarios.wait_full_maturity.value_php.toLocaleString()}</p>
                            </div>
                        ` : ''}
                        ${prescription.scenarios.wait_risk_damage ? `
                            <div class="scenario-card" style="border: 1px solid #f44336; padding: 1rem; border-radius: 8px; background: #ffebee;">
                                <h4 style="margin: 0 0 0.5rem 0; color: #f44336;">Wait (Risk Damage)</h4>
                                <p style="margin: 0.25rem 0;"><strong>Damage Risk:</strong> ${prescription.scenarios.wait_risk_damage.damage_percent}%</p>
                                <p style="margin: 0.25rem 0;"><strong>Yield:</strong> ${prescription.scenarios.wait_risk_damage.yield_tons} tons</p>
                                <p style="margin: 0.25rem 0;"><strong>Value:</strong> ₱${prescription.scenarios.wait_risk_damage.value_php.toLocaleString()}</p>
                            </div>
                        ` : ''}
                    </div>
                `;
            }
            
            let financialHtml = '';
            if (prescription.financial_analysis && Object.keys(prescription.financial_analysis).length > 0) {
                const fa = prescription.financial_analysis;
                financialHtml = `
                    <div class="financial-analysis" style="margin-top: 1rem; padding: 1rem; background: #e3f2fd; border-radius: 8px; border-left: 4px solid #2196f3;">
                        <h4 style="margin: 0 0 0.5rem 0;"><i class="fas fa-money-bill-wave"></i> Financial Analysis</h4>
                        ${fa.recommended_value ? `<p style="margin: 0.25rem 0;"><strong>Recommended Value:</strong> ₱${fa.recommended_value.toLocaleString()}</p>` : ''}
                        ${fa.risk_scenario_value ? `<p style="margin: 0.25rem 0;"><strong>Risk Scenario Value:</strong> ₱${fa.risk_scenario_value.toLocaleString()}</p>` : ''}
                        ${fa.net_benefit ? `<p style="margin: 0.25rem 0; color: #4caf50; font-weight: bold;"><strong>Net Benefit:</strong> ₱${fa.net_benefit.toLocaleString()}</p>` : ''}
                        ${fa.benefit_percentage ? `<p style="margin: 0.25rem 0; color: #4caf50;"><strong>Benefit:</strong> +${fa.benefit_percentage}%</p>` : ''}
                    </div>
                `;
            }
            
            let weatherRisksHtml = '';
            if (prescription.weather_risks) {
                const risks = prescription.weather_risks;
                weatherRisksHtml = `
                    <div class="weather-risks" style="margin-top: 1rem;">
                        <h4 style="margin: 0 0 0.5rem 0;"><i class="fas fa-cloud-rain"></i> Weather Risks</h4>
                        <p style="margin: 0.25rem 0;">
                            <strong>Overall Risk Level:</strong> 
                            <span style="color: ${riskColor}; font-weight: bold; text-transform: uppercase;">${riskLevel}</span>
                        </p>
                        ${risks.highest_risk_date ? `<p style="margin: 0.25rem 0;"><strong>Highest Risk Date:</strong> ${risks.highest_risk_date}</p>` : ''}
                        ${risks.heavy_rain && risks.heavy_rain.length > 0 ? `
                            <div style="margin-top: 0.5rem;">
                                <strong>Heavy Rain Warnings:</strong>
                                <ul style="margin: 0.25rem 0; padding-left: 1.5rem;">
                                    ${risks.heavy_rain.map(r => `<li>${r.date}: ${r.rainfall}mm (${r.severity})</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        ${risks.wind_damage && risks.wind_damage.length > 0 ? `
                            <div style="margin-top: 0.5rem;">
                                <strong>Wind Damage Warnings:</strong>
                                <ul style="margin: 0.25rem 0; padding-left: 1.5rem;">
                                    ${risks.wind_damage.map(r => `<li>${r.date}: ${r.wind_speed} km/h (${r.severity})</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                    </div>
                `;
            }
            
            return `
                <div class="prescription-card" style="border: 2px solid ${riskColor}; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div class="prescription-header" style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #eee;">
                        <div>
                            <h3 style="margin: 0 0 0.5rem 0; color: #333;">
                                <i class="fas fa-seedling" style="color: #4caf50;"></i> ${prescription.crop_name}
                            </h3>
                            <p style="margin: 0; color: #666;">Area: ${prescription.area_hectares} hectares | Price: ₱${prescription.crop_price_per_kg}/kg</p>
                        </div>
                        <div class="priority-badge" style="padding: 0.5rem 1rem; border-radius: 20px; background: ${riskColor}; color: white; font-weight: bold;">
                            <i class="fas ${priorityIcon}"></i> ${prescription.recommendation.priority.toUpperCase()}
                        </div>
                    </div>
                    
                    <div class="current-status" style="margin-bottom: 1rem; padding: 1rem; background: #f5f5f5; border-radius: 8px;">
                        <h4 style="margin: 0 0 0.5rem 0;"><i class="fas fa-chart-line"></i> Current Status</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                            <div>
                                <strong>Maturity:</strong> ${prescription.current_status.maturity_percent}%
                                <div style="width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; margin-top: 0.25rem;">
                                    <div style="width: ${prescription.current_status.maturity_percent}%; height: 100%; background: #4caf50; border-radius: 4px;"></div>
                                </div>
                            </div>
                            <div><strong>Days Planted:</strong> ${prescription.current_status.days_since_planting}</div>
                            <div><strong>Days to Maturity:</strong> ${prescription.current_status.days_to_full_maturity}</div>
                            <div><strong>Health:</strong> <span style="text-transform: capitalize;">${prescription.current_status.health_status}</span></div>
                        </div>
                    </div>
                    
                    <div class="recommendation" style="margin-bottom: 1rem; padding: 1rem; background: ${priorityClass === 'high-priority' ? '#ffebee' : priorityClass === 'medium-priority' ? '#fff3e0' : '#e8f5e9'}; border-radius: 8px; border-left: 4px solid ${riskColor};">
                        <h4 style="margin: 0 0 0.5rem 0;">
                            <i class="fas ${priorityIcon}" style="color: ${riskColor};"></i> Recommendation
                        </h4>
                        <p style="margin: 0.5rem 0; font-weight: bold; font-size: 1.1em;">${prescription.recommendation.action_text}</p>
                        ${prescription.recommendation.deadline ? `<p style="margin: 0.5rem 0; color: ${riskColor};"><strong>Deadline:</strong> ${prescription.recommendation.deadline}</p>` : ''}
                        <div style="margin-top: 0.5rem;">
                            <strong>Rationale:</strong>
                            <ul style="margin: 0.25rem 0; padding-left: 1.5rem;">
                                ${prescription.recommendation.rationale.map(r => `<li>${r}</li>`).join('')}
                            </ul>
                        </div>
                    </div>
                    
                    ${scenariosHtml}
                    ${financialHtml}
                    ${weatherRisksHtml}
                    
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; color: #999; font-size: 0.9em;">
                        Generated: ${new Date(prescription.generated_at).toLocaleString()}
                    </div>
                </div>
            `;
        }).join('');
    }

    renderMarketTiming(data) {
        const container = document.getElementById('marketTimingContainer');
        if (!container || !data.crops || data.crops.length === 0) {
            container.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No crops approaching harvest. Add crops to get market timing recommendations.
                </div>
            `;
            return;
        }

        let html = '<div class="market-timing-grid">';

        data.crops.forEach((crop, index) => {
            const harvestTiming = crop.harvest_timing || {};
            const sellTiming = crop.sell_timing || {};
            const priceAnalysis = crop.price_analysis || {};
            const opportunities = crop.market_opportunities || {};
            const actionItems = crop.action_items || [];

            html += `
                <div class="market-timing-card">
                    <div class="card-header-market">
                        <h3><i class="fas fa-seedling"></i> ${crop.crop_name}</h3>
                        <span class="crop-status badge badge-${crop.current_status}">${crop.current_status}</span>
                    </div>
                    
                    <div class="crop-info-row">
                        <div class="info-item">
                            <span class="info-label">Area:</span>
                            <span class="info-value">${crop.area_hectares} hectares</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Days to Maturity:</span>
                            <span class="info-value">${crop.days_to_maturity} days</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Expected Yield:</span>
                            <span class="info-value">${crop.expected_yield_kg ? crop.expected_yield_kg.toLocaleString() : 'N/A'} kg</span>
                        </div>
                    </div>

                    <!-- Real-Time Crop Price -->
                    ${crop.current_price_per_kg ? `
                    <div class="current-price-section">
                        <div class="price-header">
                            <i class="fas fa-tag"></i>
                            <span>Current Market Price</span>
                            <span class="price-badge price-source-${crop.price_source || 'default'}">${crop.price_source === 'api-ninjas' ? 'Live' : crop.price_source === 'api' || crop.price_source === 'commodities-api' || crop.price_source === 'alphavantage' || crop.price_source === 'twelvedata' ? 'API' : crop.price_source === 'database' ? 'Cached' : crop.price_source === 'calculated-trend' || crop.price_source === 'calculated-seasonal' || crop.price_source === 'calculated-seasonal-default' ? 'Calculated' : crop.price_source === 'calculated' ? 'Calculated' : 'Estimate'}</span>
                        </div>
                        <div class="price-display">
                            <span class="price-value">PHP ${crop.current_price_per_kg.toFixed(2)}</span>
                            <span class="price-unit">/ kg</span>
                        </div>
                        <div class="price-meta">
                            <small>Updated: ${crop.price_date || 'Today'} | Source: ${crop.price_source || 'default'}</small>
                        </div>
                    </div>
                    ` : ''}

                    <!-- Monitor Price Trends Daily -->
                    ${priceAnalysis && (priceAnalysis.historical_prices || priceAnalysis.forecasts) ? `
                    <div class="timing-section price-trends-section">
                        <div class="section-header-trends">
                            <h4><i class="fas fa-chart-line"></i> Monitor Price Trends Daily</h4>
                            ${priceAnalysis.price_change_percent !== undefined ? `
                            <div class="trend-indicator trend-${priceAnalysis.trend?.direction || 'stable'}">
                                <i class="fas ${priceAnalysis.trend?.direction === 'increasing' ? 'fa-arrow-up' : priceAnalysis.trend?.direction === 'decreasing' ? 'fa-arrow-down' : 'fa-minus'}"></i>
                                <span>${priceAnalysis.price_change_percent > 0 ? '+' : ''}${priceAnalysis.price_change_percent?.toFixed(2) || '0.00'}%</span>
                            </div>
                            ` : ''}
                        </div>
                        
                        <div class="price-trends-chart-container">
                            <canvas id="priceTrendsChart_${index}" class="price-trends-chart"></canvas>
                        </div>
                        
                        <div class="trend-summary">
                            ${priceAnalysis.price_change !== undefined ? `
                            <div class="trend-stat">
                                <span class="trend-label">Price Change:</span>
                                <span class="trend-value ${priceAnalysis.price_change >= 0 ? 'positive' : 'negative'}">
                                    ${priceAnalysis.price_change >= 0 ? '+' : ''}PHP ${priceAnalysis.price_change.toFixed(2)}
                                </span>
                            </div>
                            ` : ''}
                            ${priceAnalysis.trend?.direction ? `
                            <div class="trend-stat">
                                <span class="trend-label">Trend:</span>
                                <span class="trend-value trend-${priceAnalysis.trend.direction}">
                                    ${priceAnalysis.trend.direction.charAt(0).toUpperCase() + priceAnalysis.trend.direction.slice(1)}
                                </span>
                            </div>
                            ` : ''}
                            ${priceAnalysis.forecasts && priceAnalysis.forecasts.length > 0 ? `
                            <div class="trend-stat">
                                <span class="trend-label">30-Day Forecast:</span>
                                <span class="trend-value">
                                    PHP ${priceAnalysis.forecasts[priceAnalysis.forecasts.length - 1]?.predicted_price?.toFixed(2) || 'N/A'}
                                </span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    ` : ''}

                    <!-- Harvest Timing -->
                    <div class="timing-section">
                        <h4><i class="fas fa-calendar-check"></i> Harvest Timing</h4>
                        <div class="timing-details">
                            <div class="timing-item">
                                <span class="timing-label">Optimal Harvest Date:</span>
                                <span class="timing-value highlight">${harvestTiming.optimal_date || 'N/A'}</span>
                            </div>
                            <div class="timing-item">
                                <span class="timing-label">Harvest Window:</span>
                                <span class="timing-value">${harvestTiming.harvest_window?.start || 'N/A'} to ${harvestTiming.harvest_window?.end || 'N/A'}</span>
                            </div>
                            <div class="timing-item">
                                <span class="timing-label">Score:</span>
                                <span class="timing-value">${harvestTiming.score || 'N/A'}/100</span>
                            </div>
                        </div>
                        ${harvestTiming.reasoning && harvestTiming.reasoning.length > 0 ? `
                            <div class="reasoning-box">
                                <strong>Reasoning:</strong>
                                <ul>
                                    ${harvestTiming.reasoning.map(r => `<li>${r}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                    </div>

                    <!-- Sell Timing -->
                    <div class="timing-section">
                        <h4><i class="fas fa-dollar-sign"></i> Sell Timing</h4>
                        <div class="sell-recommendation ${sellTiming.recommendation === 'sell_immediately' ? 'recommend-immediate' : 'recommend-store'}">
                            <div class="recommendation-badge">
                                <i class="fas ${sellTiming.recommendation === 'sell_immediately' ? 'fa-bolt' : 'fa-warehouse'}"></i>
                                ${sellTiming.recommendation === 'sell_immediately' ? 'Sell Immediately' : 'Store & Sell Later'}
                            </div>
                            <div class="recommendation-details">
                                <div class="detail-row">
                                    <span>Optimal Sell Date:</span>
                                    <strong>${sellTiming.optimal_sell_date || 'N/A'}</strong>
                                </div>
                                <div class="detail-row">
                                    <span>Days After Harvest:</span>
                                    <strong>${sellTiming.days_after_harvest || 0} days</strong>
                                </div>
                                <div class="detail-row">
                                    <span>Expected Revenue:</span>
                                    <strong class="revenue-highlight">PHP ${sellTiming.expected_revenue ? sellTiming.expected_revenue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00'}</strong>
                                </div>
                                <div class="detail-row">
                                    <span>Price Improvement:</span>
                                    <strong class="${sellTiming.price_improvement > 0 ? 'text-success' : 'text-danger'}">${sellTiming.price_improvement > 0 ? '+' : ''}${sellTiming.price_improvement || 0}%</strong>
                                </div>
                            </div>
                        </div>

                        ${sellTiming.revenue_calculation ? `
                            <div class="revenue-comparison">
                                <div class="comparison-item">
                                    <div class="comparison-label">Immediate Sell</div>
                                    <div class="comparison-value">PHP ${sellTiming.revenue_calculation.immediate_sell?.net_revenue?.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) || '0.00'}</div>
                                </div>
                                <div class="comparison-arrow"><i class="fas fa-arrow-right"></i></div>
                                <div class="comparison-item highlight">
                                    <div class="comparison-label">Optimal Sell</div>
                                    <div class="comparison-value">PHP ${sellTiming.revenue_calculation.optimal_sell?.net_revenue?.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) || '0.00'}</div>
                                    <div class="comparison-profit">+PHP ${sellTiming.revenue_calculation.optimal_sell?.additional_profit?.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) || '0.00'}</div>
                                </div>
                            </div>
                        ` : ''}
                    </div>

                    <!-- Market Opportunities -->
                    ${opportunities.opportunities && opportunities.opportunities.length > 0 ? `
                        <div class="timing-section">
                            <h4><i class="fas fa-chart-line"></i> Market Opportunities</h4>
                            <div class="opportunities-list">
                                ${opportunities.opportunities.slice(0, 3).map(opp => `
                                    <div class="opportunity-item">
                                        <div class="opportunity-date">${opp.date}</div>
                                        <div class="opportunity-price">PHP ${opp.price}/kg</div>
                                        <div class="opportunity-premium">+${opp.premium}% premium</div>
                                        <div class="opportunity-reason">${opp.reason}</div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}

                    <!-- Action Items -->
                    ${actionItems && actionItems.length > 0 ? `
                        <div class="timing-section">
                            <h4><i class="fas fa-tasks"></i> Action Items</h4>
                            <div class="action-items-list">
                                ${actionItems.map(item => `
                                    <div class="action-item priority-${item.priority}">
                                        <div class="action-header">
                                            <span class="priority-badge priority-${item.priority}">${item.priority.toUpperCase()}</span>
                                            <span class="action-deadline">${item.deadline}</span>
                                        </div>
                                        <div class="action-title">${item.action}</div>
                                        <div class="action-details">${item.details}</div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        });

        html += '</div>';
        container.innerHTML = html;
        
        // Render price trends charts
        data.crops.forEach((crop, index) => {
            const priceAnalysis = crop.price_analysis || {};
            if (priceAnalysis.historical_prices || priceAnalysis.forecasts) {
                this.renderPriceTrendsChart(index, priceAnalysis, crop.crop_name);
            }
        });
    }

    renderPriceTrendsChart(index, priceAnalysis, cropName) {
        const canvasId = `priceTrendsChart_${index}`;
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        
        // Prepare data
        const historical = priceAnalysis.historical_prices || [];
        const forecasts = priceAnalysis.forecasts || [];
        
        // Get today's date for comparison
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        // Combine historical and forecast data on continuous timeline
        const allDates = [];
        const historicalPrices = [];
        const forecastPrices = [];
        
        // Add historical data (past dates up to today)
        historical.forEach(item => {
            const itemDate = new Date(item.date);
            itemDate.setHours(0, 0, 0, 0);
            if (itemDate <= today) {
                allDates.push(itemDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                historicalPrices.push(item.price);
                forecastPrices.push(null);
            }
        });
        
        // Add forecast data (future dates from today onwards)
        forecasts.forEach(item => {
            const itemDate = new Date(item.date);
            itemDate.setHours(0, 0, 0, 0);
            if (itemDate >= today) {
                allDates.push(itemDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                historicalPrices.push(null);
                forecastPrices.push(item.predicted_price);
            }
        });
        
        // If no data, show message
        if (allDates.length === 0) {
            return;
        }
        
        // Destroy existing chart if it exists
        if (this.priceTrendCharts && this.priceTrendCharts[index]) {
            this.priceTrendCharts[index].destroy();
        }
        
        if (!this.priceTrendCharts) {
            this.priceTrendCharts = {};
        }
        
        // Create new chart
        this.priceTrendCharts[index] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: allDates,
                datasets: [
                    {
                        label: 'Historical Prices',
                        data: historicalPrices,
                        borderColor: '#2196f3',
                        backgroundColor: 'rgba(33, 150, 243, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: historical.length > 0 ? 3 : 0,
                        pointHoverRadius: 5
                    },
                    {
                        label: 'Forecast',
                        data: forecastPrices,
                        borderColor: '#ff9800',
                        backgroundColor: 'rgba(255, 152, 0, 0.1)',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        fill: true,
                        tension: 0.4,
                        pointRadius: forecasts.length > 0 ? 3 : 0,
                        pointHoverRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': PHP ' + context.parsed.y.toFixed(2) + '/kg';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Date'
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45,
                            maxTicksLimit: 10
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Price (PHP/kg)'
                        },
                        beginAtZero: false
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
    }

    async loadProfile() {
        // Profile data is already loaded in updateUserInfo()
        // This could load additional profile data from API
    }

    async addCrop() {
        const form = document.getElementById('addCropForm');
        const formData = new FormData(form);
        
        try {
            const response = await fetch('api/user-crops.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    crop_name: formData.get('name'),
                    variety: formData.get('variety') || '',
                    planting_date: formData.get('planting_date'),
                    expected_harvest_date: formData.get('expected_harvest_date') || '',
                    area_hectares: parseFloat(formData.get('area')),
                    notes: formData.get('notes') || ''
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
            this.showNotification('Crop added successfully!', 'success');
            form.reset();
            this.closeModal('addCropModal');
                this.loadCrops(); // Reload crops to show the new one
            } else {
                this.showNotification(data.message, 'error');
            }
        } catch (error) {
            console.error('Error adding crop:', error);
            this.showNotification('Failed to add crop', 'error');
        }
    }

    async updateCrop() {
        const form = document.getElementById('editCropForm');
        const formData = new FormData(form);
        
        try {
            const response = await fetch('api/user-crops.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    crop_id: formData.get('crop_id'),
                    crop_name: formData.get('crop_name'),
                    variety: formData.get('variety') || '',
                    planting_date: formData.get('planting_date'),
                    expected_harvest_date: formData.get('expected_harvest_date') || '',
                    area_hectares: parseFloat(formData.get('area_hectares')),
                    status: formData.get('status') || 'planted',
                    health_status: formData.get('health_status') || 'good',
                    notes: formData.get('notes') || ''
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Crop updated successfully!', 'success');
                form.reset();
                this.closeModal('editCropModal');
                this.loadCrops(); // Reload crops to show the updated one
            } else {
                this.showNotification(data.message, 'error');
            }
        } catch (error) {
            console.error('Error updating crop:', error);
            this.showNotification('Failed to update crop', 'error');
        }
    }

    async updateProfile() {
        const form = document.getElementById('editProfileForm');
        const formData = new FormData(form);
        
        try {
            const response = await fetch('api/update-profile.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    full_name: formData.get('full_name'),
                    email: formData.get('email'),
                    phone: formData.get('phone'),
                    location: formData.get('location')
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Profile updated successfully!', 'success');
                this.closeModal('editProfileModal');
                // Update the displayed profile information
                this.updateUserInfo();
                // Reload the page to update all profile data
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                this.showNotification(data.message, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to update profile', 'error');
        }
    }

    updateCurrentDate() {
        const dateElement = document.getElementById('currentDate');
        if (dateElement) {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            dateElement.textContent = now.toLocaleDateString('en-US', options);
        }
    }

    showNotification(message, type) {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span>${message}</span>
        `;
        
        document.body.appendChild(notification);
        
        // Remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    showNotification(message, type) {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span>${message}</span>
        `;
        
        document.body.appendChild(notification);
        
        // Remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    async loadMarketPrices() {
        const container = document.getElementById('marketPricesContainer');
        if (!container) return;

        try {
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading market prices...</div>';
            
            const locationSelect = document.getElementById('marketPricesLocation');
            // Set default location to user's location if available
            if (locationSelect && this.currentUser && this.currentUser.location) {
                const userLocation = this.currentUser.location.split(',')[0].trim(); // Get first part of location
                // Check if user location exists in options, otherwise use Manila
                const optionExists = Array.from(locationSelect.options).some(opt => opt.value === userLocation);
                if (optionExists) {
                    locationSelect.value = userLocation;
                }
            }
            const location = locationSelect ? locationSelect.value : 'Manila';
            
            const response = await fetch(`api/crop-prices.php?action=all_market_prices&location=${encodeURIComponent(location)}`, {
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('Market Prices API Response:', data);

            if (data.success && data.prices && data.prices.length > 0) {
                this.marketPricesData = data.prices; // Store for filtering/sorting
                this.renderMarketPrices(data.prices, data);
            } else {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        ${data.message || 'No market prices available at this time.'}
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading market prices:', error);
            container.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load market prices: ${error.message}. Please try again later.
                </div>
            `;
        }
    }

    renderMarketPrices(prices, data) {
        const container = document.getElementById('marketPricesContainer');
        if (!container) return;

        // Update info display
        const infoEl = document.getElementById('marketPricesInfo');
        const updatedAtEl = document.getElementById('marketPricesUpdatedAt');
        const locationEl = document.getElementById('marketPricesLocationDisplay');
        const totalCropsEl = document.getElementById('marketPricesTotalCrops');
        
        if (infoEl) infoEl.style.display = 'block';
        if (updatedAtEl) updatedAtEl.textContent = data.updated_at || 'Just now';
        if (locationEl) locationEl.textContent = data.location || 'Manila';
        if (totalCropsEl) totalCropsEl.textContent = data.total_crops || prices.length;

        // Render prices grid
        if (prices.length === 0) {
            container.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No market prices available for the selected location.
                </div>
            `;
            return;
        }

        const pricesHTML = prices.map(price => {
            const trendIcon = price.trend === 'increasing' ? 'fa-arrow-up' : 
                             price.trend === 'decreasing' ? 'fa-arrow-down' : 'fa-minus';
            const trendColor = price.trend === 'increasing' ? 'text-success' : 
                              price.trend === 'decreasing' ? 'text-danger' : 'text-muted';
            const trendText = price.trend === 'increasing' ? 'Rising' : 
                             price.trend === 'decreasing' ? 'Falling' : 'Stable';
            
            const priceChangeClass = price.price_change_percent > 0 ? 'text-success' : 
                                    price.price_change_percent < 0 ? 'text-danger' : 'text-muted';
            const priceChangeSign = price.price_change_percent > 0 ? '+' : '';
            
            const sourceBadge = price.source === 'bantay-presyo' ? 'Official (DA)' :
                               price.source === 'api-ninjas' ? 'Live' : 
                               price.source === 'api' || price.source === 'commodities-api' || 
                               price.source === 'alphavantage' || price.source === 'twelvedata' ? 'API' : 
                               price.source === 'admin' ? 'Admin' :
                               price.source === 'database' ? 'Cached' : 
                               price.source.includes('calculated') ? 'Calculated' : 'Estimate';
            
            // Get accuracy level and badge
            const accuracy = price.accuracy || 'estimated';
            const accuracyConfig = {
                'high': { label: 'High Accuracy', class: 'accuracy-high', icon: 'fa-check-circle' },
                'medium': { label: 'Medium Accuracy', class: 'accuracy-medium', icon: 'fa-info-circle' },
                'low': { label: 'Low Accuracy', class: 'accuracy-low', icon: 'fa-exclamation-circle' },
                'estimated': { label: 'Estimated', class: 'accuracy-estimated', icon: 'fa-question-circle' }
            };
            const accInfo = accuracyConfig[accuracy] || accuracyConfig['estimated'];

            return `
                <div class="market-price-card" data-crop="${price.crop_name.toLowerCase()}">
                    <div class="market-price-header">
                        <h3 class="crop-name">${price.crop_name}</h3>
                        <div class="price-badges">
                            <span class="price-source-badge price-source-${price.source || 'default'}">${sourceBadge}</span>
                            <span class="accuracy-badge ${accInfo.class}" title="${accInfo.label}">
                                <i class="fas ${accInfo.icon}"></i>
                                <span>${accInfo.label}</span>
                            </span>
                        </div>
                    </div>
                    <div class="market-price-body">
                        <div class="price-display-large">
                            <span class="currency">PHP</span>
                            <span class="price-value">${price.price_per_kg.toFixed(2)}</span>
                            <span class="price-unit">/ kg</span>
                        </div>
                        <div class="price-trend">
                            <i class="fas ${trendIcon} ${trendColor}"></i>
                            <span class="${trendColor}">${trendText}</span>
                            ${price.price_change_percent !== 0 ? `
                                <span class="${priceChangeClass}" style="margin-left: 8px;">
                                    (${priceChangeSign}${price.price_change_percent}%)
                                </span>
                            ` : ''}
                        </div>
                        <div class="price-meta">
                            <div class="meta-item">
                                <i class="fas fa-calendar"></i>
                                <span>Updated: ${price.date || 'Today'}</span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-chart-bar"></i>
                                <span>Demand: ${price.demand_level || 'Medium'}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = `
            <div class="market-prices-grid">
                ${pricesHTML}
            </div>
        `;
    }
}

// Global functions
function showAddCropModal() {
    document.getElementById('addCropModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function refreshWeather() {
    if (window.farmerApp) {
        // If searching a location, refresh that location's weather
        if (window.farmerApp.isSearchingLocation && window.farmerApp.searchedLocation) {
            // Re-search the location
            const searchInput = document.getElementById('locationSearchInput');
            if (searchInput && searchInput.value) {
                searchLocationWeather();
            } else {
                window.farmerApp.fetchWeatherData();
            }
        } else {
            window.farmerApp.fetchWeatherData();
        }
    } else {
        window.location.reload();
    }
}

async function searchLocationWeather() {
    const searchInput = document.getElementById('locationSearchInput');
    if (!searchInput) return;
    
    const locationName = searchInput.value.trim();
    if (!locationName) {
        showNotification('Please enter a location name', 'error');
        return;
    }
    
    // Show loading state
    const container = document.getElementById('currentWeather');
    if (container) {
        container.innerHTML = '<div class="loading">Searching weather for ' + locationName + '...</div>';
    }
    
    try {
        // Geocode the location
        const geocodeResult = await APIUtils.geocode(locationName);
        
        if (!geocodeResult.success || !geocodeResult.data) {
            const errorMessage = geocodeResult.message || 'Location not found. Please try a different location name.';
            showNotification(errorMessage, 'error');
            if (container) {
                container.innerHTML = '<div class="error-message"><i class="fas fa-exclamation-triangle"></i><p>' + errorMessage + '</p></div>';
            }
            return;
        }
        
        const { latitude, longitude, display_name } = geocodeResult.data;
        
        // Fetch weather for this location
        if (window.farmerApp) {
            await window.farmerApp.fetchWeatherData(display_name || locationName, latitude, longitude);
            showNotification(`Weather loaded for ${display_name || locationName}`, 'success');
        } else {
            showNotification('Application not initialized', 'error');
        }
    } catch (error) {
        console.error('Error searching location weather:', error);
        const errorMessage = error.message || 'Failed to search location. Please try again.';
        showNotification(errorMessage, 'error');
        if (container) {
            container.innerHTML = '<div class="error-message"><i class="fas fa-exclamation-triangle"></i><p>' + errorMessage + '</p></div>';
        }
    }
}

function resetToMyLocation() {
    const searchInput = document.getElementById('locationSearchInput');
    if (searchInput) {
        searchInput.value = '';
    }
    
    if (window.farmerApp) {
        window.farmerApp.fetchWeatherData();
        showNotification('Reset to your location', 'success');
    }
}

function editProfile() {
    // Populate edit form with current user data
    if (window.farmerApp && window.farmerApp.currentUser) {
        const user = window.farmerApp.currentUser;
        document.getElementById('editFullName').value = user.full_name || '';
        document.getElementById('editEmail').value = user.email || '';
        document.getElementById('editPhone').value = user.phone || '';
        document.getElementById('editLocation').value = user.location || '';
        
        // Show edit modal
        document.getElementById('editProfileModal').style.display = 'block';
    } else {
        showNotification('User data not available', 'error');
    }
}

function toggleUserMenu() {
    const dropdown = document.getElementById('userDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

function showProfile() {
    // Implementation for showing profile
    console.log('Showing profile...');
}

function showSettings() {
    // Implementation for showing settings
    console.log('Showing settings...');
}

function logout() {
    fetch('api/auth.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'logout'
        })
    }).then(() => {
        window.location.href = 'login.html';
    });
}

function showNotification(message, type) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    
    // Remove after 3 seconds
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Alert Detail Modal Functions
FarmerApp.prototype.showAlertDetail = function(index) {
    if (!this.currentAlerts || !this.currentAlerts[index]) {
        console.error('Alert not found at index:', index);
        return;
    }
    
    const alert = this.currentAlerts[index];
    this.showAlertDetailModal(alert);
}

FarmerApp.prototype.showAlertDetailFromList = function(index) {
    if (!this.currentAlertsList || !this.currentAlertsList[index]) {
        console.error('Alert not found at index:', index);
        return;
    }
    
    const alert = this.currentAlertsList[index];
    this.showAlertDetailModal(alert);
}

FarmerApp.prototype.showAlertDetailFromHistory = function(index) {
    // Get alerts from history container
    const container = document.getElementById('weatherHistoryContainer');
    if (!container) return;
    
    // Find the alert element and get its data
    const alertItems = container.querySelectorAll('.alert-item');
    if (!alertItems[index]) return;
    
    // Reconstruct alert data from displayed content (limited approach)
    // Better: store alerts in a class property
    console.warn('Alert detail from history - using basic info');
}

FarmerApp.prototype.showAlertDetailModal = function(alert) {
    const modal = document.getElementById('alertDetailModal');
    if (!modal) return;
    
    // Set modal title and icon
    const icon = alert.icon || this.getAlertIcon(alert);
    const title = alert.title || alert.type || 'Alert';
    document.getElementById('alertModalIcon').className = icon;
    document.getElementById('alertModalTitleText').textContent = title;
    
    // Set severity badge
    const severityBadge = document.getElementById('alertModalSeverity');
    severityBadge.className = `alert-severity-badge ${alert.severity || 'medium'}`;
    document.getElementById('alertModalSeverityText').textContent = (alert.severity || 'medium').toUpperCase();
    
    // Set time
    const timeText = alert.time_ago || this.getTimeAgo(alert.created_at || alert.effective);
    document.getElementById('alertModalTimeText').textContent = timeText;
    
    // Set description
    const description = alert.description || alert.message || 'No description available';
    document.getElementById('alertModalDescription').innerHTML = `<p>${this.escapeHtml(description)}</p>`;
    
    // Set meta information
    document.getElementById('alertModalType').textContent = alert.type || '-';
    document.getElementById('alertModalStatus').textContent = (alert.status || 'active').toUpperCase();
    
    // Show/hide optional fields
    if (alert.urgency) {
        document.getElementById('alertModalUrgency').style.display = 'flex';
        document.getElementById('alertModalUrgencyText').textContent = alert.urgency;
    } else {
        document.getElementById('alertModalUrgency').style.display = 'none';
    }
    
    if (alert.category) {
        document.getElementById('alertModalCategory').style.display = 'flex';
        document.getElementById('alertModalCategoryText').textContent = alert.category;
    } else {
        document.getElementById('alertModalCategory').style.display = 'none';
    }
    
    // Show modal
    modal.classList.add('active');
    modal.style.display = 'block';
    
    // Close on background click
    modal.onclick = function(e) {
        if (e.target === modal) {
            closeAlertModal();
        }
    };
}

// Global function to close alert modal
function closeAlertModal() {
    const modal = document.getElementById('alertDetailModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-menu-toggle');
    
    if (!toggle.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

// Global functions for crop management
function editCrop(cropId) {
    // Find the crop data from the current crops list
    const cropsGrid = document.getElementById('cropsGrid');
    if (!cropsGrid) return;
    
    // Get crop data from the displayed crops (we need to fetch fresh data)
    fetchCropForEdit(cropId);
}

async function fetchCropForEdit(cropId) {
    try {
        const response = await fetch(`api/user-crops.php?crop_id=${cropId}`);
        const data = await response.json();
        
        if (data.success && data.data) {
            populateEditForm(data.data);
            document.getElementById('editCropModal').style.display = 'block';
        } else {
            showNotification(data.message || 'Failed to load crop data', 'error');
        }
    } catch (error) {
        console.error('Error fetching crop for edit:', error);
        showNotification('Failed to load crop data', 'error');
    }
}

function populateEditForm(crop) {
    document.getElementById('editCropId').value = crop.id;
    document.getElementById('editCropName').value = crop.crop_name || '';
    document.getElementById('editCropVariety').value = crop.variety || '';
    document.getElementById('editCropArea').value = crop.area_hectares || '';
    document.getElementById('editPlantingDate').value = crop.planting_date || '';
    document.getElementById('editExpectedHarvestDate').value = crop.expected_harvest_date || '';
    document.getElementById('editCropStatus').value = crop.status || 'planted';
    document.getElementById('editCropHealth').value = crop.health_status || 'good';
    document.getElementById('editCropNotes').value = crop.notes || '';
}

function viewCropDetails(cropId) {
    // Implementation for viewing crop details
    console.log('View crop details:', cropId);
    // TODO: Implement crop details modal
    showNotification('Crop details feature coming soon!', 'info');
}

async function deleteCrop(cropId) {
    if (!confirm('Are you sure you want to delete this crop? This action cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch('api/user-crops.php', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                crop_id: cropId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification('Crop deleted successfully!', 'success');
            // Reload crops to update the display
            if (window.farmerApp) {
                window.farmerApp.loadCrops();
            }
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        console.error('Error deleting crop:', error);
        showNotification('Failed to delete crop', 'error');
    }
}

// Global function to refresh market timing
async function refreshMarketTiming() {
    if (window.farmerApp) {
        await window.farmerApp.loadMarketTiming();
        if (typeof showNotification === 'function') {
            showNotification('Market timing data refreshed', 'success');
        }
    }
}

// Global function to load market prices
async function loadMarketPrices() {
    if (window.farmerApp) {
        await window.farmerApp.loadMarketPrices();
        if (typeof showNotification === 'function') {
            showNotification('Market prices refreshed', 'success');
        }
    }
}

// Global function to filter market prices
function filterMarketPrices() {
    const searchInput = document.getElementById('marketPricesSearch');
    const sortSelect = document.getElementById('marketPricesSort');
    const container = document.getElementById('marketPricesContainer');
    
    if (!searchInput || !container || !window.farmerApp || !window.farmerApp.marketPricesData) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    let filtered = window.farmerApp.marketPricesData.filter(price => 
        price.crop_name.toLowerCase().includes(searchTerm)
    );
    
    // Apply sorting
    const sortValue = sortSelect ? sortSelect.value : 'name';
    if (sortValue === 'price_asc') {
        filtered.sort((a, b) => a.price_per_kg - b.price_per_kg);
    } else if (sortValue === 'price_desc') {
        filtered.sort((a, b) => b.price_per_kg - a.price_per_kg);
    } else if (sortValue === 'trend') {
        filtered.sort((a, b) => {
            const trendOrder = { 'increasing': 0, 'stable': 1, 'decreasing': 2 };
            return (trendOrder[a.trend] || 1) - (trendOrder[b.trend] || 1);
        });
    } else {
        filtered.sort((a, b) => a.crop_name.localeCompare(b.crop_name));
    }
    
    // Re-render with filtered data
    const data = {
        updated_at: document.getElementById('marketPricesUpdatedAt')?.textContent || 'Just now',
        location: document.getElementById('marketPricesLocationDisplay')?.textContent || 'Manila',
        total_crops: filtered.length
    };
    window.farmerApp.renderMarketPrices(filtered, data);
}

// Global function to sort market prices
function sortMarketPrices() {
    filterMarketPrices(); // Reuse filter function which handles sorting
}

// Initialize app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.farmerApp = new FarmerApp();
    // Create global 'app' variable for onclick handlers
    window.app = window.farmerApp;
});
