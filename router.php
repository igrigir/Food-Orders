<?php
declare(strict_types=1);
// Only for PHP's local development server. Apache uses .htaccess instead.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/' || $path === '/index.php') { require __DIR__.'/index.php'; return true; }
if ($path === '/register.php') { require __DIR__.'/register.php'; return true; }
if (in_array($path, ['/assets/app.css','/assets/app.js'],true)) return false;
http_response_code(404);
echo 'Not found';
