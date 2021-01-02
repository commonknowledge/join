<?php

use Auth0\SDK\API\Authentication;
use Auth0\SDK\API\Management;

function createAuth0User($data, $planId, $customerId)
{
    global $joinBlockLog;

    $joinBlockLog->info('Obtaining Auth0 Management API access token');

    $auth0Api = new Authentication(
        $_ENV['AUTH0_DOMAIN'],
        $_ENV['AUTH0_CLIENT_ID']
    );

    $config = [
        'client_secret' => $_ENV['AUTH0_CLIENT_SECRET'],
        'client_id' => $_ENV['AUTH0_CLIENT_ID'],
        'audience' => $_ENV['AUTH0_MANAGEMENT_AUDIENCE'],
    ];

    try {
        $result = $auth0Api->client_credentials($config);
    } catch (Exception $exception) {
        $joinBlockLog->info('Auth0 Management API access token request failed');
        throw $exception;
    }

    $joinBlockLog->info('Obtaining Auth0 Management API access token successfully obtained');

    $auth0ManagementAccessToken = $result['access_token'];

    $managementApi = new Management($auth0ManagementAccessToken, $_ENV['AUTH0_DOMAIN']);

    $defaultRoles = [
        "authenticated user",
        "member",
        "GPEx Voter"
    ];

    $joinBlockLog->info('Creating user in Auth0');

    $fullName = implode(' ', [$data['firstName'], $data['lastName']]);

    try {
        $managementApi->users()->create([
            "connection" => "Username-Password-Authentication",
            'name' => $fullName,
            "email" => $data['email'],
            "password" => $data['password'],
            'given_name' => $data['firstName'],
            'family_name' => $data['lastName'],
            "app_metadata" => [
                "planId" => $planId,
                "chargebeeCustomerId" => $customerId,
                "roles" => $defaultRoles
            ]
        ]);
    } catch (Exception $expection) {
        throw $expection;
    }

    $joinBlockLog->info('Auth0 user creation successful');
}
