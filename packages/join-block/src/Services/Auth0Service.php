<?php

namespace CommonKnowledge\JoinBlock\Services;

use Auth0\SDK\API\Management;
use Auth0\SDK\Auth0;
use Auth0\SDK\Configuration\SdkConfiguration;
use CommonKnowledge\JoinBlock\Exceptions\JoinBlockException;
use CommonKnowledge\JoinBlock\Settings;

class Auth0Service
{
    public static function createAuth0User($data, $planId, $customerId)
    {
        global $joinBlockLog;

        $joinBlockLog->info('Obtaining Auth0 Management API access token');

        $auth0 = new Auth0([
            'strategy' => SdkConfiguration::STRATEGY_API,
            'domain' => Settings::get('AUTH0_DOMAIN'),
            'clientId' => Settings::get('AUTH0_CLIENT_ID'),
            'clientSecret' => Settings::get('AUTH0_CLIENT_SECRET'),
            'audience' => [Settings::get('AUTH0_MANAGEMENT_AUDIENCE')]
        ]);

        try {
            $result = $auth0->authentication()->clientCredentials([
                'audience' => Settings::get('AUTH0_MANAGEMENT_AUDIENCE')
            ]);
        } catch (\Exception $exception) {
            $joinBlockLog->error('Auth0 Management API access token request failed');
            throw $exception;
        }

        $joinBlockLog->info('Obtaining Auth0 Management API access token successfully obtained');

        $result = json_decode($result->getBody()->__toString(), true);
        $auth0ManagementAccessToken = $result['access_token'] ?? null;

        $auth0->configuration()->setManagementToken($auth0ManagementAccessToken);

        $managementApi = $auth0->management();

        $joinBlockLog->info('Checking for existing user on Auth0');

        $q = 'email:' . urlencode($data['email']);

        try {
            $response = $managementApi->users()->getAll(['q' => $q]);
            $users = json_decode($response->getBody()->__toString(), true);
            if (in_array('error', $users)) {
                throw new \Exception($users['message']);
            }
        } catch (\Exception $exception) {
            $joinBlockLog->error('User check on Auth0 failed');
            throw $exception;
        }

        if (count($users) > 0) {
            $joinBlockLog->info(
                'User already exists in Auth0, skipping',
                ['count' => count($users), 'query' => $q, 'response' => wp_json_encode($users)]
            );
            return;
        }

        $defaultRoles = [
            "authenticated user",
            "member",
        ];

        $joinBlockLog->info('Creating user in Auth0');

        $fullName = implode(' ', [$data['firstName'], $data['lastName']]);

        $data = [
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
        ];
        $data = apply_filters('ck_join_flow_pre_auth0_user_create', $data);

        try {
            $response = $managementApi->users()->create("Username-Password-Authentication", $data);
            $response = json_decode($response->getBody()->__toString(), true);
            if (in_array('error', $response)) {
                throw new \Exception($response['message']);
            }
        } catch (\Exception $expection) {
            $joinBlockLog->error('Auth0 Management API user creation request failed');
            throw $expection;
        }

        $joinBlockLog->info('Auth0 user creation successful');
    }
}
