/**
 * API Utility Functions
 * Handles external API calls for weather and geocoding
 */

class APIUtils {
    /**
     * Get current weather for coordinates
     * @param {number} latitude 
     * @param {number} longitude 
     * @returns {Promise}
     */
    static async getCurrentWeather(latitude, longitude) {
        try {
            const response = await fetch(
                `api/weather-external.php?action=current&latitude=${latitude}&longitude=${longitude}`
            );
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Error fetching current weather:', error);
            return { success: false, message: 'Failed to fetch weather data' };
        }
    }
    
    /**
     * Get weather forecast
     * @param {number} latitude 
     * @param {number} longitude 
     * @param {number} days (default: 7)
     * @returns {Promise}
     */
    static async getWeatherForecast(latitude, longitude, days = 7) {
        try {
            const response = await fetch(
                `api/weather-external.php?action=forecast&latitude=${latitude}&longitude=${longitude}&days=${days}`
            );
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Error fetching forecast:', error);
            return { success: false, message: 'Failed to fetch forecast data' };
        }
    }
    
    /**
     * Geocode: Convert location name to coordinates
     * @param {string} location 
     * @returns {Promise}
     */
    static async geocode(location) {
        try {
            const response = await fetch(
                `api/geocoding.php?action=geocode&location=${encodeURIComponent(location)}`
            );
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // Check content type to ensure we're getting JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                // Try to get text to see what we actually received
                const text = await response.text();
                console.error('Non-JSON response received:', text.substring(0, 200));
                throw new Error('Server returned invalid response format. Please try again.');
            }
            
            const data = await response.json();
            
            // If the API returned an error, preserve the message
            if (!data.success && data.message) {
                return data;
            }
            
            return data;
        } catch (error) {
            console.error('Error geocoding location:', error);
            // If it's a JSON parse error, provide a more helpful message
            if (error instanceof SyntaxError) {
                return { 
                    success: false, 
                    message: 'Server error: Invalid response format. Please contact support if this persists.' 
                };
            }
            return { 
                success: false, 
                message: error.message || 'Failed to geocode location. Please check your internet connection and try again.' 
            };
        }
    }
    
    /**
     * Reverse geocode: Convert coordinates to location name
     * @param {number} latitude 
     * @param {number} longitude 
     * @returns {Promise}
     */
    static async reverseGeocode(latitude, longitude) {
        try {
            const response = await fetch(
                `api/geocoding.php?action=reverse&latitude=${latitude}&longitude=${longitude}`
            );
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Error reverse geocoding:', error);
            return { success: false, message: 'Failed to reverse geocode coordinates' };
        }
    }
    
    /**
     * Update user location coordinates from location string
     * @param {number} userId 
     * @param {string} location 
     * @returns {Promise}
     */
    static async updateLocationFromString(userId, location) {
        try {
            // First geocode the location
            const geocodeResult = await this.geocode(location);
            
            if (!geocodeResult.success || !geocodeResult.data) {
                return { success: false, message: 'Could not find coordinates for location' };
            }
            
            const { latitude, longitude } = geocodeResult.data;
            
            // Update user location in database
            const response = await fetch('api/map-data.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    user_id: userId,
                    latitude: latitude,
                    longitude: longitude
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                return {
                    success: true,
                    data: {
                        latitude,
                        longitude,
                        display_name: geocodeResult.data.display_name
                    }
                };
            }
            
            return data;
        } catch (error) {
            console.error('Error updating location:', error);
            return { success: false, message: 'Failed to update location' };
        }
    }
    
    /**
     * Get weather for location string (geocodes first, then gets weather)
     * @param {string} location 
     * @returns {Promise}
     */
    static async getWeatherForLocation(location) {
        try {
            // First geocode
            const geocodeResult = await this.geocode(location);
            
            if (!geocodeResult.success || !geocodeResult.data) {
                return { success: false, message: 'Could not find location' };
            }
            
            const { latitude, longitude } = geocodeResult.data;
            
            // Then get weather
            return await this.getCurrentWeather(latitude, longitude);
        } catch (error) {
            console.error('Error getting weather for location:', error);
            return { success: false, message: 'Failed to get weather data' };
        }
    }
    
    /**
     * Get severe weather alerts (typhoon, storm warnings) for coordinates
     * @param {number} latitude 
     * @param {number} longitude 
     * @returns {Promise}
     */
    static async getSevereWeatherAlerts(latitude, longitude) {
        try {
            // Add timeout to prevent hanging
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
            
            const response = await fetch(
                `api/weather-alerts-external.php?action=alerts&latitude=${latitude}&longitude=${longitude}`,
                { signal: controller.signal }
            );
            
            clearTimeout(timeoutId);
            const data = await response.json();
            return data;
        } catch (error) {
            if (error.name === 'AbortError') {
                console.error('Weather alerts request timed out');
                return { success: false, message: 'Request timed out', data: [] };
            }
            console.error('Error fetching severe weather alerts:', error);
            return { success: false, message: 'Failed to fetch weather alerts', data: [] };
        }
    }
    
    /**
     * Get severe weather alerts for location string (geocodes first)
     * @param {string} location 
     * @returns {Promise}
     */
    static async getSevereWeatherAlertsForLocation(location) {
        try {
            // First geocode
            const geocodeResult = await this.geocode(location);
            
            if (!geocodeResult.success || !geocodeResult.data) {
                return { success: false, message: 'Could not find location', data: [] };
            }
            
            const { latitude, longitude } = geocodeResult.data;
            
            // Then get alerts
            return await this.getSevereWeatherAlerts(latitude, longitude);
        } catch (error) {
            console.error('Error getting weather alerts for location:', error);
            return { success: false, message: 'Failed to get weather alerts', data: [] };
        }
    }
}

// Make available globally
window.APIUtils = APIUtils;

