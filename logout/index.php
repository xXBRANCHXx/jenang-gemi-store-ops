<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

jg_admin_logout();

header('Location: ../');
exit;
