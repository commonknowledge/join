<?php
/**
 * Plugin Name: E2E fake API CA
 * Description: E2E only. Makes WordPress's HTTP API trust the fake API's test CA (see fake-api/server.js).
 *
 * Loaded from mu-plugins/, which the E2E docker-compose.yml mounts. Uses the
 * http_request_args filter rather than replacing WordPress's own CA bundle,
 * which would stop the WordPress image installing itself on a fresh volume.
 */

add_filter('http_request_args', function ($args) {
    $args['sslcertificates'] = '/usr/local/share/e2e-fake-api-ca.crt';
    return $args;
});
