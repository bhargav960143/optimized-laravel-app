<?php
// Safe OPcache preload — warms autoloader only
// Anonymous class preloading causes warnings; framework classes are warmed by OPcache on first request
$autoloader = require_once __DIR__ . '/vendor/autoload.php';
