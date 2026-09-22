#!/bin/bash
# Stop at the first failed step; without this every later step runs against a
# half-built project and the real error scrolls away.
set -e

# Check for Docker
if ! command -v docker &> /dev/null; then
    echo "Docker is required. Install it first: https://docs.docker.com/get-docker/"
    exit 1
fi

if ! docker info &> /dev/null; then
    echo "Docker provider (e.g., Docker Desktop, Lima, Colima, Orbstack, etc) is not running."
    echo "Start your Docker provider and try again."
    exit 1
fi

# Check for DDEV
if ! command -v ddev &> /dev/null; then
    echo "DDEV is required. Install it with: brew install ddev/ddev/ddev (macOS/Linux) or see https://ddev.readthedocs.io/en/stable/"
    exit 1
fi

# Parse flags up front so they can gate the whole run. --test / -t makes the
# finished site test-ready (installs the Drupal test framework and a Selenium
# browser via recipes/daedalus_recipe/setup-testing.sh). --no-art skips the closing art.
# --core <version> picks the drupal/core release to build on; the default is the
# newest core the suite is tested on. A caller that has to match sites it
# already runs passes its own.
SETUP_TESTING=false
SHOW_ART=true
CORE=11.4.7
while [[ $# -gt 0 ]]; do
    case $1 in
        --test|-t)
            SETUP_TESTING=true
            shift
            ;;
        --no-art)
            SHOW_ART=false
            shift
            ;;
        --core)
            if [[ $# -lt 2 ]]; then
                echo '--core needs a version, for example --core 11.4.7' >&2
                exit 1
            fi
            CORE="$2"
            shift 2
            ;;
        --core=*)
            CORE="${1#*=}"
            shift
            ;;
        *)
            shift
            ;;
    esac
done
if [[ -z "$CORE" ]]; then
    echo '--core needs a version, for example --core 11.4.7' >&2
    exit 1
fi

# Prompt user for project directory name
read -r -p "Enter the project directory name (default: daedalus): " project_name
project_name="${project_name:-daedalus}"

# Gather every answer up front so the long install can run unattended. The lean
# page builder (the recipe at the package root) is the default; opting in here
# installs the ai/ site recipe instead, which layers the AI editing assistant
# on top of it.
read -r -p "Install the AI editing assistant too? [y/N]: " install_ai
install_ai="${install_ai:-N}"

anthropic_key=''
gemini_key=''
if [[ "$install_ai" =~ ^[Yy]$ ]]; then
    # Chat runs on Anthropic (Claude) and is inert without a key.
    read -r -s -p "Anthropic (Claude) API key for chat - paste to enable, or press Enter to skip: " anthropic_key
    echo ''
    # Image generation is a separate opt-in (off by default in the recipe) and
    # runs on Google Gemini. Pasting a key both saves it and turns generation on;
    # press Enter to leave image generation off.
    read -r -s -p "Google Gemini API key for image generation - paste to enable, or press Enter to skip: " gemini_key
    echo ''
fi

# Resolve this script's on-disk location before we cd into the new project so
# the cleanup at the end can delete the downloaded copy.
script_self="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"

# Create directory, configure, and install Drupal
mkdir "$project_name" && cd "$project_name" || exit
echo 'Configuring ddev...'
ddev config --project-type=drupal --docroot=web --create-docroot --php-version='8.3'
echo 'Starting ddev...'
ddev start

echo "Creating drupal/recommended-project $CORE..."
ddev composer create-project drupal/recommended-project:"$CORE"
echo 'Fetching Drush...'
ddev composer require drush/drush
echo 'Setting minimum stability...'
ddev composer config minimum-stability dev
echo 'Setting prefer stable...'
ddev composer config prefer-stable true
echo 'Creating sync directory...'
ddev exec chmod 775 web/sites/default
# No unpack plugin setup or explicit drupal:recipe-unpack step is needed:
# recommended-project 11.3+ ships drupal/core-recipe-unpack pre-allowed, and the
# plugin auto-unpacks recipes to the site composer.json during composer require.
# One package carries both site recipes: recipe.yml at its root is the lean
# page builder and ai/recipe.yml beside it applies that base and layers the AI
# editing assistant on top, so the composer require is the same either way and
# only the recipe path differs. The package's composer.json requires the page
# builder, the theme and the AI stack, so nothing else is fetched later.
echo 'Fetching Daedalus...'
ddev composer require drupal/daedalus_recipe '1.0.x-dev' --prefer-source
if [[ "$install_ai" =~ ^[Yy]$ ]]; then
    recipe=../recipes/daedalus_recipe/ai
