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
npm i && npm run build
cd ../..

echo "Copying WordPress plugin files"
cp -r packages/join-block/ dist/join-block
cd dist/join-block
rm .env
rm -rf node_modules

echo "Zipping"
cd ..
zip -r -q ck-join-plugin join-block
mv ck-join-plugin.zip ..
echo "Done"
