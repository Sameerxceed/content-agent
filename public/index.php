<?php
/**
 * Entry point — redirect to dashboard or login.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

auth_start();

if (auth_check()) {
    redirect('/dashboard/index.php');
} else {
    redirect('/auth/login.php');
}
