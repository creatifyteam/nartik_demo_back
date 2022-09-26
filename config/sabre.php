<?php
if (config('app.env') == 'production') {
    $api_url = 'https://api.havail.sabre.com';
    $encoded_clientid_and_secretkey = 'VmpFNk1Xd3hOVzl6Tkc5NWJtd3dlbUoyYWpwRVJWWkRSVTVVUlZJNlJWaFU6ZGtWV1REVnpObTA9';
} else {
    $api_url = 'https://api-crt.cert.havail.sabre.com';
    $encoded_clientid_and_secretkey = 'VmpFNk1Xd3hOVzl6Tkc5NWJtd3dlbUoyYWpwRVJWWkRSVTVVUlZJNlJWaFU6ZGtWV1REVnpObTA9';
}

if (config('app.env') == 'production') {
    $api_soap_url = 'https://webservices.havail.sabre.com';
    $Username = 'V1:1l15os4oynl0zbvj:DEVCENTER:EXT';
    $Password = 'vEVL5s6m';
    $Organization = 'xxxx';
} else {
    $api_soap_url = 'https://sws-crt.cert.havail.sabre.com';
    $Username = 'V1:1l15os4oynl0zbvj:DEVCENTER:EXT';
    $Password = 'vEVL5s6m';
    $Organization = 'xxxx';
}

//dd($api_url, $encoded_clientid_and_secretkey);
// VmpFNk1Xd3hOVzl6Tkc5NWJtd3dlbUoyYWpwRVJWWkRSVTVVUlZJNlJWaFU6ZGtWV1REVnpObTA9
return array(

    'api_url' => $api_url,
    'encoded_clientid_and_secretkey' => $encoded_clientid_and_secretkey,
    'api_soap_url' => $api_soap_url,
    'Username' => $Username,
    'Password' => $Password,
    'Organization' => $Organization,

);
