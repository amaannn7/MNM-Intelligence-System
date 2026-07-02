<?php
// Microsoft 365 OAuth callback — clean URL with no query string (Azure requires this)
$_GET['action'] = 'ms-auth-callback';
require __DIR__ . '/api.php';
