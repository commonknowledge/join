#!/bin/bash
set -e

BUILD_ROOT=$(pwd)

echo -n "Clearing up..."
rm -rf dist/*
echo "done"

echo -n "Creating plugin zip file..."
cp -r packages/join-block/ dist/join-block
cd dist/join-block
rm .env
rm -rf node_modules
zip -r -q green-party-join-plugin ./
mv green-party-join-plugin.zip ../..
echo "done"
