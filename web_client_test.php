<?php

include('web_client.php');

date_default_timezone_set('America/Los_Angeles');

while (true) {
	$req = WebRequest::create('GET', 'https://api.ipify.org?format=json');
	$req->json = true;
	$req->cache = new FixedExpirationCache(10);

    $resp = $req->send();
    echo '[' . date('m/d/Y G:i:s') . '] Your IP address is: ' . $resp->getBody()['ip'] . "\n";

    unset($resp);
    unset($req);
}
