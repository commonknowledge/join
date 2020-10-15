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

function gocardless_create_customer_mandate($data) {
	$client = gocardless_get_client();

	$customer = $client->customers()->create([
		"params" => ["email" => $data['email'],
					 "given_name" => $data['firstName'],
					 "family_name" => $data['lastName'],
					 "country_code" => 'GB'
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
