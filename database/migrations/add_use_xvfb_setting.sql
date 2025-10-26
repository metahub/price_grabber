-- Migration: Add use_xvfb setting to control display mode for Chrome
-- Date: 2025-10-26

-- Add setting to control display mode
-- Values: 'auto' (default - try VNC, then Xvfb, then native), 'vnc' (force VNC), 'xvfb' (force Xvfb), 'false' (native window)
INSERT INTO settings (`key`, value, description)
VALUES (
    'use_xvfb',
    'auto',
    'Control display mode for Chrome: auto (try VNC>Xvfb>native), vnc (force VNC), xvfb (force Xvfb), false (native window)'
)
ON DUPLICATE KEY UPDATE
    description = VALUES(description);
