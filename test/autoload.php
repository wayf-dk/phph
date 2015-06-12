<?php
$root = dirname(__DIR__);
set_include_path("$root/local/:$root/library/");

spl_autoload_register(
    function($class) {
        if ($cn = stream_resolve_include_path(strtolower($class) . '.php')) {
            require $cn;
        }
    }
);
