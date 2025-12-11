# Weather-Based Crop Alert & Recommendation System

A lightweight web system that helps farmers by providing real-time weather-based alerts and simple crop recommendations. The system uses collected weather data to detect potential risks and notifies farmers so they can prepare.

## Features

### Core MVP Functionalities
- **Weather Data Collection**: Automatically fetch weather data (temperature, rainfall, humidity, wind speed, forecast date)
- **Condition Checking**: Identify risky weather patterns (drought, storm, heavy rainfall)
- **Alert System**: Send simple alerts to farmers about weather risks
- **Crop Recommendation**: Suggest suitable crops based on the current season
- **Farmer Records Management**: Store basic farmer details (name, location, crops planted)

### Reports
- **Weather Alerts Report**: List of current/past weather warnings
- **Affected Farmers Report**: Farmers at risk with crops affected
- **History Report**: Past alerts with timestamps and farmer responses/actions

## Technology Stack

- **Frontend**: HTML5, CSS3, JavaScript (Vanilla)
- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+
- **Weather API**: OpenWeatherMap (configurable)
- **Server**: Apache/Nginx (XAMPP recommended for development)

## Installation & Setup

### Prerequisites
- XAMPP (or similar LAMP/WAMP stack)
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web browser with JavaScript enabled

### Step 1: Clone/Download Project
1. Download or clone this project to your XAMPP htdocs folder
2. Extract to `C:\xampp\htdocs\project_v1\` (or your preferred location)

### Step 2: Database Setup
1. Start XAMPP and ensure MySQL is running
2. Open phpMyAdmin (http://localhost/phpmyadmin)
3. Import the database schema:
   - Click "Import" tab
   - Choose file: `database/schema.sql`
   - Click "Go" to execute

### Step 3: Configure Database Connection
1. Open `config/database.php`
2. Update database credentials if needed:
   ```php
   private $host = 'localhost';
   private $db_name = 'crop_alert_system';
   private $username = 'root';
   private $password = ''; // Your MySQL password
   ```

### Step 4: Weather API Configuration (Optional)
1. Get a free API key from [OpenWeatherMap](https://openweathermap.org/api)
2. Open `api/weather.php`
3. Replace `'your_openweathermap_api_key'` with your actual API key
4. Note: The system works with mock data even without an API key

### Step 5: Access the Application
1. Start Apache in XAMPP
2. Open your browser and navigate to: `http://localhost/project_v1/`
3. The application should load with sample data

## Usage

### Dashboard
- View current weather conditions
- See active alerts and their severity
- Monitor registered farmers count
- Check 5-day weather forecast

### Farmer Management
- Add new farmers with their details
- View all registered farmers
- Edit or delete farmer records
- Track crops planted by each farmer

### Weather Alerts
- View all weather alerts (active and resolved)
- Check weather conditions manually
- See affected farmers for each alert
- Monitor alert severity levels

### Reports
- **Weather Alerts Report**: Summary of all alerts
- **Affected Farmers Report**: List of farmers at risk
- **History Report**: Timeline of past alerts

### Crop Recommendations
- View season-appropriate crop suggestions
- See suitability ratings (high/medium/low)
- Check optimal planting times
- Get crop descriptions and requirements

## API Endpoints

### Weather API
- `GET /api/weather.php` - Fetch current weather data
- `POST /api/weather-check.php` - Check weather conditions and create alerts

### Farmers API
- `GET /api/farmers.php` - Get all farmers
- `POST /api/farmers.php` - Add new farmer
- `PUT /api/farmers.php` - Update farmer
- `DELETE /api/farmers.php` - Delete farmer

### Alerts API
- `GET /api/alerts.php` - Get all alerts
- `GET /api/alerts.php?status=active` - Get active alerts only
- `POST /api/alerts.php` - Create new alert
- `PUT /api/alerts.php` - Update alert

## Database Schema

### Tables
- **farmers**: Store farmer information
- **weather_data**: Store weather measurements
- **alerts**: Store weather alerts
- **alert_farmers**: Link alerts to affected farmers
- **crop_recommendations**: Store crop data by season
- **weather_conditions**: Store conditions that triggered alerts

## Customization

### Adding New Weather Conditions
1. Edit `api/weather-check.php`
2. Add new condition checking methods
3. Create corresponding alert creation methods
4. Update the main `checkWeatherConditions()` method

### Adding New Crops
1. Insert new records into `crop_recommendations` table
2. Or use the admin interface to add crops

### Modifying Alert Thresholds
1. Edit condition checking methods in `api/weather-check.php`
2. Adjust temperature, rainfall, and wind speed thresholds
3. Update severity levels as needed

## Troubleshooting

### Common Issues

1. **Database Connection Error**
   - Check MySQL is running in XAMPP
   - Verify database credentials in `config/database.php`
   - Ensure database `crop_alert_system` exists

2. **Weather Data Not Loading**
   - Check browser console for JavaScript errors
   - Verify API endpoints are accessible
   - Check PHP error logs

3. **Alerts Not Creating**
   - Ensure weather data exists in database
   - Check alert creation logic in `weather-check.php`
   - Verify farmers are registered

4. **Styling Issues**
   - Clear browser cache
   - Check CSS file path in `index.html`
   - Ensure all assets are in correct directories

### Error Logs
- PHP errors: Check XAMPP error logs
- JavaScript errors: Check browser developer console
- Database errors: Check MySQL error logs

## Development

### File Structure
```
project_v1/
├── index.html              # Main application page
├── assets/
│   ├── css/
│   │   └── style.css       # Application styles
│   └── js/
│       └── app.js          # Main JavaScript application
├── api/
│   ├── weather.php         # Weather data API
│   ├── farmers.php         # Farmers management API
│   ├── alerts.php          # Alerts management API
│   └── weather-check.php   # Weather condition checking
├── config/
│   └── database.php        # Database configuration
├── database/
│   └── schema.sql          # Database schema and sample data
└── README.md               # This file
```

### Adding New Features
1. Create new API endpoints in `api/` directory
2. Add corresponding JavaScript methods in `app.js`
3. Update HTML structure in `index.html`
4. Add styles in `style.css`
5. Update database schema if needed

## License

This project is open source and available under the MIT License.

## Support

For issues and questions:
1. Check the troubleshooting section
2. Review error logs
3. Ensure all prerequisites are met
4. Verify database setup and configuration

## Future Enhancements

- Real-time notifications via SMS/Email
- Interactive maps showing affected areas
- Mobile-responsive design improvements
- Advanced weather forecasting
- Integration with IoT sensors
- Multi-language support
- Advanced reporting and analytics
