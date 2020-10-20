<?php
use Auth0\SDK\API\Management;

function handle_join($data) {
	$billingAddress = array(
		"firstName" => $data['firstName'],
		"lastName" => $data['lastName'],
		"line1" => $data['addressLine1'],
		"line2" => $data['addressLine2'],
		"city" => $data['addressCity'],
		"state" => $data['addressCounty'],
		"zip" => $data['addressPostcode'],
		"country" => $data['addressCountry']
	);

	if ($data["paymentMethod"] === 'creditCard') {
		$customerResult = ChargeBee_Customer::create(array(
		  "firstName" => $data['firstName'],
		  "lastName" => $data['lastName'],
		  "email" => $data['email'],
		  "allow_direct_debit" => true,
		  "locale" => "en-GB",
		  "tokenId" => $data['paymentToken'],
		  "billingAddress" => $billingAddress,
		  "phone" => $data['phoneNumber']
		));
	} else if ($data['paymentMethod'] === 'directDebit') {
		$mandate = gocardless_create_customer_mandate($data);

		$customerResult = ChargeBee_Customer::create(array(
		  "firstName" => $data['firstName'],
		  "lastName" => $data['lastName'],
		  "email" => $data['email'],
		  "allow_direct_debit" => true,
		  "locale" => "en-GB",
		  "payment_method" => array(
				"type" => "direct_debit",
				"reference_id" => $mandate->id,
		  ),
		  "billingAddress" => $billingAddress
		));
	}

	$customer = $customerResult->customer();
		
	$subscriptionResult = ChargeBee_Subscription::createForCustomer($customer->id, array(
		"planId" => $data['planId']
	));
	
	$access_token = $_ENV['AUTH0_MANAGEMENT_API_TOKEN'];
	$default_password = $_ENV['AUTH0_DEFAULT_PASSWORD'];
	
	$managementApi = new Management($access_token, $_ENV['AUTH0_DOMAIN']);
	
	$defaultRoles = [
		"authenticated user",
		"member",
		"GPEx Voter"
	];
	
	$managementApi->users()->create([
		'password' => $default_password,
		"connection" => "Username-Password-Authentication",
		"email" => $data['email'],
		"phone_number" => $data['phoneNumber'],
		"app_metadata" => [
			"planId" => $data['planId'],
			"chargebeeCustomerId" => $customer->id,
			"roles" => $defaultRoles
		]
	]);
	
	return $customerResult;
}
