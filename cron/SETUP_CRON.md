# Automatic Price Scraping Setup Guide

This guide explains how to set up automatic daily scraping of Bantay Presyo (Philippine Department of Agriculture) price data.

## What is Bantay Presyo?

Bantay Presyo is the official price monitoring system of the Philippine Department of Agriculture (DA). It provides the most accurate and up-to-date market prices for agricultural commodities across different regions in the Philippines.

## Setup Instructions

### For Linux/Unix Servers (Recommended)

1. **Make the script executable:**
   ```bash
   chmod +x cron/bantay-presyo-scraper.php
   ```

2. **Edit crontab:**
   ```bash
   crontab -e
   ```

3. **Add the following line to run daily at 6 AM:**
   ```cron
   0 6 * * * /usr/bin/php /path/to/project_v1/cron/bantay-presyo-scraper.php >> /path/to/project_v1/logs/cron.log 2>&1
   ```
   
   Replace `/path/to/project_v1` with your actual project path.

4. **Verify cron is set up:**
   ```bash
   crontab -l
   ```

### For Windows (XAMPP)

1. **Open Task Scheduler:**
   - Press `Win + R`, type `taskschd.msc`, press Enter

2. **Create Basic Task:**
   - Click "Create Basic Task" in the right panel
   - Name: "Bantay Presyo Price Scraper"
   - Description: "Daily scraping of Philippine market prices"
   - Trigger: Daily at 6:00 AM
   - Action: Start a program
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\project_v1\cron\bantay-presyo-scraper.php`
   - Start in: `C:\xampp\htdocs\project_v1`

3. **Save the task**

### For Windows (Manual Testing)

You can test the scraper manually by running:
```cmd
cd C:\xampp\htdocs\project_v1
php cron\bantay-presyo-scraper.php
```

## What Gets Scraped?

The scraper automatically fetches prices for:
- **Priority Regions:** Manila (NCR), Cebu, Davao, Baguio, Iloilo, Cagayan de Oro
- **Commodities:** Rice, Corn, Vegetables, Fruits, and other agricultural products
- **Frequency:** Daily (recommended at 6 AM after DA updates their data)

## Logs

Scraping logs are saved to:
- `logs/bantay-presyo-scraper.log`

Check this file to monitor scraping success and troubleshoot issues.

## Price Accuracy Levels

After scraping, prices will show accuracy indicators:

- **High Accuracy** (Green): Official DA data, less than 1 day old
- **Medium Accuracy** (Blue): Official DA data 1-3 days old, or API data less than 1 day old
- **Low Accuracy** (Yellow): Data 3-7 days old
- **Estimated** (Gray): Calculated or estimated prices

## Troubleshooting

### Scraper fails to run:
1. Check PHP path is correct
2. Ensure `logs/` directory exists and is writable
3. Check internet connection (scraper needs to access DA website)
4. Verify database connection is working

### No prices appearing:
1. Check the log file for errors
2. Verify Bantay Presyo website is accessible
3. Ensure database has write permissions
4. Check if region mapping is correct

### Prices not updating:
1. Verify cron job is running: `crontab -l` (Linux) or check Task Scheduler (Windows)
2. Check log file for execution times
3. Manually run scraper to test: `php cron/bantay-presyo-scraper.php`

## Additional Notes

- The scraper respects rate limits and adds delays between requests
- Scraped prices are stored in the `market_prices` table with source='bantay-presyo'
- Prices are automatically location-adjusted based on region
- The system prioritizes Bantay Presyo data over other sources for accuracy

## Support

For issues or questions, check:
1. Log files in `logs/` directory
2. Database `market_prices` table for stored data
3. Bantay Presyo website: http://www.bantaypresyo.da.gov.ph/
