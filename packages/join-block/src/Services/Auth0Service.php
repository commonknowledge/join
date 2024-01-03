<?php

namespace CommonKnowledge\JoinBlock\Services;

use Auth0\SDK\API\Management;
use Auth0\SDK\Auth0;

class Auth0Service
{
    public static function createAuth0User($data, $planId, $customerId)
    {
        global $joinBlockLog;

        $joinBlockLog->info('Obtaining Auth0 Management API access token');

        $auth0 = new Auth0([
            'domain' => $_ENV['AUTH0_DOMAIN'],
            'clientId' => $_ENV['AUTH0_CLIENT_ID'],
            'clientSecret' => $_ENV['AUTH0_CLIENT_SECRET'],
        ]);

        try {
            $result = $auth0->authentication()->clientCredentials([
                'audience' => $_ENV['AUTH0_MANAGEMENT_AUDIENCE']
            ]);
        } catch (\Exception $exception) {
            $joinBlockLog->error('Auth0 Management API access token request failed');
            throw $exception;
        }

        $joinBlockLog->info('Obtaining Auth0 Management API access token successfully obtained');

        $auth0ManagementAccessToken = $result['access_token'];

        $auth0->configuration()->setManagementToken($auth0ManagementAccessToken);

        $managementApi = $auth0->management();

        $joinBlockLog->info('Checking for existing user on Auth0');

        $q = 'email:' . urlencode($data['email']);

        try {
            $response = $managementApi->users()->getAll(['q' => $q]);
            $users = json_decode($response->getBody()->__toString(), true);
        } catch (\Exception $expection) {
            $joinBlockLog->error('User check on Auth0 failed');
            throw $expection;
        }

        if (count($users) > 0) {
            $joinBlockLog->info(
                'User already exists in Auth0, skipping',
                ['count' => count($users), 'query' => $q, 'response' => json_encode($users)]
            );
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
            $managementApi->users()->create("Username-Password-Authentication", [
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
}
