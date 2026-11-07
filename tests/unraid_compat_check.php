<?php
// One request through real Unraid code. Warnings from this plugin's files fail the check; Unraid's own do not.
error_reporting(E_ALL); // PHP 7.4 hides notices by default
$check = $argv[1];
$docroot = '/usr/local/emhttp';
$plugin_dir = $docroot.'/plugins/zfs.master/';
$token = 'compat-test-token';

$GLOBALS['zfsm_problems'] = array();
$GLOBALS['zfsm_page_files'] = array(); // the temp copies run_page renders: their warnings are the plugin's
set_error_handler(function($severity, $message, $file, $line) use ($plugin_dir) {
	if (!(error_reporting() & $severity)) return true;
	if (strpos($file, $plugin_dir) === 0 || in_array($file, $GLOBALS['zfsm_page_files'], true)) $GLOBALS['zfsm_problems'][] = "$message in $file:$line";
	return true;
});
register_shutdown_function(function() {
	$fatal = error_get_last();
	if ($fatal && in_array($fatal['type'], array(E_ERROR, E_PARSE, E_COMPILE_ERROR), true)) {
		fwrite(STDERR, 'fatal: '.$fatal['message'].' in '.$fatal['file'].':'.$fatal['line']."\n");
		exit(1);
	}
	if (empty($GLOBALS['zfsm_check_finished'])) {
		fwrite(STDERR, "check ended before its assertions ran\n");
		exit(1);
	}
});

$var = array('csrf_token' => $token);
$display = array('text' => 0, 'critical' => 90, 'warning' => 70, 'theme' => 'black', 'date' => '%c', 'time' => '%R');
$_SERVER['DOCUMENT_ROOT'] = $docroot;
$_SERVER['SCRIPT_NAME'] = '/plugins/zfs.master/backend/ZFSMAdmin.php';
$_SERVER['HTTP_HOST'] = 'tower';

function run_page($path, $strip_header) {
	global $docroot, $var, $display;
	$source = file_get_contents($path);
	if ($strip_header) $source = substr($source, strpos($source, "\n---\n") + 5);
	if (!function_exists('_')) { function _($text) { return $text; } }
	$file = tempnam(sys_get_temp_dir(), 'zfsm-page');
	$GLOBALS['zfsm_page_files'][] = $file;
	file_put_contents($file, $source);
	ob_start();
	try {
		include $file;
	} finally {
		unlink($file);
	}
	return ob_get_clean();
}

// Every stylesheet a dialog links must exist in this Unraid, and one of them must carry the theme.
function popup_styles($html, $docroot) {
	preg_match_all('#href="(/webGui/styles/[^"?]+)#', $html, $matches);
	foreach ($matches[1] as $sheet) {
		if (!is_file($docroot.$sheet)) { fwrite(STDERR, "dialog links a stylesheet this Unraid lacks: $sheet\n"); exit(1); }
	}
	if (!preg_grep('#/(default-popup|themes/[a-z]+)\.css$#', $matches[1])) { fwrite(STDERR, "dialog has no theme stylesheet: ".implode(' ', $matches[1])."\n"); exit(1); }
}

