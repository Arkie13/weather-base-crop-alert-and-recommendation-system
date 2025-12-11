// Register Chart.js plugins
if (typeof Chart !== 'undefined') {
    // Register datalabels plugin if available
    if (typeof ChartDataLabels !== 'undefined') {
        Chart.register(ChartDataLabels);
    }
    // Register zoom plugin if available
    if (typeof zoomPlugin !== 'undefined') {
        Chart.register(zoomPlugin);
    } else if (window.Chart && window.Chart.register) {
        // Try alternative zoom plugin registration
        try {
            const zoomPluginModule = window.zoomPlugin || window.chartjsPluginZoom;
            if (zoomPluginModule) {
                Chart.register(zoomPluginModule);
            }
        } catch (e) {
            console.warn('Zoom plugin not available:', e);
        }
    }
}

class AdminApp {
    constructor() {
        this.currentUser = null;
        this.allFarmers = []; // Store all farmers for filtering
        this.init();
    }

    async init() {
        await this.checkAuthentication();
        this.setupEventListeners();
        this.updateCurrentDate();
        await this.loadInitialData();
    }

    async checkAuthentication() {
        try {
            const response = await fetch('api/auth.php');
            const data = await response.json();

            if (data.success && data.authenticated) {
                this.currentUser = data.user;
                if (data.user.role !== 'admin') {
                    window.location.href = 'farmer-dashboard.html';
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

    updateUserInfo() {
        if (this.currentUser) {
            document.getElementById('adminName').textContent = this.currentUser.full_name;
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

        // Quick action cards
        document.querySelectorAll('.quick-action-card').forEach(card => {
            card.addEventListener('click', (e) => {
                e.preventDefault();
                const section = card.dataset.section;
                if (section) {
                    this.showSection(section);
                }
            });
        });

        // Add farmer form
        const addFarmerForm = document.getElementById('addFarmerForm');
        if (addFarmerForm) {
            addFarmerForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.addFarmer();
            });
        }

        // Edit farmer form
        const addPriceForm = document.getElementById('addPriceForm');
        if (addPriceForm) {
            addPriceForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleAddPrice();
            });
        }

        const editFarmerForm = document.getElementById('editFarmerForm');
        if (editFarmerForm) {
            editFarmerForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.updateFarmer();
            });
        }
    }

    showSection(sectionId) {
        // Close full-screen analytics modal if open
        if (typeof closeFullScreenAnalytics === 'function') {
            closeFullScreenAnalytics();
        }
        
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
            case 'analytics':
                await this.loadAnalytics();
                // Load forecast data when analytics section is shown
                setTimeout(() => {
                    this.loadForecastData();
                }, 100);
                // Refresh weather trends chart
                setTimeout(() => {
                    this.refreshWeatherTrendsChart();
                }, 200);
                break;
            case 'market-timing':
                await this.loadMarketTiming();
                break;
            case 'market-prices':
                await this.loadAdminMarketPrices();
                break;
            case 'farmers':
                await this.loadFarmers();
                break;
            case 'alerts':
                await this.loadAlerts();
                break;
            case 'reports':
                await this.loadReports();
                break;
            case 'settings':
                await this.loadSettings();
                break;
            case 'backup-recovery':
                await this.loadBackupSection();
                break;
        }
    }

    async loadInitialData() {
        await this.loadDashboardData();
        await this.loadForecastData();
        // Load external weather alerts on page load
        await this.loadExternalWeatherAlerts();
    }

    async loadDashboardData() {
        try {
            // Load weather data
            await this.fetchWeatherData();
            
            // Load analytics
            await this.loadAnalytics();
            
            // Load admin stats
            await this.loadAdminStats();
            
            // Load system settings
            await this.loadSystemSettings();
            
            // Load dashboard widgets
            await this.loadRecentAlerts();
            await this.loadActivityFeed();
            await this.checkSystemStatus();
            this.updateWelcomeMessage();
            
            // Track admin activity
            await this.trackActivity('dashboard_view');
        } catch (error) {
            console.error('Error loading dashboard data:', error);
        }
    }

    async fetchWeatherData() {
        try {
            // Default to Manila, Philippines coordinates
            let latitude = 14.5995;
            let longitude = 120.9842;
            
            // Try to get user location from session or stored data
            try {
                const storedUser = localStorage.getItem('currentUser');
                if (storedUser) {
                    const user = JSON.parse(storedUser);
                    if (user.latitude && user.longitude) {
                        latitude = parseFloat(user.latitude);
                        longitude = parseFloat(user.longitude);
                    } else if (user.location) {
                        // Try to geocode location
                        const geocodeResult = await APIUtils.geocode(user.location);
                        if (geocodeResult.success && geocodeResult.data) {
                            latitude = geocodeResult.data.latitude;
                            longitude = geocodeResult.data.longitude;
                        }
                    }
                }
            } catch (e) {
                console.warn('Could not get user location, using default:', e);
            }
            
            // Try to get weather using coordinates (same as farmer panel)
            const weatherResult = await APIUtils.getCurrentWeather(latitude, longitude);
            const forecastResult = await APIUtils.getWeatherForecast(latitude, longitude, 5);
            
            if (weatherResult.success && weatherResult.data) {
                this.weatherData = {
                    current: weatherResult.data,
                    forecast: forecastResult.success ? forecastResult.data : []
                };
                this.displayCurrentWeather();
                return;
            }
            
            // Fallback to weather.php endpoint (same as farmer panel)
            const response = await fetch('api/weather.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-cache'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data && data.success && data.data) {
                this.weatherData = data.data;
                this.displayCurrentWeather();
            } else {
                throw new Error(data?.message || 'Failed to fetch weather data');
            }
        } catch (error) {
            console.error('Error fetching weather data:', error);
            this.useFallbackWeatherData();
        }
    }

    useFallbackWeatherData() {
        // Show error message instead of random data
        const container = document.getElementById('currentWeather');
        if (container) {
            container.innerHTML = `
                <div class="no-data" style="text-align: center; padding: 2rem;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: #f59e0b; margin-bottom: 1rem;"></i>
                    <p style="color: #6c757d; margin-bottom: 0.5rem;">Unable to fetch weather data</p>
                    <p style="color: #94a3b8; font-size: 0.9rem;">Please check your internet connection and try again.</p>
                    <button class="btn btn-sm btn-primary" onclick="window.adminApp.fetchWeatherData()" style="margin-top: 1rem;">
                        <i class="fas fa-sync-alt"></i> Retry
                    </button>
                </div>
            `;
        }
    }

    displayCurrentWeather() {
        const container = document.getElementById('currentWeather');
        if (!container) {
            console.error('Weather container not found');
            return;
        }

        if (!this.weatherData || !this.weatherData.current) {
            container.innerHTML = '<div class="no-data">No weather data available</div>';
            return;
        }

        const weather = this.weatherData.current;
        
        // Ensure all required fields exist with defaults
        const temperature = weather.temperature || weather.temp || 25;
        const humidity = weather.humidity || 60;
        const rainfall = weather.rainfall || weather.precipitation || 0;
        const windSpeed = weather.wind_speed || weather.windspeed || 10;
        const condition = weather.condition || weather.conditions || 'Sunny';
        
        const weatherIcon = this.getWeatherIcon(condition);
        
        container.innerHTML = `
            <div class="current-weather-grid">
                <div class="weather-primary">
                    <div class="weather-icon-large">${weatherIcon}</div>
                    <div class="weather-temp-large">${temperature}°C</div>
                    <div class="weather-condition-text">${condition}</div>
                </div>
                <div class="weather-secondary">
                    <div class="weather-metric">
                        <div class="metric-icon">
                            <i class="fas fa-tint"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-value">${humidity}%</div>
                            <div class="metric-label">Humidity</div>
                        </div>
                    </div>
                    <div class="weather-metric">
                        <div class="metric-icon">
                            <i class="fas fa-cloud-rain"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-value">${rainfall}mm</div>
                            <div class="metric-label">Rainfall</div>
                        </div>
                    </div>
                    <div class="weather-metric">
                        <div class="metric-icon">
                            <i class="fas fa-wind"></i>
                        </div>
                        <div class="metric-content">
                            <div class="metric-value">${windSpeed} km/h</div>
                            <div class="metric-label">Wind Speed</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
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

    async loadAnalytics() {
        try {
            console.log('Loading analytics data...');
            const response = await fetch('api/analytics.php');
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('HTTP error response:', response.status, errorText);
                throw new Error(`HTTP error! status: ${response.status} - ${errorText.substring(0, 200)}`);
            }
            
            // Check if response is actually JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response received:', text.substring(0, 500));
                throw new Error('Server returned non-JSON response. Check PHP errors.');
            }
            
            const data = await response.json();
            console.log('Analytics API response:', data);

            if (data.success) {
                this.analyticsData = data.data;
                console.log('Analytics data loaded successfully:', this.analyticsData);
                this.createAnalyticsCharts();
            } else {
                console.error('Analytics API returned error:', data.message || 'Unknown error', data.error_details || '');
                // Show user-friendly error message with details
                const analyticsSection = document.querySelector('.analytics-section');
                if (analyticsSection) {
                    // Remove any existing error messages
                    const existingError = analyticsSection.querySelector('.alert-danger');
                    if (existingError) existingError.remove();
                    
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger';
                    errorDiv.style.cssText = 'padding: 1rem; margin: 1rem 0; background: #f8d7da; color: #721c24; border-radius: 4px;';
                    errorDiv.innerHTML = `<strong>Error:</strong> ${data.message || 'Failed to load analytics data'}. Please check the console for details.`;
                    analyticsSection.insertBefore(errorDiv, analyticsSection.firstChild);
                }
            }
        } catch (error) {
            console.error('Error loading analytics:', error);
            console.error('Error details:', error.message, error.stack);
            
            // Show user-friendly error message
            const analyticsSection = document.querySelector('.analytics-section');
            if (analyticsSection) {
                // Remove any existing error messages
                const existingError = analyticsSection.querySelector('.alert-danger');
                if (existingError) existingError.remove();
                
                const errorDiv = document.createElement('div');
                errorDiv.className = 'alert alert-danger';
                errorDiv.style.cssText = 'padding: 1rem; margin: 1rem 0; background: #f8d7da; color: #721c24; border-radius: 4px;';
                errorDiv.innerHTML = `<strong>Error:</strong> Failed to load analytics data. Error: ${error.message}. Please check the browser console for more details.`;
                analyticsSection.insertBefore(errorDiv, analyticsSection.firstChild);
            }
        }
    }

    createAnalyticsCharts() {
        if (!this.analyticsData) {
            console.error('No analytics data available to create charts');
            return;
        }

        console.log('Creating analytics charts with data:', this.analyticsData);

        // Update performance metrics
        try {
            this.updatePerformanceMetrics();
        } catch (error) {
            console.error('Error updating performance metrics:', error);
        }
        
        // Farmers by Location Chart
        try {
            this.createFarmersLocationChart();
        } catch (error) {
            console.error('Error creating Farmers Location Chart:', error);
        }
        
        // Alerts by Type Chart
        try {
            this.createAlertsTypeChart();
        } catch (error) {
            console.error('Error creating Alerts Type Chart:', error);
        }
        
        // Alerts by Status Chart
        try {
            this.createAlertsStatusChart();
        } catch (error) {
            console.error('Error creating Alerts Status Chart:', error);
        }
        
        // Alerts by Severity Chart
        try {
            this.createAlertsSeverityChart();
        } catch (error) {
            console.error('Error creating Alerts Severity Chart:', error);
        }
        
        // Weather Trends Chart
        try {
            this.createWeatherTrendsChart();
        } catch (error) {
            console.error('Error creating Weather Trends Chart:', error);
        }
        
        // User Activity Chart
        try {
            this.createUserActivityChart();
        } catch (error) {
            console.error('Error creating User Activity Chart:', error);
        }
        
        // Crop Distribution Chart
        try {
            this.createCropDistributionChart();
        } catch (error) {
            console.error('Error creating Crop Distribution Chart:', error);
        }
        
        // Alert Trends Chart
        try {
            this.createAlertTrendsChart();
        } catch (error) {
            console.error('Error creating Alert Trends Chart:', error);
        }
        
        // Alerts by Hour Chart
        try {
            this.createAlertsByHourChart();
        } catch (error) {
            console.error('Error creating Alerts By Hour Chart:', error);
        }
    }
    
    updatePerformanceMetrics() {
        if (!this.analyticsData.performance) return;
        
        const perf = this.analyticsData.performance;
        
        // Update performance metric cards
        const responseRateEl = document.getElementById('alertResponseRate');
        if (responseRateEl) {
            responseRateEl.textContent = `${perf.alert_response_rate || 0}%`;
        }
        
        const accuracyRateEl = document.getElementById('alertAccuracyRate');
        if (accuracyRateEl) {
            accuracyRateEl.textContent = `${perf.alert_accuracy_rate || 0}%`;
        }
        
        const uptimeEl = document.getElementById('systemUptime');
        if (uptimeEl) {
            uptimeEl.textContent = `${perf.system_uptime_percent || 0}%`;
        }
        
        const resolutionTimeEl = document.getElementById('avgResolutionTime');
        if (resolutionTimeEl) {
            const hours = perf.avg_resolution_hours || 0;
            if (hours < 24) {
                resolutionTimeEl.textContent = `${hours.toFixed(1)}h`;
            } else {
                resolutionTimeEl.textContent = `${(hours / 24).toFixed(1)}d`;
            }
        }
    }

    refreshWeatherTrendsChart() {
        console.log('Refreshing weather trends chart...');
        if (this.analyticsData && this.analyticsData.weather && this.analyticsData.weather.daily_data) {
            console.log('Weather data available:', this.analyticsData.weather.daily_data);
            this.createWeatherTrendsChart();
        } else {
            console.log('No weather data available for trends chart');
        }
    }

    generateSampleWeatherData() {
        // Generate 7 days of sample weather data for demonstration
        const sampleData = [];
        const today = new Date();
        
        for (let i = 6; i >= 0; i--) {
            const date = new Date(today);
            date.setDate(date.getDate() - i);
            
            // Generate realistic sample data with some variation
            const baseTemp = 25;
            const baseHumidity = 60;
            const tempVariation = (Math.random() - 0.5) * 10; // ±5°C variation
            const humidityVariation = (Math.random() - 0.5) * 20; // ±10% variation
            
            sampleData.push({
                date: date.toISOString().split('T')[0],
                avg_temp: Math.round((baseTemp + tempVariation) * 10) / 10,
                avg_humidity: Math.round((baseHumidity + humidityVariation) * 10) / 10,
                total_rainfall: Math.round(Math.random() * 5 * 10) / 10 // 0-5mm rainfall
            });
        }
        
        console.log('Generated sample weather data:', sampleData);
        return sampleData;
    }

    createFarmersLocationChart() {
        const ctx = ChartUtils.checkCanvas('farmersLocationChart');
        if (!ctx) return;
        
        const validation = ChartUtils.validateChartData(
            this.analyticsData?.farmers?.by_location,
            []
        );
        
        if (!validation.valid) {
            ChartUtils.showNoDataMessage(ctx, 'No location data available');
            return;
        }

        const data = this.analyticsData.farmers.by_location;
        
        ChartUtils.destroyChart(this.farmersLocationChart);
        
        this.farmersLocationChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(item => item.location || 'Unknown'),
                datasets: [{
                    data: data.map(item => item.count),
                    backgroundColor: ChartUtils.generateColors(data.length)
                }]
            },
            options: ChartUtils.getDoughnutOptions(true)
        });
    }

    createAlertsTypeChart() {
        const ctx = ChartUtils.checkCanvas('alertsTypeChart');
        if (!ctx) return;
        
        const validation = ChartUtils.validateChartData(
            this.analyticsData?.alerts?.by_type,
            []
        );
        
        if (!validation.valid) {
            ChartUtils.showNoDataMessage(ctx, 'No alert type data available');
            return;
        }

        const data = this.analyticsData.alerts.by_type;
        
        ChartUtils.destroyChart(this.alertsTypeChart);
        
        const ctx2d = ctx.getContext('2d');
        const gradient = ChartUtils.createGradient(ctx2d, ChartUtils.colors.gradients.primary);
        
        this.alertsTypeChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(item => item.type || 'Unknown'),
                datasets: [{
                    label: 'Number of Alerts',
                    data: data.map(item => item.count),
                    backgroundColor: ChartUtils.generateColors(data.length),
                    borderColor: ChartUtils.colors.primary,
                    borderWidth: 1
                }]
            },
            options: {
                ...ChartUtils.getBarOptions(false),
                plugins: {
                    ...ChartUtils.getBarOptions(false).plugins,
                    tooltip: {
                        ...ChartUtils.getBarOptions(false).plugins.tooltip,
                        callbacks: {
                            label: function(context) {
                                return `Alerts: ${ChartUtils.formatNumber(context.parsed.y)}`;
                            }
                        }
                    }
                }
            }
        });
    }
    
    createAlertsStatusChart() {
        const ctx = ChartUtils.checkCanvas('alertsStatusChart');
        if (!ctx) return;
        
        const validation = ChartUtils.validateChartData(
            this.analyticsData?.alerts?.by_status,
            []
        );
        
        if (!validation.valid) {
            ChartUtils.showNoDataMessage(ctx, 'No alert status data available');
            return;
        }

        const data = this.analyticsData.alerts.by_status;
        
        ChartUtils.destroyChart(this.alertsStatusChart);
        
        // Status color mapping
        const statusColors = {
            'active': ChartUtils.colors.warning,
            'resolved': ChartUtils.colors.success,
            'cancelled': ChartUtils.colors.danger
        };
        
        this.alertsStatusChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: data.map(item => item.status.charAt(0).toUpperCase() + item.status.slice(1)),
                datasets: [{
                    data: data.map(item => item.count),
                    backgroundColor: data.map(item => statusColors[item.status] || ChartUtils.colors.primary),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: ChartUtils.getDoughnutOptions(true)
        });
    }
    
    createAlertsSeverityChart() {
        const ctx = ChartUtils.checkCanvas('alertsSeverityChart');
        if (!ctx) return;
        
        const validation = ChartUtils.validateChartData(
            this.analyticsData?.alerts?.by_severity,
            []
        );
        
        if (!validation.valid) {
            ChartUtils.showNoDataMessage(ctx, 'No alert severity data available');
            return;
        }

        const data = this.analyticsData.alerts.by_severity;
        
        ChartUtils.destroyChart(this.alertsSeverityChart);
        
        // Color mapping for severity
        const severityColors = {
            'low': ChartUtils.colors.success,
            'medium': ChartUtils.colors.warning,
            'high': ChartUtils.colors.danger
        };
        
        this.alertsSeverityChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(item => item.severity.charAt(0).toUpperCase() + item.severity.slice(1)),
                datasets: [{
                    data: data.map(item => item.count),
                    backgroundColor: data.map(item => severityColors[item.severity] || ChartUtils.colors.primary),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: ChartUtils.getDoughnutOptions(true)
        });
    }
    
    createCropDistributionChart() {
        const ctx = ChartUtils.checkCanvas('cropDistributionChart');
        if (!ctx) return;
        
        const validation = ChartUtils.validateChartData(
            this.analyticsData?.crop_distribution?.distribution,
            []
        );
        
        if (!validation.valid) {
            ChartUtils.showNoDataMessage(ctx, 'No crop data available');
            return;
        }

        const data = this.analyticsData.crop_distribution.distribution;
        
        ChartUtils.destroyChart(this.cropDistributionChart);
        
        const ctx2d = ctx.getContext('2d');
        const gradient = ChartUtils.createGradient(ctx2d, ChartUtils.colors.gradients.primary, 'horizontal');
        
        this.cropDistributionChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(item => item.crop_name || item.crop),
                datasets: [{
                    label: 'Farmers',
                    data: data.map(item => item.count),
                    backgroundColor: ChartUtils.colors.primary,
                    borderColor: ChartUtils.colors.secondary,
                    borderWidth: 1
                }]
            },
            options: {
                ...ChartUtils.getBarOptions(true),
                plugins: {
                    ...ChartUtils.getBarOptions(true).plugins,
                    tooltip: {
                        ...ChartUtils.getBarOptions(true).plugins.tooltip,
                        callbacks: {
                            label: function(context) {
                                return `Farmers: ${ChartUtils.formatNumber(context.parsed.x)}`;
                            }
                        }
                    }
                }
            }
        });
    }
    
    createAlertTrendsChart() {
        const ctx = ChartUtils.checkCanvas('alertTrendsChart');
        if (!ctx) return;
        
        const validation = ChartUtils.validateChartData(
            this.analyticsData?.alert_trends?.daily_trends,
            []
        );
        
        if (!validation.valid) {
            ChartUtils.showNoDataMessage(ctx, 'No alert trend data available');
            return;
        }

        const data = this.analyticsData.alert_trends.daily_trends;
        
        ChartUtils.destroyChart(this.alertTrendsChart);
        
        const lineOptions = ChartUtils.getLineOptions(true);
        
        this.alertTrendsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(item => new Date(item.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
                datasets: [
                    {
                        label: 'Total Alerts',
                        data: data.map(item => item.count),
                        borderColor: ChartUtils.colors.primary,
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Active',
                        data: data.map(item => item.active_count || 0),
                        borderColor: ChartUtils.colors.warning,
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Resolved',
                        data: data.map(item => item.resolved_count || 0),
                        borderColor: ChartUtils.colors.success,
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                ...lineOptions,
                plugins: {
                    ...lineOptions.plugins,
                    zoom: {
                        zoom: {
                            wheel: {
                                enabled: true
                            },
                            pinch: {
                                enabled: true
                            },
                            mode: 'x'
                        },
                        pan: {
                            enabled: true,
                            mode: 'x'
                        }
                    }
                },
                scales: {
                    ...lineOptions.scales,
                    y: {
                        ...lineOptions.scales.y,
                        ticks: {
                            ...lineOptions.scales.y.ticks,
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
    
    createAlertsByHourChart() {
        const ctx = ChartUtils.checkCanvas('alertsByHourChart');
        if (!ctx) return;
        
        const validation = ChartUtils.validateChartData(
            this.analyticsData?.alert_trends?.by_hour,
            []
        );
        
        if (!validation.valid) {
            ChartUtils.showNoDataMessage(ctx, 'No hourly alert data available');
            return;
        }

        const data = this.analyticsData.alert_trends.by_hour;
        
        // Create array for all 24 hours, filling in missing hours with 0
        const hourlyData = Array(24).fill(0);
        data.forEach(item => {
            hourlyData[item.hour] = item.count;
        });
        
        ChartUtils.destroyChart(this.alertsByHourChart);
        
        const barOptions = ChartUtils.getBarOptions(false);
        
        this.alertsByHourChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: Array.from({length: 24}, (_, i) => {
                    const hour = i % 12 || 12;
                    const period = i < 12 ? 'AM' : 'PM';
                    return `${hour}:00 ${period}`;
                }),
                datasets: [{
                    label: 'Alerts',
                    data: hourlyData,
                    backgroundColor: ChartUtils.colors.primary,
                    borderColor: ChartUtils.colors.secondary,
                    borderWidth: 1
                }]
            },
            options: {
                ...barOptions,
                plugins: {
                    ...barOptions.plugins,
                    tooltip: {
                        ...barOptions.plugins.tooltip,
                        callbacks: {
                            label: function(context) {
                                return `Alerts: ${ChartUtils.formatNumber(context.parsed.y)}`;
                            }
                        }
                    }
                },
                scales: {
                    ...barOptions.scales,
                    x: {
                        ...barOptions.scales.x,
                        ticks: {
                            ...barOptions.scales.x.ticks,
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }

    createWeatherTrendsChart() {
        const ctx = ChartUtils.checkCanvas('weatherTrendsChart');
        if (!ctx) return;

        if (!this.analyticsData || !this.analyticsData.weather) {
            ChartUtils.showNoDataMessage(ctx, 'No weather data available');
            return;
        }

        let data = this.analyticsData.weather.daily_data;
        
        // If no real data available, generate sample data for demonstration
        if (!data || data.length < 2) {
            console.log('No sufficient weather data available, generating sample data for forecast demonstration');
            data = this.generateSampleWeatherData();
        }
        
        // Calculate straight-line forecasts for temperature and humidity
        const tempForecast = this.calculateStraightLineForecast(
            data.map(item => ({ date: item.date, value: item.avg_temp || 0 })), 
            7
        );
        const humidityForecast = this.calculateStraightLineForecast(
            data.map(item => ({ date: item.date, value: item.avg_humidity || 0 })), 
            7
        );
        
        // Combine historical and forecast data
        const allDates = [...data.map(item => item.date), ...tempForecast.map(f => f.date)];
        const tempHistorical = data.map(item => Math.round(item.avg_temp || 0));
        const tempForecastValues = tempForecast.map(f => Math.round(f.predicted_value));
        const humidityHistorical = data.map(item => Math.round(item.avg_humidity || 0));
        const humidityForecastValues = humidityForecast.map(f => Math.round(f.predicted_value));
        
        // Create combined datasets with null values to separate historical from forecast
        const tempCombined = [...tempHistorical, ...tempForecastValues];
        const humidityCombined = [...humidityHistorical, ...humidityForecastValues];
        
        ChartUtils.destroyChart(this.weatherTrendsChart);
        
        const lineOptions = ChartUtils.getLineOptions(false);
        
        this.weatherTrendsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: allDates.map(date => new Date(date).toLocaleDateString()),
                datasets: [
                    {
                        label: 'Temperature (°C)',
                        data: tempCombined,
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        borderWidth: 2,
                        spanGaps: false
                    },
                    {
                        label: 'Humidity (%)',
                        data: humidityCombined,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        borderWidth: 2,
                        spanGaps: false
                    }
                ]
            },
            options: {
                ...lineOptions,
                plugins: {
                    ...lineOptions.plugins,
                    tooltip: {
                        ...lineOptions.plugins.tooltip,
                        callbacks: {
                            title: function(context) {
                                return context[0].label;
                            },
                            label: function(context) {
                                const value = context.parsed.y;
                                if (value === null) return '';
                                return context.dataset.label + ': ' + value + (context.dataset.label.includes('Temperature') ? '°C' : '%');
                            }
                        }
                    },
                    zoom: {
                        zoom: {
                            wheel: {
                                enabled: true
                            },
                            pinch: {
                                enabled: true
                            },
                            mode: 'x'
                        },
                        pan: {
                            enabled: true,
                            mode: 'x'
                        }
                    }
                },
                scales: {
                    ...lineOptions.scales,
                    y: {
                        ...lineOptions.scales.y,
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            ...lineOptions.scales.y.ticks,
                            callback: function(value) {
                                return value + (value >= 50 ? '%' : '°C');
                            }
                        }
                    },
                    x: {
                        ...lineOptions.scales.x,
                        ticks: {
                            ...lineOptions.scales.x.ticks,
                            maxRotation: 0
                        }
                    }
                }
            }
        });
    }
    
    calculateStraightLineForecast(historicalData, forecastDays) {
        if (historicalData.length < 2) {
            return [];
        }
        
        // Calculate trend using simple linear regression
        const n = historicalData.length;
        let sumX = 0, sumY = 0, sumXY = 0, sumXX = 0;
        
        // Convert dates to numeric values (days from first date)
        const firstDate = new Date(historicalData[0].date);
        
        historicalData.forEach((dataPoint, index) => {
            const currentDate = new Date(dataPoint.date);
            const x = Math.floor((currentDate - firstDate) / (1000 * 60 * 60 * 24)); // days
            const y = dataPoint.value;
            
            sumX += x;
            sumY += y;
            sumXY += x * y;
            sumXX += x * x;
        });
        
        // Calculate slope (m) and intercept (b) for y = mx + b
        const slope = (n * sumXY - sumX * sumY) / (n * sumXX - sumX * sumX);
        const intercept = (sumY - slope * sumX) / n;
        
        // Generate forecast
        const forecast = [];
        const lastHistoricalDate = new Date(historicalData[historicalData.length - 1].date);
        
        for (let i = 1; i <= forecastDays; i++) {
            const forecastDate = new Date(lastHistoricalDate);
            forecastDate.setDate(forecastDate.getDate() + i);
            
            const daysFromFirst = Math.floor((forecastDate - firstDate) / (1000 * 60 * 60 * 24));
            const predictedValue = slope * daysFromFirst + intercept;
            
            forecast.push({
                date: forecastDate.toISOString().split('T')[0],
                predicted_value: Math.max(0, Math.round(predictedValue * 100) / 100) // Ensure non-negative and round to 2 decimal places
            });
        }
        
        console.log(`Generated ${forecast.length} forecast points for ${forecastDays} days:`, forecast);
        return forecast;
    }

    createUserActivityChart() {
        const ctx = ChartUtils.checkCanvas('userActivityChart');
        if (!ctx) return;
        
        const validation = ChartUtils.validateChartData(
            this.analyticsData?.user_activity?.daily_registrations,
            []
        );
        
        if (!validation.valid) {
            ChartUtils.showNoDataMessage(ctx, 'No user activity data available');
            return;
        }

        const userActivityData = this.analyticsData.user_activity;
        const data = userActivityData.daily_registrations;
        
        ChartUtils.destroyChart(this.userActivityChart);
        
        const lineOptions = ChartUtils.getLineOptions(true);
        
        this.userActivityChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(item => item.day || item.date || 'Unknown'),
                datasets: [{
                    label: 'New Users',
                    data: data.map(item => item.count || 0),
                    backgroundColor: 'rgba(102, 126, 234, 0.2)',
                    borderColor: ChartUtils.colors.primary,
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                ...lineOptions,
                plugins: {
                    ...lineOptions.plugins,
                    title: {
                        display: true,
                        text: `Total Users: ${userActivityData.total_users || 0} | Active: ${userActivityData.active_users || 0}`,
                        color: ChartUtils.getDefaultOptions().scales.x.ticks.color
                    },
                    zoom: {
                        zoom: {
                            wheel: {
                                enabled: true
                            },
                            pinch: {
                                enabled: true
                            },
                            mode: 'x'
                        },
                        pan: {
                            enabled: true,
                            mode: 'x'
                        }
                    }
                },
                scales: {
                    ...lineOptions.scales,
                    y: {
                        ...lineOptions.scales.y,
                        ticks: {
                            ...lineOptions.scales.y.ticks,
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    async loadAdminStats() {
        try {
            const response = await fetch('api/analytics.php');
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('HTTP error loading admin stats:', response.status, errorText.substring(0, 200));
                return;
            }
            
            // Check if response is actually JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response in loadAdminStats:', text.substring(0, 500));
                return;
            }
            
            const data = await response.json();

            if (data.success) {
                const stats = data.data.overview;
                const userActivity = data.data.user_activity;
                
                const totalFarmers = stats.total_farmers || 0;
                const activeAlerts = stats.active_alerts || 0;
                const weatherRecords = stats.weather_records_today || 0;
                const totalUsers = userActivity ? userActivity.total_users : (stats.total_farmers || 0) + 1;
                const activeUsers = userActivity ? userActivity.active_users : 0;
                
                document.getElementById('totalFarmers').textContent = totalFarmers;
                document.getElementById('activeAlerts').textContent = activeAlerts;
                document.getElementById('weatherRecords').textContent = weatherRecords;
                document.getElementById('totalUsers').textContent = totalUsers;
                document.getElementById('activeUsers').textContent = activeUsers;
                
                // Update trends (simplified - you can enhance this with actual historical data)
                this.updateStatTrends({
                    farmers: totalFarmers,
                    alerts: activeAlerts,
                    weather: weatherRecords,
                    users: totalUsers,
                    activeUsers: activeUsers
                });
            } else {
                console.error('Admin stats API returned error:', data.message || 'Unknown error');
            }
        } catch (error) {
            console.error('Error loading admin stats:', error);
        }
    }

    updateStatTrends(stats) {
        // Simple trend calculation - you can enhance this with actual historical comparison
        const trends = {
            farmers: Math.random() > 0.5 ? 'up' : 'down',
            alerts: Math.random() > 0.5 ? 'up' : 'down',
            weather: 'up',
            users: 'up',
            activeUsers: Math.random() > 0.5 ? 'up' : 'down'
        };

        Object.keys(trends).forEach(key => {
            const trendEl = document.getElementById(`${key}Trend`);
            if (trendEl) {
                const trend = trends[key];
                const percentage = Math.floor(Math.random() * 20) + 1;
                trendEl.className = `stat-trend ${trend === 'up' ? '' : trend === 'down' ? 'down' : 'neutral'}`;
                trendEl.innerHTML = `
                    <i class="fas fa-arrow-${trend === 'up' ? 'up' : trend === 'down' ? 'down' : 'right'}"></i>
                    <span>${percentage}%</span>
                `;
            }
        });
    }

    async loadRecentAlerts() {
        try {
            const response = await fetch('api/alerts.php?limit=5');
            const data = await response.json();
            
            const container = document.getElementById('recentAlertsList');
            if (!container) return;

            if (data.success && data.data && data.data.length > 0) {
                const alerts = data.data.slice(0, 5);
                container.innerHTML = alerts.map(alert => {
                    const severity = alert.severity || 'medium';
                    const icon = this.getAlertIcon(alert.type || 'weather');
                    const timeAgo = this.getTimeAgo(alert.created_at);
                    
                    return `
                        <div class="recent-alert-item" onclick="window.adminApp.showSection('alerts')">
                            <div class="alert-icon-wrapper severity-${severity}">
                                <i class="${icon}"></i>
                            </div>
                            <div class="alert-content">
                                <div class="alert-title">${alert.type || 'Weather Alert'}</div>
                                <div class="alert-description">${(alert.message || alert.description || '').substring(0, 60)}...</div>
                                <div class="alert-time">
                                    <i class="fas fa-clock"></i>
                                    ${timeAgo}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                container.innerHTML = '<div class="no-data" style="text-align: center; padding: 2rem; color: #6c757d;">No recent alerts</div>';
            }
        } catch (error) {
            console.error('Error loading recent alerts:', error);
            const container = document.getElementById('recentAlertsList');
            if (container) {
                container.innerHTML = '<div class="no-data" style="text-align: center; padding: 2rem; color: #6c757d;">Failed to load alerts</div>';
            }
        }
    }

    getAlertIcon(type) {
        const icons = {
            'weather': 'fas fa-cloud-rain',
            'typhoon': 'fas fa-hurricane',
            'flood': 'fas fa-water',
            'drought': 'fas fa-sun',
            'temperature': 'fas fa-thermometer-half',
            'rainfall': 'fas fa-cloud-showers-heavy'
        };
        return icons[type.toLowerCase()] || 'fas fa-exclamation-triangle';
    }

    async loadActivityFeed() {
        try {
            // Try to get recent activity from track-activity API
            const response = await fetch('api/track-activity.php?limit=5&action=recent');
            let activities = [];
            
            try {
                const data = await response.json();
                if (data.success && data.data) {
                    activities = data.data;
                }
            } catch (e) {
                // If API doesn't exist or fails, create sample activities
                activities = this.generateSampleActivities();
            }

            const container = document.getElementById('activityFeed');
            if (!container) return;

            if (activities.length > 0) {
                container.innerHTML = activities.map(activity => {
                    const icon = this.getActivityIcon(activity.action || activity.type);
                    const timeAgo = this.getTimeAgo(activity.created_at || activity.timestamp);
                    
                    return `
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="${icon}"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">${activity.description || activity.message || 'System activity'}</div>
                                <div class="activity-time">${timeAgo}</div>
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                container.innerHTML = '<div class="no-data" style="text-align: center; padding: 2rem; color: #6c757d;">No recent activity</div>';
            }
        } catch (error) {
            console.error('Error loading activity feed:', error);
            const container = document.getElementById('activityFeed');
            if (container) {
                container.innerHTML = this.generateSampleActivities().map(activity => {
                    const icon = this.getActivityIcon(activity.type);
                    const timeAgo = this.getTimeAgo(activity.timestamp);
                    return `
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="${icon}"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">${activity.description}</div>
                                <div class="activity-time">${timeAgo}</div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }
    }

    generateSampleActivities() {
        const now = new Date();
        return [
            {
                type: 'user',
                description: 'New farmer registered',
                timestamp: new Date(now - 2 * 60 * 60 * 1000).toISOString()
            },
            {
                type: 'alert',
                description: 'Weather alert generated',
                timestamp: new Date(now - 4 * 60 * 60 * 1000).toISOString()
            },
            {
                type: 'system',
                description: 'System backup completed',
                timestamp: new Date(now - 6 * 60 * 60 * 1000).toISOString()
            },
            {
                type: 'user',
                description: 'Farmer profile updated',
                timestamp: new Date(now - 8 * 60 * 60 * 1000).toISOString()
            },
            {
                type: 'alert',
                description: 'Alert resolved',
                timestamp: new Date(now - 12 * 60 * 60 * 1000).toISOString()
            }
        ];
    }

    getActivityIcon(type) {
        const icons = {
            'user': 'fas fa-user',
            'alert': 'fas fa-bell',
            'system': 'fas fa-cog',
            'weather': 'fas fa-cloud',
            'farmer': 'fas fa-user-tie'
        };
        return icons[type] || 'fas fa-circle';
    }

    async checkSystemStatus() {
        const apiStatusEl = document.getElementById('apiStatus');
        const dbStatusEl = document.getElementById('dbStatus');
        const weatherServiceStatusEl = document.getElementById('weatherServiceStatus');

        // Check API status
        try {
            const apiResponse = await fetch('api/auth.php');
            if (apiResponse.ok) {
                apiStatusEl.textContent = 'Operational';
                apiStatusEl.parentElement.querySelector('.status-indicator').className = 'status-indicator status-ok';
            } else {
                throw new Error('API not responding');
            }
        } catch (error) {
            apiStatusEl.textContent = 'Error';
            apiStatusEl.parentElement.querySelector('.status-indicator').className = 'status-indicator status-error';
        }

        // Check database status (via analytics endpoint)
        try {
            const dbResponse = await fetch('api/analytics.php');
            if (dbResponse.ok) {
                dbStatusEl.textContent = 'Connected';
                dbStatusEl.parentElement.querySelector('.status-indicator').className = 'status-indicator status-ok';
            } else {
                throw new Error('Database not responding');
            }
        } catch (error) {
            dbStatusEl.textContent = 'Disconnected';
            dbStatusEl.parentElement.querySelector('.status-indicator').className = 'status-indicator status-error';
        }

        // Check weather service status
        try {
            const weatherResponse = await fetch('api/weather.php');
            if (weatherResponse.ok) {
                weatherServiceStatusEl.textContent = 'Active';
                weatherServiceStatusEl.parentElement.querySelector('.status-indicator').className = 'status-indicator status-ok';
            } else {
                throw new Error('Weather service not responding');
            }
        } catch (error) {
            weatherServiceStatusEl.textContent = 'Inactive';
            weatherServiceStatusEl.parentElement.querySelector('.status-indicator').className = 'status-indicator status-warning';
        }
    }

    updateWelcomeMessage() {
        const welcomeEl = document.getElementById('welcomeMessage');
        const summaryEl = document.getElementById('dashboardSummary');
        
        if (!welcomeEl || !summaryEl) return;

        const hour = new Date().getHours();
        let greeting = 'Welcome back';
        if (hour < 12) {
            greeting = 'Good morning';
        } else if (hour < 18) {
            greeting = 'Good afternoon';
        } else {
            greeting = 'Good evening';
        }

        if (this.currentUser) {
            welcomeEl.textContent = `${greeting}, ${this.currentUser.full_name || 'Admin'}!`;
        } else {
            welcomeEl.textContent = `${greeting}, Admin!`;
        }

        // Update summary
        const totalFarmers = parseInt(document.getElementById('totalFarmers')?.textContent || 0);
        const activeAlerts = parseInt(document.getElementById('activeAlerts')?.textContent || 0);
        
        summaryEl.textContent = `You have ${totalFarmers} registered farmers and ${activeAlerts} active alerts today.`;
    }

    getTimeAgo(dateString) {
        if (!dateString) return 'Just now';
        
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins} min${diffMins > 1 ? 's' : ''} ago`;
        if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
        if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
        
        return date.toLocaleDateString();
    }

    async loadFarmers() {
        try {
            const response = await fetch('api/farmers.php');
            const data = await response.json();

            if (data.success) {
                this.allFarmers = data.data; // Store all farmers
                this.populateLocationFilter(); // Populate location filter dropdown
                this.applyFilters(); // Apply any active filters
            }
        } catch (error) {
            console.error('Error loading farmers:', error);
        }
    }

    populateLocationFilter() {
        const locationFilter = document.getElementById('locationFilter');
        if (!locationFilter) return;

        // Get unique locations from all farmers
        const locations = [...new Set(this.allFarmers.map(f => f.location).filter(Boolean))].sort();
        
        // Clear existing options except "All Locations"
        locationFilter.innerHTML = '<option value="">All Locations</option>';
        
        // Add location options
        locations.forEach(location => {
            const option = document.createElement('option');
            option.value = location;
            option.textContent = location;
            locationFilter.appendChild(option);
        });
    }

    applyFilters() {
        const searchTerm = document.getElementById('farmerSearch')?.value.toLowerCase().trim() || '';
        const locationFilter = document.getElementById('locationFilter')?.value || '';

        let filteredFarmers = [...this.allFarmers];

        // Apply search filter
        if (searchTerm) {
            filteredFarmers = filteredFarmers.filter(farmer => {
                const name = (farmer.name || '').toLowerCase();
                const username = (farmer.username || '').toLowerCase();
                const email = (farmer.email || '').toLowerCase();
                const phone = (farmer.phone || farmer.contact || '').toLowerCase();
                const location = (farmer.location || '').toLowerCase();
                const id = (farmer.id || '').toString();

                return name.includes(searchTerm) ||
                       username.includes(searchTerm) ||
                       email.includes(searchTerm) ||
                       phone.includes(searchTerm) ||
                       location.includes(searchTerm) ||
                       id.includes(searchTerm);
            });
        }

        // Apply location filter
        if (locationFilter) {
            filteredFarmers = filteredFarmers.filter(farmer => 
                farmer.location === locationFilter
            );
        }

        // Display filtered results
        this.displayFarmers(filteredFarmers);
    }

    displayFarmers(farmers) {
        const tbody = document.getElementById('farmersTableBody');
        
        if (farmers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center">No farmers found</td></tr>';
            return;
        }

        tbody.innerHTML = farmers.map(farmer => `
            <tr>
                <td>${farmer.id}</td>
                <td>${farmer.username || 'N/A'}</td>
                <td>${farmer.name}</td>
                <td>${farmer.location}</td>
                <td>${farmer.email || 'N/A'}</td>
                <td>${farmer.phone || farmer.contact || 'N/A'}</td>
                <td>${new Date(farmer.created_at).toLocaleDateString()}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="editFarmer(${farmer.id})">Edit</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteFarmer(${farmer.id})">Delete</button>
                </td>
            </tr>
        `).join('');
    }

    async addFarmer() {
        const form = document.getElementById('addFarmerForm');
        const formData = new FormData(form);
        
        try {
            const response = await fetch('api/farmers.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Farmer added successfully!', 'success');
                form.reset();
                this.closeModal('addFarmerModal');
                await this.loadFarmers();
            } else {
                this.showNotification(data.message, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to add farmer', 'error');
        }
    }

    async handleAddPrice() {
        const form = document.getElementById('addPriceForm');
        const formData = new FormData(form);
        
        const priceId = formData.get('price_id');
        const isEdit = priceId && priceId !== '';
        
        const priceData = {
            crop_name: formData.get('crop_name'),
            location: formData.get('location'),
            price_per_kg: parseFloat(formData.get('price_per_kg')),
            date: formData.get('date'),
            demand_level: formData.get('demand_level'),
            quality_grade: formData.get('quality_grade')
        };
        
        try {
            // Use update_price action for edits, add_price for new entries
            const action = isEdit ? 'update_price' : 'add_price';
            const response = await fetch(`api/crop-prices.php?action=${action}`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(priceData)
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification(isEdit ? 'Price updated successfully!' : 'Price added successfully!', 'success');
                form.reset();
                document.getElementById('editPriceId').value = '';
                document.getElementById('priceDate').value = new Date().toISOString().split('T')[0];
                document.getElementById('addPriceModalTitle').innerHTML = '<i class="fas fa-dollar-sign"></i> Add Crop Price';
                this.closeModal('addPriceModal');
                await this.loadAdminMarketPrices();
            } else {
                this.showNotification(data.error || 'Failed to save price', 'error');
            }
        } catch (error) {
            console.error('Error saving price:', error);
            this.showNotification('Failed to save price', 'error');
        }
    }

    async updateFarmer() {
        const form = document.getElementById('editFarmerForm');
        const formData = new FormData(form);
        
        try {
            const response = await fetch('api/farmers.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: formData.get('id'),
                    username: formData.get('username'),
                    name: formData.get('name'),
                    location: formData.get('location'),
                    email: formData.get('email'),
                    phone: formData.get('phone')
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Farmer updated successfully!', 'success');
                this.closeModal('editFarmerModal');
                await this.loadFarmers();
            } else {
                this.showNotification(data.message, 'error');
            }
        } catch (error) {
            this.showNotification('Failed to update farmer', 'error');
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
            
            try {
                // Load existing alerts from database
                const response = await fetch('api/alerts.php');
                const data = await response.json();

                if (data.success) {
                    // Combine external alerts with database alerts
                    const allAlerts = this.combineAlerts(data.data || [], externalAlerts || []);
                    this.displayAlerts(allAlerts);
                } else {
                    // If database fails, still show external alerts
                    this.displayAlerts(externalAlerts || []);
                }
            } catch (error) {
                console.error('Error loading database alerts:', error);
                // Show external alerts even if database fails
                this.displayAlerts(externalAlerts || []);
            }
        } catch (error) {
            console.error('Error loading alerts:', error);
            // Always clear loading state, even on error
            this.displayAlerts([]);
        }
    }
    
    async loadExternalWeatherAlerts() {
        try {
            // Default to Manila, Philippines coordinates
            const latitude = 14.5995;
            const longitude = 120.9842;
            
            // Try to get user's location from session or settings
            const userLocation = this.getUserLocation();
            let result;
            
            if (userLocation && userLocation.latitude && userLocation.longitude) {
                result = await APIUtils.getSevereWeatherAlerts(
                    userLocation.latitude,
                    userLocation.longitude
                );
            } else {
                // Use default location
                result = await APIUtils.getSevereWeatherAlerts(latitude, longitude);
            }
            
            if (result.success && result.data && result.data.length > 0) {
                // Display external alerts prominently in banner
                this.displayExternalAlerts(result.data);
                
                // Store them in database
                await this.storeExternalAlerts(result.data);
                
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
            id: `external_${Date.now()}_${index}`, // Temporary ID for external alerts
            type: alert.type || 'severe_weather',
            severity: alert.severity || 'medium',
            description: alert.description || alert.title || 'Severe weather alert',
            message: alert.description || alert.title || 'Severe weather alert',
            status: 'active',
            created_at: alert.effective || new Date().toISOString(),
            is_external: true, // Flag to identify external alerts
            urgency: alert.urgency || 'expected',
            category: alert.category || 'severe_weather',
            title: alert.title || alert.type || 'Severe Weather Alert'
        }));
    }
    
    combineAlerts(databaseAlerts, externalAlerts) {
        // Combine and sort alerts by created_at (most recent first)
        const allAlerts = [...databaseAlerts, ...externalAlerts];
        
        // Sort by created_at descending
        allAlerts.sort((a, b) => {
            const dateA = new Date(a.created_at || 0);
            const dateB = new Date(b.created_at || 0);
            return dateB - dateA;
        });
        
        return allAlerts;
    }

    async loadReports() {
        try {
            // Load report statistics/preview data
            // This can be used to show report summaries before generation
            const [analyticsResponse, alertsResponse, farmersResponse] = await Promise.all([
                fetch('api/analytics.php').catch(() => null),
                fetch('api/alerts.php?limit=100').catch(() => null),
                fetch('api/farmers.php').catch(() => null)
            ]);

            // Store data for report generation with proper error handling
            if (analyticsResponse && analyticsResponse.ok) {
                try {
                    const contentType = analyticsResponse.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        const analyticsData = await analyticsResponse.json();
                        if (analyticsData && analyticsData.success) {
                            this.reportAnalytics = analyticsData.data;
                        }
                    } else {
                        console.warn('Analytics API returned non-JSON response');
                    }
                } catch (jsonError) {
                    console.error('Error parsing analytics JSON:', jsonError);
                }
            }

            if (alertsResponse && alertsResponse.ok) {
                try {
                    const contentType = alertsResponse.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        const alertsData = await alertsResponse.json();
                        if (alertsData && alertsData.success) {
                            this.reportAlerts = alertsData.data;
                        }
                    } else {
                        console.warn('Alerts API returned non-JSON response');
                    }
                } catch (jsonError) {
                    console.error('Error parsing alerts JSON:', jsonError);
                }
            }

            if (farmersResponse && farmersResponse.ok) {
                try {
                    const contentType = farmersResponse.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        const farmersData = await farmersResponse.json();
                        if (farmersData && farmersData.success) {
                            this.reportFarmers = farmersData.data;
                        }
                    } else {
                        console.warn('Farmers API returned non-JSON response');
                    }
                } catch (jsonError) {
                    console.error('Error parsing farmers JSON:', jsonError);
                }
            }

            // Update report cards with summary information
            this.updateReportCards();
        } catch (error) {
            console.error('Error loading reports data:', error);
            // Don't show error to user as this is background loading
            // Reports can still be generated even if preview data fails
        }
    }

    updateReportCards() {
        // Update report cards with summary statistics
        const alertsCount = this.reportAlerts?.length || 0;
        const farmersCount = this.reportFarmers?.length || 0;
        const activeAlerts = this.reportAlerts?.filter(a => a.status === 'active').length || 0;

        // You can add visual updates here if needed
        console.log('Reports data loaded:', {
            alerts: alertsCount,
            farmers: farmersCount,
            activeAlerts: activeAlerts
        });
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
    
    async storeExternalAlerts(alerts) {
        try {
            // Store external alerts in database via API
            for (const alert of alerts) {
                await fetch('api/alerts.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        type: alert.type || 'severe_weather',
                        severity: alert.severity || 'medium',
                        description: alert.description || alert.title || 'Severe weather alert'
                    })
                });
            }
        } catch (error) {
            console.error('Error storing external alerts:', error);
        }
    }
    
    getUserLocation() {
        // Try to get location from session storage or settings
        try {
            const stored = sessionStorage.getItem('userLocation');
            if (stored) {
                return JSON.parse(stored);
            }
        } catch (e) {
            // Ignore
        }
        return null;
    }

    displayAlerts(alerts) {
        const container = document.getElementById('alertsList');
        
        if (!container) {
            return;
        }
        
        // Ensure alerts is an array
        if (!alerts || !Array.isArray(alerts)) {
            alerts = [];
        }
        
        if (alerts.length === 0) {
            container.innerHTML = '<div class="no-alerts">No alerts found</div>';
            return;
        }

        // Store alerts for modal access
        this.currentAlerts = alerts;
        
        container.innerHTML = alerts.map((alert, index) => {
            // Get appropriate icon based on alert type
            const icon = this.getAlertIcon(alert);
            const isExternal = alert.is_external || false;
            const alertType = alert.type || 'severe_weather';
            const alertMessage = alert.message || alert.description || 'No description available';
            const alertTitle = alert.title || alert.type || 'Alert';
            const createdDate = alert.created_at ? new Date(alert.created_at).toLocaleString() : 'Just now';
            
            return `
            <div class="alert-item ${alert.severity} ${isExternal ? 'external-alert' : ''}" onclick="window.adminApp.showAlertDetail(${index})">
                <div class="alert-icon">
                    <i class="${icon}"></i>
                </div>
                <div class="alert-content">
                    <h3>${this.escapeHtml(alertTitle)} ${isExternal ? '<span class="external-badge">Live</span>' : ''}</h3>
                    <p>${this.escapeHtml(alertMessage)}</p>
                    <span class="alert-time">
                        <i class="fas fa-clock"></i> ${createdDate}
                        ${isExternal && alert.urgency ? ` • <span class="urgency-badge ${alert.urgency}">${alert.urgency}</span>` : ''}
                    </span>
                </div>
                <div class="alert-actions" onclick="event.stopPropagation();">
                    ${!isExternal ? `<button class="btn btn-sm btn-primary" onclick="resolveAlert(${alert.id})">Resolve</button>` : ''}
                </div>
            </div>
            `;
        }).join('');
    }
    
    getAlertIcon(alert) {
        const type = (alert.type || '').toLowerCase();
        const category = (alert.category || '').toLowerCase();
        
        if (type.includes('typhoon') || type.includes('storm') || category.includes('typhoon')) {
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
        } else {
            return 'fas fa-exclamation-triangle';
        }
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    updateCurrentDate() {
        const dateElement = document.getElementById('currentDate');
        if (dateElement) {
            dateElement.textContent = new Date().toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
    }

    async loadSystemSettings() {
        try {
            const response = await fetch('api/system-settings.php');
            const data = await response.json();
            
            if (data.success) {
                this.populateSettingsForm(data.data);
            }
        } catch (error) {
            console.error('Error loading system settings:', error);
        }
    }
    
    populateSettingsForm(settings) {
        // Weather Settings
        if (document.getElementById('weatherApiKey')) {
            document.getElementById('weatherApiKey').value = settings.weather_api_key || '';
        }
        if (document.getElementById('defaultLocation')) {
            document.getElementById('defaultLocation').value = settings.default_location || '';
        }
        
        // Alert Settings
        if (document.getElementById('tempThreshold')) {
            document.getElementById('tempThreshold').value = settings.extreme_heat_threshold || '35';
        }
        if (document.getElementById('rainThreshold')) {
            document.getElementById('rainThreshold').value = settings.heavy_rain_threshold || '30';
        }
        
        // System Settings
        if (document.getElementById('dataRetention')) {
            document.getElementById('dataRetention').value = settings.data_retention_days || '90';
        }
        if (document.getElementById('autoBackup')) {
            document.getElementById('autoBackup').checked = settings.auto_backup_enabled === 'true';
        }
        if (document.getElementById('maxAlertsPerPage')) {
            document.getElementById('maxAlertsPerPage').value = settings.max_alerts_per_page || '50';
        }
        if (document.getElementById('maxFarmersPerPage')) {
            document.getElementById('maxFarmersPerPage').value = settings.max_farmers_per_page || '100';
        }
        
        // Notification Settings
        if (document.getElementById('emailNotifications')) {
            document.getElementById('emailNotifications').checked = settings.email_notifications === 'true';
        }
        if (document.getElementById('smsNotifications')) {
            document.getElementById('smsNotifications').checked = settings.sms_notifications === 'true';
        }
        if (document.getElementById('notificationFrequency')) {
            document.getElementById('notificationFrequency').value = settings.notification_frequency || 'immediate';
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

    // Forecasting functions
    async loadForecastData() {
        try {
            const period = document.getElementById('forecastPeriod')?.value || 7;
            const method = document.getElementById('forecastMethod')?.value || 'exponential_smoothing';
            console.log('Loading forecast data for period:', period, 'method:', method);
            
            // Update method info display
            this.updateForecastMethodInfo(method);
            
            // Try real forecasting API first, fallback to sample data API
            let response;
            let data;
            try {
                response = await fetch(`api/forecasting-real.php?action=forecast&days=${period}&method=${method}`);
                if (!response.ok) {
                    throw new Error('Real API not available');
                }
                data = await response.json();
                console.log('Forecast API response status:', response.status);
                console.log('Forecast data received:', data);
            } catch (error) {
                console.log('Real forecasting API not available, using sample data API');
                try {
                    response = await fetch(`api/forecasting.php?action=forecast&days=${period}&method=${method}`);
                    if (!response.ok) {
                        throw new Error('Forecast API not available');
                    }
                    data = await response.json();
                    console.log('Forecast API response status:', response.status);
                    console.log('Forecast data received:', data);
                } catch (fallbackError) {
                    console.error('Both forecast APIs failed:', fallbackError);
                    this.showNotification('Failed to load forecast data. Please try again later.', 'error');
                    return;
                }
            }
            
            if (data && data.success && data.data) {
                // Validate data structure
                if (!data.data.historical || !data.data.forecast) {
                    console.error('Invalid forecast data structure:', data.data);
                    this.showNotification('Invalid forecast data format', 'error');
                    return;
                }
                
                // Check if forecast has forecast_data array
                if (!data.data.forecast.forecast_data && Array.isArray(data.data.forecast)) {
                    // Handle case where forecast is directly an array
                    data.data.forecast = {
                        forecast_data: data.data.forecast,
                        trend_direction: 'stable',
                        method: 'unknown'
                    };
                }
                
                this.renderForecastChart(data.data);
                this.updateForecastSummary(data.data.forecast);
                
                // Show note if using sample data
                if (data.data.note) {
                    console.log('Note:', data.data.note);
                }
            } else {
                const errorMsg = data?.message || 'Unknown error';
                console.error('Forecast data error:', errorMsg);
                this.showNotification('Failed to load forecast data: ' + errorMsg, 'error');
            }
        } catch (error) {
            console.error('Error loading forecast data:', error);
            this.showNotification('Error loading forecast data: ' + error.message, 'error');
        }
    }
    
    renderForecastChart(forecastData) {
        const ctx = document.getElementById('forecastChart');
        if (!ctx) {
            console.error('Forecast chart canvas not found');
            return;
        }

        console.log('Rendering forecast chart with data:', forecastData);

        // Destroy existing chart if it exists
        if (this.forecastChart) {
            this.forecastChart.destroy();
        }

        // Validate data structure
        if (!forecastData.historical || !forecastData.forecast) {
            console.error('Invalid forecast data structure:', forecastData);
            this.showNotification('Invalid forecast data structure', 'error');
            return;
        }

        const historical = forecastData.historical || [];
        const forecastObj = forecastData.forecast;
        const forecast = forecastObj.forecast_data || forecastObj.forecast || [];

        if (!Array.isArray(historical) || !Array.isArray(forecast)) {
            console.error('Historical or forecast data is not an array:', { historical, forecast });
            this.showNotification('Invalid forecast data format', 'error');
            return;
        }

        // Get the current method
        const method = document.getElementById('forecastMethod')?.value || 'exponential_smoothing';
        const isStraightLine = method === 'straight_line';
        
        // Combine historical and forecast data
        const allDates = [...historical.map(h => h.date), ...forecast.map(f => f.date)];
        
        // Extract values based on method
        const historicalValues = isStraightLine 
            ? historical.map(h => h.avg_temperature || 0)
            : historical.map(h => h.daily_records || 0);
        
        const forecastValues = forecast.map(f => {
            if (isStraightLine) {
                return f.predicted_temperature || f.predicted_records || f.predicted_value || 0;
            } else {
                return f.predicted_records || f.predicted_value || 0;
            }
        });

        // Create datasets
        const datasets = [
            {
                label: isStraightLine ? 'Historical Average Temperature' : 'Historical Records Count',
                data: [...historicalValues, ...new Array(forecast.length).fill(null)],
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                fill: false,
                tension: 0.1
            },
            {
                label: isStraightLine ? 'Forecast (Straight Line - Realistic)' : 'Forecast (Exponential Smoothing)',
                data: [...new Array(historical.length).fill(null), ...forecastValues],
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderDash: [5, 5],
                fill: false,
                tension: 0.1
            }
        ];
        
        // Add optimistic and pessimistic lines for straight line method
        if (isStraightLine && forecast.length > 0 && forecast[0].optimistic !== undefined) {
            datasets.push({
                label: 'Forecast (Optimistic)',
                data: [...new Array(historical.length).fill(null), ...forecast.map(f => f.optimistic || 0)],
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                borderDash: [3, 3],
                fill: false,
                tension: 0.1
            });
            datasets.push({
                label: 'Forecast (Pessimistic)',
                data: [...new Array(historical.length).fill(null), ...forecast.map(f => f.pessimistic || 0)],
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                borderDash: [3, 3],
                fill: false,
                tension: 0.1
            });
        }

        this.forecastChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: allDates.map(date => new Date(date).toLocaleDateString()),
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: false
                    },
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12,
                                weight: '500'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14,
                            weight: '600'
                        },
                        bodyFont: {
                            size: 13
                        },
                        borderColor: '#667eea',
                        borderWidth: 1,
                        cornerRadius: 8
                    }
                },
                scales: {
                    y: {
                        beginAtZero: !isStraightLine, // Don't force zero for temperature
                        title: {
                            display: true,
                            text: isStraightLine ? 'Temperature (°C)' : 'Records Count',
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#495057'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            color: '#6c757d'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Date',
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#495057'
                        },
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            color: '#6c757d'
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                elements: {
                    point: {
                        radius: 4,
                        hoverRadius: 6,
                        borderWidth: 2
                    },
                    line: {
                        borderWidth: 2.5,
                        tension: 0.3
                    }
                }
            }
        });

        // Render forecast table
        this.renderForecastTable(forecastData);
    }

    renderForecastTable(forecastData) {
        const tableContainer = document.getElementById('forecastTableContainer');
        if (!tableContainer) {
            console.error('Forecast table container not found');
            return;
        }

        if (!forecastData.forecast) {
            console.error('No forecast data available for table');
            return;
        }

        const forecastObj = forecastData.forecast;
        const forecast = forecastObj.forecast_data || forecastObj.forecast || [];
        const method = forecastObj.method || 'unknown';
        const isStraightLine = method.includes('straight_line');
        const methodName = isStraightLine 
            ? 'Straight Line (3 Modes) - Average Temperature'
            : (method === 'triple_exponential_smoothing' ? 'Triple Exponential Smoothing (Holt-Winters)' : method);

        let tableHTML = `
            <div class="forecast-table-wrapper">
                <table class="forecast-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            ${isStraightLine 
                                ? '<th>Realistic (°C)</th><th>Optimistic (°C)</th><th>Pessimistic (°C)</th>'
                                : '<th>Predicted Records</th>'}
                            <th>Confidence</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        forecast.forEach(item => {
            const date = new Date(item.date);
            const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
            const confidence = item.confidence || 0;

            if (isStraightLine) {
                const realistic = item.predicted_temperature || item.realistic || item.predicted_records || 0;
                const optimistic = item.optimistic || realistic;
                const pessimistic = item.pessimistic || realistic;
                
                tableHTML += `
                    <tr>
                        <td>${date.toLocaleDateString()}</td>
                        <td>${dayName}</td>
                        <td><strong>${realistic.toFixed(1)}</strong></td>
                        <td>${optimistic.toFixed(1)}</td>
                        <td>${pessimistic.toFixed(1)}</td>
                        <td>
                            <div class="confidence-bar">
                                <div class="confidence-fill" style="width: ${confidence}%"></div>
                                <span class="confidence-text">${confidence}%</span>
                            </div>
                        </td>
                    </tr>
                `;
            } else {
                const predictedValue = item.predicted_records || item.predicted_value || 0;
                const formattedValue = typeof predictedValue === 'number' ? predictedValue.toFixed(2) : predictedValue;
                tableHTML += `
                    <tr>
                        <td>${date.toLocaleDateString()}</td>
                        <td>${dayName}</td>
                        <td><strong>${formattedValue}</strong></td>
                        <td>
                            <div class="confidence-bar">
                                <div class="confidence-fill" style="width: ${confidence}%"></div>
                                <span class="confidence-text">${confidence}%</span>
                            </div>
                        </td>
                    </tr>
                `;
            }
        });

        tableHTML += `
                    </tbody>
                </table>
            </div>
        `;

        tableContainer.innerHTML = tableHTML;
    }
    
    updateForecastSummary(forecastInfo) {
        const trendElement = document.getElementById('trendDirection');
        const confidenceElement = document.getElementById('forecastConfidence');
        
        if (!forecastInfo) {
            console.error('No forecast info provided');
            return;
        }
        
        if (trendElement) {
            const trendDirection = forecastInfo.trend_direction || 'stable';
            const trendText = trendDirection.charAt(0).toUpperCase() + trendDirection.slice(1);
            trendElement.textContent = trendText;
            trendElement.className = `insight-value ${trendDirection}`;
        }
        
        if (confidenceElement) {
            const forecastData = forecastInfo.forecast_data || forecastInfo.forecast || [];
            const confidence = forecastData.length > 0 
                ? (forecastData[0]?.confidence || forecastData[0]?.confidence_score || 0)
                : 0;
            confidenceElement.textContent = `${confidence}%`;
        }
    }

    async loadMarketTiming(userId = null) {
        const container = document.getElementById('marketTimingContainer');
        if (!container) return;

        try {
            // If userId is provided, load that user's crops
            if (userId) {
                await this.loadUserMarketTiming(userId);
                return;
            }

            // Otherwise, load the farmers list
            container.innerHTML = '<div class="loading">Loading farmers...</div>';
            
            const response = await fetch('api/market-timing.php?action=list_farmers', {
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('Farmers List API Response:', data);

            if (data.success) {
                if (data.data && data.data.farmers && data.data.farmers.length > 0) {
                    this.renderFarmersList(data.data.farmers);
                } else {
                    container.innerHTML = `
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            No farmers with upcoming harvests found.
                        </div>
                    `;
                }
            } else {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        ${data.message || 'Failed to load farmers list.'}
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

    async loadUserMarketTiming(userId) {
        const container = document.getElementById('marketTimingContainer');
        if (!container) return;

        try {
            container.innerHTML = '<div class="loading">Loading market timing recommendations...</div>';
            
            const response = await fetch(`api/market-timing.php?user_id=${userId}`, {
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('User Market Timing API Response:', data);

            if (data.success) {
                if (data.data && data.data.crops && data.data.crops.length > 0) {
                    this.renderMarketTiming(data.data, userId);
                } else {
                    container.innerHTML = `
                        <button class="btn btn-secondary" onclick="window.adminApp.loadMarketTiming()" style="margin-bottom: 15px;">
                            <i class="fas fa-arrow-left"></i> Back to Farmers List
                        </button>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            ${data.message || data.data?.message || 'No crops approaching harvest for this farmer.'}
                        </div>
                    `;
                }
            } else {
                container.innerHTML = `
                    <button class="btn btn-secondary" onclick="window.adminApp.loadMarketTiming()" style="margin-bottom: 15px;">
                        <i class="fas fa-arrow-left"></i> Back to Farmers List
                    </button>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        ${data.message || 'Failed to load market timing recommendations.'}
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading user market timing:', error);
            container.innerHTML = `
                <button class="btn btn-secondary" onclick="window.adminApp.loadMarketTiming()" style="margin-bottom: 15px;">
                    <i class="fas fa-arrow-left"></i> Back to Farmers List
                </button>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load market timing data: ${error.message}. Please try again later.
                </div>
            `;
        }
    }

    renderFarmersList(farmers) {
        const container = document.getElementById('marketTimingContainer');
        if (!container) return;

        let html = `
            <div class="farmers-list-container">
                <h3 style="margin-bottom: 20px; color: #333;">Select a Farmer to View Market Timing Data</h3>
                <div class="farmers-grid">
        `;

        farmers.forEach(farmer => {
            html += `
                <div class="farmer-card" onclick="window.adminApp.loadUserMarketTiming(${farmer.id})" style="cursor: pointer;">
                    <div class="farmer-card-header">
                        <i class="fas fa-user-circle" style="font-size: 2.5em; color: #007bff; margin-bottom: 10px;"></i>
                        <h4>${farmer.full_name || 'Unknown Farmer'}</h4>
                    </div>
                    <div class="farmer-card-body">
                        <div class="farmer-info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>${farmer.location || 'N/A'}</span>
                        </div>
                        ${farmer.email ? `
                        <div class="farmer-info-item">
                            <i class="fas fa-envelope"></i>
                            <span>${farmer.email}</span>
                        </div>
                        ` : ''}
                        ${farmer.phone ? `
                        <div class="farmer-info-item">
                            <i class="fas fa-phone"></i>
                            <span>${farmer.phone}</span>
                        </div>
                        ` : ''}
                        <div class="farmer-stats">
                            <div class="stat-item">
                                <div class="stat-value">${farmer.upcoming_crop_count || 0}</div>
                                <div class="stat-label">Upcoming Crops</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${farmer.crop_count || 0}</div>
                                <div class="stat-label">Total Crops</div>
                            </div>
                        </div>
                    </div>
                    <div class="farmer-card-footer">
                        <button class="btn btn-primary btn-block">
                            <i class="fas fa-eye"></i> View Market Timing
                        </button>
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;

        container.innerHTML = html;
    }

    renderMarketTiming(data, userId = null) {
        const container = document.getElementById('marketTimingContainer');
        if (!container || !data.crops || data.crops.length === 0) {
            const backButton = userId ? `
                <button class="btn btn-secondary" onclick="window.adminApp.loadMarketTiming()" style="margin-bottom: 15px;">
                    <i class="fas fa-arrow-left"></i> Back to Farmers List
                </button>
            ` : '';
            container.innerHTML = `
                ${backButton}
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No crops approaching harvest. Add crops to get market timing recommendations.
                </div>
            `;
            return;
        }

        const backButton = userId ? `
            <button class="btn btn-secondary" onclick="window.adminApp.loadMarketTiming()" style="margin-bottom: 20px;">
                <i class="fas fa-arrow-left"></i> Back to Farmers List
            </button>
        ` : '';

        let html = `${backButton}<div class="market-timing-grid">`;

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
                    ${crop.farmer_info ? `
                    <div class="farmer-info-section" style="background: #f8f9fa; padding: 12px; margin-bottom: 15px; border-radius: 6px; border-left: 3px solid #007bff;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-user" style="color: #007bff;"></i>
                            <div>
                                <strong>${crop.farmer_info.name || 'Farmer'}</strong>
                                ${crop.farmer_info.location ? `<div style="font-size: 0.9em; color: #666;">📍 ${crop.farmer_info.location}</div>` : ''}
                            </div>
                        </div>
                    </div>
                    ` : ''}
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
    }

    async loadBackupSection() {
        await this.getBackupStatus();
        await this.listBackups();
    }

    async getBackupStatus() {
        try {
            const response = await fetch('api/backup-restore.php?action=status');
            const data = await response.json();

            if (data.success) {
                // Update status display
                const lastBackupEl = document.getElementById('lastBackupTime');
                const totalBackupsEl = document.getElementById('totalBackups');
                const diskUsageEl = document.getElementById('diskUsage');
                const freeSpaceEl = document.getElementById('freeSpace');

                if (lastBackupEl) {
                    lastBackupEl.textContent = data.last_backup 
                        ? new Date(data.last_backup.time).toLocaleString() 
                        : 'Never';
                }

                if (totalBackupsEl) {
                    const total = (data.backup_count.database || 0) + (data.backup_count.files || 0);
                    totalBackupsEl.textContent = total;
                }

                if (diskUsageEl) {
                    diskUsageEl.textContent = data.disk_usage.total_size_formatted || '0 B';
                }

                if (freeSpaceEl) {
                    freeSpaceEl.textContent = data.disk_usage.free_space_formatted || '-';
                }
            }
        } catch (error) {
            console.error('Error fetching backup status:', error);
        }
    }

    async listBackups() {
        try {
            const response = await fetch('api/backup-restore.php?action=list');
            const data = await response.json();

            if (data.success) {
                this.displayBackups(data.backups.database, 'database');
                this.displayBackups(data.backups.files, 'files');
            }
        } catch (error) {
            console.error('Error listing backups:', error);
            this.showNotification('Failed to load backups', 'error');
        }
    }

    displayBackups(backups, type) {
        const tbodyId = type === 'database' ? 'databaseBackupsBody' : 'fileBackupsBody';
        const tbody = document.getElementById(tbodyId);
        
        if (!tbody) return;

        if (!backups || backups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center">No backups found</td></tr>';
            return;
        }

        let html = '';
        backups.forEach(backup => {
            const createdDate = new Date(backup.created).toLocaleString();
            html += `
                <tr>
                    <td>${backup.file}</td>
                    <td>${createdDate}</td>
                    <td>${backup.size_formatted}</td>
                    <td>
                        <div class="backup-actions-buttons">
                            ${type === 'database' ? `
                                <button class="btn btn-sm btn-primary" onclick="downloadBackup('${backup.file}', '${type}')" title="Download">
                                    <i class="fas fa-download"></i>
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="restoreBackup('${backup.file}', '${type}')" title="Restore">
                                    <i class="fas fa-undo"></i>
                                </button>
                            ` : `
                                <button class="btn btn-sm btn-primary" onclick="downloadBackup('${backup.file}', '${type}')" title="Download">
                                    <i class="fas fa-download"></i>
                                </button>
                            `}
                            <button class="btn btn-sm btn-danger" onclick="deleteBackup('${backup.file}', '${type}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    async loadAdminMarketPrices() {
        const container = document.getElementById('adminMarketPricesContainer');
        if (!container) return;

        try {
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading market prices...</div>';
            
            const locationSelect = document.getElementById('adminMarketPricesLocation');
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
            console.log('Admin Market Prices API Response:', data);

            if (data.success && data.prices && data.prices.length > 0) {
                this.adminMarketPricesData = data.prices; // Store for filtering/sorting
                this.renderAdminMarketPrices(data.prices, data);
            } else {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        ${data.message || 'No market prices available at this time.'}
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading admin market prices:', error);
            container.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load market prices: ${error.message}. Please try again later.
                </div>
            `;
        }
    }

    renderAdminMarketPrices(prices, data) {
        const container = document.getElementById('adminMarketPricesContainer');
        if (!container) return;

        // Update info display
        const infoEl = document.getElementById('adminMarketPricesInfo');
        const updatedAtEl = document.getElementById('adminMarketPricesUpdatedAt');
        const locationEl = document.getElementById('adminMarketPricesLocationDisplay');
        const totalCropsEl = document.getElementById('adminMarketPricesTotalCrops');
        
        if (infoEl) infoEl.style.display = 'block';
        if (updatedAtEl) updatedAtEl.textContent = data.updated_at || 'Just now';
        if (locationEl) locationEl.textContent = data.location || 'Manila';
        if (totalCropsEl) totalCropsEl.textContent = data.total_crops || prices.length;

        // Render prices grid with admin actions
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
                               price.source === 'database' ? 'Cached' : 
                               price.source === 'admin' ? 'Admin' :
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
                        <div class="admin-price-actions" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-light); display: flex; gap: 0.5rem;">
                            <button class="btn btn-sm btn-primary" onclick="editPrice('${price.crop_name}', '${data.location}', ${price.price_per_kg}, '${price.date}', '${price.demand_level || 'medium'}', '${price.quality_grade || 'standard'}')" style="flex: 1;">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            ${price.source === 'admin' ? `
                            <button class="btn btn-sm btn-danger" onclick="deletePrice('${price.crop_name}', '${data.location}', '${price.date}')" style="flex: 1;">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                            ` : ''}
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
function showAddFarmerModal() {
    document.getElementById('addFarmerModal').style.display = 'block';
}

// Backup Management Functions
let currentRestoreFile = null;
let currentRestoreType = 'database';

async function createBackup() {
    const btn = document.getElementById('createBackupBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Backup...';
    }

    try {
        const response = await fetch('api/backup-restore.php?action=create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action: 'create' })
        });

        const data = await response.json();

        if (data.success) {
            window.adminApp.showNotification('Backup created successfully!', 'success');
            await window.adminApp.getBackupStatus();
            await window.adminApp.listBackups();
        } else {
            window.adminApp.showNotification('Backup failed: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Error creating backup:', error);
        window.adminApp.showNotification('Failed to create backup', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plus"></i> Create Backup Now';
        }
    }
}

async function refreshBackupList() {
    await window.adminApp.getBackupStatus();
    await window.adminApp.listBackups();
    window.adminApp.showNotification('Backup list refreshed', 'success');
}

function restoreBackup(filename, type) {
    currentRestoreFile = filename;
    currentRestoreType = type;

    // Find backup info
    const backups = type === 'database' 
        ? document.querySelectorAll('#databaseBackupsBody tr')
        : document.querySelectorAll('#fileBackupsBody tr');

    let backupInfo = null;
    backups.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0 && cells[0].textContent === filename) {
            backupInfo = {
                file: filename,
                date: cells[1].textContent,
                size: cells[2].textContent
            };
        }
    });

    if (backupInfo) {
        document.getElementById('restoreFileName').textContent = backupInfo.file;
        document.getElementById('restoreFileDate').textContent = backupInfo.date;
        document.getElementById('restoreFileSize').textContent = backupInfo.size;
    }

    // Reset checkbox
    const checkbox = document.getElementById('restoreConfirmCheckbox');
    const confirmBtn = document.getElementById('confirmRestoreBtn');
    if (checkbox) {
        checkbox.checked = false;
    }
    if (confirmBtn) {
        confirmBtn.disabled = true;
    }

    // Show modal
    document.getElementById('restoreBackupModal').style.display = 'block';

    // Enable/disable confirm button based on checkbox
    if (checkbox && confirmBtn) {
        checkbox.onchange = function() {
            confirmBtn.disabled = !this.checked;
        };
    }
}

async function confirmRestore() {
    if (!currentRestoreFile) {
        window.adminApp.showNotification('No backup file selected', 'error');
        return;
    }

    const confirmBtn = document.getElementById('confirmRestoreBtn');
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
    }

    try {
        const response = await fetch('api/backup-restore.php?action=restore', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'restore',
                file: currentRestoreFile,
                type: currentRestoreType
            })
        });

        const data = await response.json();

        if (data.success) {
            window.adminApp.showNotification('Database restored successfully!', 'success');
            closeModal('restoreBackupModal');
            // Refresh backup list
            await refreshBackupList();
        } else {
            window.adminApp.showNotification('Restore failed: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Error restoring backup:', error);
        window.adminApp.showNotification('Failed to restore backup', 'error');
    } finally {
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = 'Restore Database';
        }
    }
}

async function deleteBackup(filename, type) {
    if (!confirm(`Are you sure you want to delete this backup?\n\nFile: ${filename}\n\nThis action cannot be undone.`)) {
        return;
    }

    try {
        const response = await fetch(`api/backup-restore.php?action=delete&file=${encodeURIComponent(filename)}&type=${type}`, {
            method: 'DELETE'
        });

        const data = await response.json();

        if (data.success) {
            window.adminApp.showNotification('Backup deleted successfully', 'success');
            await window.adminApp.getBackupStatus();
            await window.adminApp.listBackups();
        } else {
            window.adminApp.showNotification('Delete failed: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Error deleting backup:', error);
        window.adminApp.showNotification('Failed to delete backup', 'error');
    }
}

function downloadBackup(filename, type) {
    const url = `api/backup-restore.php?action=download&file=${encodeURIComponent(filename)}&type=${type}`;
    window.location.href = url;
}

// Reset chart zoom function
function resetChartZoom() {
    if (window.adminApp && window.adminApp.forecastChart) {
        if (typeof window.adminApp.forecastChart.resetZoom === 'function') {
            window.adminApp.forecastChart.resetZoom();
        } else {
            // Fallback: recreate chart if resetZoom is not available
            if (window.adminApp.loadForecastData) {
                window.adminApp.loadForecastData();
            }
        }
    }
}

// Global function to close alert modal
function closeAlertModal() {
    const modal = document.getElementById('alertDetailModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

// Global function to resolve alert from modal
function resolveAlertFromModal() {
    const resolveBtn = document.getElementById('alertModalResolveBtn');
    if (resolveBtn && resolveBtn.getAttribute('data-alert-id')) {
        const alertId = resolveBtn.getAttribute('data-alert-id');
        resolveAlert(alertId);
        closeAlertModal();
    }
}

// Export chart function
function exportChart(canvasId, filename) {
    if (!window.adminApp) {
        console.error('AdminApp not initialized');
        return;
    }
    
    const chartMap = {
        'farmersLocationChart': window.adminApp.farmersLocationChart,
        'alertsTypeChart': window.adminApp.alertsTypeChart,
        'alertsStatusChart': window.adminApp.alertsStatusChart,
        'alertsSeverityChart': window.adminApp.alertsSeverityChart,
        'weatherTrendsChart': window.adminApp.weatherTrendsChart,
        'userActivityChart': window.adminApp.userActivityChart,
        'cropDistributionChart': window.adminApp.cropDistributionChart,
        'alertTrendsChart': window.adminApp.alertTrendsChart,
        'alertsByHourChart': window.adminApp.alertsByHourChart
    };
    
    const chart = chartMap[canvasId];
    if (chart) {
        ChartUtils.exportChart(chart, filename, 'png');
        if (typeof showNotification === 'function') {
            showNotification(`Chart exported as ${filename}.png`, 'success');
        }
    } else {
        console.warn(`Chart not found for canvas: ${canvasId}`);
    }
}

async function checkWeatherConditions() {
    try {
        const response = await fetch('api/weather-check.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            app.showNotification(`Weather check completed. ${data.data.alerts_created} alerts generated.`, 'success');
            // Reload alerts
            app.loadAlerts();
        } else {
            app.showNotification(data.message, 'error');
        }
    } catch (error) {
        console.error('Error checking weather conditions:', error);
        app.showNotification('Failed to check weather conditions', 'error');
    }
}

async function runWeatherScheduler() {
    try {
        const response = await fetch('api/weather-scheduler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            app.showNotification(`Weather scheduler completed. ${data.data.alerts_generated} alerts generated.`, 'success');
            // Reload alerts
            app.loadAlerts();
        } else {
            app.showNotification(data.message, 'error');
        }
    } catch (error) {
        console.error('Error running weather scheduler:', error);
        app.showNotification('Failed to run weather scheduler', 'error');
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function refreshData() {
    window.location.reload();
}

function refreshWeather() {
    window.location.reload();
}

function checkWeatherConditions() {
    // Implementation for weather check
    console.log('Checking weather conditions...');
}

function exportFarmers() {
    // Implementation for farmer export
    console.log('Exporting farmers...');
}

async function generateReport(type) {
    try {
        // Show loading notification
        if (window.adminApp) {
            window.adminApp.showNotification('Generating report...', 'info');
        }

        let reportData = [];
        let filename = '';
        let headers = [];

        switch (type) {
            case 'weather':
                // Weather Report
                const weatherResponse = await fetch('api/analytics.php');
                if (!weatherResponse.ok) {
                    throw new Error(`Weather API returned status ${weatherResponse.status}`);
                }
                const contentType = weatherResponse.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Weather API returned non-JSON response');
                }
                const weatherData = await weatherResponse.json();
                
                if (weatherData.success && weatherData.data.weather) {
                    const weather = weatherData.data.weather;
                    reportData = [
                        ['Weather Report Summary'],
                        ['Generated:', new Date().toLocaleString()],
                        [''],
                        ['Total Weather Records:', weather.total || 0],
                        ['Average Temperature (30 days):', weather.avg_temperature ? weather.avg_temperature.toFixed(2) + '°C' : 'N/A'],
                        ['Average Humidity (30 days):', weather.avg_humidity ? weather.avg_humidity.toFixed(2) + '%' : 'N/A'],
                        ['Total Rainfall (30 days):', weather.total_rainfall ? weather.total_rainfall.toFixed(2) + 'mm' : 'N/A'],
                        [''],
                        ['Weather Conditions Distribution:']
                    ];
                    
                    if (weather.conditions_distribution) {
                        reportData.push(['Condition', 'Count']);
                        Object.entries(weather.conditions_distribution).forEach(([condition, count]) => {
                            reportData.push([condition, count]);
                        });
                    }
                }
                filename = `weather-report-${new Date().toISOString().split('T')[0]}.csv`;
                break;

            case 'farmers':
                // Farmers Report
                const farmersResponse = await fetch('api/farmers.php');
                if (!farmersResponse.ok) {
                    throw new Error(`Farmers API returned status ${farmersResponse.status}`);
                }
                const farmersContentType = farmersResponse.headers.get('content-type');
                if (!farmersContentType || !farmersContentType.includes('application/json')) {
                    throw new Error('Farmers API returned non-JSON response');
                }
                const farmersData = await farmersResponse.json();
                
                if (farmersData.success && farmersData.data) {
                    headers = ['ID', 'Name', 'Username', 'Email', 'Phone', 'Location', 'Latitude', 'Longitude', 'Status', 'Registered Date'];
                    reportData = [headers];
                    
                    farmersData.data.forEach(farmer => {
                        reportData.push([
                            farmer.id || '',
                            farmer.name || farmer.full_name || '',
                            farmer.username || '',
                            farmer.email || '',
                            farmer.phone || '',
                            farmer.location || '',
                            farmer.latitude || '',
                            farmer.longitude || '',
                            farmer.is_active ? 'Active' : 'Inactive',
                            farmer.created_at ? new Date(farmer.created_at).toLocaleDateString() : ''
                        ]);
                    });
                }
                filename = `farmers-report-${new Date().toISOString().split('T')[0]}.csv`;
                break;

            case 'alerts':
                // Weather Alerts Report
                const alertsResponse = await fetch('api/alerts.php?limit=all');
                if (!alertsResponse.ok) {
                    throw new Error(`Alerts API returned status ${alertsResponse.status}`);
                }
                const alertsContentType = alertsResponse.headers.get('content-type');
                if (!alertsContentType || !alertsContentType.includes('application/json')) {
                    throw new Error('Alerts API returned non-JSON response');
                }
                const alertsData = await alertsResponse.json();
                
                if (alertsData.success && alertsData.data) {
                    headers = ['ID', 'Type', 'Severity', 'Status', 'Description', 'Affected Farmers', 'Created Date'];
                    reportData = [headers];
                    
                    alertsData.data.forEach(alert => {
                        reportData.push([
                            alert.id || '',
                            alert.type || '',
                            alert.severity || '',
                            alert.status || '',
                            (alert.message || alert.description || '').substring(0, 100),
                            alert.affected_farmers || 0,
                            alert.created_at ? new Date(alert.created_at).toLocaleString() : ''
                        ]);
                    });
                }
                filename = `alerts-report-${new Date().toISOString().split('T')[0]}.csv`;
                break;

            case 'activity':
                // Farmer Activity Report
                const activityResponse = await fetch('api/analytics.php');
                if (!activityResponse.ok) {
                    throw new Error(`Analytics API returned status ${activityResponse.status}`);
                }
                const activityContentType = activityResponse.headers.get('content-type');
                if (!activityContentType || !activityContentType.includes('application/json')) {
                    throw new Error('Analytics API returned non-JSON response');
                }
                const activityData = await activityResponse.json();
                
                if (activityData.success && activityData.data.user_activity) {
                    const activity = activityData.data.user_activity;
                    reportData = [
                        ['Farmer Activity Report'],
                        ['Generated:', new Date().toLocaleString()],
                        [''],
                        ['Total Users:', activity.total_users || 0],
                        ['Active Users (Last 30 days):', activity.active_users || 0],
                        ['New Registrations (Last 7 days):', activity.new_registrations || 0],
                        ['New Registrations (Last 30 days):', activity.new_registrations_30d || 0],
                        [''],
                        ['Activity by Day (Last 30 days):']
                    ];
                    
                    if (activity.activity_by_day && Array.isArray(activity.activity_by_day)) {
                        reportData.push(['Date', 'Active Users']);
                        activity.activity_by_day.forEach(day => {
                            reportData.push([
                                day.date || '',
                                day.count || 0
                            ]);
                        });
                    }
                }
                filename = `activity-report-${new Date().toISOString().split('T')[0]}.csv`;
                break;

            case 'usage':
                // System Usage Report
                const usageResponse = await fetch('api/analytics.php');
                if (!usageResponse.ok) {
                    throw new Error(`Analytics API returned status ${usageResponse.status}`);
                }
                const usageContentType = usageResponse.headers.get('content-type');
                if (!usageContentType || !usageContentType.includes('application/json')) {
                    throw new Error('Analytics API returned non-JSON response');
                }
                const usageData = await usageResponse.json();
                
                if (usageData.success) {
                    const overview = usageData.data.overview || {};
                    const performance = usageData.data.performance || {};
                    
                    reportData = [
                        ['System Usage Report'],
                        ['Generated:', new Date().toLocaleString()],
                        [''],
                        ['Overview Statistics:'],
                        ['Total Farmers:', overview.total_farmers || 0],
                        ['Active Alerts:', overview.active_alerts || 0],
                        ['Weather Records Today:', overview.weather_records_today || 0],
                        ['New Farmers (Last 7 days):', overview.new_farmers_week || 0],
                        [''],
                        ['Performance Metrics:'],
                        ['Average Response Time:', performance.avg_response_time ? performance.avg_response_time + 'ms' : 'N/A'],
                        ['System Uptime:', performance.uptime || 'N/A'],
                        ['Total API Calls:', performance.total_api_calls || 0]
                    ];
                }
                filename = `usage-report-${new Date().toISOString().split('T')[0]}.csv`;
                break;

            default:
                if (window.adminApp) {
                    window.adminApp.showNotification('Unknown report type: ' + type, 'error');
                }
                return;
        }

        // Convert to CSV format
        const csvContent = reportData.map(row => {
            return row.map(cell => {
                // Escape commas and quotes in CSV
                if (cell === null || cell === undefined) return '';
                const cellStr = String(cell);
                if (cellStr.includes(',') || cellStr.includes('"') || cellStr.includes('\n')) {
                    return '"' + cellStr.replace(/"/g, '""') + '"';
                }
                return cellStr;
            }).join(',');
        }).join('\n');

        // Add BOM for UTF-8 to support special characters in Excel
        const BOM = '\uFEFF';
        const blob = new Blob([BOM + csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        // Show success notification
        if (window.adminApp) {
            window.adminApp.showNotification(`Report "${filename}" generated successfully!`, 'success');
        }
    } catch (error) {
        console.error('Error generating report:', error);
        if (window.adminApp) {
            window.adminApp.showNotification('Failed to generate report: ' + error.message, 'error');
        }
    }
}

async function saveWeatherSettings() {
    try {
        const settings = {
            weather_api_key: document.getElementById('weatherApiKey').value,
            default_location: document.getElementById('defaultLocation').value,
            weather_update_interval: document.getElementById('weatherUpdateInterval')?.value || '30'
        };

        const response = await fetch('api/system-settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings)
        });

        const data = await response.json();
        
        if (data.success) {
            app.showNotification('Weather settings saved successfully!', 'success');
        } else {
            app.showNotification('Failed to save weather settings: ' + data.message, 'error');
        }
    } catch (error) {
        console.error('Error saving weather settings:', error);
        app.showNotification('Failed to save weather settings', 'error');
    }
}

async function saveAlertSettings() {
    try {
        const settings = {
            drought_rainfall_threshold: document.getElementById('droughtRainfallThreshold')?.value || '3',
            drought_days_threshold: document.getElementById('droughtDaysThreshold')?.value || '3',
            storm_wind_threshold: document.getElementById('stormWindThreshold')?.value || '50',
            storm_rain_threshold: document.getElementById('stormRainThreshold')?.value || '20',
            heavy_rain_threshold: document.getElementById('heavyRainThreshold')?.value || '30',
            extreme_heat_threshold: document.getElementById('extremeHeatThreshold')?.value || '35',
            extreme_cold_threshold: document.getElementById('extremeColdThreshold')?.value || '10',
            high_humidity_threshold: document.getElementById('highHumidityThreshold')?.value || '85',
            frost_temp_threshold: document.getElementById('frostTempThreshold')?.value || '5',
            heat_wave_temp_threshold: document.getElementById('heatWaveTempThreshold')?.value || '32',
            heat_wave_days_threshold: document.getElementById('heatWaveDaysThreshold')?.value || '2'
        };

        const response = await fetch('api/system-settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings)
        });

        const data = await response.json();
        
        if (data.success) {
            app.showNotification('Alert settings saved successfully!', 'success');
        } else {
            app.showNotification('Failed to save alert settings: ' + data.message, 'error');
        }
    } catch (error) {
        console.error('Error saving alert settings:', error);
        app.showNotification('Failed to save alert settings', 'error');
    }
}

async function saveSystemSettings() {
    try {
        const settings = {
            data_retention_days: document.getElementById('dataRetention').value,
            auto_backup_enabled: document.getElementById('autoBackup').checked ? 'true' : 'false',
            max_alerts_per_page: document.getElementById('maxAlertsPerPage').value,
            max_farmers_per_page: document.getElementById('maxFarmersPerPage').value
        };

        const response = await fetch('api/system-settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings)
        });

        const data = await response.json();
        
        if (data.success) {
            app.showNotification('System settings saved successfully!', 'success');
        } else {
            app.showNotification('Failed to save system settings: ' + data.message, 'error');
        }
    } catch (error) {
        console.error('Error saving system settings:', error);
        app.showNotification('Failed to save system settings', 'error');
    }
}

async function saveNotificationSettings() {
    try {
        const settings = {
            email_notifications: document.getElementById('emailNotifications').checked ? 'true' : 'false',
            sms_notifications: document.getElementById('smsNotifications').checked ? 'true' : 'false',
            notification_frequency: document.getElementById('notificationFrequency').value
        };

        const response = await fetch('api/system-settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings)
        });

        const data = await response.json();
        
        if (data.success) {
            app.showNotification('Notification settings saved successfully!', 'success');
        } else {
            app.showNotification('Failed to save notification settings: ' + data.message, 'error');
        }
    } catch (error) {
        console.error('Error saving notification settings:', error);
        app.showNotification('Failed to save notification settings', 'error');
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

function editFarmer(id) {
    // Get farmer data and populate edit form
    fetch(`api/farmers.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const farmer = data.data;
                document.getElementById('editFarmerId').value = farmer.id;
                document.getElementById('editFarmerUsername').value = farmer.username || '';
                document.getElementById('editFarmerName').value = farmer.name || '';
                document.getElementById('editFarmerLocation').value = farmer.location || '';
                document.getElementById('editFarmerEmail').value = farmer.email || '';
                document.getElementById('editFarmerPhone').value = farmer.phone || farmer.contact || '';
                
                // Show edit modal
                document.getElementById('editFarmerModal').style.display = 'block';
            } else {
                showNotification('Failed to load farmer data', 'error');
            }
        })
        .catch(error => {
            console.error('Error loading farmer:', error);
            showNotification('Failed to load farmer data', 'error');
        });
}

function deleteFarmer(id) {
    if (confirm('Are you sure you want to delete this farmer?')) {
        fetch('api/farmers.php', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                id: id
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Farmer deleted successfully!', 'success');
                // Reload farmers list
                if (window.adminApp) {
                    window.adminApp.loadFarmers();
                }
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error deleting farmer:', error);
            showNotification('Failed to delete farmer', 'error');
        });
    }
}

// Search and filter functions
function searchFarmers() {
    if (window.adminApp) {
        window.adminApp.applyFilters();
    }
}

function filterFarmers() {
    if (window.adminApp) {
        window.adminApp.applyFilters();
    }
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

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-menu-toggle');
    
    if (!toggle.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});


// Global functions for forecasting
async function updateForecast() {
    if (window.adminApp) {
        await window.adminApp.loadForecastData();
    }
}

async function refreshForecast() {
    if (window.adminApp) {
        await window.adminApp.loadForecastData();
        if (window.showNotification) {
            showNotification('Forecast data refreshed', 'success');
        }
    }
}

function changeForecastMethod() {
    if (window.adminApp) {
        window.adminApp.loadForecastData();
    }
}

// Add method to AdminApp class
AdminApp.prototype.updateForecastMethodInfo = function(method) {
    const infoEl = document.getElementById('forecastMethodInfo');
    const titleEl = document.getElementById('forecastTitle');
    
    if (method === 'straight_line') {
        if (infoEl) {
            infoEl.innerHTML = `
                <div class="method-item">
                    <span class="method-label">Method:</span>
                    <span class="method-value">Straight Line (3 Modes)</span>
                </div>
                <div class="method-item">
                    <span class="method-label">Variable:</span>
                    <span class="method-value">Average Temperature (°C)</span>
                </div>
                <div class="method-item">
                    <span class="method-label">Modes:</span>
                    <span class="method-value">Mode 1: Optimistic (Upper), Mode 2: Realistic (Best Fit), Mode 3: Pessimistic (Lower)</span>
                </div>
            `;
        }
        if (titleEl) {
            titleEl.textContent = '7-Day Average Temperature Forecast';
        }
    } else {
        if (infoEl) {
            infoEl.innerHTML = `
                <div class="method-item">
                    <span class="method-label">Method:</span>
                    <span class="method-value">Exponential Smoothing</span>
                </div>
                <div class="method-item">
                    <span class="method-label">Variable:</span>
                    <span class="method-value">Weather Records Count</span>
                </div>
                <div class="method-item">
                    <span class="method-label">Formula:</span>
                    <span class="method-value formula-text">(0.40 × actual) + (0.60 × forecasted_previous)</span>
                </div>
            `;
        }
        if (titleEl) {
            titleEl.textContent = '7-Day Weather Records Forecast';
        }
    }
};

// Global function to refresh weather trends chart
function refreshWeatherTrends() {
    if (window.adminApp) {
        window.adminApp.refreshWeatherTrendsChart();
        if (typeof showNotification === 'function') {
            showNotification('Weather trends chart refreshed', 'success');
        }
    }
}

// Method Comparison Box Functions
let isComparisonMaximized = false;

function toggleMethodComparison() {
    const box = document.getElementById('methodComparisonBox');
    const content = document.getElementById('comparisonBoxContent');
    const toggleBtn = document.getElementById('comparisonToggleBtn');
    const closeBtn = document.getElementById('comparisonCloseBtn');
    const backdrop = document.getElementById('comparisonBackdrop');
    
    if (!box || !content) return;
    
    isComparisonMaximized = !isComparisonMaximized;
    
    if (isComparisonMaximized) {
        box.classList.add('maximized');
        content.style.display = 'block';
        toggleBtn.classList.add('rotated');
        if (closeBtn) closeBtn.style.display = 'block';
        if (backdrop) backdrop.classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent scrolling when maximized
        
        // Load comparison data - always refresh to ensure it matches current records
        const tablesEl = document.getElementById('comparisonTables');
        loadMethodComparison(true); // Force refresh to match current records
    } else {
        box.classList.remove('maximized');
        content.style.display = 'none';
        toggleBtn.classList.remove('rotated');
        if (closeBtn) closeBtn.style.display = 'none';
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = ''; // Restore scrolling
    }
}

async function refreshMethodComparison() {
    const refreshBtn = document.getElementById('comparisonRefreshBtn');
    if (refreshBtn) {
        refreshBtn.classList.add('spinning');
    }
    
    try {
        await loadMethodComparison(true); // Force refresh
    } finally {
        if (refreshBtn) {
            refreshBtn.classList.remove('spinning');
        }
    }
}

async function loadMethodComparison(forceRefresh = false) {
    const loadingEl = document.getElementById('comparisonLoading');
    const tablesEl = document.getElementById('comparisonTables');
    const exponentialTableEl = document.getElementById('exponentialSmoothingTable');
    const straightLineTableEl = document.getElementById('straightLineTable');
    
    if (!loadingEl || !tablesEl || !exponentialTableEl || !straightLineTableEl) return;
    
    loadingEl.style.display = 'block';
    tablesEl.style.display = 'none';
    
    try {
        const period = 7; // 7-day forecast
        
        // Load both methods in parallel with cache busting if force refresh
        const cacheBuster = forceRefresh ? '&_t=' + Date.now() : '';
        const [exponentialData, straightLineData] = await Promise.all([
            loadForecastDataForMethod(period, 'exponential_smoothing', forceRefresh),
            loadForecastDataForMethod(period, 'straight_line', forceRefresh)
        ]);
        
        // Validate data before rendering
        if (!exponentialData || !straightLineData) {
            throw new Error('Failed to load forecast data for one or both methods');
        }
        
        // Render tables
        renderComparisonTable(exponentialTableEl, exponentialData, 'exponential_smoothing');
        renderComparisonTable(straightLineTableEl, straightLineData, 'straight_line');
        
        loadingEl.style.display = 'none';
        tablesEl.style.display = 'grid';
        
        // Add last updated timestamp
        const lastUpdated = new Date().toLocaleString();
        const timestampEl = document.getElementById('comparisonLastUpdated');
        if (timestampEl) {
            timestampEl.textContent = `Last updated: ${lastUpdated}`;
        } else {
            // Create timestamp element if it doesn't exist
            const timestampDiv = document.createElement('div');
            timestampDiv.id = 'comparisonLastUpdated';
            timestampDiv.style.cssText = 'margin-top: 1rem; padding: 0.5rem; text-align: center; color: #6c757d; font-size: 0.85rem;';
            timestampDiv.textContent = `Last updated: ${lastUpdated}`;
            tablesEl.appendChild(timestampDiv);
        }
    } catch (error) {
        console.error('Error loading method comparison:', error);
        loadingEl.innerHTML = `<div style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> Failed to load comparison data: ${error.message}. Please try again.</div>`;
    }
}

async function loadForecastDataForMethod(days, method, forceRefresh = false) {
    const cacheBuster = forceRefresh ? '&_t=' + Date.now() : '';
    
    try {
        // Try real forecasting API first
        let response = await fetch(`api/forecasting-real.php?action=forecast&days=${days}&method=${method}${cacheBuster}`, {
            cache: forceRefresh ? 'no-cache' : 'default',
            headers: {
                'Cache-Control': forceRefresh ? 'no-cache' : 'default'
            }
        });
        if (!response.ok) {
            throw new Error('Real API not available');
        }
        let data = await response.json();
        
        if (data && data.success && data.data) {
            // Validate data structure
            if (data.data.forecast && (data.data.forecast.forecast_data || Array.isArray(data.data.forecast))) {
                return data.data;
            } else {
                console.warn(`Invalid forecast structure for ${method} from real API:`, data.data);
                throw new Error('Invalid forecast structure');
            }
        }
    } catch (error) {
        console.log(`Real API not available for ${method}, trying sample API:`, error.message);
    }
    
    try {
        // Fallback to sample data API
        const response = await fetch(`api/forecasting.php?action=forecast&days=${days}&method=${method}${cacheBuster}`, {
            cache: forceRefresh ? 'no-cache' : 'default',
            headers: {
                'Cache-Control': forceRefresh ? 'no-cache' : 'default'
            }
        });
        if (!response.ok) {
            throw new Error('Forecast API not available');
        }
        const data = await response.json();
        
        if (data && data.success && data.data) {
            // Validate data structure
            if (data.data.forecast && (data.data.forecast.forecast_data || Array.isArray(data.data.forecast))) {
                return data.data;
            } else {
                console.warn(`Invalid forecast structure for ${method} from sample API:`, data.data);
                throw new Error('Invalid forecast structure');
            }
        } else {
            throw new Error('Invalid API response structure');
        }
    } catch (error) {
        console.error(`Error loading forecast for ${method}:`, error);
        throw error;
    }
}

function renderComparisonTable(container, forecastData, method) {
    if (!forecastData || !forecastData.forecast) {
        console.error('Invalid forecast data structure:', forecastData);
        container.innerHTML = '<p style="color: #6c757d; padding: 1rem;">No forecast data available</p>';
        return;
    }
    
    const forecastObj = forecastData.forecast;
    const forecast = forecastObj.forecast_data || forecastObj.forecast || [];
    const historical = forecastData.historical || [];
    
    if (!Array.isArray(forecast) || forecast.length === 0) {
        console.error('Forecast array is empty or invalid:', forecast);
        container.innerHTML = '<p style="color: #6c757d; padding: 1rem;">No forecast data available</p>';
        return;
    }
    
    // Sort forecast by date to ensure correct order
    const sortedForecast = [...forecast].sort((a, b) => {
        const dateA = new Date(a.date);
        const dateB = new Date(b.date);
        return dateA - dateB;
    });
    
    // Get last historical value for trend calculation
    const lastHistoricalValue = historical && historical.length > 0 
        ? (historical[historical.length - 1].daily_records || historical[historical.length - 1].avg_temperature || 0)
        : null;
    
    let tableHTML = `
        <div class="comparison-table-wrapper">
            <table class="comparison-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Predicted Records</th>
                        <th>Confidence</th>
                        <th>Trend</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    sortedForecast.forEach((item, index) => {
        const date = new Date(item.date).toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric',
            year: 'numeric'
        });
        const predictedValue = item.predicted_records || item.predicted_value || item.predicted || 0;
        
        // Handle confidence - could be decimal (0.85) or percentage (85)
        let confidence = item.confidence || item.confidence_score || 0;
        if (confidence > 1) {
            confidence = confidence / 100; // Convert percentage to decimal
        }
        const confidencePercent = Math.round(confidence * 100);
        
        // Calculate trend: compare with previous forecast value or last historical value
        let trend = 'stable';
        let prevValue = null;
        
        if (index > 0) {
            prevValue = sortedForecast[index - 1].predicted_records || sortedForecast[index - 1].predicted_value || sortedForecast[index - 1].predicted || 0;
        } else if (lastHistoricalValue !== null) {
            prevValue = lastHistoricalValue;
        }
        
        if (prevValue !== null) {
            const diff = predictedValue - prevValue;
            const threshold = Math.abs(prevValue * 0.05); // 5% threshold
            if (diff > threshold) {
                trend = 'increasing';
            } else if (diff < -threshold) {
                trend = 'decreasing';
            } else {
                trend = 'stable';
            }
        } else {
            // Fallback to overall trend if available
            trend = forecastObj.trend_direction || item.trend || 'stable';
        }
        
        // Determine trend icon
        let trendIcon = 'fa-equals';
        let trendColor = '#6c757d';
        if (trend === 'increasing' || trend === 'up') {
            trendIcon = 'fa-arrow-up';
            trendColor = '#28a745';
        } else if (trend === 'decreasing' || trend === 'down') {
            trendIcon = 'fa-arrow-down';
            trendColor = '#dc3545';
        }
        
        tableHTML += `
            <tr>
                <td><strong>${date}</strong></td>
                <td>${Math.round(predictedValue)}</td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <div style="flex: 1; background: #e9ecef; border-radius: 4px; height: 20px; position: relative;">
                            <div style="width: ${confidencePercent}%; height: 100%; background: ${confidencePercent >= 70 ? '#28a745' : confidencePercent >= 40 ? '#ffc107' : '#dc3545'}; border-radius: 4px; transition: width 0.3s;"></div>
                            <span style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 0.75rem; font-weight: 600; color: ${confidencePercent >= 50 ? '#fff' : '#333'};">${confidencePercent}%</span>
                        </div>
                    </div>
                </td>
                <td>
                    <span style="color: ${trendColor};">
                        <i class="fas ${trendIcon}"></i> ${trend.charAt(0).toUpperCase() + trend.slice(1)}
                    </span>
                </td>
            </tr>
        `;
    });
    
    tableHTML += `
                </tbody>
            </table>
        </div>
        <div style="margin-top: 1rem; padding: 0.75rem; background: white; border-radius: 4px; border-left: 3px solid #667eea;">
            <small style="color: #6c757d;">
                <strong>Method Info:</strong> ${method === 'exponential_smoothing' 
                    ? 'Exponential Smoothing - Formula: (0.40 × actual) + (0.60 × forecasted_previous)' 
                    : 'Straight Line (3 Modes) - Mode 1: Optimistic, Mode 2: Realistic, Mode 3: Pessimistic'}
            </small>
        </div>
    `;
    
    container.innerHTML = tableHTML;
}

// Global function to refresh all analytics
async function refreshAllAnalytics() {
    if (window.adminApp) {
        await window.adminApp.loadAnalytics();
        if (typeof showNotification === 'function') {
            showNotification('All analytics refreshed', 'success');
        }
    }
}

// Global function to refresh market timing
async function refreshMarketTiming() {
    if (window.adminApp) {
        await window.adminApp.loadMarketTiming();
        if (typeof showNotification === 'function') {
            showNotification('Market timing data refreshed', 'success');
        }
    } else if (window.farmerApp) {
        await window.farmerApp.loadMarketTiming();
        if (typeof showNotification === 'function') {
            showNotification('Market timing data refreshed', 'success');
        }
    }
}

// Global function to load admin market prices
async function loadAdminMarketPrices() {
    if (window.adminApp) {
        await window.adminApp.loadAdminMarketPrices();
        if (typeof showNotification === 'function') {
            showNotification('Market prices refreshed', 'success');
        }
    }
}

// Global function to show add price modal
function showAddPriceModal() {
    // Reset form
    document.getElementById('addPriceForm').reset();
    document.getElementById('editPriceId').value = '';
    document.getElementById('addPriceModalTitle').innerHTML = '<i class="fas fa-dollar-sign"></i> Add Crop Price';
    document.getElementById('priceDate').value = new Date().toISOString().split('T')[0];
    
    // Show modal
    document.getElementById('addPriceModal').style.display = 'block';
}

// Global function to edit price
async function editPrice(cropName, location, pricePerKg, date, demandLevel, qualityGrade = 'standard') {
    // Fill form with existing data
    document.getElementById('priceCropName').value = cropName;
    document.getElementById('priceLocation').value = location;
    document.getElementById('pricePerKg').value = pricePerKg;
    document.getElementById('priceDate').value = date;
    document.getElementById('priceDemandLevel').value = demandLevel || 'medium';
    document.getElementById('priceQualityGrade').value = qualityGrade || 'standard';
    document.getElementById('editPriceId').value = ''; // We'll use crop/location/date to identify
    
    // Change modal title
    document.getElementById('addPriceModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Crop Price';
    
    // Show modal
    document.getElementById('addPriceModal').style.display = 'block';
}

// Global function to delete price
async function deletePrice(cropName, location, date) {
    if (!confirm(`Are you sure you want to delete the price for ${cropName} at ${location} on ${date}?`)) {
        return;
    }
    
    try {
        // Try to find price ID from current data first (faster)
        const prices = window.adminApp?.adminMarketPricesData || [];
        const price = prices.find(p => 
            p.crop_name === cropName && 
            p.location === location && 
            p.date === date
        );
        
        if (price && price.id) {
            // Delete using the found price ID
            await deletePriceById(price.id);
            return;
        }
        
        // If not found in current data, fetch from API
        const response = await fetch(`api/crop-prices.php?action=get_price&crop_name=${encodeURIComponent(cropName)}&location=${encodeURIComponent(location)}&date=${date}`, {
            credentials: 'include'
        });
        
        const priceData = await response.json();
        
        if (!priceData.success || !priceData.price) {
            window.adminApp?.showNotification('Price not found', 'error');
            return;
        }
        
        // Delete using the price ID from API
        await deletePriceById(priceData.price.id);
        
    } catch (error) {
        console.error('Error deleting price:', error);
        window.adminApp?.showNotification('Failed to delete price', 'error');
    }
}

// Helper function to delete price by ID
async function deletePriceById(priceId) {
    try {
        const response = await fetch('api/crop-prices.php?action=delete_price', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ price_id: priceId })
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.adminApp?.showNotification('Price deleted successfully!', 'success');
            await window.adminApp?.loadAdminMarketPrices();
        } else {
            window.adminApp?.showNotification(data.error || 'Failed to delete price', 'error');
        }
    } catch (error) {
        console.error('Error deleting price:', error);
        window.adminApp?.showNotification('Failed to delete price', 'error');
    }
}

// Global function to close modal
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Global function to filter admin market prices
function filterAdminMarketPrices() {
    const searchInput = document.getElementById('adminMarketPricesSearch');
    const sortSelect = document.getElementById('adminMarketPricesSort');
    const container = document.getElementById('adminMarketPricesContainer');
    
    if (!searchInput || !container || !window.adminApp || !window.adminApp.adminMarketPricesData) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    let filtered = window.adminApp.adminMarketPricesData.filter(price => 
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
        updated_at: document.getElementById('adminMarketPricesUpdatedAt')?.textContent || 'Just now',
        location: document.getElementById('adminMarketPricesLocationDisplay')?.textContent || 'Manila',
        total_crops: filtered.length
    };
    window.adminApp.renderAdminMarketPrices(filtered, data);
}

// Global function to sort admin market prices
function sortAdminMarketPrices() {
    filterAdminMarketPrices(); // Reuse filter function which handles sorting
}

// Full-screen analytics functions
let currentFullScreenChartId = null;

// Helper function to wait for chart to be ready
async function waitForChartReady(chartId, maxAttempts = 20, delay = 200) {
    for (let i = 0; i < maxAttempts; i++) {
        const canvas = document.getElementById(chartId);
        if (canvas) {
            const chartInstance = Chart.getChart(canvas);
            if (chartInstance) {
                return true;
            }
        }
        await new Promise(resolve => setTimeout(resolve, delay));
    }
    return false;
}

function openFullScreenAnalytics(chartId) {
    // Only allow opening if we're in the analytics section
    const analyticsSection = document.getElementById('analytics');
    if (!analyticsSection || !analyticsSection.classList.contains('active')) {
        // If not in analytics section, navigate to it first
        if (window.adminApp) {
            window.adminApp.showSection('analytics');
            // Wait longer for analytics to load, then check if chart is ready
            setTimeout(async () => {
                // Wait for chart to be ready if it's not a performance metrics card
                if (chartId !== 'performance-metrics') {
                    const isReady = await waitForChartReady(chartId, 30, 200);
                    if (!isReady) {
                        console.warn(`Chart ${chartId} not ready after waiting, attempting to open anyway...`);
                    }
                }
                openFullScreenAnalytics(chartId);
            }, 1000);
        }
        return;
    }
    
    currentFullScreenChartId = chartId;
    const modal = document.getElementById('fullScreenAnalyticsModal');
    const titleElement = document.getElementById('fullScreenAnalyticsTitle');
    const bodyElement = document.getElementById('fullScreenAnalyticsBody');
    
    if (!modal || !titleElement || !bodyElement) return;
    
    // Get chart title
    let chartTitle = 'Analytics';
    const chartElement = document.getElementById(chartId);
    if (chartElement) {
        const card = chartElement.closest('.analytics-card');
        if (card) {
            const header = card.querySelector('.chart-header h4');
            if (header) {
                chartTitle = header.textContent.trim();
            }
            // Special handling for forecast title
            const forecastTitle = card.querySelector('#forecastTitle');
            if (forecastTitle) {
                chartTitle = forecastTitle.textContent.trim();
            }
        }
    }
    
    // Special handling for performance metrics
    if (chartId === 'performance-metrics') {
        chartTitle = 'Performance Metrics';
        bodyElement.innerHTML = `
            <div class="fullscreen-performance-metrics">
                <div class="fullscreen-metric-card">
                    <div class="metric-icon" style="font-size: 4rem; color: #667eea; margin-bottom: 1rem;">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div class="metric-value" style="font-size: 3rem; font-weight: bold; color: #2c3e50; margin-bottom: 0.5rem;" id="fullScreenAlertResponseRate">-</div>
                    <div class="metric-label" style="color: #6c757d; font-size: 1.2rem;">Alert Response Rate</div>
                </div>
                <div class="fullscreen-metric-card">
                    <div class="metric-icon" style="font-size: 4rem; color: #28a745; margin-bottom: 1rem;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="metric-value" style="font-size: 3rem; font-weight: bold; color: #2c3e50; margin-bottom: 0.5rem;" id="fullScreenAlertAccuracyRate">-</div>
                    <div class="metric-label" style="color: #6c757d; font-size: 1.2rem;">Alert Accuracy</div>
                </div>
                <div class="fullscreen-metric-card">
                    <div class="metric-icon" style="font-size: 4rem; color: #17a2b8; margin-bottom: 1rem;">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="metric-value" style="font-size: 3rem; font-weight: bold; color: #2c3e50; margin-bottom: 0.5rem;" id="fullScreenSystemUptime">-</div>
                    <div class="metric-label" style="color: #6c757d; font-size: 1.2rem;">System Uptime</div>
                </div>
                <div class="fullscreen-metric-card">
                    <div class="metric-icon" style="font-size: 4rem; color: #ffc107; margin-bottom: 1rem;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="metric-value" style="font-size: 3rem; font-weight: bold; color: #2c3e50; margin-bottom: 0.5rem;" id="fullScreenAvgResolutionTime">-</div>
                    <div class="metric-label" style="color: #6c757d; font-size: 1.2rem;">Avg Resolution Time</div>
                </div>
            </div>
        `;
        
        // Copy values from original metrics
        const alertResponseRate = document.getElementById('alertResponseRate')?.textContent || '-';
        const alertAccuracyRate = document.getElementById('alertAccuracyRate')?.textContent || '-';
        const systemUptime = document.getElementById('systemUptime')?.textContent || '-';
        const avgResolutionTime = document.getElementById('avgResolutionTime')?.textContent || '-';
        
        document.getElementById('fullScreenAlertResponseRate').textContent = alertResponseRate;
        document.getElementById('fullScreenAlertAccuracyRate').textContent = alertAccuracyRate;
        document.getElementById('fullScreenSystemUptime').textContent = systemUptime;
        document.getElementById('fullScreenAvgResolutionTime').textContent = avgResolutionTime;
        
        document.getElementById('fullScreenExportBtn').style.display = 'none';
        titleElement.textContent = chartTitle;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        return; // Exit early for performance metrics
    } else {
        // Clone the canvas for full screen
        const originalCanvas = document.getElementById(chartId);
        if (originalCanvas) {
            const canvas = document.createElement('canvas');
            canvas.id = 'fullScreenCanvas';
            canvas.style.width = '100%';
            canvas.style.height = 'auto';
            canvas.style.maxHeight = '75vh';
            canvas.style.minHeight = '500px';
            
            // Check if this is the forecast chart (has additional info)
            const card = originalCanvas.closest('.analytics-card');
            const isForecastChart = chartId === 'forecastChart' || (card && card.classList.contains('forecast-card-professional'));
            const forecastMethodInfo = card ? card.querySelector('#forecastMethodInfo') : null;
            const forecastSummary = card ? card.querySelector('#forecastSummary') : null;
            const forecastTable = card ? card.querySelector('#forecastTableContainer') : null;
            const trendDirection = card ? card.querySelector('#trendDirection') : null;
            const forecastConfidence = card ? card.querySelector('#forecastConfidence') : null;
            const forecastMethod = card ? card.querySelector('#forecastMethod') : null;
            
            // Show loading state first and open modal
            bodyElement.innerHTML = '<div class="loading" style="padding: 2rem; text-align: center; color: #6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading chart...</div>';
            titleElement.textContent = chartTitle;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Add special class for forecast fullscreen
            if (isForecastChart) {
                modal.classList.add('forecast-fullscreen');
            } else {
                modal.classList.remove('forecast-fullscreen');
            }
            
            // Wait a bit for modal to render, then check for chart
            setTimeout(async () => {
                let chartInstance = Chart.getChart(originalCanvas);
                
                // If chart not ready, wait for it
                if (!chartInstance && chartId !== 'forecastChart') {
                    const isReady = await waitForChartReady(chartId, 20, 150);
                    if (isReady) {
                        chartInstance = Chart.getChart(originalCanvas);
                    }
                }
                
                if (chartInstance) {
                    // Special handling for forecast chart - maintain professional layout
                    if (isForecastChart) {
                        bodyElement.innerHTML = '';
                        bodyElement.className = 'fullscreen-analytics-body forecast-fullscreen-body';
                        
                        // Create the professional three-column layout
                        const forecastWrapper = document.createElement('div');
                        forecastWrapper.className = 'forecast-fullscreen-wrapper';
                        
                        // Left Sidebar - Method Info & Controls
                        const leftSidebar = document.createElement('div');
                        leftSidebar.className = 'forecast-fullscreen-sidebar';
                        
                        // Method Card
                        const methodCard = document.createElement('div');
                        methodCard.className = 'forecast-fullscreen-method-card';
                        methodCard.innerHTML = `
                            <div class="method-card-header">
                                <i class="fas fa-cog method-icon"></i>
                                <span class="method-title">Method Details</span>
                            </div>
                            <div class="method-info-content" id="fullscreenForecastMethodInfo">
                                ${forecastMethodInfo ? forecastMethodInfo.innerHTML : ''}
                            </div>
                        `;
                        leftSidebar.appendChild(methodCard);
                        
                        // Controls Card
                        const controlsCard = document.createElement('div');
                        controlsCard.className = 'forecast-fullscreen-controls-card';
                        const methodValue = forecastMethod ? forecastMethod.value : 'exponential_smoothing';
                        controlsCard.innerHTML = `
                            <div class="control-group">
                                <label for="fullscreenForecastMethod" class="control-label">
                                    <i class="fas fa-filter"></i> Forecast Method
                                </label>
                                <select id="fullscreenForecastMethod" class="forecast-select" onchange="changeForecastMethod(); setTimeout(() => { openFullScreenAnalytics('forecastChart'); }, 500);">
                                    <option value="exponential_smoothing" ${methodValue === 'exponential_smoothing' ? 'selected' : ''}>Exponential Smoothing (Weather Records)</option>
                                    <option value="straight_line" ${methodValue === 'straight_line' ? 'selected' : ''}>Straight Line (Average Temperature)</option>
                                </select>
                            </div>
                            <button class="forecast-refresh-btn" onclick="refreshForecast(); setTimeout(() => { openFullScreenAnalytics('forecastChart'); }, 500);">
                                <i class="fas fa-sync-alt"></i>
                                <span>Refresh</span>
                            </button>
                        `;
                        leftSidebar.appendChild(controlsCard);
                        
                        // Main Content - Chart & Insights
                        const mainContent = document.createElement('div');
                        mainContent.className = 'forecast-fullscreen-main';
                        
                        // Chart Container
                        const chartContainer = document.createElement('div');
                        chartContainer.className = 'forecast-fullscreen-chart-container';
                        chartContainer.innerHTML = `
                            <div class="chart-toolbar">
                                <button class="chart-toolbar-btn" onclick="resetChartZoom()">
                                    <i class="fas fa-search-minus"></i> Reset Zoom
                                </button>
                            </div>
                        `;
                        canvas.id = 'fullScreenCanvas';
                        canvas.style.width = '100%';
                        canvas.style.height = '100%';
                        chartContainer.appendChild(canvas);
                        mainContent.appendChild(chartContainer);
                        
                        // Insights Panel
                        const insightsPanel = document.createElement('div');
                        insightsPanel.className = 'forecast-fullscreen-insights';
                        const trendText = trendDirection ? trendDirection.textContent : '-';
                        const trendClass = trendDirection ? trendDirection.className.replace('stat-value', 'insight-value') : 'insight-value';
                        const confidenceText = forecastConfidence ? forecastConfidence.textContent : '-';
                        insightsPanel.innerHTML = `
                            <div class="insight-card trend-insight">
                                <div class="insight-icon trend-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="insight-content">
                                    <span class="insight-label">TREND</span>
                                    <span class="insight-value ${trendClass.includes('increasing') ? 'increasing' : trendClass.includes('decreasing') ? 'decreasing' : 'stable'}" id="fullscreenTrendDirection">${trendText}</span>
                                </div>
                            </div>
                            <div class="insight-card confidence-insight">
                                <div class="insight-icon confidence-icon">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="insight-content">
                                    <span class="insight-label">CONFIDENCE</span>
                                    <span class="insight-value" id="fullscreenForecastConfidence">${confidenceText}</span>
                                </div>
                            </div>
                        `;
                        mainContent.appendChild(insightsPanel);
                        
                        // Right Sidebar - Forecast Table
                        const rightSidebar = document.createElement('div');
                        rightSidebar.className = 'forecast-fullscreen-table-sidebar';
                        const tableCard = document.createElement('div');
                        tableCard.className = 'forecast-fullscreen-table-card';
                        tableCard.innerHTML = `
                            <div class="table-card-header">
                                <i class="fas fa-table table-icon"></i>
                                <span class="table-title">7-Day Forecast Details</span>
                            </div>
                            <div class="forecast-table-container" id="fullscreenForecastTable">
                                ${forecastTable ? forecastTable.innerHTML : ''}
                            </div>
                        `;
                        rightSidebar.appendChild(tableCard);
                        
                        // Assemble the layout
                        forecastWrapper.appendChild(leftSidebar);
                        forecastWrapper.appendChild(mainContent);
                        forecastWrapper.appendChild(rightSidebar);
                        bodyElement.appendChild(forecastWrapper);
                    } else {
                        // Regular chart handling (non-forecast)
                        bodyElement.className = 'fullscreen-analytics-body';
                        bodyElement.innerHTML = '';
                        
                        // Create chart container for better control with explicit dimensions
                        const chartContainer = document.createElement('div');
                        chartContainer.className = 'chart-container';
                        // Calculate available height (90vh modal - header - padding)
                        const containerHeight = Math.max(600, window.innerHeight * 0.6);
                        chartContainer.style.cssText = `position: relative; width: 100%; height: ${containerHeight}px; display: flex; align-items: center; justify-content: center; background: white; border-radius: 12px; padding: 2rem; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);`;
                        
                        // Set canvas dimensions properly - Chart.js will handle sizing based on container
                        canvas.style.maxWidth = '100%';
                        canvas.style.maxHeight = '100%';
                        
                        chartContainer.appendChild(canvas);
                        bodyElement.appendChild(chartContainer);
                    }
                    
                    // Clone chart config properly - preserve data structure
                    const config = chartInstance.config;
                    const newConfig = {
                        type: config.type,
                        data: JSON.parse(JSON.stringify(config.data)), // Deep clone data
                        options: JSON.parse(JSON.stringify(config.options || {})) // Deep clone options
                    };
                
                // Update options for full screen with maximum detail
                if (!newConfig.options) {
                    newConfig.options = {};
                }
                newConfig.options.responsive = true;
                
                // For forecast fullscreen, use maintainAspectRatio false to fill container
                if (isForecastChart) {
                    newConfig.options.maintainAspectRatio = false;
                } else {
                    newConfig.options.maintainAspectRatio = true;
                    // Set appropriate aspect ratio based on chart type
                    if (newConfig.type === 'pie' || newConfig.type === 'doughnut') {
                        newConfig.options.aspectRatio = 1; // Circular for pie charts
                    } else {
                        newConfig.options.aspectRatio = 2; // Wider for line/bar charts
                    }
                }
                
                // Increase font sizes significantly for better visibility in full screen
                if (newConfig.options.plugins) {
                    if (newConfig.options.plugins.legend) {
                        newConfig.options.plugins.legend.labels = newConfig.options.plugins.legend.labels || {};
                        newConfig.options.plugins.legend.labels.font = newConfig.options.plugins.legend.labels.font || {};
                        newConfig.options.plugins.legend.labels.font.size = 18;
                        newConfig.options.plugins.legend.labels.padding = 20;
                        newConfig.options.plugins.legend.position = 'top';
                    }
                    if (newConfig.options.plugins.tooltip) {
                        newConfig.options.plugins.tooltip.titleFont = { size: 18, weight: 'bold' };
                        newConfig.options.plugins.tooltip.bodyFont = { size: 16 };
                        newConfig.options.plugins.tooltip.padding = 12;
                        newConfig.options.plugins.tooltip.titleSpacing = 8;
                        newConfig.options.plugins.tooltip.bodySpacing = 6;
                    }
                    if (newConfig.options.plugins.datalabels) {
                        newConfig.options.plugins.datalabels.font = newConfig.options.plugins.datalabels.font || {};
                        newConfig.options.plugins.datalabels.font.size = 16;
                        newConfig.options.plugins.datalabels.font.weight = 'bold';
                    }
                }
                
                // Update scales with larger fonts and better spacing
                if (newConfig.options.scales) {
                    Object.keys(newConfig.options.scales).forEach(key => {
                        if (newConfig.options.scales[key].ticks) {
                            newConfig.options.scales[key].ticks.font = newConfig.options.scales[key].ticks.font || {};
                            newConfig.options.scales[key].ticks.font.size = 16;
                            newConfig.options.scales[key].ticks.padding = 12;
                        }
                        if (newConfig.options.scales[key].title) {
                            newConfig.options.scales[key].title.font = newConfig.options.scales[key].title.font || {};
                            newConfig.options.scales[key].title.font.size = 18;
                            newConfig.options.scales[key].title.font.weight = 'bold';
                            newConfig.options.scales[key].title.padding = { top: 10, bottom: 10 };
                        }
                        newConfig.options.scales[key].grid = newConfig.options.scales[key].grid || {};
                        newConfig.options.scales[key].grid.lineWidth = 2;
                    });
                }
                
                // Enable zoom and pan for better detail viewing (only for charts that support it)
                if (newConfig.type !== 'pie' && newConfig.type !== 'doughnut') {
                    if (!newConfig.options.plugins) {
                        newConfig.options.plugins = {};
                    }
                    newConfig.options.plugins.zoom = {
                        zoom: {
                            wheel: {
                                enabled: true,
                            },
                            pinch: {
                                enabled: true,
                            },
                            mode: 'xy',
                            limits: {
                                x: { min: 0.5, max: 4 },
                                y: { min: 0.5, max: 4 }
                            }
                        },
                        pan: {
                            enabled: true,
                            mode: 'xy',
                            limits: {
                                x: 'pan',
                                y: 'pan'
                            }
                        }
                    };
                }
                
                // Destroy old fullscreen chart if exists
                const oldChart = Chart.getChart(canvas);
                if (oldChart) {
                    oldChart.destroy();
                }
                
                // Create new chart with better detail - wait for DOM to be ready
                setTimeout(() => {
                    try {
                        // Ensure canvas is in DOM and container has dimensions
                        if (!canvas.parentElement || canvas.parentElement.offsetHeight === 0) {
                            console.error('Canvas container not ready');
                            bodyElement.innerHTML = '<div class="loading" style="padding: 2rem; text-align: center; color: #6c757d;">Error: Chart container not ready. Please try again.</div>';
                            return;
                        }
                        
                        // Create the chart
                        const fullScreenChart = new Chart(canvas, newConfig);
                        
                        // Verify chart was created successfully
                        if (!fullScreenChart || !fullScreenChart.canvas) {
                            throw new Error('Chart creation failed - no chart instance returned');
                        }
                        
                        // Add zoom reset button (only for charts that support zoom)
                        if (newConfig.type !== 'pie' && newConfig.type !== 'doughnut') {
                            const zoomResetBtn = document.createElement('button');
                            zoomResetBtn.className = 'zoom-reset-btn';
                            zoomResetBtn.innerHTML = '<i class="fas fa-search-minus"></i> Reset Zoom';
                            zoomResetBtn.title = 'Reset zoom and pan (or use mouse wheel + drag)';
                            zoomResetBtn.onclick = (e) => {
                                e.stopPropagation();
                                try {
                                    if (fullScreenChart && typeof fullScreenChart.resetZoom === 'function') {
                                        fullScreenChart.resetZoom();
                                    } else if (fullScreenChart && fullScreenChart.zoomScale) {
                                        fullScreenChart.zoomScale('reset');
                                    }
                                } catch (err) {
                                    console.log('Zoom reset not available:', err);
                                }
                            };
                            
                            const chartContainer = canvas.parentElement;
                            // Check for both regular and forecast fullscreen containers
                            if (chartContainer && (chartContainer.classList.contains('chart-container') || 
                                chartContainer.classList.contains('forecast-fullscreen-chart-container'))) {
                                // For forecast fullscreen, add to toolbar instead
                                if (chartContainer.classList.contains('forecast-fullscreen-chart-container')) {
                                    const toolbar = chartContainer.querySelector('.chart-toolbar');
                                    if (toolbar) {
                                        zoomResetBtn.style.position = 'static';
                                        zoomResetBtn.style.top = 'auto';
                                        zoomResetBtn.style.right = 'auto';
                                        toolbar.appendChild(zoomResetBtn);
                                    } else {
                                        chartContainer.appendChild(zoomResetBtn);
                                    }
                                } else {
                                    chartContainer.appendChild(zoomResetBtn);
                                }
                            }
                        }
                        
                        // Store chart reference for cleanup
                        canvas._fullScreenChartInstance = fullScreenChart;
                        
                        console.log('Fullscreen chart created successfully:', chartId);
                    } catch (error) {
                        console.error('Error creating fullscreen chart:', error);
                        console.error('Chart config:', newConfig);
                        bodyElement.innerHTML = `
                            <div style="padding: 3rem; text-align: center; color: #6c757d;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #dc3545; margin-bottom: 1rem;"></i>
                                <h3 style="margin-bottom: 1rem;">Error Displaying Chart</h3>
                                <p style="margin-bottom: 1.5rem;">${error.message || 'Failed to render chart in fullscreen mode.'}</p>
                                <button class="btn btn-primary" onclick="closeFullScreenAnalytics(); openFullScreenAnalytics('${chartId}');">
                                    <i class="fas fa-redo"></i> Try Again
                                </button>
                            </div>
                        `;
                    }
                }, 200);
                } else {
                    // Chart not available - show error message
                    bodyElement.innerHTML = `
                        <div style="padding: 3rem; text-align: center; color: #6c757d;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #ffc107; margin-bottom: 1rem;"></i>
                            <h3 style="margin-bottom: 1rem;">Chart Not Available</h3>
                            <p style="margin-bottom: 1.5rem;">The chart is still loading or failed to initialize. Please try:</p>
                            <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                                <button class="btn btn-primary" onclick="refreshAllAnalytics(); setTimeout(() => { openFullScreenAnalytics('${chartId}'); }, 1000);">
                                    <i class="fas fa-sync-alt"></i> Refresh & Retry
                                </button>
                                <button class="btn btn-secondary" onclick="closeFullScreenAnalytics();">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        </div>
                    `;
                    document.getElementById('fullScreenExportBtn').style.display = 'none';
                }
            }, 100);
            
            document.getElementById('fullScreenExportBtn').style.display = 'inline-flex';
        } else {
            // Canvas element not found
            bodyElement.innerHTML = `
                <div style="padding: 3rem; text-align: center; color: #6c757d;">
                    <i class="fas fa-exclamation-circle" style="font-size: 3rem; color: #dc3545; margin-bottom: 1rem;"></i>
                    <h3 style="margin-bottom: 1rem;">Chart Element Not Found</h3>
                    <p>The chart element "${chartId}" could not be found on the page.</p>
                    <button class="btn btn-secondary" onclick="closeFullScreenAnalytics();" style="margin-top: 1rem;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;
            titleElement.textContent = chartTitle;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            document.getElementById('fullScreenExportBtn').style.display = 'none';
        }
    }
}

function closeFullScreenAnalytics() {
    const modal = document.getElementById('fullScreenAnalyticsModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        
        // Destroy fullscreen chart if exists
        const canvas = document.getElementById('fullScreenCanvas');
        if (canvas) {
            const chart = Chart.getChart(canvas) || canvas._fullScreenChartInstance;
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
            canvas._fullScreenChartInstance = null;
        }
        
        // Clear the modal body content
        const bodyElement = document.getElementById('fullScreenAnalyticsBody');
        if (bodyElement) {
            bodyElement.innerHTML = '';
        }
        
        currentFullScreenChartId = null;
    }
}

function exportFullScreenChart() {
    if (currentFullScreenChartId === 'performance-metrics') {
        // Export performance metrics as image
        const body = document.getElementById('fullScreenAnalyticsBody');
        if (body && typeof html2canvas !== 'undefined') {
            html2canvas(body).then(canvas => {
                const link = document.createElement('a');
                link.download = 'performance-metrics.png';
                link.href = canvas.toDataURL();
                link.click();
            });
        } else {
            // Fallback: use the original exportChart function or show message
            if (typeof showNotification === 'function') {
                showNotification('Export feature requires html2canvas library', 'info');
            } else {
                alert('Export feature requires html2canvas library');
            }
        }
    } else {
        const canvas = document.getElementById('fullScreenCanvas');
        if (canvas) {
            const link = document.createElement('a');
            link.download = `${currentFullScreenChartId || 'chart'}.png`;
            link.href = canvas.toDataURL();
            link.click();
        }
    }
}

// Close modal on ESC key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeFullScreenAnalytics();
    }
});

// Close modal when clicking outside
document.addEventListener('click', (e) => {
    const modal = document.getElementById('fullScreenAnalyticsModal');
    if (modal && e.target === modal) {
        closeFullScreenAnalytics();
    }
});

// Alert Detail Modal Functions
AdminApp.prototype.showAlertDetail = function(index) {
    if (!this.currentAlerts || !this.currentAlerts[index]) {
        console.error('Alert not found at index:', index);
        return;
    }
    
    const alert = this.currentAlerts[index];
    this.showAlertDetailModal(alert);
}

AdminApp.prototype.showAlertDetailModal = function(alert) {
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
    const createdDate = alert.created_at ? new Date(alert.created_at).toLocaleString() : 'Just now';
    document.getElementById('alertModalTimeText').textContent = createdDate;
    
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
    
    // Show affected farmers if available
    if (alert.affected_farmers !== undefined) {
        document.getElementById('alertModalAffectedFarmers').style.display = 'flex';
        document.getElementById('alertModalAffectedFarmersText').textContent = alert.affected_farmers || 0;
    } else {
        document.getElementById('alertModalAffectedFarmers').style.display = 'none';
    }
    
    // Show resolve button for non-external alerts
    const resolveBtn = document.getElementById('alertModalResolveBtn');
    if (resolveBtn) {
        if (alert.is_external || !alert.id) {
            resolveBtn.style.display = 'none';
        } else {
            resolveBtn.style.display = 'inline-block';
            resolveBtn.setAttribute('data-alert-id', alert.id);
        }
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

// Global function to resolve alert from modal
function resolveAlertFromModal() {
    const resolveBtn = document.getElementById('alertModalResolveBtn');
    if (resolveBtn && resolveBtn.getAttribute('data-alert-id')) {
        const alertId = resolveBtn.getAttribute('data-alert-id');
        resolveAlert(alertId);
        closeAlertModal();
    }
}

// Initialize app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.adminApp = new AdminApp();
});
