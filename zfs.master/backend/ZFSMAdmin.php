<?php

$plugin = 'zfs.master';
$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp');

require_once $docroot.'/webGui/include/Helpers.php';
require_once $docroot.'/plugins/'.$plugin.'/include/ZFSMBase.php';
require_once $docroot.'/plugins/'.$plugin.'/include/ZFSMError.php';
require_once $docroot.'/plugins/'.$plugin.'/include/ZFSMHelpers.php';
require_once $docroot.'/plugins/'.$plugin.'/backend/ZFSMOperations.php';

$zfsm_cfg = loadConfig(parse_plugin_cfg($plugin, true));
$_POST = is_array($_POST ?? null) ? $_POST : array();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function zfsmPostString($key, $default = '') {
	$value = $_POST[$key] ?? $default;
	return is_string($value) ? $value : $default;
}

function zfsmResolveAnswerCodes($answer) {
	if (!is_array($answer)) return zfsmFailure('request', 'The operation returned no usable result');
	$answer['succeeded'] = is_array($answer['succeeded'] ?? null) ? $answer['succeeded'] : array();
	$answer['failed'] = is_array($answer['failed'] ?? null) ? $answer['failed'] : array();
	foreach ($answer['succeeded'] as $key => $value) $answer['succeeded'][$key] = resolve_error($value);
	foreach ($answer['failed'] as $key => $value) $answer['failed'][$key] = resolve_error($value);
	return $answer;
}

