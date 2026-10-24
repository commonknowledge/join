#!/usr/bin/env bash
# Regenerates the fake API's test CA and server certificate.
#
# Only needed when adding a hostname: add it to san.cnf, run this, and add the
# same hostname to the fake-api network aliases in ../../docker-compose.yml.
# The CA key is deleted afterwards, so these files can't sign anything else.
# Test-only: trusted only inside the E2E Docker network.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 36500 \
  -subj "/CN=Join E2E test CA (not for real use)"
openssl req -newkey rsa:2048 -nodes -keyout server.key -out server.csr \
  -subj "/CN=fake-api" -config san.cnf
openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial \
  -out server.crt -days 36500 -extfile san.cnf -extensions ext
rm -f server.csr ca.srl ca.key