else
    recipe=../recipes/daedalus_recipe
fi
# The site is installed from the recipe itself rather than from an install
# profile with the recipe applied afterwards: both recipes are Site recipes and
# carry everything the installer needs, so this is the one path the suite is
# tested on. Drush resolves the path against the Drupal root (web/).
echo "Installing Drupal from $recipe..."
ddev drush site:install "$recipe" --account-name=admin --account-pass=admin -y
echo 'Clearing caches...'
ddev drush cr

# Apply the AI configuration gathered up front. Keys live in the Key module's
# `config` provider; writing them with `drush cset` stores the value in config
# (and it is briefly visible to `ps` while drush runs) - acceptable for a local
# ddev box. Rotate or remove keys later at /admin/config/system/keys.
if [[ "$install_ai" =~ ^[Yy]$ ]]; then
    set_ai_key() {
        # $1 = key id (key.key.<id>), $2 = secret value.
        ddev drush cset "key.key.$1" key_provider_settings.key_value "$2" -y > /dev/null
    }

    if [ -n "$anthropic_key" ]; then
        set_ai_key anthropic "$anthropic_key"
        echo 'Anthropic key saved - chat is ready.'
    else
        echo 'Anthropic chat key skipped. Add it later at /admin/config/system/keys (key id: anthropic).'
    fi

    if [ -n "$gemini_key" ]; then
        set_ai_key gemini "$gemini_key"
        # Turn image generation on. The recipe already defaults text-to-image to
        # Google Gemini, so no provider wiring is needed here; the chat default
        # (Anthropic) is left untouched.
        ddev drush cset daedalus_ai.settings image_generation_enabled true -y > /dev/null
        ddev drush cr
        echo 'Gemini key saved - image generation enabled.'
    else
        echo 'Image generation left off (no Gemini key). Turn it on later in the Daedalus AI settings.'
    fi
fi

# Make the finished site test-ready when requested (--test / -t). The recipe repo
# ships setup-testing.sh; it was pulled in by the composer require above, so it is
# present in the new project at recipes/daedalus_recipe/.
if [ "$SETUP_TESTING" = true ]; then
    echo 'Setting up the test environment...'
    bash recipes/daedalus_recipe/setup-testing.sh
fi

echo 'Launching site...'
if command -v open &> /dev/null; then
    ddev drush uli | xargs open
else
    ddev launch
fi
echo 'Clearing caches...'
ddev drush cr

if [ "$SHOW_ART" = true ]; then
  # cspell:disable
  echo "
