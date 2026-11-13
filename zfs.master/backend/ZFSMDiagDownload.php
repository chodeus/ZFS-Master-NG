<?php
// Sends one of the plugin's diagnostics zips from /boot/logs to the browser: a GET with no side effect. Only a name
// scripts/diagnostics.php makes is served, and only a file of that name in that folder itself, never through a link.
$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp');
require_once $docroot.'/plugins/zfs.master/include/ZFSMHelpers.php';
require_once $docroot.'/plugins/zfs.master/include/ZFSMDiagBundle.php';

$name = is_string($_GET['file'] ?? null) ? $_GET['file'] : '';
$file = zfsmStatePath('/boot/logs').'/'.$name;
// realpath: neither the zip nor the folder it is in may be reached through a link.
$served = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && zfsmDiagIsZip($name) && is_file($file) && realpath($file) === $file;
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
