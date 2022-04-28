<?php
require_once __DIR__.'/../vendor/autoload.php';

session_start();

$client = new Google\Client();
$client->setAuthConfigFile('../config/google/client_secret_348365894735-1kb5idgb6dur3tmb90u0shlegrljo18j.apps.googleusercontent.com.json');
$client->setRedirectUri('http://localhost:8080/productsup/my_project/public/oauth2callback.php');
$client->addScope(Google\Service\Drive::DRIVE_METADATA_READONLY);
//print_r($_GET);
if (! isset($_GET['code'])) {
  $auth_url = $client->createAuthUrl();
  header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
} else {
  $client->authenticate($_GET['code']);
  print_r(json_encode($client->getAccessToken()));
  $redirect_uri = 'http://' . $_SERVER['HTTP_HOST'] . '/';
  //header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
}