[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m    [37m [0m[37m [0m[37m [0m[37m [0m      [37m [0m[37m [0m[37m [0m[37m.[0m[34m,[0m[34m.[0m[34m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m     [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m   [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m,[0m[34md[0m[34m:[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[36mc[0m[36mX[0m[34md[0m[34m,[0m[34m,[0m[34m.[0m[34m.[0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[37m'[0m[36mO[0m[37mN[0m[34mx[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m    [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[37m,[0m[36mo[0m[36mK[0m[37mW[0m[36m0[0m[34ml[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m.[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m       [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[37m'[0m[36mc[0m[36mx[0m[37mK[0m[37mN[0m[37mN[0m[34mO[0m[34ml[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
    [37m [0m[37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[37m;[0m[36mx[0m[36mK[0m[37mW[0m[37mW[0m[37mN[0m[36mK[0m[36mO[0m[34mo[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m,[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[37m.[0m[37m;[0m[37ml[0m[37mx[0m[37m0[0m[37mN[0mM[37mM[0m[37mM[0m[37mM[0m[37mN[0m[36mK[0m[34mO[0m[34mo[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m.[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m   [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[37m'[0m[36mo[0m[36m0[0m[37mX[0m[37mW[0mMMMMMM[37mW[0m[36mX[0m[36m0[0m[34mk[0m[34md[0m[34m:[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m    [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
 [37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m.[0m[37m'[0m[36mc[0m[36mx[0m[37m0[0m[37mW[0m[37mM[0m[37mM[0m[37mM[0m[37mM[0mMMM[37mW[0m[37mX[0m[36m0[0m[34mO[0m[34mx[0m[34ml[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
  [37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m'[0m[36ml[0m[36mk[0m[37mK[0m[37mW[0mM[37mM[0m[37mM[0m[37mM[0m[37mM[0mMM[37mM[0m[37mW[0m[36mX[0m[34mO[0m[34mx[0m[34mo[0m[34mc[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m    [37m [0m[37m [0m[37m [0m[37m.[0m[36mo[0m[37mX[0mMMMMMM[37mM[0mM[37mW[0m[37mW[0m[37mX[0m[36mK[0m[34m0[0m[34mx[0m[34mc[0m[34m:[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m.[0m[37m [0m[37m [0m     [37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[36m:[0m[36mO[0m[37mW[0mMMMMMMM[37mW[0m[37mX[0m[36mK[0m[34m0[0m[34mO[0m[34mx[0m[34mo[0m[34m:[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m    [37m [0m
[37m [0m[37m [0m  [37m [0m[37m [0m[37m.[0m[36mc[0m[36mO[0m[37mN[0mMMMM[37mM[0m[37mW[0m[37mN[0m[37mX[0m[36mX[0m[34mO[0m[34mx[0m[34mo[0m[34ml[0m[34mc[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m
[37m [0m  [37m [0m[37m [0m[34m,[0m[34mo[0m[36mK[0m[37mW[0mW[37mW[0m[37mN[0m[36mX[0m[36mK[0m[36mK[0m[34m0[0m[34mk[0m[34mx[0m[34mo[0m[34mc[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
  [37m [0m[37m [0m[34m'[0m[34ml[0m[34mO[0m[34mO[0m[34m0[0m[36m0[0m[36m0[0m[34m0[0m[34mO[0m[34mx[0m[34md[0m[34ml[0m[34mc[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m
 [37m [0m[37m [0m[34m.[0m[34m,[0m[34ml[0m[34mx[0m[34mk[0m[34mx[0m[34mx[0m[34md[0m[34mo[0m[34mc[0m[34m:[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[37m [0m[37m [0m
[37m [0m[37m [0m[34m.[0m[34m'[0m[34m;[0m[34m;[0m[34m;[0m[34m:[0m[34m:[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m
[37m [0m[34m.[0m[34m'[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[37m.[0m[37m [0m
[34m.[0m[34m'[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[37m [0m
[34m.[0m[34m'[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[34m.[0m
[34m'[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m
[34m,[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m
[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m
[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34mc[0m[34mc[0m[34ml[0m[34ml[0m[34ml[0m[34mc[0m[34mc[0m[34m:[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m
[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34mc[0m[34mo[0m[34mx[0m[36m0[0m[36mX[0m[36mX[0m[36mN[0m[36mN[0m[36mN[0m[36mN[0m[36mX[0m[36mK[0m[34mO[0m[34mx[0m[34mo[0m[34mc[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m;[0m[34mc[0m[34mo[0m[34mo[0m[34ml[0m[34mc[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m
[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m:[0m[34mo[0m[34mk[0m[36mK[0m[37mN[0mWMMMMMMMMMMMM[37mW[0m[37mN[0m[36mK[0m[36mO[0m[34mx[0m[34ml[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m;[0m[34mc[0m[34mo[0m[36mO[0m[37mX[0m[37mW[0mMMM[37mN[0m[36mK[0m[34ml[0m[34m'[0m[34m'[0m[34m'[0m
[34m'[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m:[0m[34mk[0m[37mW[0mMMMMMMMMMMMM[37mM[0m[37mM[0m[37mM[0m[37mM[0m[37mM[0mM[37mM[0mMM[37mW[0m[36m0[0m[34mx[0m[34ml[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m;[0m[34mo[0m[36mk[0m[37mX[0m[37mW[0mMMMM[37mM[0m[37mM[0mM[37mM[0mM[36mK[0m[34m:[0m[34m'[0m[34m.[0m
[34m.[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m:[0m[34mk[0m[37mW[0mMMMMMMMMMMMMMMMMMMMMMMM[37mM[0m[37mM[0m[37mN[0m[36m0[0m[34mx[0m[34ml[0m[34m;[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34ml[0m[36mO[0m[36mX[0m[37mW[0mM[37mM[0m[37mM[0m[37mM[0m[37mM[0mMMMMM[37mM[0m[37mM[0m[37mW[0m[36md[0m[34m'[0m[34m.[0m
[34m.[0m[34m'[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34mx[0m[37mW[0mM[37mM[0m[37mM[0mMMMMM[37mM[0m[37mM[0m[37mM[0m[37mM[0m[37mM[0m[37mM[0m[37mM[0mMMM[37mM[0m[37mM[0m[37mM[0mMMMMMMMM[37mN[0m[36m0[0m[36md[0m[34ml[0m[34mc[0m[34mc[0m[34mo[0m[36mx[0m[36mO[0mNM[37mM[0m[37mM[0m[37mM[0mMMM[37mM[0m[37mM[0m[37mM[0mMMMMM[37mW[0m[36md[0m[34m'[0m[34m.[0m
[37m [0m[34m.[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34mo[0m[36mX[0mM[37mM[0mMMMMMMMMMMMMMMMMM[37mM[0m[37mM[0m[37mM[0m[37mM[0m[37mM[0mMMMMM[37mM[0m[37mM[0m[37mM[0mM[37mW[0m[37mN[0m[37mW[0mMMMMM[37mM[0mMMMMMMMMMMMMM[37mN[0m[34mc[0m[37m.[0m[37m [0m
[37m [0m[37m [0m[34m.[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34mx[0m[36mW[0mMMMMMMMMMMMMMMMMMMMMMMMM[37mM[0m[37mM[0m[37mM[0m[37mM[0mM[37mW[0m[37mX[0m[36m0[0m[36md[0m[34ml[0m[34mc[0m[34ml[0m[36mo[0m[36mk[0m[37mX[0m[37mW[0mMMMMMMMMMMMMMMM[37m0[0m[34m,[0m[37m.[0m[37m [0m
[37m [0m[37m [0m[37m [0m[34m'[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34mo[0m[36mX[0mM[37mM[0mMMMMMMMMM[37mM[0m[37mM[0m[37mM[0mMMMMMMMMMM[37mM[0m[37mM[0m[37mN[0m[37m0[0m[36mx[0m[34ml[0m[34m:[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m:[0m[36mO[0m[37mW[0mMMMMMMM[37mM[0m[37mM[0m[37mM[0mMMMM[36mx[0m[37m.[0m[37m [0m[37m [0m
 [37m [0m[37m [0m[34m.[0m[34m,[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m:[0m[34mx[0m[37mN[0mMMMMMMMMM[37mM[0m[37mM[0mMMMMMMMMM[37mW[0m[37mN[0m[37mK[0m[36mk[0m[34ml[0m[34m:[0m[34m,[0m[34m'[0m[34m'[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m,[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34ml[0m[36mk[0m[37mX[0mWMMMM[37mM[0m[37mM[0m[37mM[0mMM[37mW[0m[37m0[0m[37m,[0m[37m [0m[37m [0m[37m [0m
  [37m [0m[37m [0m[34m.[0m[34m.[0m[34m'[0m[34m,[0m[34m,[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34m;[0m[34mo[0m[36mX[0mMMMMMMMMMMMMMMMM[37mN[0m[36m0[0m[36mx[0m[34mo[0m[34m:[0m[34m,[0m[34m'[0m[34m'[0m[34m,[0m[34mc[0m[36md[0m[36mO[0m[36m0[0m[36mX[0m[36mX[0m[37mX[0m[37mK[0m[36m0[0m[36mk[0m[34ml[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m;[0m[34mo[0m[36m0[0m[37mW[0mMMMMMM[37mN[0m[36md[0m[37m,[0m[37m [0m[37m [0m [37m [0m
   [37m [0m[37m [0m[34m [0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34m,[0m[34m,[0m[34m,[0m[34m,[0m[34m,[0m[34m,[0m[34m,[0m[34m,[0m[34mc[0m[36mk[0m[36m0[0m[37mX[0m[37mW[0mMMMMMMM[37mW[0m[37mN[0m[37mX[0m[36mO[0m[34mo[0m[34m:[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34mo[0m[36mO[0m[36mN[0m[36mN[0m[36m0[0m[36mx[0m[36md[0m[34mo[0m[34md[0m[36mx[0m[36m0[0m[37mN[0m[37mN[0m[36mx[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m;[0m[34ml[0m[36mO[0m[37mN[0mW[37mW[0m[36mX[0m[36mk[0m[36mc[0m[37m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
 [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34m;[0m[34m:[0m[34mc[0m[34mc[0m[34ml[0m[34ml[0m[34ml[0m[34ml[0m[34mc[0m[34mc[0m[34m:[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34mc[0m[36mO[0m[34mx[0m[34mo[0m[34m:[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34ml[0m[36mk[0m[34mx[0m[34m;[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m:[0m[34mc[0m[34m:[0m[34m,[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
 [37m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m.[0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[37m.[0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34m:[0m[34m;[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34mc[0m[34m;[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m   [37m [0m[37m [0m[37m [0m[37m.[0m[34m.[0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34mo[0m[36mX[0m[36mX[0m[36mk[0m[36md[0m[34mc[0m[34m:[0m[34m;[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34m;[0m[34m:[0m[34mc[0m[36mo[0m[36mx[0m[36m0[0m[36mK[0m[34mc[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m   [37m [0m[37m [0m [37m [0m   [37m [0m[37m [0m[37m [0m[37m.[0m[34m.[0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m;[0m[36md[0m[36mO[0m[36m0[0m[36mX[0m[36mX[0m[36mX[0m[36mX[0m[36mK[0m[36m0[0m[36m0[0m[36m0[0m[36m0[0m[36m0[0m[36m0[0m[36mK[0m[36m0[0m[36m0[0m[36m0[0m[36m0[0m[36mO[0m[36mx[0m[34mo[0m[34m:[0m[34m'[0m[34m.[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m   [37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[34m.[0m[34m.[0m[34m'[0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m,[0m[34m:[0m[34mc[0m[34ml[0m[34mo[0m[36mo[0m[36md[0m[36md[0m[34md[0m[34mo[0m[34mo[0m[34ml[0m[34mc[0m[34m:[0m[34m,[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m  [37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m   [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[34m.[0m[34m.[0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[34m.[0m[34m.[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m
[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m    [37m [0m [37m [0m   [37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m.[0m[34m.[0m[34m.[0m[34m.[0m[34m'[0m[34m.[0m[34m.[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m'[0m[34m.[0m[34m.[0m[34m.[0m[34m.[0m[34m.[0m[34m.[0m[34m.[0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m[37m [0m

[36mTry out Daedalus:
- Go Create > Landing Page > give it a title > Save
- Drag a text block from the sidebar to the page body and place it in a drop zone
- Click the pencil icon > click the text block > change the text
"
  # cspell:enable
fi

if [[ "$install_ai" =~ ^[Yy]$ ]]; then
  echo ''
  echo 'AI editing assistant installed:'
  echo '- Open the chat panel (bubble, bottom-right) and ask it to build or edit the page.'
  echo "- In Edit Mode, press 'a' for the AI tool to select blocks/fields as context."
  echo '- Manage or add API keys anytime at /admin/config/system/keys.'
fi
# Clean up after ourselves.
rm -f "$script_self"
