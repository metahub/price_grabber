#!/usr/bin/env python3
"""
Capture browser fingerprint using Selenium with undetected-chromedriver
"""

import sys
import json
import os
import time
import undetected_chromedriver as uc
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

def capture_fingerprint():
    """
    Load fingerprint-test.html and capture the fingerprint data
    """
    options = uc.ChromeOptions()

    # Use same options as actual scraper
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--window-size=1920,1080')
    options.add_argument('--disable-blink-features=AutomationControlled')
    options.add_argument('--lang=de-DE,de')
    options.add_argument('--disable-notifications')

    prefs = {
        "credentials_enable_service": False,
        "profile.password_manager_enabled": False,
        "profile.default_content_setting_values.notifications": 2
    }
    options.add_experimental_option("prefs", prefs)

    driver = None

    try:
        # Create undetected Chrome driver
        driver = uc.Chrome(options=options, version_main=None, use_subprocess=True)

        # Get absolute path to fingerprint-test.html
        script_dir = os.path.dirname(os.path.abspath(__file__))
        html_path = os.path.join(script_dir, 'fingerprint-test.html')
        file_url = f'file://{html_path}'

        print(f"Loading fingerprint test from: {file_url}", file=sys.stderr)

        # Load the test page
        driver.get(file_url)

        # Wait for fingerprint collection to complete
        # Check if window.fingerprintData exists
        max_wait = 15
        start_time = time.time()

        print("Waiting for fingerprint collection...", file=sys.stderr)

        while time.time() - start_time < max_wait:
            try:
                # Try to get the fingerprint data
                fingerprint_ready = driver.execute_script('return typeof window.fingerprintData !== "undefined" && window.fingerprintData !== null;')
                if fingerprint_ready:
                    elapsed = time.time() - start_time
                    print(f"Fingerprint data ready after {elapsed:.1f}s", file=sys.stderr)
                    break
            except Exception as e:
                print(f"Wait error: {e}", file=sys.stderr)
                pass
            time.sleep(0.5)

        # Get the fingerprint data
        fingerprint_data = driver.execute_script('return window.fingerprintData;')

        if not fingerprint_data:
            # Debug: check what's in the page
            page_text = driver.find_element(By.TAG_NAME, 'body').text
            print(f"Page content preview: {page_text[:200]}", file=sys.stderr)

        if fingerprint_data:
            # Add some metadata
            fingerprint_data['_meta'] = {
                'captured_at': time.strftime('%Y-%m-%d %H:%M:%S'),
                'script_version': '1.0'
            }

            # Output as JSON
            print(json.dumps(fingerprint_data, indent=2))
            return True
        else:
            print("ERROR: Could not capture fingerprint data", file=sys.stderr)
            return False

    except Exception as e:
        print(f"ERROR: {str(e)}", file=sys.stderr)
        return False

    finally:
        if driver:
            driver.quit()

if __name__ == '__main__':
    success = capture_fingerprint()
    sys.exit(0 if success else 1)
