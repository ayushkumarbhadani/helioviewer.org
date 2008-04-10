<?php
	include 'database_functions.php';
	header("content-type:application/json");
	
	//example query: SELECT * FROM maps WHERE (Instrument = 'EIT' AND day >= '5' AND hour = '0' AND minute = '00'AND timestamp BETWEEN '2003-10-05 00:00:00' AND '2003-10-15 00:00:00') ORDER BY timestamp LIMIT 30
	
	//$Observatory = $_POST["Observatory"];
	//$Detector = $_POST["Detector"];
	//$Measurement = $_POST["Measurement"];
	$From = 	        $_GET["From"];
	$To = 		 	    $_GET["To"];
	$Instrument = 	    $_GET["Instrument"];
	$Year =				$_GET["Year"];
	$Month =			$_GET["Month"];
	$Day = 				$_GET["Day"];
	$Hour =				$_GET["Hour"];
	$Minute =			$_GET["Minute"];
	$Second =			$_GET["Second"];
	$IncrementDays =    $_GET["IncrementDays"];
	$IncrementHours =   $_GET["IncrementHours"];
	$IncrementMinutes = $_GET["IncrementMinutes"];
	$Direction =		$_GET["Direction"];

	Filter ($Observatory, $Instrument, $Detector, $From, $To, $Measurement, $Year, $Month, $Day, $Hour, $Minute, $Second, $IncrementDays, $IncrementHours, $IncrementMinutes, $Direction);
	
	function Filter ($observatory, $instrument, $detector, $from, $to, $measurement, $year, $month, $day, $hour, $minute, $second, $incrementDays, $incrementHours, $incrementMinutes, $direction)
	{
		$limit = 2;
		
		$query = "SELECT * FROM maps WHERE (";
		
		//Instrument
		if ($instrument != null) {
			$query .= "Instrument = '$instrument' ";
		}
		
		//Direction to query
		if ($direction == "forward") {
			//Time Increment
			if ($incrementHours == 1) {
				$query .= "AND minute = '$minute'";
				$limit = 24;
			}
			
			if ($incrementDays == 1) {
				$query .= "AND hour = '$hour' AND minute = '$minute'";
				$limit = 30;
			}
			
			if ($incrementYears == 1) {
				$query .= "AND day = '$day' AND hour = '$hour' AND minute = '$minute'";
				$limit = 10;
			}
			
			if ($incrementMinutes == 1) {
				$query .= "AND month= '$month' AND day = '$day' AND hour = '$hour'";
				$limit = 10;
			}
		}
		
		// Limit Range to query
		$query .= "AND timestamp BETWEEN '$from' AND '$to'";
		
		$query .= ") ORDER BY timestamp LIMIT $limit";
		
		//echo "\n\n$query\n\n<br /><br />";
				
		$result = mysql_query($query);
		
		$resultArray = array();

		while ($row = mysql_fetch_array($result, MYSQL_ASSOC))
		{
			array_push($resultArray, $row);
		}

		//pad array to keep images in sync with observation time
		//for (var $i = 0; $i < $resultArray.length; $i++) {...}
		if ($incrementDays == 1) {
			$time = date_create("from");

			
			if ($row[i]["timestamp"] != $time->format('Y-d-m H:i:s')) {
				//Get the next cloest time
				$timeString = $time->format('Y-d-m H:i:s');
				$query = "SELECT * FROM maps WHERE (instrument = '$instrument') ORDER BY ABS(UNIX_TIMESTAMP('$timeString')-UNIX_TIMESTAMP(timestamp)) DESC LIMIT 1";
				
				$result      = mysql_query($query);
				$closestDate = array();
				
				while ($row = mysql_fetch_array($result, MYSQL_ASSOC))
				{
					array_push($closestDate, $row);
				}
				
				//echo $closestDate["timestamp"];
					
				//Insert into array
				array_splice($resultArray, i, 0, $closestDate);
				
				//Update the time variable
				$time->modify("+1 day");
				
			}
			
			//another method: first add all missing values and then sort array...
		}

		echo json_encode($resultArray);
	}
	
?>
