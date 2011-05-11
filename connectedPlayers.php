<?php 
function getTime() 
    { 
    $a = explode (' ',microtime()); 
    return(double) $a[0] + $a[1]; 
    } 
$Start = getTime(); 
?>
<?php

// McLogParser - 4/20/2011 by Spencer <srt4@uw.edu>

// open the sqlite database
$db = sqlite_open('logfiles/Sessions.db', 0777, $dberr);

if(!$db) {
    die("Sqlite encountered an error: $dberr");
}

$query = "SELECT * FROM sessions WHERE 1";
$result = sqlite_query($db, $query);

checkIfUpdate($db);
function checkIfUpdate($db) {
    $query = "SELECT max(logout) AS max FROM sessions WHERE logout != '';";
    $result = sqlite_query($db, $query, SQLITE_ASSOC, $err);
    $dbmax = sqlite_fetch_single($result);
    $lines = file('logfiles/server.log');
    for ($i = count($lines); $i > 0; $i--) {
        $line = $lines[$i];
        if ( strstr($line, "lost connection") ) {
            $line_array = explode(' ', $line);
            $login_date = $line_array[0];
            $login_time = $line_array[1];
            $logmax = strtotime($login_date . $login_time);
            if ($logmax>$dbmax) {
                $process_array;
                // create a new array from the breakpoint to the end of the logfile
                for($j = $i-10; $j < count($lines); $j++) {
                    $process_array[] = $lines[$j];
                }
                parseLog($db, $process_array);
                break;
            }
            break;
        }
    }
}

function parseLog($db, $lines) {
    sqlite_query($db, "START TRANSACTION;");
    
    $connect = array();
    
    $sessions = array();
    
    $total_time = array();
    $recent_time = array();
    // Loop through our array, show HTML source as HTML source; and line numbers too.
    foreach ($lines as $line_num => $line) {
        // Find connections
        if(strstr($line, "logged in")) {
            $line_array = explode(' ', $line);
            $username = $line_array[3];
            $login_date = $line_array[0];
            $login_time = $line_array[1];
            
            $sessions[$username]=strtotime($login_date . $login_time);
            
            array_push($connect, $username);
        } else if( strstr($line, "lost connection") && (strstr($line, "quitting") || strstr($line, "disconnect")) ) { // bad boolean to detect a disconnect w/o a failed login attempt
            $line_array = explode(' ', $line);
            $username = $line_array[3];
            $login_date = $line_array[0];
            $login_time = $line_array[1];
            
            $date = strtotime($login_date . $login_time);
            $lastweek = strtotime("last Monday");
            $query = "INSERT INTO sessions ( login, logout, username ) VALUES ( '$sessions[$username]', '$date', '$username' );";
            sqlite_query($db, $query, SQLITE_ASSOC, $err);
            
            if ($sessions[$username] != null) {
                $time = abs($sessions[$username] - $date);
                // calc the time difference, plus equal it to the array of times
                if($total_time[$username] == null) $total_time[$username] = abs($sessions[$username] - $date);
                else $total_time[$username] += abs($sessions[$username] - $date);
                if($lastweek - $date < 0) { // If the login occured within the last week
                    $recent_time[$username] = $recent_time[$username] == null ? $time : $recent_time[$username] + $time;
                }
            }
            // dat user, remove 'em from the array
            unset($sessions[$username]);
        } else if ( strstr($line, "Preparing level") ) { // detect if the server has rebooted
            // we don't know how long the server has been down, so let's not inflate the time-played
            $sessions = array();
        } else if ( strstr($line, "Stopping server") ) { // detect if the server has been stopped
            // this is the equiv of ALL active sessions disconnecting, behave accordingly
            $line_array = explode(' ', $line);
            $login_date = $line_array[0];
            $login_time = $line_array[1];
            $date = strtotime($login_date . $login_time);
            foreach($sessions as $user=>$session) {
                if ($session != null) {
                    if($total_time[$user] == null) $total_time[$user] = abs($session - $date);
                    else $total_time[$user] += abs($session - $date);
                        $query = "INSERT INTO sessions ( login, logout, username ) VALUES ( '$session', '$date', '$user' );";
                    sqlite_query($db, $query, SQLITE_FETCH_ASSOC, $err);
                }
            }
            
            $sessions = array();
        }
    }
    sqlite_query($db, "COMMIT TRANSACTION;");
}
?>
<div style="width:330px">
    <div style="float:left; width:150px;  border-bottom: thin dotted; font-size:12px">Username</div>
    <div style="float:left; width:50px; border-left: thin solid; border-bottom: thin dotted; font-size:12px;">Logins</div>
    <div style="float:left; width:100px; border-left: thin solid; border-bottom: thin dotted; font-size:12px">Time Played</div>
</div>
<div style="width:330px">
<?php
// Open a new transaction for the select statement

$query = "SELECT round(sum(abs(login - logout))/60/60 * 100)/100 "
	."AS time, count(*) AS logins, username FROM  sessions "
	."WHERE login != '' AND logout != '' GROUP BY username "
	."ORDER BY time DESC;"; 

$result = sqlite_query($db, $query);

while ($login_array = sqlite_fetch_array($result)) {
	?>
	<div class="username" style="float:left; width:150px;  border-bottom: thin dotted;">
	<?=$login_array['username']?>
	</div>
	<div class="time" style="float:left; width:50px; border-left: thin solid; border-bottom: thin dotted; text-align:left; padding-left:5px">
	<?=$login_array['logins']?>
	</div>
	<div class="time" style="float:left; width:100px; border-left: thin solid; border-bottom: thin dotted; text-align:left; padding-left:5px">
	<?=$login_array['time']?>
	</div>
<?php
}
// Calc total time played ever
$query = "SELECT round(sum(abs(login-logout))/60/60 *10)/10 "
	."AS time FROM sessions WHERE login != '' AND "
	."LOGOUT != '';";
$result = sqlite_query($db, $query);
$sum_time = sqlite_fetch_single($result);
?>
Total time: <?=$sum_time?> hours, or $<?=$sum_time * 8.5?> of minimum wage labor.
</div>
<?php 
$End = getTime(); 
echo "Parsed log in ".number_format(($End - $Start),2)."s"; 
?>
