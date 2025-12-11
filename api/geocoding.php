<?php
// Start output buffering to catch any unwanted output
if (!ob_get_level()) {
    ob_start();
}

// Disable error display but log errors instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Only send headers if called directly (not when included)
if (basename($_SERVER['PHP_SELF']) === 'geocoding.php') {
    // Clean any output that might have been generated
    if (ob_get_level() > 0) {
        $output = ob_get_contents();
        if (!empty($output) && trim($output) !== '') {
            error_log('Unexpected output before JSON: ' . substr($output, 0, 500));
            ob_clean();
        }
    }
    
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

/**
 * Geocoding API Integration
 * Uses Nominatim (OpenStreetMap) - Free, no API key required
 * Documentation: https://nominatim.org/release-docs/develop/api/Overview/
 * 
 * Note: Please respect the usage policy:
 * - Maximum 1 request per second
 * - Include User-Agent header
 * - Use for legitimate purposes only
 */

class GeocodingAPI {
    private $userAgent = 'CropAlertSystem/1.0 (Contact: admin@cropalert.com)';
    
    /**
     * Geocode: Convert location name to coordinates
     * @param string $location
     * @return array
     */
    public function geocode($location) {
        try {
            if (empty($location)) {
                throw new Exception('Location is required');
            }
            
            // Rate limiting: Wait 1 second between requests
            $this->rateLimit();
            
            // First try with Philippines restriction (prioritize PH locations)
            $url = "https://nominatim.openstreetmap.org/search?" . http_build_query([
                'q' => $location,
                'format' => 'json',
                'limit' => 1,
                'addressdetails' => 1,
                'countrycodes' => 'ph' // Prioritize Philippines
            ]);
            
            $result = $this->performGeocodeRequest($url);
            
            // If no results found with PH restriction, try without country restriction
            if (empty($result)) {
                $this->rateLimit(); // Wait another second for rate limiting
                
                $url = "https://nominatim.openstreetmap.org/search?" . http_build_query([
                    'q' => $location,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 1
                    // No country restriction - search globally
                ]);
                
                $result = $this->performGeocodeRequest($url);
            }
            
            if (empty($result)) {
                return [
                    'success' => false,
                    'message' => 'Location not found. Please try a more specific location name (e.g., "Manila, Philippines" or "Cebu City").'
                ];
            }
            
            return [
                'success' => true,
                'data' => [
                    'latitude' => (float)$result['lat'],
                    'longitude' => (float)$result['lon'],
                    'display_name' => $result['display_name'],
                    'address' => $result['address'] ?? []
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Perform the actual geocoding request
     * @param string $url
     * @return array|null
     */
    private function performGeocodeRequest($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept-Language: en'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("Geocoding curl error: " . $curlError);
            throw new Exception('Failed to connect to geocoding service');
        }
        
        if ($httpCode !== 200 || !$response) {
            error_log("Geocoding HTTP error: Code $httpCode, Response: " . substr($response, 0, 200));
            throw new Exception('Failed to geocode location');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Geocoding JSON decode error: " . json_last_error_msg());
            throw new Exception('Invalid response from geocoding service');
        }
        
        if (empty($data) || !is_array($data)) {
            return null;
        }
        
        return $data[0];
    }
    
    /**
     * Reverse Geocode: Convert coordinates to location name
     * @param float $latitude
     * @param float $longitude
     * @return array
     */
    public function reverseGeocode($latitude, $longitude) {
        try {
            if ($latitude === null || $longitude === null) {
                throw new Exception('Latitude and longitude are required');
            }
            
            // Rate limiting: Wait 1 second between requests
            $this->rateLimit();
            
            $url = "https://nominatim.openstreetmap.org/reverse?" . http_build_query([
                'lat' => $latitude,
                'lon' => $longitude,
                'format' => 'json',
                'addressdetails' => 1,
                'zoom' => 18
            ]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept-Language: en'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                throw new Exception('Failed to reverse geocode coordinates');
            }
            
            $data = json_decode($response, true);
            
            if (!isset($data['address'])) {
                return [
                    'success' => false,
                    'message' => 'Location not found'
                ];
            }
            
            $address = $data['address'];
            
            // Build a readable address string
            $addressParts = [];
            if (isset($address['village']) || isset($address['town']) || isset($address['city'])) {
                $addressParts[] = $address['village'] ?? $address['town'] ?? $address['city'];
            }
            if (isset($address['state']) || isset($address['province'])) {
                $addressParts[] = $address['state'] ?? $address['province'];
            }
            if (isset($address['country'])) {
                $addressParts[] = $address['country'];
            }
            
            $displayName = !empty($addressParts) ? implode(', ', $addressParts) : $data['display_name'];
            
            return [
                'success' => true,
                'data' => [
                    'display_name' => $displayName,
                    'full_address' => $data['display_name'],
                    'address' => $address,
                    'latitude' => (float)$data['lat'],
                    'longitude' => (float)$data['lon']
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Batch geocode multiple locations
     * @param array $locations
     * @return array
     */
    public function batchGeocode($locations) {
        $results = [];
        
        foreach ($locations as $location) {
            $result = $this->geocode($location);
            $results[] = [
                'location' => $location,
                'result' => $result
            ];
            // Wait 1 second between requests
            sleep(1);
        }
        
        return [
            'success' => true,
            'data' => $results
        ];
    }
    
    /**
     * Simple rate limiting (store last request time in session)
     */
    private function rateLimit() {
        // Only start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lastRequest = $_SESSION['geocoding_last_request'] ?? 0;
        $currentTime = time();
        
        if ($currentTime - $lastRequest < 1) {
            sleep(1);
        }
        
        $_SESSION['geocoding_last_request'] = time();
    }
}

// Handle the request (only if called directly, not when included)
if (basename($_SERVER['PHP_SELF']) === 'geocoding.php') {
    try {
        // Clean any output before processing
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Only handle HTTP requests when called directly
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $action = $_GET['action'] ?? 'geocode';
            $geocodingAPI = new GeocodingAPI();
            
            $result = null;
            switch ($action) {
                case 'geocode':
                    $location = $_GET['location'] ?? '';
                    $result = $geocodingAPI->geocode($location);
                    break;
                    
                case 'reverse':
                    $latitude = isset($_GET['latitude']) ? (float)$_GET['latitude'] : null;
                    $longitude = isset($_GET['longitude']) ? (float)$_GET['longitude'] : null;
                    $result = $geocodingAPI->reverseGeocode($latitude, $longitude);
                    break;
                    
                case 'batch':
                    $locations = isset($_GET['locations']) ? json_decode($_GET['locations'], true) : [];
                    if (empty($locations)) {
                        $result = [
                            'success' => false,
                            'message' => 'Locations array is required'
                        ];
                    } else {
                        $result = $geocodingAPI->batchGeocode($locations);
                    }
                    break;
                    
                default:
                    $result = [
                        'success' => false,
                        'message' => 'Invalid action'
                    ];
            }
            
            // Clean output again before sending JSON
            if (ob_get_level() > 0) {
                ob_clean();
            }
            
            header('Content-Type: application/json');
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
        } else {
            // Clean output before sending error
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    } catch (Exception $e) {
        // Clean output before sending error response
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Error $e) {
        // Catch fatal errors too
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    // End output buffering if it was started
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
// If included, do nothing - just provide the class
?>

