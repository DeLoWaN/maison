<?php
header('Content-Type: text/plain; charset=utf-8');
echo PHP_VERSION, "\n", dirname(__DIR__, 2), "\n", ini_get('open_basedir'), "\n";
