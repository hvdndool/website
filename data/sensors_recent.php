<?php
	/**
	 * Taken from https://stackoverflow.com/a/10054282/740048
	 *
	 * Calculates the great-circle distance between two points, with
	 * the Haversine formula.
	 * @param float $latitudeFrom Latitude of start point in [deg decimal]
	 * @param float $longitudeFrom Longitude of start point in [deg decimal]
	 * @param float $latitudeTo Latitude of target point in [deg decimal]
	 * @param float $longitudeTo Longitude of target point in [deg decimal]
	 * @param float $earthRadius Mean earth radius in [m]
	 * @return float Distance between points in [m] (same as earthRadius)
	 */
	function haversineGreatCircleDistance(
	  $latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
	{
	  // convert from degrees to radians
	  $latFrom = deg2rad($latitudeFrom);
	  $lonFrom = deg2rad($longitudeFrom);
	  $latTo = deg2rad($latitudeTo);
	  $lonTo = deg2rad($longitudeTo);

	  $latDelta = $latTo - $latFrom;
	  $lonDelta = $lonTo - $lonFrom;

	  $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
	    cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
	  return $angle * $earthRadius;
	}

	header('Content-Type: text/html; charset=utf-8');

	if (file_exists('../sensorsets.json')) $sensorsets = json_decode(file_get_contents('../sensorsets.json'), true);
	else $sensorsets = array();
?>
<!DOCTYPE html>
<html class="no-js">
	<head>
		<meta http-equiv="refresh" content="60">
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css" integrity="sha384-9aIt2nRpC12Uk9gS9baDl411NQApFmC26EwAOH8WgZl5MYYxFfc+NcPb1dKGj7Sk" crossorigin="anonymous">
		<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js" integrity="sha512-bLT0Qm9VnAYZDflyKcBaQ2gg0hSYNQrJ8RilYldYQ1FxQYoCLtUjuuRuZo+fjqhx/qtq/1itJ0C2ejDxltZVFg==" crossorigin="anonymous"></script>
		<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.bundle.min.js" integrity="sha384-1CmrxMRARb6aLqgBO7yyAxTOQE2AKb9GfXnEo760AUcUmFx3ibVJJAzGytlQcNXd" crossorigin="anonymous"></script>
		<style type="text/css">
			.gw_location[href=""] {
				display: none;
			}
		</style>
	</head>
	<body>
		<img style="width: 200px; padding: 10px;" src="https://meetjestad.nl/images/5dc94f60a26fc.png" alt="" />
		<table class="table table-bordered table-striped table-sm" style="width: calc(100% - 20px); margin: 10px">
			<tr class="bg-info">
				<th>ID</th>
				<th>Time</th>
				<th>Temp</th>
				<th>Humidity</th>
				<th>Light</th>
				<th>PM2.5</th>
				<th>PM10</th>
				<th>Voltage</th>
				<th>Extra</th>
				<th>Firmware</th>
				<th>Position</th>
				<th>Fcnt</th>
				<th>Gateways</th>
				<th>Distance</th>
				<th>RSSI</th>
				<th>LSNR</th>
				<th>Radiosettings</th>
			</tr>
<?php
	// connect to database
	include ("../connect.php");
	$database = Connection();
	
	// Ophalen van de meetdata van de lora sensoren
	if (isset($_GET['limit']))
		$limit = (int)$_GET['limit'];
	else
		$limit = 200;

	// Compatibility with existing links
	if (isset($_GET['sensor']))
		$_GET['sensors'] = $_GET['sensor'];
	if (isset($_GET['gateway']))
		$_GET['gateways'] = $_GET['gateway'];

	if (isset($_GET['sensors'])) {
		$sensors = explode(',', $_GET['sensors']);
		foreach ($sensors as &$id)
			$id = (int)$id;
		$sensors = implode(',', $sensors);
		$WHERE = "WHERE msr.station_id IN ($sensors)";
	} else {
		$WHERE = "";
	}

	$gateways_filter = false;
	if (isset($_GET['gateways']))
		$gateways_filter = explode(',', $_GET['gateways']);

	$show_other_gateways = false;
	if (isset($_GET['show_other_gateways']))
		$show_other_gateways = (bool)$_GET['show_other_gateways'];

	$gateway_descriptions = [
		"eui-1dee0b64b020eec4" => "Meetjestad #1 (De WAR)",
		"mjs-gateway-1" => "Meetjestad #1 (De WAR)",
		"mjs-gateway-3" => "Meetjestad #3 (Berghotel)",
		"mjs-gateway-6" => "Meetjestad #6 (La Balise)",
		"eui-1dee1cc11cba7539" => "Meetjestad #4 (De Koperhorst)",
		"mjs-gateway-4" => "Meetjestad #4 (De Koperhorst)",
		"eui-0000024b080e020a" => "(NH Hotel Amersfoort)",
		"eui-0000024b080602ed" => "(De Bilt)",
		"eui-000078a504f5b057" => "(De Bilt)",
	];

	$result = $database->query("SELECT msr.*, msg.message FROM sensors_measurement AS msr LEFT JOIN sensors_message AS msg ON (msg.id = msr.message_id) $WHERE ORDER BY msr.timestamp DESC LIMIT $limit");
	$count_per_station = array();
	$stations_per_gateway = array();
	$messagecount_per_gateway = array();
	$distances_per_gateway = array();
	$messagecount = 0;

	function node_button($id) {
		$id = intval($id);
		return <<<EOF
<span class="dropdown">
  <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
    $id
  </a>

  <div class="dropdown-menu">
    <a class="dropdown-item" href="?sensors=$id">Show only messages of node $id</a>
    <a class="dropdown-item" href="../node/$id">Show information about node $id</a>
  </div>
</div>
EOF;
	}

	function gateway_button($gw_id, $gw_data = null) {
		global $gateway_descriptions;

		if (array_key_exists($gw_id, $gateway_descriptions))
			$gw = $gateway_descriptions[$gw_id];
		else
			$gw = $gw_id;
		$gw_html = htmlspecialchars($gw);

		$filter_url_html = htmlspecialchars('?gateways=' . urlencode($gw_id) . '&show_other_gateways=1');
		$location_url_html = '';
		if ($gw_data && array_key_exists('latitude', $gw_data) && $gw_data['latitude'] && array_key_exists('longitude', $gw_data) && $gw_data['longitude']) {
			$location_url_html = htmlspecialchars('http://www.openstreetmap.org/?mlat=' . rawurlencode($gw_data['latitude']). "&mlon=" . rawurlencode($gw_data['longitude']));
		}
		return <<<EOF
<span class="dropdown">
  <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
    $gw_html
  </a>

  <div class="dropdown-menu">
    <a class="dropdown-item gw_filter" href="$filter_url_html">Show only messages received by $gw_html</a>
    <a class="dropdown-item gw_location" href="$location_url_html">Show gateway location</a>
  </div>
</div>
EOF;
	}

	function output_cell($rowspan, $data) {
		echo("  <td rowspan=\"$rowspan\"> " . $data . "</td>\n");
	}

	while($row = $result->fetch_array(MYSQLI_ASSOC)) {
		if ($row['message']) {
			$message = json_decode($row['message'], true);
			$metadata = $message['metadata'];

			if (!array_key_exists('gateways', $metadata)) {
				$gateways = [];

				// Convert TTN staging to production format
				foreach ($metadata as $meta) {
					$meta['gtw_id'] = 'eui-' . strtolower($meta['gateway_eui']);
					$meta['snr'] = $meta['lsnr'];
					$gateways[] = $meta;
				}

				$metadata = [
					'gateways' => $gateways,
					'frequency' => $metadata[0]['frequency'],
					'data_rate' => $metadata[0]['datarate'],
					'coding_rate' => $metadata[0]['codingrate'],
				];
			}


			$gateways = $metadata['gateways'];
			if ($gateways_filter) {
				$gateways = [];
				foreach ($metadata['gateways'] as $gwdata) {
					if (in_array($gwdata['gtw_id'], $gateways_filter)) {
						$found = true;
						if (!$show_other_gateways) {
							$gateways[] = $gwdata;
						} else {
							$gateways = $metadata['gateways'];
							break;
						}
					}
				}
				if (!$gateways)
					continue;
			}
			$rowspan = count($gateways);

			// Sort by LSR, descending
			usort($gateways, function($a, $b) { return $a['snr'] < $b['snr']; });
		} else {
			$message = [];
			$metadata = [];
			$gateways = [[]];
			$rowspan = 1;
		}

		$messagecount++;

		if (array_key_exists($row['station_id'], $count_per_station))
			$count_per_station[$row['station_id']]++;
		else
			$count_per_station[$row['station_id']] = 1;

		$first = true;
		foreach ($gateways as $gwdata) {
			echo("<tr>\n");
			if ($first) {

				$url = '?sensors=' . $row["station_id"] . '&amp;limit=50';

				//output_cell($rowspan, "<a href=\"" . $url . "\">" . $row["station_id"] . "</a>");
				output_cell($rowspan, node_button($row["station_id"]));

				$datetime = DateTime::createFromFormat('Y-m-d H:i:s', $row['timestamp'], new DateTimeZone('UTC'));
				$datetime->setTimeZone(new DateTImeZone('Europe/Amsterdam'));
				output_cell($rowspan, $datetime->format('Y-m-d H:i:s'));
				output_cell($rowspan, $row["temperature"] . "°C");
				output_cell($rowspan, $row["humidity"] . "%");
				output_cell($rowspan, $row["lux"] . ($row["lux"]>0?" lux":""));
				if ($row["pm2_5"] == 0xffff)
					output_cell($rowspan, "read error");
				else if ($row["pm2_5"])
					output_cell($rowspan, $row["pm2_5"] . " μg/m³");
				else
					output_cell($rowspan, "");
				if ($row["pm10"] == 0xffff)
					output_cell($rowspan, "read error");
				else if ($row["pm10"])
					output_cell($rowspan, $row["pm10"] . " μg/m³");
				else
					output_cell($rowspan, "");

				if ($row['battery'] && $row['supply']) {
					output_cell($rowspan, round($row["battery"],2) . "V / " . round($row['supply'],2) . "V");
				} else if ($row['supply']) {
					output_cell($rowspan, round($row['supply'],2) . "V");
				} else {
					output_cell($rowspan, '-');
				}
				output_cell($rowspan, $row["extra"]);
				if ($row['firmware_version'] === null)
					output_cell($rowspan, '< v1');
				else if ($row['firmware_version'] !== 255)
					output_cell($rowspan, '<a href="https://github.com/meetjestad/mjs_firmware/tree/v' . $row['firmware_version'] . '">v' . $row['firmware_version'] . '</a>');
				else
					output_cell($rowspan, 'v' . $row['firmware_version']);
				if ($row['latitude'] == '0.0' && $row['longitude'] == '0.0') {
					output_cell($rowspan, 'No position');
				} else {
					$url = "http://www.openstreetmap.org/?mlat=" . $row['latitude'] . "&amp;mlon=" . $row['longitude'];
					output_cell($rowspan, "<a href=\"" . $url . "\">" . $row["latitude"] . " / " . $row["longitude"] . "</a>");
				}
				if (array_key_exists('counter', $message)) {
					output_cell($rowspan, $message['counter']);
				} else {
					output_cell($rowspan, '-');
				}
			} else {
				//echo("  <td colspan=\"6\"></td>");
			}

			if (empty($gwdata)) {
				echo("  <td colspan=\"5\">Not available</a>");
			} else {
				
				$distance = false;
				if ($row['latitude'] && array_key_exists('latitude', $gwdata) && $gwdata['latitude']) {
					$distance = haversineGreatCircleDistance($row['latitude'], $row['longitude'], $gwdata['latitude'], $gwdata['longitude']);
				}
				$gw_id = $gwdata['gtw_id'];
				echo("  <td>" . gateway_button($gw_id, $gwdata) . "</td>\n");
				if ($distance)
					echo("  <td>" . round($distance / 1000, 3) . "km</td>\n");
				else
					echo("  <td>-</td>\n");
				echo("  <td>" . htmlspecialchars($gwdata["rssi"]) . "</td>\n");
				echo("  <td>" . htmlspecialchars($gwdata["snr"]) . "</td>\n");
				echo("  <td>" . htmlspecialchars($metadata["frequency"]) . "Mhz, " . htmlspecialchars($metadata["data_rate"]) . ", " .htmlspecialchars($metadata["coding_rate"]) . "CR</td>\n");

				if (!array_key_exists($gw_id, $stations_per_gateway)) {
					$stations_per_gateway[$gw_id] = array();
					$messagecount_per_gateway[$gw_id] = 0;
					$distances_per_gateway[$gw_id] = array();
				}
				$stations_per_gateway[$gw_id][$row['station_id']] = true;
				$messagecount_per_gateway[$gw_id]++;
				if ($distance)
					$distances_per_gateway[$gw_id][$row['station_id']] = $distance;
			}
			echo("</tr>\n");
			$first = false;
		}
	}
	?>
		</table>
		<p>Message count: <?= $messagecount ?><br>Node count: <?= count($count_per_station)?></p>
		<p><h3>Messages per node</h3></p>
		<table class="table table-bordered table-striped table-sm" style="width: calc(100% - 20px); margin: 10px">
		<tr class="bg-info"><th>Number of messages in list above</th><th>Nodes</th></tr>
		<?php
			$stations_per_count = array();
			foreach($count_per_station as $station => $count) {
				if (!array_key_exists($count, $stations_per_count))
					$stations_per_count[$count] = array();

				$stations_per_count[$count][] = $station;
			}
			ksort($stations_per_count);
			foreach($stations_per_count as $count => $stations) {
				$stationlist = implode("", array_map('node_button', $stations));
				echo("<tr><td>".htmlspecialchars($count)."</td><td>".$stationlist."</td></tr>\n");
			}
		?>
		</table>

	<p><h3>Statistics per gateway</h3></p>
		<table class="table table-bordered table-striped table-sm" style="width: calc(100% - 20px); margin: 10px">
		<tr class="bg-info"><th>Gateway</th><th>Number of messages</th><th>Number of nodes</th><th>Nodes</th></tr>
		<?php
			arsort($messagecount_per_gateway);
			foreach($messagecount_per_gateway as $gw_id => $messagecount) {
				$stations = array_keys($stations_per_gateway[$gw_id]);
				$stationlist = '';
				foreach ($stations as $station) {
					$stationlist .= node_button($station);
					if (array_key_exists($station, $distances_per_gateway[$gw_id])) {
						$distance = $distances_per_gateway[$gw_id][$station];
						$stationlist .= ' (' . round($distance / 1000, 3) . 'km)';
					}
				}
				$stationcount = count($stations);
				echo("<tr><td>".gateway_button($gw_id)."</td><td>".htmlspecialchars($messagecount)."</td><td>".htmlspecialchars($stationcount)."</td><td>".$stationlist."</td></tr>\n");
			}
		?>
		</table>
		<p><h3>Filter by dataset</h3></p>
		<ul>
		<?php foreach ($sensorsets as $id => $set) { ?>
			<li><a href="?sensors=<?= htmlspecialchars($set['ids'])?>"><?=htmlspecialchars($set['description'])?></a></li>
		<?php } ?>
		</ul>
	</body>
</html>
