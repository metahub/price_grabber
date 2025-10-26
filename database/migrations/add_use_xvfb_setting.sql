-- Migration: Add use_xvfb setting to control whether to use Xvfb for Chrome
-- Date: 2025-10-26

-- Add setting to control Xvfb usage
-- Values: 'auto' (default - auto-detect), 'true' (force use), 'false' (never use)
INSERT INTO settings (`key`, value, description)
VALUES (
    'use_xvfb',
    'auto',
    'Control Xvfb usage for Chrome: auto (auto-detect), true (force use), false (never use)'
)
ON DUPLICATE KEY UPDATE
    value = VALUES(value),
    description = VALUES(description);
