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
	
	$phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
	
	$phoneNumberDetails = $phoneUtil->parse($data['phoneNumber'], $data['addressCountry']);
	$data['phoneNumber'] = $phoneUtil->format($phoneNumberDetails, \libphonenumber\PhoneNumberFormat::E164);

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
		  "phone" => $data['phoneNumber'],
		  "payment_method" => array(
				"type" => "direct_debit",
				"reference_id" => $mandate->id,
		  ),
		  "billingAddress" => $billingAddress
		));
	}

	$customer = $customerResult->customer();
	
	$chargebeeSubscriptionPayload = [];
	$chargebeeSubscriptionPayload['addons'] = [];

	// "Suggested Member Contribution" has two components in Chargebee and therefore a special treatment.
	// - A monthly recurring donation of £3 a month, the standard plan called "membership_monthly_individual"
	// - An additional donation, in Chargebee an add-on callled "additional_donation_month" we set to £7
	if ($data['planId'] === 'suggested') {
		$chargebeeSubscriptionPayload['planId'] = "membership_monthly_individual";
		
		$chargebeeSubscriptionPayload['addons'][] =[
			[
				"id" => "additional_donation_month",
				"unitPrice" => "700"
			]
		];
	} else {
		$chargebeeSubscriptionPayload['planId'] =  $data['planId'];
	}
	
	// Handle donation amount, which is sent to us in GBP but Chargebee requires in pence

	// Non-recurring
	if ($data['donationAmount'] !== '' && $data['recurDonation'] === false) {
		$chargebeeSubscriptionPayload['addons'][] = [
			[
				"id" => "additional_donation_single",
				"unitPrice" => (int)$data['donationAmount'] * 100
			]
		];
	}
	
	// Recurring
	if ($data['donationAmount'] !== '' && $data['recurDonation'] === true) {
		$chargebeeSubscriptionPayload['addons'][] = [
			[
				"id" => "additional_donation_month",
				"unitPrice" => (int)$data['donationAmount'] * 100
			]
		];
	}
	
	$subscriptionResult = ChargeBee_Subscription::createForCustomer($customer->id, $chargebeeSubscriptionPayload);
	
	$access_token = $_ENV['AUTH0_MANAGEMENT_API_TOKEN'];
	
	$managementApi = new Management($access_token, $_ENV['AUTH0_DOMAIN']);
	
	$defaultRoles = [
		"authenticated user",
		"member",
		"GPEx Voter"
	];
	
	$managementApi->users()->create([
		'password' => $data['password'],
		"connection" => "Username-Password-Authentication",
		"email" => $data['email'],
		"app_metadata" => [
			"planId" => $chargebeeSubscriptionPayload['planId'],
			"chargebeeCustomerId" => $customer->id,
			"roles" => $defaultRoles
		]
	]);
	
	return $customerResult;
}
