#!/bin/bash

VERSION=$(echo "$1" | sed 's/[^0-9]//g');

echo "Building v$VERSION..."

sed -i'.bck' -e "s/VDEV/V$VERSION/g" src/WpGuard.php
rm src/WpGuard.php.bck

sed -i'.bck' -e "s/VDEV/V$VERSION/g" composer.json
rm composer.json.bck

echo "Running strauss..."
composer run-script prefix

echo "Cleaning up..."
rm -rf .github .git vendor composer.json composer.lock
rm build.sh

echo "Creating archive..."
zip -r wp-guard.zip .