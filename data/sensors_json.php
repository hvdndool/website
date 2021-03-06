<?php
	// connect to database
	include ("../connect.php");
	$database = Connection();

	// collect data in json
	$json = '[';

	// Ophalen van de meetdata van de lora sensoren
	// Query gebaseerd op http://stackoverflow.com/a/12625667/740048
	$time = date('Y-m-d H:i:s', time()-24*60*60);

	$WHERE = "";
	if (isset($_GET['select']) && $_GET['select']=='all') {
		$WHERE = "";
	}
	elseif (isset($_GET['select']) && $_GET['select']=='gone') {
		$WHERE = "WHERE timestamp < '".$database->real_escape_string($time)."'";
	}
	elseif (isset($_GET['start']) || isset($_GET['end'])) {
		if ($_GET['start']) $WHERE = "WHERE timestamp >= '".$database->real_escape_string(urldecode($_GET['start']))."'";
		if ($_GET['end']) $WHERE.= ($WHERE?" AND ":"WHERE ")."timestamp <= '".$database->real_escape_string(urldecode($_GET['end']))."'";
	}
	else {
		$WHERE = "WHERE timestamp >= '$time'";
	}
	if (isset($_GET['ids'])) {
		$ids_int_array = array_map('intval', explode(',', $_GET['ids']));
		$WHERE.= ($WHERE?" AND ":" WHERE ")."station_id IN (".implode(',', $ids_int_array).")";
	}

	if (isset($_GET['start']) || isset($_GET['end'])) {
		$result = $database->query("SELECT * FROM sensors_measurement $WHERE");
	}
	else {
		$result = $database->query("SELECT sensors_measurement.* FROM sensors_station INNER JOIN sensors_measurement ON (sensors_station.last_measurement = sensors_measurement.id) $WHERE");
	}

	$features = [];
	// exclude data from nodes that lost contact with their sensor
	while($table = $result->fetch_array(MYSQLI_ASSOC)) if ($table["humidity"]>-26 && $table["temperature"]>-26) {
		if ($table['longitude'] == 0)
			continue;
		$datetime = DateTime::createFromFormat('Y-m-d H:i:s', $table['timestamp'], new DateTimeZone('UTC'));
		$datetime->setTimeZone(new DateTImeZone('Europe/Amsterdam'));
		$features[] = [
			'type' => 'Feature',
			'properties' => [
				'type' => 'sensor',
				'id' => $table["station_id"],
				'temperature' => $table["temperature"],
				'humidity' => $table["humidity"],
				'light' => $table["lux"],
				'timestamp_utc' => $table["timestamp"],
				'timestamp' => $datetime->format('Y-m-d H:i:s'),
				'location' => 'sensor '.$table["station_id"],
			],
			'geometry' => [
				'type' => 'Point',
				'coordinates' => [floatval($table["longitude"]),floatval($table["latitude"])],
			],
		];
	}

	$json = json_encode([
		'type' => 'FeatureCollection',
		'features' => $features,
	]);

	// output data
	header("Access-Control-Allow-Origin: *");
	header("Content-Type: application/json; charset=UTF-8");
	echo($json);
?>
