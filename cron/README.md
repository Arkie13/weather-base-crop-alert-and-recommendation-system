# Automatic Price Scraping System

This directory contains scripts for automatically updating crop prices from various sources.

## Files

- **bantay-presyo-scraper.php** - Scrapes official Philippine Department of Agriculture (DA) price data
- **update-crop-prices.php** - Updates prices from multiple sources (includes Bantay Presyo scraping)
- **setup-windows-task.bat** - Windows setup script for automatic scheduling
- **SETUP_CRON.md** - Detailed setup instructions

## Quick Setup

### Windows (XAMPP)

1. **Double-click** `setup-windows-task.bat` (Run as Administrator)
   OR
2. **Manual Setup:**
   - Open Task Scheduler
   - Create task to run: `php.exe cron\bantay-presyo-scraper.php` daily at 6 AM

### Linux/Unix

```bash
# Add to crontab
crontab -e

# Add this line:
0 6 * * * /usr/bin/php /path/to/project_v1/cron/bantay-presyo-scraper.php
```

## Price Accuracy

After scraping, prices will show accuracy indicators:

- 🟢 **High Accuracy**: Official DA data, < 1 day old
- 🔵 **Medium Accuracy**: Official DA data 1-3 days old, or API data < 1 day old  
- 🟡 **Low Accuracy**: Data 3-7 days old
- ⚪ **Estimated**: Calculated or estimated prices

## Logs

All scraping activities are logged to:
- `logs/bantay-presyo-scraper.log`
- `logs/crop-prices-update.log`

Check these files to monitor scraping success and troubleshoot issues.

## Testing

Test the scraper manually:
```bash
php cron/bantay-presyo-scraper.php
```

Or test price updates:
```bash
php cron/update-crop-prices.php
```
