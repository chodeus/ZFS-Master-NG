<?php
// Sends one of the plugin's diagnostics zips from /boot/logs to the browser: a GET with no side effect. Only a name
// scripts/diagnostics.php makes is served, and only a file of that name in that folder itself, never through a link.
$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp');
require_once $docroot.'/plugins/zfs.master/include/ZFSMHelpers.php';

$name = is_string($_GET['file'] ?? null) ? $_GET['file'] : '';
$file = zfsmStatePath('/boot/logs').'/'.$name;
$served = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && preg_match('/^zfs\.master-diagnostics-\d{8}-\d{6}\.zip$/D', $name) === 1 && !is_link($file) && is_file($file);
if (!$served) {
	http_response_code(404);
	exit;
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$name.'"');
header('Content-Length: '.filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($file);