function zfsmReturnAnswer($ret, $title, $success_text, $failed_text, $unraid_notify = false) {
	$answer = zfsmResolveAnswerCodes($ret);
	foreach (zfsmActionLogLines($title, $answer) as $line) zfsmLog($line, LOG_NOTICE);
	if ($unraid_notify) zfsmNotifyAnswer($title, $answer, $success_text, $failed_text);
	echo json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

// A dry run can still block on a slow pool, so it shares the inventory time limit.
function zfsmWithReadLimit($read) {
	return zfsmWithDeadline(45, $read);
}

function zfsmRequireDestructiveMode($config, $subject) {
	return (int)($config['destructive_mode'] ?? 0) === 1 ? null : zfsmFailure($subject, 'Destructive Mode is disabled in ZFS Master NG settings');
}

try {
	$command = zfsmPostString('cmd');
	switch ($command) {
		case 'inventory':
		case 'inventorysnapshots':
			// Use the existing authenticated, CSRF-protected plugin endpoint.
			$pool = $command === 'inventorysnapshots' ? zfsmPostString('pool') : '';
			if ($command === 'inventorysnapshots' && $pool === '') throw new InvalidArgumentException('Missing pool name');
			$answer = zfsmReadInventory($zfsm_cfg, $command === 'inventorysnapshots' || !$zfsm_cfg['lazy_load'], $pool);
			$answer['config'] = $zfsm_cfg;
			$answer['publishing_paused'] = is_file('/tmp/publishPaused');
			echo json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
			break;

		case 'refresh':
			// Only a browser tab left open across an upgrade still sends this.
			zfsmReturnAnswer(zfsmFailure('refresh', 'Reload the updated ZFS Master NG page to use direct inventory.'), 'Refresh', '', '');
			break;

		case 'createdataset':
			$data = is_array($_POST['data'] ?? null) ? $_POST['data'] : array();
			$pool = is_string($data['zpool'] ?? null) ? $data['zpool'] : '';
			$name = is_string($data['name'] ?? null) ? trim($data['name'], '/') : '';
			$dataset = $pool.($name !== '' ? '/'.$name : '');
			// Anything but a clear yes or no used to create the dataset unencrypted.
			$valid_choice = in_array($data['encryption'] ?? 'no', array('yes', 'no'), true);
			zfsmReturnAnswer($valid_choice ? createDataset($dataset, cleanZFSCreateDatasetParams($data)) : zfsmFailure($dataset, 'Encryption must be Yes or No.'), 'ZFS Dataset Creation', 'Dataset created successfully', 'Unable to create dataset');
			break;

		case 'editdatasetproperty':
			zfsmReturnAnswer(setDatasetProperty(zfsmPostString('zdataset'), zfsmPostString('property'), zfsmPostString('value')), 'ZFS Dataset Edit', 'Dataset edited successfully', 'Unable to edit dataset');
			break;

		case 'getdatasetproperties':
			echo json_encode(getAllDatasetProperties(zfsmPostString('zdataset'), $zfsm_cfg['znapzend_data'] ?? 0), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			break;

		case 'previewdestroy':
			$target = zfsmPostString('target');
			echo json_encode(zfsmWithReadLimit(function() use ($target) { return zfsmDestroyPreview($target, zfsmPostString('force')); }), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			break;

		case 'previewrollback':
			$snapshot = zfsmPostString('snapshot');
			echo json_encode(zfsmWithReadLimit(function() use ($snapshot) { return zfsmRollbackPreview($snapshot); }), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			break;

		case 'previewconvert':
			$directory = zfsmPostString('directory');
			$pool = zfsmPostString('pool');
			echo json_encode(zfsmWithReadLimit(function() use ($directory, $pool) { return zfsmConvertPreview($directory, $pool); }), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			break;

		case 'diffsnapshot':
			$snapshot = zfsmPostString('snapshot');
			echo json_encode(zfsmWithReadLimit(function() use ($snapshot) { return zfsmSnapshotDiff($snapshot); }), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			break;

		case 'validateexclusion':
			echo json_encode(array('valid' => zfsmExclusionIsValid(zfsmPostString('pattern'))));
			break;

		case 'adddirectortlisting':
			zfsmReturnAnswer(addToDirectoryListing(zfsmPostString('zdataset')), 'Directory Listing', 'Dataset added successfully', 'Unable to add dataset');
			break;

		case 'removedirectorylisting':
			zfsmReturnAnswer(removeFromDirectoryListing(zfsmPostString('zdataset')), 'Directory Listing', 'Dataset removed successfully', 'Unable to remove dataset');
			break;

		case 'renamedataset':
			zfsmReturnAnswer(renameDataset(zfsmPostString('zdataset'), zfsmPostString('newname'), zfsmPostString('force')), 'ZFS Dataset Rename', 'Dataset renamed successfully', 'Unable to rename dataset');
			break;

		case 'destroydataset':
			$dataset = zfsmPostString('zdataset');
			$blocked = zfsmRequireDestructiveMode($zfsm_cfg, $dataset);
			zfsmReturnAnswer($blocked ?? zfsmDestroyReviewed($dataset, zfsmPostString('force'), zfsmPostString('token')), 'ZFS Dataset Destroy', 'Dataset destroyed successfully', 'Unable to destroy dataset');
			break;

		case 'lockdataset':
			zfsmReturnAnswer(zfsmExplainBusy(lockDataset(zfsmPostString('zdataset')), zfsmPostString('zdataset')), 'ZFS Dataset Lock', 'Dataset locked successfully', 'Unable to lock dataset');
			break;

		case 'unlockdataset':
			zfsmReturnAnswer(unlockDataset(zfsmPostString('zdataset'), zfsmPostString('passphrase')), 'ZFS Dataset Unlock', 'Dataset unlocked successfully', 'Unable to unlock dataset');
			break;

		case 'promotedataset':
			zfsmReturnAnswer(promoteDataset(zfsmPostString('zdataset')), 'ZFS Dataset Promote', 'Dataset promoted successfully', 'Unable to promote dataset');
			break;

		case 'movedirectory':
			zfsmReturnAnswer(moveDirectory(zfsmPostString('directory'), zfsmPostString('newname')), 'ZFS Directory Move', 'Directory moved successfully', 'Unable to move directory');
			break;

		case 'previewrewrite':
			$zdataset = zfsmPostString('zdataset');
			echo json_encode(zfsmWithReadLimit(function() use ($zdataset) { return zfsmRewritePreview($zdataset); }), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			break;

		case 'rewritedataset':
			zfsmReturnAnswer(zfsmStartRewrite(zfsmPostString('zdataset'), zfsmPostString('physical') === '1'), 'ZFS Dataset Rewrite', 'Rewrite started', 'Unable to start the rewrite');
			break;

		case 'convertdirectory':
			$directory = zfsmPostString('directory');
			$blocked = zfsmRequireDestructiveMode($zfsm_cfg, $directory);
			zfsmReturnAnswer($blocked ?? convertDirectory($directory, zfsmPostString('pool'), zfsmPostString('clone', '1') !== '0'), 'ZFS Directory Convert', 'Directory converted successfully', 'Unable to convert directory', true);
			break;

		case 'deletedirectory':
			$directory = zfsmPostString('directory');
			$blocked = zfsmRequireDestructiveMode($zfsm_cfg, $directory);
			zfsmReturnAnswer($blocked ?? deleteDirectory($directory, zfsmPostString('force')), 'ZFS Directory Delete', 'Directory deleted successfully', 'Unable to delete directory');
			break;

		case 'rollbacksnapshot':
			$snapshot = zfsmPostString('snapshot');
			$blocked = zfsmPostString('destroy_newer') === '1' ? zfsmRequireDestructiveMode($zfsm_cfg, $snapshot) : null;
			zfsmReturnAnswer($blocked ?? zfsmRollbackReviewed($snapshot, zfsmPostString('destroy_newer'), zfsmPostString('token')), 'ZFS Snapshot Rollback', 'Snapshot rolled back successfully', 'Unable to rollback snapshot');
			break;

		case 'renamesnapshot':
			$snapshot = zfsmPostString('snapshot');
			$pool = explode('/', explode('@', $snapshot, 2)[0], 2)[0];
			zfsmReturnAnswer(renameDatasetSnapshot($pool, $snapshot, zfsmPostString('newname')), 'ZFS Snapshot Rename', 'Snapshot renamed successfully', 'Unable to rename snapshot');
			break;

		case 'holdsnapshot':
			zfsmReturnAnswer(holdDatasetSnapshot(zfsmPostString('snapshot')), 'ZFS Snapshot Reference', 'Snapshot reference added successfully', 'Unable to add reference');
			break;

		case 'releasesnapshot':
			zfsmReturnAnswer(releaseDatasetSnapshot(zfsmPostString('snapshot')), 'ZFS Snapshot Release', 'Snapshot reference removed successfully', 'Unable to remove reference');
			break;

		case 'clonesnapshot':
			zfsmReturnAnswer(cloneDatasetSnapshot(zfsmPostString('snapshot'), zfsmPostString('clone')), 'ZFS Snapshot Clone', 'Snapshot cloned successfully', 'Unable to clone snapshot');
			break;

		case 'destroysnapshot':
			$snapshot = zfsmPostString('snapshot');
			$blocked = zfsmPostString('recursive') === '1' ? zfsmRequireDestructiveMode($zfsm_cfg, $snapshot) : null;
			zfsmReturnAnswer($blocked ?? zfsmDeleteSnapshotsReviewed($snapshot, zfsmPostString('recursive'), zfsmPostString('token')), 'ZFS Snapshot Destroy', 'Snapshot destroyed successfully', 'Unable to destroy snapshot');
			break;

		case 'snapshotdataset':
			$snapshot = zfsmNewSnapshotName($zfsm_cfg, time());
			zfsmReturnAnswer(createDatasetSnapshot(zfsmPostString('zdataset'), $snapshot, zfsmPostString('recursive')), 'ZFS Snapshot Create', 'Snapshot created successfully', 'Unable to create snapshot');
			break;

		default:
			zfsmReturnAnswer(zfsmFailure('request', 'Unknown or missing command'), 'Request', '', '');
	}
} catch (Throwable $error) {
	zfsmLog('ZFS Master NG backend exception: '.$error->getMessage());
	zfsmReturnAnswer(zfsmFailure('request', $error->getMessage()), 'Request', '', '');
}

?>
