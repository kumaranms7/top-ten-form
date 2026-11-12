<?php
require __DIR__ . '/../app/bootstrap.php';
start_session();
if (current_user()) log_activity('Logged out');
$_SESSION = [];
session_destroy();
header('Location: login.php');
