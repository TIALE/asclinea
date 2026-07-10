<?php

declare(strict_types=1);

// Redirigir de forma segura al Front Controller en la carpeta public
header('Location: public/index.php');
exit;
