<?php

function gocardless_get_client() {
	$client = new \GoCardlessPro\Client([
		'access_token' => $_ENV['GC_ACCESS_TOKEN'],
		'environment' => \GoCardlessPro\Environment::SANDBOX
	]);

	return $client;
}

function gocardless_create_customer_mandate($data) {
	$client = gocardless_get_client();

	$customer = $client->customers()->create([
		"params" => ["email" => $data['email'],
					 "given_name" => $data['firstName'],
					 "family_name" => $data['lastName'],
					 "country_code" => $data['addressCountry'],
					 "phone_number" => $data['phoneNumber']
					 ]
	  ]);

	$account = $client->customerBankAccounts()->create([
		"params" => ["account_number" => $data["ddAccountNumber"],
					 "branch_code" => $data["ddSortCode"],
					 "account_holder_name" => $data["ddAccountHolderName"],
					 "country_code" => $data["addressCountry"],
					 "links" => ["customer" => $customer->id]]
	  ]);

	  return $client->mandates()->create([
		"params" => ["scheme" => "bacs",
					 "links" => ["customer_bank_account" => $account->id]]
	  ]);
}
