<?php
ini_set('display_errors', '0');

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestedPage = rawurldecode((string) ($_GET['auth_page'] ?? ''));
$page = strtolower(basename($requestedPage !== '' ? $requestedPage : $requestPath));
if ($requestPath === '/' || $page === '') $page = 'index.html';

if (preg_match('/\.(sqlite|db|env)(?:$|\/)/i', $requestPath) || basename($requestPath) === '.env.example') {
  http_response_code(404);
  exit;
}
if (strtolower(pathinfo($page, PATHINFO_EXTENSION)) !== 'html' || $page === 'account.html') return false;

$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => $isHttps,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');

$accountId = (int) ($_SESSION['auth_user']['id'] ?? 0);
if (!$accountId) {
  session_write_close();
  header('Location: account.html', true, 302);
  exit;
}

try {
  $db = new PDO('sqlite:' . __DIR__ . '/medicine_system.sqlite');
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $query = $db->prepare('SELECT account_status FROM users WHERE id=?');
  $query->execute([$accountId]);
  $status = $query->fetchColumn();
} catch (PDOException $error) {
  error_log('Authentication page gate database error: ' . $error->getMessage());
  http_response_code(503);
  echo 'The account service is temporarily unavailable.';
  exit;
}

if ($status !== 'active') {
  $_SESSION = [];
  session_regenerate_id(true);
  setcookie('MS_REMEMBER', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_write_close();
  header('Location: account.html', true, 302);
  exit;
}

session_write_close();
header('Content-Type: text/html; charset=UTF-8');
$pagePath = __DIR__ . DIRECTORY_SEPARATOR . $page;
if (!is_file($pagePath)) {
  http_response_code(404);
  exit;
}
readfile($pagePath);
exit;
