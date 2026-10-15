<?php
require __DIR__ . '/_common.php';
$_SESSION = []; session_destroy();
header('Location: login.php');
