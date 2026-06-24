<?php

$host = 'sql300.infinityfree.com';

var_dump(gethostbyname($host));

$fp = @fsockopen($host, 3306, $errno, $errstr, 10);

if (!$fp) {
    echo "$errno - $errstr";
} else {
    echo "Connected";
    fclose($fp);
}