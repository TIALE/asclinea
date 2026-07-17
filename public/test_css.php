<?php
header('Content-Type: text/plain');
echo "Current PHP File: " . __FILE__ . "\n";
echo "Relative assets/css/style.css exists? " . (file_exists(__DIR__ . '/assets/css/style.css') ? 'YES' : 'NO') . "\n";
echo "Relative assets/css/style.css path: " . realpath(__DIR__ . '/assets/css/style.css') . "\n";
echo "Document root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
