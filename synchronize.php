<?php

require_once __DIR__.'/../../../wp-load.php';
require_once 'vendor/autoload.php';

wp();

// Add an autoloader for the plugin's classes
spl_autoload_register(function($class) {
    $filename = __DIR__ .'/classes/'. $class .'.php';

    if(file_exists($filename))
        include $filename;
});

// Initiate the Mayocat environment
XWiki_Adm::init();

print_r( "Hello" );

//print_r( XWiki_Adm::synchronize_all() );
