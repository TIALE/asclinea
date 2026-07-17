<?php
$output = [];
$return_var = 0;
exec('php -d display_errors=1 -l ' . __DIR__ . '/asistente_api.php 2>&1', $output, $return_var);
echo "Return: $return_var\n";
echo implode("\n", $output);
