<?php

function handle_join($data) {
	$billingAddress = array(
		"firstName" => $data['firstName'],
		"lastName" => $data['lastName'],
		"line1" => $data['addressLine1'],
		"line2" => $data['addressLine2'],
		"city" => $data['addressCity'],
		"state" => $data['addressCounty'],
		"zip" => $data['addressPostcode'],
		"country" => 'GB'
	);

	if ($data["paymentMethod"] === 'creditCard') {
		$result = ChargeBee_Customer::create(array(
		  "firstName" => $data['firstName'],
		  "lastName" => $data['lastName'],
		  "email" => $data['email'],
		  "allow_direct_debit" => true,
		  "locale" => "en-GB",
		  "tokenId" => $data['creditCardToken'],
		  "billingAddress" => $billingAddress
		));

		error_log(json_encode($result));
		return $result;

	} else if ($data['paymentMethod'] === 'directDebit') {
		$mandate = gocardless_create_customer_mandate($data);

		$result = ChargeBee_Customer::create(array(
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

		error_log(json_encode($result));
		return $result;
	}
}
