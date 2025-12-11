<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Weather-Based Crop Alert System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .admin-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        .admin-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            text-align: center;
        }
        .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        .admin-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .admin-card h3 {
            color: #2c3e50;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .status-online { background: #28a745; }
        .status-offline { background: #dc3545; }
        .status-warning { background: #ffc107; }
        .back-link {
            display: inline-block;
            margin-bottom: 1rem;
            color: #667eea;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <a href="../index.html" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Main Application
        </a>
        
        <div class="admin-header">
            <h1><i class="fas fa-cogs"></i> Admin Panel</h1>
            <p>System Management & Monitoring</p>
        </div>
        
        <div class="admin-grid">
            <div class="admin-card">
                <h3><i class="fas fa-database"></i> Database Status</h3>
                <div id="databaseStatus">
                    <div class="loading">Checking database connection...</div>
                </div>
            </div>
            
            <div class="admin-card">
                <h3><i class="fas fa-cloud-sun"></i> Weather API Status</h3>
                <div id="weatherApiStatus">
                    <div class="loading">Checking weather API...</div>
                </div>
            </div>
            
            <div class="admin-card">
                <h3><i class="fas fa-chart-pie"></i> System Statistics</h3>
                <div id="systemStats">
                    <div class="loading">Loading statistics...</div>
                </div>
            </div>
            
            <div class="admin-card">
                <h3><i class="fas fa-users"></i> User Management</h3>
                <div id="userManagement">
                    <p><strong>Total Farmers:</strong> <span id="totalFarmers">-</span></p>
                    <p><strong>Active Alerts:</strong> <span id="activeAlerts">-</span></p>
                    <p><strong>Weather Records:</strong> <span id="weatherRecords">-</span></p>
                </div>
            </div>
            
            <div class="admin-card">
                <h3><i class="fas fa-tools"></i> System Actions</h3>
                <div class="admin-actions">
                    <button class="btn btn-primary" onclick="checkWeatherConditions()">
                        <i class="fas fa-search"></i> Check Weather Conditions
                    </button>
                    <button class="btn btn-secondary" onclick="clearOldData()">
                        <i class="fas fa-trash"></i> Clear Old Data
                    </button>
                    <button class="btn btn-success" onclick="exportData()">
                        <i class="fas fa-download"></i> Export Data
                    </button>
                </div>
            </div>
            
            <div class="admin-card">
                <h3><i class="fas fa-cog"></i> Configuration</h3>
                <div class="config-options">
                    <p><strong>Weather Update Interval:</strong> 30 minutes</p>
                    <p><strong>Alert Thresholds:</strong></p>
                    <ul>
                        <li>Drought: < 5mm rainfall for 7 days</li>
                        <li>Storm: > 50 km/h wind + > 20mm rain</li>
                        <li>Heavy Rain: > 30mm rainfall</li>
                        <li>Extreme Temp: > 35°C or < 10°C</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="admin-card" style="margin-top: 2rem;">
            <h3><i class="fas fa-history"></i> Recent Activity</h3>
            <div id="recentActivity">
                <div class="loading">Loading recent activity...</div>
            </div>
        </div>
    </div>
    

    <script>
        class AdminPanel {
            constructor() {
                this.init();
            }
            
            init() {
                this.loadSystemStatus();
                this.loadSystemStats();
                this.loadRecentActivity();
                
                // Refresh data every 30 seconds
                setInterval(() => {
                    this.loadSystemStatus();
                    this.loadSystemStats();
                }, 30000);
            }
            
            async loadSystemStatus() {
                try {
                    // Check database status
                    const dbResponse = await fetch('../api/farmers.php');
                    const dbData = await dbResponse.json();
                    
                    const dbStatus = document.getElementById('databaseStatus');
                    if (dbData.success) {
                        dbStatus.innerHTML = `
                            <div class="status-indicator status-online"></div>
                            <strong>Online</strong><br>
                            <small>Connection successful</small>
                        `;
                    } else {
                        dbStatus.innerHTML = `
                            <div class="status-indicator status-offline"></div>
                            <strong>Offline</strong><br>
                            <small>Connection failed</small>
                        `;
                    }
                    
                    // Check weather API status
                    const weatherResponse = await fetch('../api/weather.php');
                    const weatherData = await weatherResponse.json();
                    
                    const weatherStatus = document.getElementById('weatherApiStatus');
                    if (weatherData.success) {
                        weatherStatus.innerHTML = `
                            <div class="status-indicator status-online"></div>
                            <strong>Online</strong><br>
                            <small>API responding</small>
                        `;
                    } else {
                        weatherStatus.innerHTML = `
                            <div class="status-indicator status-warning"></div>
                            <strong>Limited</strong><br>
                            <small>Using mock data</small>
                        `;
                    }
                    
                } catch (error) {
                    console.error('Error loading system status:', error);
                }
            }
            
            async loadSystemStats() {
                try {
                    // Load farmers count
                    const farmersResponse = await fetch('../api/farmers.php');
                    const farmersData = await farmersResponse.json();
                    document.getElementById('totalFarmers').textContent = totalFarmers;
                    
                    // Load alerts count
                    const alertsResponse = await fetch('../api/alerts.php?status=active');
                    const alertsData = await alertsResponse.json();
                    if (alertsData.success) {
                        // Show total count if available, otherwise show current count
                        const countText = alertsData.total !== undefined 
                            ? `${alertsData.data.length} (${alertsData.total} total)` 
                            : alertsData.data.length;
                        document.getElementById('activeAlerts').textContent = countText;
                    } else {
                        document.getElementById('activeAlerts').textContent = '0';
                    }
                    
                    // Load weather records count (mock)
                    document.getElementById('weatherRecords').textContent = '25';
                    
                } catch (error) {
                    console.error('Error loading system stats:', error);
                }
            }
            
            async loadRecentActivity() {
                try {
                    const alertsResponse = await fetch('../api/alerts.php?limit=5');
                    const alertsData = await alertsResponse.json();
                    
                    const activityContainer = document.getElementById('recentActivity');
                    if (alertsData.success && alertsData.data.length > 0) {
                        let html = '';
                        
                        // Show total count if available
                        if (alertsData.total !== undefined) {
                            html += `<div style="margin-bottom: 1rem; padding: 0.75rem; background: #e3f2fd; border-radius: 5px; border-left: 3px solid #2196f3;">
                                <strong>Total Alerts:</strong> ${alertsData.total} (showing ${alertsData.count} most recent)
                            </div>`;
                        }
                        
                        html += alertsData.data.map(alert => `
                            <div class="activity-item" style="padding: 0.5rem; margin-bottom: 0.5rem; background: #f8f9fa; border-radius: 5px; border-left: 3px solid #667eea;">
                                <strong>${alert.type}</strong> - ${new Date(alert.created_at).toLocaleString()}<br>
                                <small>${alert.description}</small>
                            </div>
                        `).join('');
                        
                        activityContainer.innerHTML = html;
                    } else {
                        activityContainer.innerHTML = '<p>No recent activity</p>';
                    }
                    
                } catch (error) {
                    console.error('Error loading recent activity:', error);
                }
            }
            
            async checkWeatherConditions() {
                try {
                    const response = await fetch('../api/weather-check.php', {
                        method: 'POST'
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        alert(`Weather conditions checked successfully! ${data.data.alerts_created} alerts created.`);
                        this.loadSystemStats();
                        this.loadRecentActivity();
                    } else {
                        alert('Failed to check weather conditions: ' + data.message);
                    }
                } catch (error) {
                    alert('Error checking weather conditions');
                }
            }
            
            async clearOldData() {
                if (confirm('Are you sure you want to clear old data? This action cannot be undone.')) {
                    alert('Clear old data functionality coming soon');
                }
            }
            
            async exportData() {
                alert('Export data functionality coming soon');
            }
        }
        
        // Initialize admin panel
        const adminPanel = new AdminPanel();
        
        // Global functions for onclick handlers
        function checkWeatherConditions() {
            adminPanel.checkWeatherConditions();
        }
        
        function clearOldData() {
            adminPanel.clearOldData();
        }
        
        function exportData() {
            adminPanel.exportData();
        }
    </script>
</body>
</html>
