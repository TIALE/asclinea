<?php
if (file_exists('/home/u438902893/domains/ascppc.com/public_html/asclinea/error_log')) {
    echo file_get_contents('/home/u438902893/domains/ascppc.com/public_html/asclinea/error_log');
} else {
    echo "No error log found in root dir.\n";
}

if (file_exists(__DIR__ . '/error_log')) {
    echo "--- public/error_log ---\n";
    echo file_get_contents(__DIR__ . '/error_log');
}
