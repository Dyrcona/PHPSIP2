<?php

require_once("sip2client.class.php");

$sip2 = new SIP2Client;

$hostname = 'localhost';
$port = 6001;
$username = 'myuser';
$password = 'mypassword';
$testbarcode = '21234000012345';
$testpin = '0000';

if($sip2->connect($hostname, $port, $username, $password)) {
    echo "Connected to $hostname\n";
    $patronInfo = $sip2->patronInformation($testbarcode, $testpin);
    if($patronInfo === false || (isset($patronInfo['BL'][0]) && $patronInfo['BL'][0] != 'Y')) {
        echo "Bad Patron!\n";
    }
    else {
        if(!isset($patronInfo['CQ'][0]) || $patronInfo['CQ'][0] != 'Y')
            echo "Bad PIN!\n";
        else
            echo "Valid Patron and Pin!\n";
    }
    $sip2->disconnect();
}
