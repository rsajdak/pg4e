<?php

use \Tsugi\Util\U;
use \Tsugi\Util\Mersenne_Twister;

require_once "names.php";
require_once "courses.php";

function makeRoster($code,$course_count=false,$name_count=false) {
    global $names, $courses;
    $MT = new Mersenne_Twister($code);
    $retval = array();
    $cc = 0;
    foreach($courses as $k => $course) {
    $cc = $cc + 1;
    if ( $course_count && $cc > $course_count ) break;
        $new = $MT->shuffle($names);
        $new = array_slice($new,0,$MT->getNext(17,53));
        $inst = 1;
        $nc = 0;
        foreach($new as $k2 => $name) {
            $nc = $nc + 1;
            if ( $name_count && $nc > $name_count ) break;
            $retval[] = array($name, $course, $inst);
            $inst = 0;
        }
    }
    return $retval;
}

// Unique to user + course
function getUnique($LAUNCH) {
    return md5($LAUNCH->user->key.'::'.$LAUNCH->context->key.
        '::'.$LAUNCH->user->id.'::'.$LAUNCH->context->id);
}

function getDbName($unique) {
    return substr("pg4e".$unique,0,15);
}

function getDbUser($unique) {
    return "pg4e_user_".substr($unique,15,5);
}

function getDbPass($unique) {
    return "pg4e_pass_".substr($unique,20,5);
}

/**
 * Returns
 * Object if good JSON was recceived.
 * String if something went wrong
 * Number if something went wrong and all we have is the http code
 */
function pg4e_request($dbname, $path='info') { 
    global $CFG, $pg4e_request_result;

    $pg4e_request_result = false;
    $endpoint = $CFG->pg4e_api_url.'/'.$path.'/'.$dbname;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $CFG->pg4e_api_key.':'.$CFG->pg4e_api_password);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

    $pg4e_request_result = curl_exec($ch);
    if($pg4e_request_result === false)
    {
        return 'Curl error: ' . curl_error($ch);
    }                                                                                                      
    $returnCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ( $returnCode != 200 ) return $returnCode;

    // It seems as though create success returns '"" '
    if ( $returnCode == 200 && trim($pg4e_request_result) == '""' ) return 200;

    // Lets parse the JSON
    $retval = json_decode($pg4e_request_result, false);  // As stdClass
    if ( $retval == null ) {
        error_log("JSON Error: ".json_last_error_msg());
        error_log($pg4e_request_result);
        return "JSON Error: ".json_last_error_msg();
    }
    return $retval;
}

function pg4e_extract_info($info) {
    $user = false;
    $password = false;
    $ip = false;
    try {
	$retval = new \stdClass();
 	$retval->user = base64_decode($info->auth->data->POSTGRES_USER);
 	$retval->password = base64_decode($info->auth->data->POSTGRES_PASSWORD);
 	$retval->ip = $info->svc->status->loadBalancer->ingress[0]->ip ?? null;
	return $retval;
    } catch(Exception $e) {
	return null;
    }
}

function pg4e_unlock_check($LAUNCH) {
    global $CFG;
    if ( $LAUNCH->context->key != '12345' ) return true;
    $unlock_code = md5(getUnique($LAUNCH) . $CFG->pg4e_unlock) ;
    if ( U::get($_COOKIE, 'unlock_code') == $unlock_code ) return true;
    return false;
}

function pg4e_unlock($LAUNCH) {
    global $CFG, $OUTPUT;
    if ( pg4e_unlock_check($LAUNCH) ) return true;

    if ( U::get($_POST, 'unlock_code') == $CFG->pg4e_unlock ) {
	setcookie('unlock_code', $unlock_code);
	header("Location: ".addSession($_SERVER['REQUEST_URI']));
	return false;
    }
    $OUTPUT->header();
    $OUTPUT->bodyStart(false);
    $OUTPUT->topNav();
    ?>
<form method="post">
<p>Unlock code:
<input type="password" name="unlock_code">
<input type="submit">
</form>
<?php
    $OUTPUT->footer();
    return false;
}


