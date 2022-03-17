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
    } catch (\Exception $exception) {
        $joinBlockLog->error('Auth0 Management API access token request failed');
        throw $exception;
    }

    $joinBlockLog->info('Obtaining Auth0 Management API access token successfully obtained');

    $auth0ManagementAccessToken = $result['access_token'];

    $managementApi = new Management($auth0ManagementAccessToken, $_ENV['AUTH0_DOMAIN']);
    
    $joinBlockLog->info('Checking for existing user on Auth0');
    
    $q = 'email:' . urlencode($data['email']);
    
    try {
        $users = $managementApi->users()->getAll(['q' => $q]);
    } catch (\Exception $expection) {
        $joinBlockLog->error('User check on Auth0 failed');
        throw $expection;
    }
    
    if ($users !== null) {
        $joinBlockLog->info('User already exists in Auth0, skipping', ['count' => count($users), 'query' => $q, 'response' => json_encode($users)]);
        return;
    }

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
    } catch (\Exception $expection) {
        $joinBlockLog->error('Auth0 Management API user creation request failed');
        throw $expection;
    }

    $joinBlockLog->info('Auth0 user creation successful');
}
