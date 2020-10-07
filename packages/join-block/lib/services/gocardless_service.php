<?php

function gocardless_get_client() {
	$client = new \GoCardlessPro\Client([
		// We recommend storing your access token in an
		// environment variable for security
		'access_token' => getenv('GC_ACCESS_TOKEN'),
		// Change me to LIVE when you're ready to go live
		'environment' => \GoCardlessPro\Environment::SANDBOX
	]);

	return $client;
}

function gocardless_create_redirect($data) {
	error_log(json_encode($data));

	$client = gocardless_get_client();

	$redirectFlow = $client->redirectFlows()->create([
		"params" => [
			// This will be shown on the payment pages
			"description" => "Green Party Membership",
			// Not the access token
			"session_token" => $data['sessionToken'],
			"success_redirect_url" => $data['redirectUrl'],
			"prefilled_customer" => [
			  "given_name" => $data['firstName'] ?: '',
			  "family_name" => $data['lastName'] ?: '',
			  "email" => $data['email'] ?: '',
			  "address_line1" => $data['addressLine1'] ?: '',
			  "address_line2" => $data['addressLine2'] ?: '',
			  "city" => $data['addressCity'] ?: '',
			  "postal_code" => $data['addressPostcode'] ?: ''
			]
		]
	]);

	return [
		"redirectFlow" => [
			"id" => $redirectFlow->id,
			"url" => $redirectFlow->redirect_url,
		]
	];
}
