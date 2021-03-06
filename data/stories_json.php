<?php
/*
 * Per this example in the LimeSurvey wiki:
 * https://manual.limesurvey.org/RemoteControl_2_API#PHP_Example
 *
 * For all possible function calls, see http://uvertelt.nu/admin/remotecontrol
 */

// without composer this line can be used
require_once 'jsonrpcphp/src/org/jsonrpcphp/JsonRPCClient.php';
// with composer support just add the autoloader
// include_once 'vendor/autoload.php';

// This defines LS_BASEURL, LS_USER and LS_PASSWORD
require_once('uvertelt.php');

// ID van de Meet je Stad 3 externe survey
$survey_id = 993944;

// instanciate a new client
$myJSONRPCClient = new \org\jsonrpcphp\JsonRPCClient( LS_BASEURL.'/admin/remotecontrol' );

// receive session key
$sessionKey= $myJSONRPCClient->get_session_key( LS_USER, LS_PASSWORD );

// receive all ids and info of groups belonging to a given survey
//$groups = $myJSONRPCClient->list_groups( $sessionKey, $survey_id );
//print_r($groups, null );

// receive all responses belonging to a given survey
// docs: http://api.limesurvey.org/classes/remotecontrol_handle.html#method_export_responses
$responses = $myJSONRPCClient->export_responses(
	$sessionKey,
	$survey_id,
	'json'
);

// goede response is een base64 encoded string.
if (is_string($responses)){
	$data = json_decode(base64_decode($responses), true);
	$list = $data['responses'];

	// Output genereren voor openLayers
	$features = [];
	foreach($list as $item) {
		$story = reset($item);
		if ($coordinates = explode(';',$story['LOCKAART'],-1)) {
			$lat = round(floatval($coordinates[0]), 4) ;
			$lon = round(floatval($coordinates[1]), 4);
		} else {
			$lat = 0;
			$lon = 0;
		}
		$title = $story['Title'];
		$narrative = $story['Narrative'];

		if ($lat && $lon && $title && $narrative) {
			$features[] = [
				'type' => 'Feature',
				'properties' => [
					'type' => 'story',
					'title' => $title,
					'narrative' => $narrative,
				],
				'geometry' => [
					'type' => 'Point',
					'coordinates' => [$lon, $lat],
				],
			];
		}
	}
	$json = json_encode([
		'type' => 'FeatureCollection',
		'features' => $features,
	]);

	header("Access-Control-Allow-Origin: *");
	header("Content-Type: application/json; charset=UTF-8");
	echo($json);
}
else {
	// Geen toegang of errors zijn arrays
	// dit vervangen voor een schrijfactie naar je logs

//	print_r($responses);
}

// release the session key
$myJSONRPCClient->release_session_key( $sessionKey );

?>
