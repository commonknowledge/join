#!/bin/bash
set -e

BUILD_ROOT=$(pwd)

echo -n "Clearing up..."
rm -rf dist
echo "done"

echo "Creating plugin zip file"
mkdir -p dist

echo "Building front end"
cd packages/join-flow
NODE_ENV=production && npm i && npm run build
cd ../..

echo "Copying WordPress plugin files"
cp -r packages/join-block/ dist/common-knowledge-join-flow
cd dist/common-knowledge-join-flow
rm -f .env .env.example
# Reduce vendor directory size
rm -rf logs/debug-* node_modules vendor wordpress
composer install --no-dev
# Remove files banned by WordPress
rm -f vendor/mailchimp/marketing/git_push.sh
cd ../..

echo "Copying front-end source files (to meet WordPress guidelines)"
cp -r packages/join-flow/ dist/common-knowledge-join-flow/build-src
cd dist/common-knowledge-join-flow/build-src
rm -f .env .env.example
rm -rf node_modules
cd ../..

echo "Zipping..."
zip -r -q common-knowledge-join-flow common-knowledge-join-flow

if [ $(wc -c < "common-knowledge-join-flow.zip") -gt $((10 * 1024 * 1024)) ]; then
    echo "Warning: file is larger than 10MB!"
else
    echo "File is smaller than 10MB, well done!"
fi

mv common-knowledge-join-flow.zip ..
echo "Done."