switch ($check) {
	case 'body_token':
	case 'no_token':
	case 'wrong_token':
		$GLOBALS['zfsm_check_finished'] = true; // asserts in its own shutdown function: csrf_terminate exits
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array('cmd' => 'inventory');
		if ($check === 'body_token') $_POST['csrf_token'] = $token;
		if ($check === 'wrong_token') $_POST['csrf_token'] = 'not-the-token';
		ob_start();
		register_shutdown_function(function() use ($check) {
			$output = ob_get_clean();
			$answer = json_decode((string)$output, true);
			$served = is_array($answer) && isset($answer['data']['datasets']['tank']['child']['tank/data']);
			if ($check === 'body_token' ? !$served : $served) {
				fwrite(STDERR, $check.' got: '.substr((string)$output, 0, 300)."\n");
				exit(1);
			}
			if ($GLOBALS['zfsm_problems']) {
				fwrite(STDERR, implode("\n", $GLOBALS['zfsm_problems'])."\n");
				exit(1);
			}
		});
		require $docroot.'/webGui/include/local_prepend.php';
		require $docroot.'/plugins/zfs.master/backend/ZFSMAdmin.php';
		break;

	case 'main_page':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		// A saved setting that closes the script tag must stay inside the page's JSON.
		$cfg = '/boot/config/plugins/zfs.master/zfs.master.cfg';
		$original = file_get_contents($cfg);
		file_put_contents($cfg, str_replace('snap_prefix = ""', 'snap_prefix = "</script><i id=zfsm-injected>"', $original));
		$html = run_page($docroot.'/plugins/zfs.master/ZFSMaster.page', true);
		// Unraid 7 strips tags when it reads settings; 6.x hands them over as saved.
		$tags_survive = strpos(parse_plugin_cfg('zfs.master', true)['general']['snap_prefix'] ?? '', '<') !== false;
		file_put_contents($cfg, $original);
		if (strpos($html, 'zfs_master_body') === false) { fwrite(STDERR, "main page did not render\n"); exit(1); }
		if ($tags_survive && strpos($html, 'zfsm-injected') === false) { fwrite(STDERR, "the hostile setting never reached the page\n"); exit(1); }
		if (strpos($html, '<i id=zfsm-injected>') !== false) { fwrite(STDERR, "a saved setting closed the page's script tag\n"); exit(1); }
		break;

	case 'main_page_stuck_zfs':
		// A zfs that never answers must not hold up the Main tab: the rewrite probe gives up and offers no Rewrite Files.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		putenv('ZFSM_TEST_REWRITE_HANG=1');
		$began = microtime(true);
		$html = run_page($docroot.'/plugins/zfs.master/ZFSMaster.page', true);
		$took = microtime(true) - $began;
		if (strpos($html, 'zfs_master_body') === false || $took > 9) { fwrite(STDERR, sprintf("main page with a stuck zfs took %.1f s\n", $took)); exit(1); }
		if (strpos($html, '"rewrite":false') === false) { fwrite(STDERR, "a stuck zfs still offered Rewrite Files\n"); exit(1); }
		break;

	case 'saved_settings':
		// A settings file as older versions left it: CRLF list, Lua exclusion, the old misspelt key.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		require_once $docroot.'/webGui/include/Helpers.php';
		require_once $docroot.'/plugins/zfs.master/backend/ZFSMOperations.php';
		$saved = loadConfig(parse_plugin_cfg('zfs.master', true));
		$expected = array('refresh_interval' => 300, 'lazy_load' => 0, 'destructive_mode' => 1, 'snap_max_days_alert' => 7, 'snap_prefix' => 'auto-', 'snap_pattern' => 'Y-m-d', 'snap_utc' => 1, 'alert_interval' => 60, 'alert_stale' => 1,
			'dataset_exclusion' => '/docker%-old/.*', 'directory_listing' => array('cache/appdata', 'cache/My Share'));
		foreach ($expected as $key => $value) if ($saved[$key] !== $value) { fwrite(STDERR, "saved $key read as ".json_encode($saved[$key])."\n"); exit(1); }
		if (!zfsmMatchesExclusion('cache/docker-old/abc', $saved['dataset_exclusion']) || zfsmMatchesExclusion('cache/data', $saved['dataset_exclusion'])) { fwrite(STDERR, "saved exclusion no longer matches\n"); exit(1); }
		break;

	case 'menu_order':
		// Real find_pages: the ZFS guard works and the tab sits below the boot device.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		require_once $docroot.'/webGui/include/Helpers.php';
		require_once $docroot.'/webGui/include/PageBuilder.php';
		$site = array();
		// Other pages' conditions read live array state this harness doesn't have.
		build_pages($docroot.'/plugins/zfs.master/*.page');
		build_pages($docroot.'/plugins/dynamix/BootDevice.page');
		$names = array_column(array_values(find_pages('Main')), 'name');
		$position = array_search('ZFSMaster', $names, true);
		if (is_file('/usr/sbin/zpool')) {
			$boot = array_search('BootDevice', $names, true);
			if ($position === false) { fwrite(STDERR, "tab missing with ZFS present\n"); exit(1); }
			if ($boot !== false && $position < $boot) { fwrite(STDERR, 'tab above the boot device: '.implode(',', $names)."\n"); exit(1); }
		} elseif ($position !== false) {
			fwrite(STDERR, "tab shown without ZFS\n");
			exit(1);
		}
		$GLOBALS['zfsm_problems'] = array();
		break;

	case 'time_format':
		// Dates must come out exactly as this Unraid's own my_time() writes them, in the user's display format.
		require_once $docroot.'/webGui/include/Helpers.php';
		require_once $docroot.'/plugins/zfs.master/backend/ZFSMOperations.php';
		$at = 1793700000;
		if (zfsmTime($at) !== my_time($at)) { fwrite(STDERR, "zfsmTime differs from my_time: ".zfsmTime($at).' vs '.my_time($at)."\n"); exit(1); }
		if (function_exists('my_date')) {
			$display['date'] = 'd-m-Y';
			$display['time'] = 'H:i';
			if (zfsmTime($at) !== date('d-m-Y, H:i', $at)) { fwrite(STDERR, "display format ignored: ".zfsmTime($at)."\n"); exit(1); }
		}
		break;

	case 'settings_roundtrip':
		// What saveConfig writes reads back unchanged through this Unraid's parse_plugin_cfg; anything that wouldn't is refused.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		require_once $docroot.'/webGui/include/Helpers.php';
		require_once $docroot.'/plugins/zfs.master/backend/ZFSMOperations.php';
		$cfg = '/boot/config/plugins/zfs.master/zfs.master.cfg';
		$original = file_get_contents($cfg);
		$bad = array();
		foreach (array('plain', 'say "hi"', 'C:\\temp\\', 'x&y', 'cost $5', 'a;b', 'tank/My Share', '(?!x)') as $value) {
			$config = parse_plugin_cfg('zfs.master', true);
			$config['general']['snap_prefix'] = $value;
			$read = saveConfig($config) ? (parse_plugin_cfg('zfs.master', true)['general']['snap_prefix'] ?? null) : 'not saved';
			if ($read !== $value) $bad[] = 'snap_prefix '.json_encode($value).' read back as '.json_encode($read);
		}
		foreach (array('pre${HOME}post', 'x<b', 'a&quot;b', 'x&#92;') as $value) {
			$config = parse_plugin_cfg('zfs.master', true);
			$config['general']['snap_prefix'] = $value;
			$before = file_get_contents($cfg);
			if (saveConfig($config) || file_get_contents($cfg) !== $before) $bad[] = 'saved '.json_encode($value);
		}
		$listing = function() { return loadConfig(parse_plugin_cfg('zfs.master', true))['directory_listing']; };
		file_put_contents($cfg, "[general]\r\ndirectory_listing = \"cache/appdata\r\ncache/R&D\"\r\n");   // a list older versions saved with CRLF
		$ret = addToDirectoryListing('tank/data');
		if ($listing() !== array('cache/appdata', 'cache/R&D', 'tank/data')) $bad[] = 'listing after add: '.json_encode($listing()).' '.json_encode($ret);
		$ret = removeFromDirectoryListing('cache/appdata');
		if ($listing() !== array('cache/R&D', 'tank/data')) $bad[] = 'listing after remove: '.json_encode($listing()).' '.json_encode($ret);
		// An entry with a quote, as the Settings page can save one: the add is refused and the file is left as it was.
		file_put_contents($cfg, $quoted = "[general]\ndirectory_listing = \"cache/appdata\ncache/a\\\"b\"\n");
		$ret = addToDirectoryListing('tank/data');
		if (!is_string($ret['failed']['tank/data'] ?? null) || file_get_contents($cfg) !== $quoted) $bad[] = 'a quoted entry: '.json_encode($ret).' '.json_encode(file_get_contents($cfg));
		// A change waits while another holds the settings lock, then lands: two overlapping changes both stay.
		file_put_contents($cfg, "[general]\ndirectory_listing = \"cache/appdata\"\n");
		foreach (array('add' => array('addToDirectoryListing', 'tank/data', array('cache/appdata', 'tank/data')), 'remove' => array('removeFromDirectoryListing', 'cache/appdata', array('tank/data'))) as $name => list($call, $dataset, $after)) {
			$held = fopen('/var/run/zfs.master.lock', 'c');
			flock($held, LOCK_EX);
			$before = $listing();
			$child = proc_open(array(PHP_BINARY, '-n', '-r', '$docroot = '.var_export($docroot, true).'; $_SERVER["REQUEST_METHOD"] = "GET"; require_once $docroot."/webGui/include/Helpers.php"; require_once $docroot."/plugins/zfs.master/backend/ZFSMOperations.php"; '.$call.'('.var_export($dataset, true).');'), array(), $pipes);
			usleep(400000);
			if ($listing() !== $before) $bad[] = "$name ran while the settings lock was held: ".json_encode($listing());
			flock($held, LOCK_UN);
			fclose($held);
			proc_close($child);
			if ($listing() !== $after) $bad[] = "$name after the lock: ".json_encode($listing());
		}
		file_put_contents($cfg, $original);
		if ($bad) { fwrite(STDERR, implode("\n", $bad)."\n"); exit(1); }
		break;

	case 'settings_page':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		require_once $docroot.'/webGui/include/Helpers.php';
		$html = run_page($docroot.'/plugins/zfs.master/ZFSMasterSettings.page', true);
		if (strpos($html, 'name="exclusion"') === false) { fwrite(STDERR, "settings page did not render\n"); exit(1); }
		// Unraid runs Markdown() over the page; the fields must still become form rows inside each section.
		require_once is_file($docroot.'/webGui/include/MarkdownExtra.inc.php') ? $docroot.'/webGui/include/MarkdownExtra.inc.php' : $docroot.'/webGui/include/Markdown.php';
		$rendered = Markdown($html);
		foreach (array('General Settings' => 'Refresh Interval', 'Datasets' => 'Directory Listing', 'Snapshots' => 'Snapshots Pattern', 'Alerts' => 'Pool Health Alerts') as $section => $field) {
			if (!preg_match('#<details class="zfsm-section"[^>]*>\s*<summary>[^<]*'.$section.'[^<]*</summary>(?:(?!</details>).)*<dt>(?:<[^>]+>|[^<])*'.$field.'#s', $rendered)) { fwrite(STDERR, "settings section $section lost its form rows\n"); exit(1); }
		}
		if (!preg_match('#<dt>(?:<[^>]+>|[^<])*Snapshot Time(?:(?!</dl>).)*<select name="snap_utc">#s', $rendered)) { fwrite(STDERR, "settings page lost the Snapshot Time row\n"); exit(1); }
		foreach (array('Pool Health Alerts' => 'alert_interval', 'Stale Snapshot Alerts' => 'alert_stale') as $label => $name) {
			if (!preg_match('#<dt>(?:<[^>]+>|[^<])*'.$label.'(?:(?!</dl>).)*<select name="'.$name.'">#s', $rendered)) { fwrite(STDERR, "settings page lost the $label row\n"); exit(1); }
		}
		// Saving runs this through update.php's #command, which needs a real executable under the docroot.
		if (strpos($html, '<input type="hidden" name="#command" value="plugins/zfs.master/scripts/alerts.php"/>') === false || strpos($html, '<input type="hidden" name="#arg[1]" value="schedule"/>') === false) { fwrite(STDERR, "settings page does not schedule alerts on save\n"); exit(1); }
		if (!is_executable($docroot.'/plugins/zfs.master/scripts/alerts.php')) { fwrite(STDERR, "the installed alert script is not executable\n"); exit(1); }
		break;

	case 'create_popup':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = array('zpool' => 'tank');
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMCreateDataset.php';
		$html = ob_get_clean();
		if (strpos($html, 'zfsm_csrf_token') === false) { fwrite(STDERR, "create popup did not render\n"); exit(1); }
		foreach (array('name', 'mount', 'mountpoint', 'quota', 'encryption', 'passphrase', 'sync') as $field) {
			if (!preg_match('#<dl><dt class="zfsm-tip" tabindex="0" data-tip="[^"]+">[^<]+</dt><dd>[^\n]*name="'.$field.'"#', $html)) { fwrite(STDERR, "create popup lost the $field row or its tooltip\n"); exit(1); }
		}
		popup_styles($html, $docroot);
		break;

	case 'snapshot_popup':
		// The fixture dataset's .zfs folder, which both icons need; this runs in a disposable container.
		@mkdir('/mnt/tank/data/.zfs/snapshot', 0755, true);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = array('zpool' => 'tank', 'zdataset' => 'tank/data');
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMAdminDatasetSnaps.php';
		$html = ob_get_clean();
		if (strpos($html, 'tank/data@daily') === false) { fwrite(STDERR, "snapshot popup did not list the snapshot\n"); exit(1); }
		if (strpos($html, '1 snapshot · ') === false) { fwrite(STDERR, "snapshot popup header did not count one snapshot\n"); exit(1); }
		// Filter and sort read these attributes: ZFSMSnapshotList.js.
		if (!preg_match('#<tr id="snap-[0-9a-f]{40}" data-snapshot="daily" data-automatic="0">.*<td class="num" data-text="2">[^<]+</td><td class="num" data-text="50">.*<td data-text="1700000400">#', $html)) { fwrite(STDERR, "snapshot row lost its filter or sort values\n"); exit(1); }
		if (strpos($html, 'id="zfsm-snap-filter"') === false || strpos($html, 'ZFSMSnapshotList.js') === false) { fwrite(STDERR, "snapshot popup lost its filter box or list script\n"); exit(1); }
		if (strpos($html, 'id="zfsm-hide-auto"') !== false) { fwrite(STDERR, "snapshot popup offers to hide automatic snapshots it does not have\n"); exit(1); }
		if (strpos($html, '<a title="Browse files in this snapshot" href="/Main/Browse?dir=%2Fmnt%2Ftank%2Fdata%2F.zfs%2Fsnapshot%2Fdaily" target="_blank" rel="noopener">') === false) { fwrite(STDERR, "snapshot row does not open the snapshot in the file browser\n"); exit(1); }
		if (strpos($html, 'onclick="diffSnapshot(') === false || strpos($html, 'ZFSMSnapshotDiff.js') === false) { fwrite(STDERR, "snapshot row does not offer its changes\n"); exit(1); }
		// An <a> without href never takes keyboard focus, so each click-only icon is a button.
		preg_match_all('#<a [^>]*onclick=[^>]*>#', $html, $icons);
		if (count($icons[0]) !== 6 || count(preg_grep('#role="button" tabindex="0"#', $icons[0])) !== 6) { fwrite(STDERR, "a snapshot row icon cannot take keyboard focus\n"); exit(1); }
		if (strpos($html, 'id="zfsm-children"') !== false) { fwrite(STDERR, "snapshot popup offers child datasets the dataset does not have\n"); exit(1); }
		popup_styles($html, $docroot);
		break;

	// A parent dataset offers its children's snapshots; with them, each row browses its own dataset.
	case 'snapshot_popup_parent':
	case 'snapshot_popup_children':
	case 'snapshot_popup_excluded':
		@mkdir('/mnt/tank/.zfs/snapshot', 0755, true);
		@mkdir('/mnt/tank/data/.zfs/snapshot', 0755, true);
		putenv('ZFSM_TEST_MOUNT_ROOT=/mnt');
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = array('zpool' => 'tank', 'zdataset' => 'tank');
		if ($check !== 'snapshot_popup_parent') $_GET['children'] = '1';
		putenv('ZFSM_TEST_CALL_LOG=/tmp/zfsm-calls-'.$check);
		$cfg = '/boot/config/plugins/zfs.master/zfs.master.cfg';
		$saved_cfg = @file_get_contents($cfg);
		if ($check === 'snapshot_popup_excluded') file_put_contents($cfg, "[general]\nexclusion = \"tank/data\"\n");
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMAdminDatasetSnaps.php';
		$html = ob_get_clean();
		if ($saved_cfg === false) @unlink($cfg); else file_put_contents($cfg, $saved_cfg);
		if ($check === 'snapshot_popup_excluded') {
			// Hidden from the main list, so hidden here: tank/vol still makes tank a parent.
			if (strpos($html, 'tank/data@daily') !== false || strpos($html, '1 snapshot in 1 dataset · ') === false) { fwrite(STDERR, "an excluded child dataset's snapshots were listed\n"); exit(1); }
			if (strpos($html, '<input type="checkbox" id="zfsm-children" checked>') === false) { fwrite(STDERR, "snapshot popup lost its Include child datasets box\n"); exit(1); }
			break;
		}
		$children = $check === 'snapshot_popup_children';
		if (strpos($html, 'tank@root') === false || (strpos($html, 'tank/data@daily') !== false) !== $children) { fwrite(STDERR, "snapshot popup listed the wrong datasets' snapshots\n"); exit(1); }
		if (strpos($html, '<input type="checkbox" id="zfsm-children"'.($children ? ' checked' : '').'>') === false) { fwrite(STDERR, "snapshot popup lost its Include child datasets box\n"); exit(1); }
		if (!$children) break;
		if (strpos($html, '2 snapshots in 2 datasets · ') === false) { fwrite(STDERR, "snapshot popup header did not count the datasets\n"); exit(1); }
		if (strpos($html, 'data-snapshot="tank/data@daily"') === false || strpos($html, 'placeholder="Filter by dataset or name"') === false) { fwrite(STDERR, "the filter does not match dataset names\n"); exit(1); }
		foreach (array('%2Fmnt%2Ftank%2F.zfs%2Fsnapshot%2Froot', '%2Fmnt%2Ftank%2Fdata%2F.zfs%2Fsnapshot%2Fdaily') as $dir) {
			if (strpos($html, 'href="/Main/Browse?dir='.$dir.'"') === false) { fwrite(STDERR, "a child dataset's snapshot does not browse its own dataset\n"); exit(1); }
		}
		// One read for every dataset's browse and changes, not two per dataset.
		$calls = (string)@file_get_contents('/tmp/zfsm-calls-'.$check);
		if (substr_count($calls, 'mounted,mountpoint') !== 1 || preg_match('/^get .* (mounted|mountpoint) /m', $calls)) { fwrite(STDERR, "the child list read access per dataset:\n$calls\n"); exit(1); }
		break;

	// Mount details that could not be read: no icons, and a note saying why, alone or with the children.
	case 'snapshot_popup_unread':
	case 'snapshot_popup_children_unread':
		$children = $check === 'snapshot_popup_children_unread';
		@mkdir('/mnt/tank/.zfs/snapshot', 0755, true);
		@mkdir('/mnt/tank/data/.zfs/snapshot', 0755, true);
		putenv('ZFSM_TEST_MOUNT_ROOT=/mnt');
		putenv('ZFSM_TEST_GET_FAIL='.($children ? 'tank' : 'tank/data'));
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = $children ? array('zpool' => 'tank', 'zdataset' => 'tank', 'children' => '1') : array('zpool' => 'tank', 'zdataset' => 'tank/data');
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMAdminDatasetSnaps.php';
		$html = ob_get_clean();
		if (strpos($html, 'tank/data@daily') === false) { fwrite(STDERR, "snapshot popup did not list the snapshot\n"); exit(1); }
		if (strpos($html, 'Browse files in this snapshot') !== false || strpos($html, 'onclick="diffSnapshot(') !== false) { fwrite(STDERR, "unread mount details still offered browse or changes\n"); exit(1); }
		if (strpos($html, 'id="zfsm-access-unread"') === false) { fwrite(STDERR, "unread mount details hid the icons without saying why\n"); exit(1); }
		break;

	// Both icons need the dataset mounted with a .zfs folder; Unraid's file browser also needs /mnt or /boot.
	case 'snapshot_popup_unmounted':
	case 'snapshot_popup_elsewhere':
		@mkdir('/mnt/tank/data/.zfs/snapshot', 0755, true);
		@mkdir('/srv/data/.zfs/snapshot', 0755, true);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = array('zpool' => 'tank', 'zdataset' => 'tank/data');
		putenv($check === 'snapshot_popup_unmounted' ? 'ZFSM_TEST_MOUNTED=no' : 'ZFSM_TEST_DATASET_MOUNT=/srv/data');
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMAdminDatasetSnaps.php';
		$html = ob_get_clean();
		if (strpos($html, 'tank/data@daily') === false) { fwrite(STDERR, "snapshot popup did not list the snapshot\n"); exit(1); }
		if (strpos($html, 'Browse files in this snapshot') !== false) { fwrite(STDERR, "snapshot row offers a file browser path Unraid cannot open\n"); exit(1); }
		if ((strpos($html, 'onclick="diffSnapshot(') !== false) !== ($check === 'snapshot_popup_elsewhere')) { fwrite(STDERR, "snapshot row offers changes only when the dataset is mounted\n"); exit(1); }
		break;

	case 'snapshot_popup_automatic':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = array('zpool' => 'tank', 'zdataset' => 'tank/data');
		putenv('ZFSM_TEST_SNAPSHOT_AUTO=1');
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMAdminDatasetSnaps.php';
		$html = ob_get_clean();
		if (strpos($html, 'data-snapshot="autosnap_2023-11-14_22:15:00_hourly" data-automatic="1"') === false) { fwrite(STDERR, "a Sanoid snapshot is not marked automatic\n"); exit(1); }
		if (strpos($html, 'id="zfsm-hide-auto"') === false) { fwrite(STDERR, "snapshot popup does not offer to hide its Sanoid snapshot\n"); exit(1); }
		break;

	case 'snapshot_popup_failed':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = array('zpool' => 'tank', 'zdataset' => 'tank/data');
		putenv('ZFSM_TEST_DATASET_SNAPSHOTS_FAIL=1');
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMAdminDatasetSnaps.php';
		$html = ob_get_clean();
		if (strpos($html, 'simulated dataset snapshot failure') === false) { fwrite(STDERR, "snapshot popup did not show the listing error\n"); exit(1); }
		if (strpos($html, 'Snapshots could not be listed') === false || strpos($html, '0 snapshots') !== false) { fwrite(STDERR, "snapshot popup header claims a count after a failed listing\n"); exit(1); }
		if (strpos($html, 'id="zfsm-snap-filter"') !== false) { fwrite(STDERR, "snapshot popup offers a filter with nothing listed\n"); exit(1); }
		break;

	default:
		exit(2);
}

$GLOBALS['zfsm_check_finished'] = true;
if ($GLOBALS['zfsm_problems']) {
	fwrite(STDERR, implode("\n", $GLOBALS['zfsm_problems'])."\n");
	exit(1);
}
