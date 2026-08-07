<?php
/**
 * public/index.php  ->  route: /
 * Redirects the bare domain to the offer registration page.
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
redirect('/offer.php');
