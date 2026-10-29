<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
json_out(state_payload($user));
