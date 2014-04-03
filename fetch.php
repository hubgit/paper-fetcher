<?php

require 'Article.php';

libxml_use_internal_errors(true);

$types = array('json', 'html', 'pdf');

foreach ($types as $type) {
  $dir = 'data/' . $type;
  if (!file_exists($dir)) {
    mkdir($dir, 0700, true);
  }
}

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 60,
  CURLOPT_ENCODING => '', // all supported types
  CURLOPT_NOPROGRESS => false,
  CURLOPT_MAXREDIRS => 20,
  CURLOPT_COOKIEJAR => '/tmp/cookies.txt',
  CURLOPT_COOKIEFILE => '/tmp/cookies.txt',
  // browser-like User Agent needed for some publishers :(
  CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/33.0.1750.152 Safari/537.36',
));

$dois = file('dois.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
printf("Fetching data for %d DOIs\n", count($dois));

shuffle($dois); // randomise the DOIs to distribute load

$log = fopen('error.log', 'w');

$selectors = json_decode(file_get_contents('selectors.json'), true);

foreach ($dois as $doi) {
  $article = new Article($curl, $log, $selectors, $doi);
  $article->fill();
}
