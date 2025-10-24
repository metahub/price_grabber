#!/usr/bin/env python3
"""
Fetch URL using Selenium with undetected-chromedriver
This bypasses Kasada and other bot detection systems
"""

import sys
import time
import undetected_chromedriver as uc
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

def fetch_url(url, timeout=60, wait_time=15, headless=True):
    """
    Fetch URL using undetected Chrome

    Args:
        url: URL to fetch
        timeout: Page load timeout in seconds
        wait_time: Time to wait after page load for JS challenges (seconds)
        headless: Run in headless mode (requires Xvfb on servers without display)

    Returns:
        HTML content as string

    Note:
        On headless servers, wrap this script with xvfb-run:
        xvfb-run python3 selenium-fetch.py <url> ...
    """
    options = uc.ChromeOptions()

    if headless:
        options.add_argument('--headless=new')

    # Stability options
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--window-size=1920,1080')

    # Enhanced stealth options to avoid detection
    # Note: undetected-chromedriver already handles many stealth options
    options.add_argument('--disable-blink-features=AutomationControlled')
    options.add_argument('--lang=de-DE,de')
    options.add_argument('--disable-notifications')

    # Set user preferences to appear more like a real browser
    prefs = {
        "credentials_enable_service": False,
        "profile.password_manager_enabled": False,
        "profile.default_content_setting_values.notifications": 2
    }
    options.add_experimental_option("prefs", prefs)

    driver = None

    try:
        # Create undetected Chrome driver with enhanced stealth
        driver = uc.Chrome(options=options, version_main=None, use_subprocess=True)

        # Set page load timeout
        driver.set_page_load_timeout(timeout)

        # Inject JavaScript BEFORE navigating to hide automation
        try:
            driver.execute_cdp_cmd('Page.addScriptToEvaluateOnNewDocument', {
                'source': '''
                    Object.defineProperty(navigator, 'webdriver', {
                        get: () => undefined
                    });
                    Object.defineProperty(navigator, 'plugins', {
                        get: () => [1, 2, 3, 4, 5]
                    });
                    Object.defineProperty(navigator, 'languages', {
                        get: () => ['de-DE', 'de', 'en-US', 'en']
                    });
                    window.chrome = {
                        runtime: {}
                    };
                    Object.defineProperty(navigator, 'permissions', {
                        get: () => ({
                            query: () => Promise.resolve({ state: 'prompt' })
                        })
                    });
                '''
            })
        except Exception as e:
            print(f"Warning: Could not inject stealth JavaScript: {e}", file=sys.stderr)

        # Navigate to URL
        driver.get(url)

        # Wait for page content to load (Kasada challenge to complete)
        # Check immediately if elements exist, only wait if not found
        start_time = time.time()

        # List of selectors that indicate page loaded successfully
        selectors_to_try = [
            (By.TAG_NAME, 'h1'),  # Product title (most reliable for Otto.de)
            (By.CSS_SELECTOR, '[data-oc-click*="price"]'),  # Price element
            (By.CSS_SELECTOR, '[data-testid="pdp-price"]'),  # Product detail page price
            (By.XPATH, '//*[@itemtype="http://schema.org/Product"]'),  # Schema.org product
        ]

        element_found = False

        # FIRST: Check if elements already exist (no waiting)
        # After Kasada redirects, elements appear immediately
        for by_method, selector in selectors_to_try:
            try:
                driver.find_element(by_method, selector)
                elapsed = time.time() - start_time
                print(f"Element already present: {selector} (checked in {elapsed:.1f}s)", file=sys.stderr)
                element_found = True
                break
            except:
                # Element not present yet, continue checking
                continue

        # SECOND: If no element found, wait for Kasada challenge to complete
        if not element_found:
            print(f"No element present yet, waiting for challenge...", file=sys.stderr)

            for by_method, selector in selectors_to_try:
                try:
                    # Check if we've exceeded total wait time
                    elapsed = time.time() - start_time
                    if elapsed >= wait_time:
                        break

                    # Wait for this element
                    remaining_time = wait_time - elapsed
                    WebDriverWait(driver, remaining_time).until(
                        EC.presence_of_element_located((by_method, selector))
                    )
                    elapsed = time.time() - start_time
                    print(f"Element found after waiting: {selector} (total {elapsed:.1f}s)", file=sys.stderr)
                    element_found = True
                    break
                except:
                    # This selector didn't work, try next one
                    continue

        if not element_found:
            # No element found even after waiting
            elapsed = time.time() - start_time
            print(f"No element found after {elapsed:.1f}s, continuing anyway", file=sys.stderr)

        # Get page source
        html = driver.page_source

        return html

    except Exception as e:
        print(f"Error: {str(e)}", file=sys.stderr)
        return None

    finally:
        if driver:
            driver.quit()

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python3 selenium-fetch.py <url> [timeout] [wait_time] [headless]", file=sys.stderr)
        sys.exit(1)

    url = sys.argv[1]
    timeout = int(sys.argv[2]) if len(sys.argv) > 2 else 60
    wait_time = int(sys.argv[3]) if len(sys.argv) > 3 else 15
    headless = sys.argv[4].lower() == 'true' if len(sys.argv) > 4 else True

    html = fetch_url(url, timeout, wait_time, headless)

    if html:
        print(html)
        sys.exit(0)
    else:
        sys.exit(1)
