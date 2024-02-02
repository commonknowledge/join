#!/bin/bash
set -e

BUILD_ROOT=$(pwd)

echo -n "Clearing up..."
rm -rf dist
echo "done"

echo -n "Creating plugin zip file..."
mkdir -p dist
cp -r packages/join-block/ dist/join-block
cd dist/join-block
rm .env
rm -rf node_modules
zip -r -q ck-join-plugin ./
mv ck-join-plugin.zip ../..
echo "done"
