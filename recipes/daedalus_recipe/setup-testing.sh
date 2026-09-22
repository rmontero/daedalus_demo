#!/bin/bash
#
# Makes an existing Daedalus ddev project test-ready: installs the Drupal test
# framework (drupal/core-dev, which brings PHPUnit + Mink + the WebDriver stack)
# and a Selenium/Chrome browser for FunctionalJavascript (WebDriver) tests.
# Idempotent - safe to re-run.
#
# Run from the project root (where .ddev/ lives):
#   bash recipes/daedalus_recipe/setup-testing.sh
#
# The installer runs this for you when passed --test (or -t).

# Must run from a ddev project root: every step below drives ddev.
if [ ! -f .ddev/config.yaml ]; then
    echo "This must be run from a ddev project root (no .ddev/config.yaml here)."
    echo "cd into your Daedalus project first, then re-run."
    exit 1
fi

if ! command -v ddev &> /dev/null; then
    echo "DDEV is required. See https://ddev.readthedocs.io/en/stable/"
    exit 1
fi

# The test framework is a dev dependency; drupal/recommended-project ships without
# it. core-dev pulls PHPUnit, Mink, mink-selenium2-driver, and the browser test
# base classes. --with-all-dependencies lets its shared deps resolve against the
# versions core-recommended already pins.
echo 'Installing the Drupal test framework (drupal/core-dev)...'
ddev composer require --dev drupal/core-dev:'^11.3' --with-all-dependencies \
    || { echo 'Failed to require drupal/core-dev.'; exit 1; }

# FunctionalJavascript tests drive a real browser over WebDriver. This community
# addon adds a Selenium/Chromium service (the selenium/standalone-chromium image,
# which is arm64-compatible) and sets the test env vars - SIMPLETEST_DB,
# SIMPLETEST_BASE_URL, MINK_DRIVER_ARGS_WEBDRIVER, BROWSERTEST_OUTPUT_DIRECTORY -
# in the web container, so tests run with no per-run configuration.
echo 'Adding the Selenium/Chrome browser for FunctionalJavascript tests...'
ddev add-on get ddev/ddev-selenium-standalone-chrome \
    || { echo 'Failed to add the selenium-standalone-chrome addon.'; exit 1; }

# Bring the browser service and its env vars into the running containers.
echo 'Restarting ddev so the browser service and test env vars take effect...'
ddev restart

echo ''
echo 'Test environment ready. The selenium addon sets SIMPLETEST_DB,'
echo 'SIMPLETEST_BASE_URL, MINK_DRIVER_ARGS_WEBDRIVER, and BROWSERTEST_OUTPUT_DIRECTORY'
echo 'in the web container, so no inline env vars are needed. Run tests from the'
echo 'project root, e.g.:'
echo ''
echo "  ddev exec bash -c 'cd web && XDEBUG_MODE=off ../vendor/bin/phpunit -c core modules/contrib/daedalus/modules/daedalus_blueprint/tests'"
echo ''
echo 'The same command runs Unit, Kernel, Functional, and FunctionalJavascript'
echo '(Selenium) tests. XDEBUG_MODE=off avoids a core transaction-teardown fatal when'
echo "Xdebug's develop mode is enabled. Example (JS tier):"
echo ''
echo "  ddev exec bash -c 'cd web && XDEBUG_MODE=off ../vendor/bin/phpunit -c core modules/contrib/daedalus/modules/daedalus_ai/tests/src/FunctionalJavascript'"
