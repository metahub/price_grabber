# Selenium Setup Guide

This guide covers installing and configuring Selenium with undetected-chromedriver for bypassing bot detection (e.g., Kasada on Otto.de).

## Requirements

- Python 3.7+
- Chrome/Chromium browser
- Xvfb (for headless servers without a display)

## Installation

### 1. Install Python Dependencies

```bash
pip3 install selenium undetected-chromedriver
```

### 2. Install Chrome/Chromium

#### Ubuntu/Debian:
```bash
# Install Chrome
wget https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
sudo dpkg -i google-chrome-stable_current_amd64.deb
sudo apt-get install -f

# Or install Chromium
sudo apt-get install chromium-browser
```

#### macOS:
```bash
brew install --cask google-chrome
```

### 3. Install Xvfb (Headless Servers Only)

Xvfb provides a virtual display for running Chrome in headless environments.

#### Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install xvfb
```

#### Verify installation:
```bash
which xvfb-run
# Should output: /usr/bin/xvfb-run
```

## Testing the Setup

### Test on Local Machine (with display):
```bash
python3 selenium-fetch.py "https://www.otto.de/p/?moin=M00PJC0055" 90 15 true
```

### Test on Headless Server (with Xvfb):
```bash
xvfb-run python3 selenium-fetch.py "https://www.otto.de/p/?moin=M00PJC0055" 90 15 true
```

### Expected Output:
- HTML content (700KB+) printed to stdout
- Logs to stderr showing: "Element already present: h1 (checked in 0.1s)"
- Exit code 0 on success

## How It Works

### Automatic Xvfb Detection

The PHP scraper (`src/Core/Scraper.php`) automatically detects if `xvfb-run` is available:

- **With Xvfb** (headless server): Wraps command with `xvfb-run -a`
- **Without Xvfb** (local machine with display): Runs command directly

### Bot Detection Bypass

The script uses `undetected-chromedriver` which:
- Patches Chrome to avoid CDP (Chrome DevTools Protocol) detection
- Removes automation indicators that bot detection systems look for
- Mimics human browser behavior

### Performance Optimization

The script uses two-phase element detection:
1. **Instant check**: Immediately looks for page elements (0.1s when already loaded)
2. **Wait mode**: Only waits up to 15s if elements not found (Kasada challenge)

This provides ~50% performance improvement over fixed wait times.

## Troubleshooting

### Error: "no such window: target window already closed"

**Cause**: Multiple scraper processes running simultaneously, competing for browser instances.

**Solution**: Only run one scraper process at a time, or increase wait time between runs.

### Error: "ChromeDriver only supports Chrome version X"

**Cause**: Chrome/Chromium version mismatch with chromedriver.

**Solution**: Update Chrome or let undetected-chromedriver auto-download matching version:
```bash
pip3 install --upgrade undetected-chromedriver
```

### Error: "Cannot open display" (on headless server)

**Cause**: Xvfb not installed or not being used.

**Solution**:
1. Install Xvfb: `sudo apt-get install xvfb`
2. Verify detection: Check logs for "Xvfb detected" message
3. Manual test: `xvfb-run python3 selenium-fetch.py ...`

### Still Getting 716-byte Responses (Kasada Challenge)

**Possible causes**:
1. IP address blocked/rate-limited
2. Kasada has updated detection methods
3. Headless mode detected (ensure Xvfb is working)

**Solutions**:
- Wait before retrying (rate limiting)
- Try from different IP address
- Verify Xvfb logs show virtual display is active

### High Memory Usage

**Expected behavior**: Each Chrome instance uses ~150-300MB RAM

**Solutions**:
- Limit concurrent scrapers (currently: 2 max)
- Monitor with `php scrape.php` logs showing memory usage
- Increase server RAM if running many concurrent scrapers

## Server Deployment Checklist

- [ ] Python 3.7+ installed
- [ ] `selenium` and `undetected-chromedriver` packages installed
- [ ] Chrome/Chromium browser installed
- [ ] Xvfb installed and working
- [ ] `selenium-fetch.py` has execute permissions: `chmod +x selenium-fetch.py`
- [ ] Test scraping one URL manually
- [ ] Check logs show "Xvfb detected" message
- [ ] Verify HTML responses are 700KB+, not 716 bytes

## Performance Notes

- **First scrape after Chrome update**: ~10-15s (driver initialization)
- **Subsequent scrapes**: ~7-8s per product (with instant element detection)
- **Concurrent scrapers**: 2 max (configured in `scrape.php`)
- **Memory per scraper**: ~150-300MB (Chrome instance)
- **Xvfb overhead**: ~20MB RAM

## References

- [undetected-chromedriver GitHub](https://github.com/ultrafunkamsterdam/undetected-chromedriver)
- [Selenium Python Docs](https://selenium-python.readthedocs.io/)
- [Xvfb Man Page](https://www.x.org/releases/X11R7.6/doc/man/man1/Xvfb.1.xhtml)
