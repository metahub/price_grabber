# VNC Server Setup for Scraping

This guide explains how to set up a VNC server on your Linux server to provide a full desktop environment for Chrome, which appears more "real" to bot detection systems like Kasada compared to Xvfb.

## Why VNC Instead of Xvfb?

**Xvfb (X Virtual Framebuffer):**
- Minimal X11 server with basic rendering only
- No window manager, no desktop environment
- Bot detection systems (like Kasada) can fingerprint it

**VNC with Desktop Environment:**
- Full XFCE/LXDE desktop with window manager
- Proper font rendering and WebGL support
- More realistic browser environment
- Harder for bot detection to fingerprint

## Installation

### 1. Install VNC Server and Desktop Environment

```bash
sudo apt-get update
sudo apt-get install tightvncserver xfce4 xfce4-goodies dbus-x11
```

### 2. Initial VNC Setup

Run VNC server once to create config files:

```bash
# Run as www-data user (or whatever user runs your PHP scripts)
sudo -u www-data vncserver :1

# It will prompt for a password - set one and remember it (for debugging access only)
# View-only password: n (not needed)
```

This creates `~/.vnc/` directory with config files.

### 3. Stop the Initial VNC Server

```bash
sudo -u www-data vncserver -kill :1
```

### 4. Configure VNC Startup Script

Edit `~/.vnc/xstartup` for www-data user:

```bash
sudo -u www-data nano /var/www/.vnc/xstartup
```

Replace contents with:

```bash
#!/bin/sh
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
exec startxfce4
```

Make it executable:

```bash
sudo chmod +x /var/www/.vnc/xstartup
```

### 5. Install Systemd Service

Copy the service file:

```bash
sudo cp server-config/vncserver@.service /etc/systemd/system/
```

Reload systemd and enable the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable vncserver@1.service
sudo systemctl start vncserver@1.service
```

Verify it's running:

```bash
sudo systemctl status vncserver@1.service
ps aux | grep vnc
```

### 6. Update Database Setting

Run the migration to update the setting description:

```bash
mysql -u [user] -p [database] < database/migrations/add_use_xvfb_setting.sql
```

Set the display mode to use VNC:

```sql
-- Force VNC usage
UPDATE settings SET value='vnc' WHERE `key`='use_xvfb';

-- Or use auto-detect (tries VNC first, falls back to Xvfb)
UPDATE settings SET value='auto' WHERE `key`='use_xvfb';
```

## Testing

### Test VNC Server

```bash
# Check if VNC is running on display :1
ps aux | grep vnc

# Check display lock file
ls -la /tmp/.X1-lock

# Test connecting to VNC (optional, for debugging)
# From your local machine:
ssh -L 5901:localhost:5901 user@server
# Then use VNC viewer to connect to localhost:5901
```

### Test Scraper

```bash
php scrape.php -n 1
```

Check logs for:
```
"VNC auto-detected", display: ":1"
or
"VNC forced by setting", display: ":1"
```

Expected results:
- HTML length: 600KB+ (not 810 bytes)
- Price extracted successfully
- No Kasada challenge page

## Troubleshooting

### VNC Server Won't Start

```bash
# Check logs
journalctl -u vncserver@1.service -n 50

# Manually test as www-data user
sudo -u www-data vncserver :1 -geometry 1920x1080 -depth 24

# Check for port conflicts
netstat -tulpn | grep 590
```

### Scraper Still Gets Kasada Challenge

If VNC doesn't bypass Kasada, possible causes:
1. **IP reputation** - Server IP is flagged/rate-limited
2. **Missing fonts** - Install common fonts:
   ```bash
   sudo apt-get install fonts-liberation fonts-dejavu-core
   ```
3. **Still fingerprinted** - VNC might not be enough; may need:
   - Residential proxy service
   - Different server location (German IP for Otto.de)
   - Physical machine with real GPU

### Memory Usage

VNC + XFCE uses more memory than Xvfb:
- Xvfb: ~20MB RAM
- VNC + XFCE: ~100-150MB RAM

Monitor with:
```bash
ps aux --sort=-%mem | head -20
```

### Permissions

Ensure www-data user can access display:

```bash
# Add www-data to necessary groups
sudo usermod -a -G video www-data

# Verify VNC is running as www-data
ps aux | grep vnc
```

## Display Mode Settings

Configure via database `settings` table:

| Value | Behavior |
|-------|----------|
| `'auto'` | Try VNC first, fall back to Xvfb, then native window |
| `'vnc'` | Force VNC usage (fails if VNC not running) |
| `'xvfb'` | Force Xvfb usage (for comparison testing) |
| `'false'` | Native window (for local development) |

## Monitoring

Check VNC server status:

```bash
# Service status
sudo systemctl status vncserver@1.service

# Display in use
ps aux | grep Xvnc

# Connected clients (if any)
netstat -an | grep 5901
```

## Stopping VNC

```bash
# Stop service
sudo systemctl stop vncserver@1.service

# Or kill manually
sudo -u www-data vncserver -kill :1
```

## References

- [TightVNC Documentation](https://www.tightvnc.com/)
- [XFCE Desktop Environment](https://www.xfce.org/)
- [Systemd Service Files](https://www.freedesktop.org/software/systemd/man/systemd.service.html)
