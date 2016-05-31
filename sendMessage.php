<?php
/**
 * Created by PhpStorm.
 * User: kunin
 * Date: 25.05.16
 * Time: 22:49
 */

require_once './Chat-API/src/whatsprot.class.php';

$username = '421919297391';
$password = 'uvK6s8HYIKDbSrhXMLPJe42fvTQ=';
$nickname = 'iTop';
$debug = true;

// Create a instance of WhastPort.
$w = new WhatsProt($username, $nickname, $debug);

$w->connect(); // Connect to WhatsApp network
$w->loginWithPassword($password); // logging in with the password we got!

$target = '79652729351'; // The number of the person you are sending the message
$messages = ['Ты тоже это слышишь?', 'Прием!'];

$w->loginWithPassword($password);
$w->sendGetServerProperties();
$w->sendClientConfig();
$sync = [$target];
$w->sendSync($sync);
//$w->pollMessage();
//$w->sendMessage($target , $message1);

foreach ($messages as $message) {

    $w->pollMessage();
    $w->sendMessage($target , $message);

}
