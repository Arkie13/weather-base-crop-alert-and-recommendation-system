# Price Accuracy & Automatic Scraping Setup - Complete

## ✅ What Has Been Implemented

### 1. Automatic Bantay Presyo Scraping ✅
- **Created:** `cron/bantay-presyo-scraper.php` - Automated scraper for DA official prices
- **Updated:** `cron/update-crop-prices.php` - Now includes Bantay Presyo scraping
- **Schedules:** Daily at 6 AM (recommended)
- **Regions:** Manila, Cebu, Davao, Baguio, Iloilo, Cagayan de Oro

### 2. Price Accuracy Indicators ✅
- **Backend:** Added `getPriceAccuracy()` function that determines accuracy based on:
  - Source (Bantay Presyo = highest, calculated = lowest)
  - Age of data (newer = more accurate)
- **Frontend:** Added accuracy badges showing:
  - 🟢 **High Accuracy** - Official DA data < 1 day old
  - 🔵 **Medium Accuracy** - Official DA 1-3 days, or API < 1 day old
  - 🟡 **Low Accuracy** - Data 3-7 days old
  - ⚪ **Estimated** - Calculated or estimated prices

### 3. UI Updates ✅
- **Farmer Dashboard:** Market Prices section shows accuracy badges
- **Admin Dashboard:** Market Prices section shows accuracy badges
- **Visual Indicators:** Color-coded badges with icons
- **Mobile Responsive:** Badges adapt to screen size

## 📋 Setup Instructions

### Quick Setup (Windows)

1. **Run the setup script:**
   ```
   Right-click cron\setup-windows-task.bat → Run as Administrator
   ```

2. **Or manually:**
   - Open Task Scheduler
   - Create task to run daily at 6 AM:
     - Program: `C:\xampp\php\php.exe`
     - Arguments: `C:\xampp\htdocs\project_v1\cron\bantay-presyo-scraper.php`

### Quick Setup (Linux/Unix)

```bash
# Edit crontab
crontab -e

# Add this line:
0 6 * * * /usr/bin/php /path/to/project_v1/cron/bantay-presyo-scraper.php
```

## 🎯 How It Works

### Price Source Priority (Highest to Lowest Accuracy):

1. **Bantay Presyo (DA Official)** 🟢
   - Scraped daily from Department of Agriculture
   - Most accurate for Philippine market prices
   - Location-specific data

2. **Admin Manual Entry** 🟢
   - Prices entered by administrators
   - High accuracy if recent (< 7 days)

3. **International APIs** 🔵
   - API Ninjas, Commodities-API, etc.
   - Converted from USD and adjusted for location
   - Medium accuracy (global prices, not PH-specific)

4. **Calculated Prices** ⚪
   - Based on historical trends
   - Seasonal and location adjustments applied
   - Estimated accuracy

### Accuracy Calculation:

```php
High Accuracy:    Bantay Presyo < 1 day old, or Admin < 7 days old
Medium Accuracy:  Bantay Presyo 1-3 days, API < 1 day, Admin 7-14 days
Low Accuracy:      Data 3-7 days old
Estimated:        Calculated prices or data > 7 days old
```

## 📊 What Users See

### Price Cards Now Display:

1. **Source Badge:**
   - "Official (DA)" - Bantay Presyo data
   - "Live" - API Ninjas
   - "API" - Other APIs
   - "Admin" - Manually entered
   - "Cached" - Database stored
   - "Calculated" - System calculated
   - "Estimate" - Fallback

2. **Accuracy Badge:**
   - Color-coded indicator
   - Tooltip showing full accuracy level
   - Icon indicating reliability

3. **Price Information:**
   - Current price per kg
   - Price trend (rising/falling/stable)
   - Last updated date
   - Demand level

## 🔧 Testing

### Test Scraper Manually:
```bash
php cron/bantay-presyo-scraper.php
```

### Test Price Updates:
```bash
php cron/update-crop-prices.php
```

### Check Logs:
- `logs/bantay-presyo-scraper.log` - Scraper activity
- `logs/crop-prices-update.log` - Price update activity

## 📈 Expected Results

After setup:
- ✅ Prices update automatically daily
- ✅ Most prices show "High Accuracy" (from Bantay Presyo)
- ✅ Prices vary by location (Manila vs Cebu vs Davao)
- ✅ Users can see price reliability at a glance
- ✅ Admins can manually update prices for even better accuracy

## 🎨 Visual Examples

**High Accuracy Price:**
```
Rice                    [Official (DA)] [🟢 High Accuracy]
PHP 28.50 / kg
```

**Estimated Price:**
```
Tomato                 [Calculated] [⚪ Estimated]
PHP 35.00 / kg
```

## 📝 Notes

- Scraper runs daily at 6 AM (after DA updates their data)
- Prices are cached in database for performance
- Location adjustments applied automatically
- Seasonal adjustments applied automatically
- Admin can override any price for maximum accuracy

## 🆘 Troubleshooting

See `cron/SETUP_CRON.md` for detailed troubleshooting guide.

---

**Status:** ✅ Complete and Ready to Use
**Last Updated:** Today
**Next Steps:** Set up cron job using instructions above
