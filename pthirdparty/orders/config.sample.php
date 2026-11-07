<?php
// TEMPLATE ONLY. The real file is pthirdparty/orders/config.php: it lives on the server, is never committed (see .gitignore),
// and holds the real values. To set up a new server: copy this file to config.php and fill it in.
return [
    'db_host'  => 'localhost',
    'db_name'  => 'CHANGE_ME',            // database name from hPanel > Databases
    'db_user'  => 'CHANGE_ME',            // database user
    'db_pass'  => 'CHANGE_ME',            // database password
    'timezone' => 'Asia/Kolkata',
    'api_key'  => 'CHANGE_ME',            // long random text
    // key for the private buying-price file on your website (get_costs.php)
    'website_costs_key' => 'CHANGE_ME',   // long random text, same value as on the website
    'debug'    => false,                  // keep false on the live site (true shows internal errors to visitors)
];
