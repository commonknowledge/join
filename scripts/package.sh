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

echo "Installing dependencies"
cd packages/join-block
composer install
cd ../..

echo "Copying WordPress plugin files"
cp -r packages/join-block/ dist/join-block
cd dist/join-block
rm -f .env .env.example
rm -rf logs node_modules wordpress
cd ../..

echo "Copying front-end source files (to meet WordPress guidelines)"
cp -r packages/join-flow/ dist/join-block/build-src
cd dist/join-block/build-src
rm -f .env .env.example
rm -rf node_modules
cd ../..

echo -n "Zipping..."
zip -r -q ck-join-block join-block
mv ck-join-block.zip ..
echo "done"
