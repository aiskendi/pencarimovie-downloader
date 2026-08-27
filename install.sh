#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

if [ -f vendor/autoload.php ]; then
  echo "Bundled vendor dependencies found. Composer install is not needed."
  exit 0
fi

RUNTIME="./bin/frankenphp"
if [ ! -x "$RUNTIME" ]; then
  RUNTIME="php"
fi

if [ ! -f bin/php.ini ]; then
  echo "Bundled PHP config bin/php.ini not found."
  exit 1
fi

if [ "$RUNTIME" = "php" ]; then
  if ! command -v php >/dev/null 2>&1; then
    echo "PHP is only required when vendor dependencies are missing."
    echo ""
    echo "This package does not contain vendor/autoload.php. Install PHP first, then run ./install.sh again."
    echo ""
    echo "Debian/Ubuntu:"
    echo "  sudo apt update"
    echo "  sudo apt install -y php-cli php-curl php-mbstring php-xml php-zip php-common unzip"
    echo ""
    echo "RHEL/CentOS/Fedora/Amazon Linux:"
    echo "  sudo yum install -y php-cli php-curl php-mbstring php-xml php-zip php-common unzip"
    echo ""
    echo "If your distro uses dnf instead of yum:"
    echo "  sudo dnf install -y php-cli php-curl php-mbstring php-xml php-zip php-common unzip"
    echo ""
    echo "macOS with Homebrew:"
    echo "  brew install php composer"
    exit 1
  fi
elif [ ! -x "$RUNTIME" ]; then
  echo "PHP is only required when vendor dependencies are missing."
  echo ""
  echo "This package does not contain vendor/autoload.php. Install PHP first, then run ./install.sh again."
  echo ""
  echo "Debian/Ubuntu:"
  echo "  sudo apt update"
  echo "  sudo apt install -y php-cli php-curl php-mbstring php-xml php-zip php-common unzip"
  echo ""
  echo "RHEL/CentOS/Fedora/Amazon Linux:"
  echo "  sudo yum install -y php-cli php-curl php-mbstring php-xml php-zip php-common unzip"
  echo ""
  echo "If your distro uses dnf instead of yum:"
  echo "  sudo dnf install -y php-cli php-curl php-mbstring php-xml php-zip php-common unzip"
  echo ""
  echo "macOS with Homebrew:"
  echo "  brew install php composer"
  exit 1
fi
if command -v composer >/dev/null 2>&1; then
  composer install --no-interaction --prefer-dist
elif [ -f composer.phar ]; then
  "$RUNTIME" composer.phar install --no-interaction --prefer-dist
else
  echo "Composer is only required when vendor dependencies are missing."
  echo "This package does not contain vendor/autoload.php and Composer was not found."
  exit 1
fi
