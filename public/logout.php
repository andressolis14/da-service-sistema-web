<?php
require_once __DIR__ . '/../src/config/app.php';

$_SESSION = [];
session_destroy();

header('Location: ' . URL_BASE . '/login.php');
exit;
