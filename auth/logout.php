<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/session.php';

Session::logout();
header('Location: ../index.php');
exit;
