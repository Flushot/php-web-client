# PHP Web Client

TODO: Write docs.

## Example Usage

Here's some example code:

	include('web_client.php');

	// Call out to a web service to get publicly exposed IP address
	$request = WebRequest::create('GET', 'https://api.ipify.org?format=json');
	$request->json = true;
	try {
		$response = $request->send();
		$jsonBody = $response->getBody();

		echo 'Your IP address is: ' . $jsonBody['ip'];
	}
	except (WebRequestException $ex) {
		error_log('Request failed: ' . $ex->getMessage());
	}
