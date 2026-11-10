<?php
$plugin = "zfs.master";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$urlzmadmin = "/plugins/".$plugin."/backend/ZFSMAdmin.php";

require_once $docroot."/webGui/include/Helpers.php";
require_once $docroot."/plugins/".$plugin."/include/ZFSMBase.php";
require_once $docroot."/plugins/".$plugin."/include/ZFSMHelpers.php";
require_once $docroot."/plugins/".$plugin."/backend/ZFSMOperations.php";

$zpool = $_GET['zpool'] ?? '';
$zdataset = $_GET['zdataset'] ?? '';
$config = loadConfig(parse_plugin_cfg($plugin, true));
$exclusion = (string)($config['dataset_exclusion'] ?? '');
$with_children = ($_GET['children'] ?? '') === '1';
// A stuck zfs mustn't hold the popup open: its reads share one time limit, and a read that runs out counts as a failed read.
list($snapshot_result, $children_found, $snapshot_access) = zfsmWithDeadline($GLOBALS['zfsm_popup_read_seconds'] ?? 45, function() use ($zpool, $zdataset, $with_children, $exclusion) {
	$timed = function($read, $failed) { try { return $read(); } catch (RuntimeException $error) { return $failed; } };
	$result = $timed(function() use ($zpool, $zdataset, $with_children, $exclusion) { return getDatasetSnapshotsResult($zpool, $zdataset, $with_children, $exclusion); },
		array('ok' => false, 'snapshots' => array(), 'error' => 'Listing the snapshots took too long'));
	$children = $with_children || $timed(function() use ($zdataset, $exclusion) { return zfsmHasChildDatasets($zdataset, $exclusion); }, false);
	// Browse and changes for each dataset with a row: one read for a dataset and its children, else the dataset's own.
	$access = !$result['snapshots'] ? array() : $timed(function() use ($zpool, $zdataset, $with_children) {
		return $with_children ? zfsmSnapshotAccessTree($zdataset) : array($zdataset => zfsmSnapshotAccess($zpool, $zdataset));
	}, null);
	return array($result, $children, $access);
});
$snapshots = $snapshot_result['snapshots'];
$snapshot_error = $snapshot_result['error'];
$destructive_mode = (int)($config['destructive_mode'] ?? 0) === 1;
// Shown while ticked even if the children are gone, so it can be unticked.
$offer_children = $children_found;
// A failed read leaves the icons off and says so (zfsm-access-unread), rather than hiding them without a word.
$access_unread = $snapshot_access === null || !empty($snapshot_access[$zdataset]['unread']);
$snapshot_access = $snapshot_access ?? array();

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">


<style type="text/css">
  .zfsm-snaps{max-width:1120px;margin:18px auto;padding:0 18px}
  .zfsm-count{margin-left:auto;font-weight:normal;opacity:.7}
  .zfsm-section>.zfsm-snap-table{padding:0}
  .zfsm-snap-table{width:100%;border-collapse:collapse;margin:0}
  .zfsm-snap-table th{text-align:left;font-weight:600;font-size:.85em;text-transform:uppercase;letter-spacing:.06em;opacity:.75;padding:9px 12px;border-bottom:1px solid rgba(128,128,128,.3);white-space:nowrap}
  .zfsm-snap-table td{padding:9px 12px;border-bottom:1px solid rgba(128,128,128,.15);white-space:nowrap}
  .zfsm-snap-table tbody tr:last-child td{border-bottom:none}
  .zfsm-snap-table tbody tr:hover{background:rgba(128,128,128,.08)}
  .zfsm-snap-table th:first-child,.zfsm-snap-table td:first-child{width:28px}
  .zfsm-snap-table .num{text-align:right}
  .zfsm-snap-name{font-family:monospace}
  .zfsm-snap-name .zfsm-ds{opacity:.55}
  .zfsm-badge{display:inline-block;margin-left:8px;padding:0 6px;border-radius:3px;font-size:.8em;border:1px solid rgba(255,140,47,.6);color:#ff8c2f}
  .zfsm-actions{display:flex;gap:14px}
  .zfsm-actions a{cursor:pointer;opacity:.8}
  .zfsm-actions a:hover{opacity:1}
  .zfsm-actions .zfsm-rollback{color:#ff8c2f}
  .zfsm-message td{text-align:center;padding:18px 12px;opacity:.75}
  .zfsm-message.zfsm-error td{color:#e22828;opacity:1}
  .zfsm-snap-tools .zfsm-error{color:#e22828;opacity:1}
  .zfsm-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:4px 2px}
  .zfsm-status{opacity:.75}
  .zfsm-snap-tools{display:flex;align-items:center;flex-wrap:wrap;gap:8px 18px;padding:10px 12px}
  .zfsm-snap-tools input[type=text]{width:16em;margin:0}
  .zfsm-hidden{display:none}
  .zfsm-snap-table .tablesorter-header:not(.sorter-false){cursor:pointer}
  .zfsm-snap-table .tablesorter-header-inner{display:inline}
  .zfsm-snap-table .tablesorter-headerAsc:after,.zfsm-snap-table .tablesorter-headerDesc:after{font-family:FontAwesome;margin-left:6px}
  .zfsm-snap-table .tablesorter-headerAsc:after{content:"\f0de"}
  .zfsm-snap-table .tablesorter-headerDesc:after{content:"\f0dd"}
</style>

<script src="<?php autov('/webGui/javascript/dynamix.js'); ?>"></script>
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/default-fonts.css'); ?>">
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/font-awesome.css'); ?>">
<?php foreach (zfsmPopupStylesheets($docroot) as $sheet): ?>
<link type="text/css" rel="stylesheet" href="<?php autov($sheet); ?>">
<?php endforeach; ?>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/zfs.master/styles/zfsm-forms.css'); ?>">
<script type="text/javascript" src="<?php autov('/plugins/zfs.master/assets/sweetalert2.all.min.js'); ?>"></script>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/zfs.master/assets/sweetalert2.min.css'); ?>">
<script src="<?php autov('/plugins/zfs.master/frontend/ZFSMDialogs.js'); ?>"></script>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/zfs.master/styles/zfsm-dialogs.css'); ?>">

</head>

<body>
	<?php
	$total_used = array_sum(array_map(function($snap) { return (int)($snap['used'] ?? 0); }, $snapshots));
	$dataset_count = count(array_unique(array_map(function($snap) { return explode('@', (string)($snap['name'] ?? ''), 2)[0]; }, $snapshots)));
	$in_datasets = $with_children ? ' in '.$dataset_count.' dataset'.($dataset_count === 1 ? '' : 's') : '';
	$count_text = $snapshot_error !== '' ? 'Snapshots could not be listed' : count($snapshots).' snapshot'.(count($snapshots) === 1 ? '' : 's').$in_datasets.' · '.fromBytesToString($total_used).' used';
	$has_automatic = (bool)array_filter(array_column($snapshots, 'name'), 'zfsmIsAutomaticSnapshot');
	$filter_hint = $with_children ? 'Filter by dataset or name' : 'Filter by name';
	?>
	<div class="zfsm-snaps">
	<details class="zfsm-section" open>
	<summary><?=zfsmHtml($zdataset)?><span class="zfsm-count"><?=zfsmHtml($count_text)?></span></summary>
	<?php if ($snapshots || $offer_children): ?>
	<div class="zfsm-snap-tools">
		<?php if ($snapshots): ?><input type="text" id="zfsm-snap-filter" placeholder="<?php echo $filter_hint; ?>" aria-label="<?php echo $filter_hint; ?>" autocomplete="off" spellcheck="false"><?php endif; ?>
		<?php if ($offer_children): ?><label><input type="checkbox" id="zfsm-children"<?php echo $with_children ? ' checked' : ''; ?>> Include child datasets</label><?php endif; ?>
		<?php if ($has_automatic): ?><label><input type="checkbox" id="zfsm-hide-auto"> Hide Sanoid and Syncoid snapshots</label><?php endif; ?>
		<span id="zfsm-snap-shown" class="zfsm-status"></span>
		<?php if ($access_unread): ?><span id="zfsm-access-unread" class="zfsm-status zfsm-error">Browse and changes are off: the mount details could not be read.</span><?php endif; ?>
	</div>
	<?php endif; ?>
	<table class="zfsm-snap-table">
	<thead>
		<tr>
		<th><input type="checkbox" id="checkAll" title="Select all"></th>
		<th>Snapshot</th>
		<th class="num">Used</th>
		<th class="num">Refer</th>
		<th class="num">Written</th>
		<th class="num">Holds</th>
		<th>Created</th>
		<th>Actions</th>
		</tr>
	</thead>
	<tbody class="tablesorter-infoOnly">
		<?php
		if ($snapshot_error !== ''):
			echo '<tr class="zfsm-message zfsm-error"><td colspan="8">'.zfsmHtml($snapshot_error).'</td></tr>';
		elseif (!$snapshots):
			echo '<tr class="zfsm-message"><td colspan="8">'.($with_children ? 'Neither this dataset nor its child datasets have snapshots.' : 'This dataset has no snapshots.').'</td></tr>';
		endif;
		?>
	</tbody>
	<tbody id="zpools">
		<?php
		$sort_value = function($value) { return ' data-text="'.zfsmHtml((string)$value).'"'; };
		foreach ($snapshots as $snap):
			$snapshot_name = (string)($snap['name'] ?? '');
			list($snapshot_dataset, $snapshot_short) = array_pad(explode('@', $snapshot_name, 2), 2, '');
			$snapshot_html = zfsmHtml($snapshot_name);
			$snapshot_js = zfsmHtml(zfsmJson($snapshot_name));
			$holds = (int)($snap['userrefs'] ?? 0);
			// With child datasets the filter and sort go by the whole name, so a dataset's rows can be picked out.
			$listed_name = $with_children ? $snapshot_name : $snapshot_short;
			// A volume has no mount, so the read has no entry for it and its rows offer neither.
			$access = $snapshot_access[$snapshot_dataset] ?? zfsmSnapshotAccessFrom('', null);
			echo '<tr id="snap-'.sha1($snapshot_name).'" data-snapshot="'.zfsmHtml($listed_name).'" data-automatic="'.(zfsmIsAutomaticSnapshot($snapshot_name) ? '1' : '0').'">';
			echo '<td><input class="snapl-check" type="checkbox" value="'.$snapshot_html.'"></td>';
			echo '<td class="zfsm-snap-name"'.$sort_value($listed_name).'><span class="zfsm-ds">'.zfsmHtml($snapshot_dataset).'@</span>'.zfsmHtml($snapshot_short);
				if ($holds > 0) echo '<span class="zfsm-badge">held</span>';
				if (($snap['defer_destroy'] ?? '') === 'on') echo '<span class="zfsm-badge">deferred</span>';
			echo '</td>';
			echo '<td class="num"'.$sort_value($snap['used']).'>'.fromBytesToString($snap['used']).'</td>';
			echo '<td class="num"'.$sort_value($snap['referenced']).'>'.fromBytesToString($snap['referenced']).'</td>';
			echo '<td class="num"'.$sort_value($snap['written']).'>'.fromBytesToString($snap['written']).'</td>';
			echo '<td class="num"'.$sort_value($holds).'>'.$holds.'</td>';
			echo '<td'.$sort_value($snap['creation']).'>'.zfsmHtml(zfsmTime($snap['creation'])).'</td>';
			echo '<td><span class="zfsm-actions">';
				if ($access['browse'] !== '') echo '<a title="Browse files in this snapshot" href="/Main/Browse?dir='.zfsmHtml(rawurlencode($access['browse'].'/'.$snapshot_short)).'" target="_blank" rel="noopener"><i class="fa fa-folder-open-o"></i></a>';
				if ($access['diff']) echo '<a title="Show changes since this snapshot" role="button" tabindex="0" onclick="diffSnapshot('.$snapshot_js.')"><i class="fa fa-history"></i></a>';
				echo '<a class="zfsm-rollback" title="Roll back to this snapshot" role="button" tabindex="0" onclick="rollbackSnapshot('.$snapshot_js.')"><i class="fa fa-backward"></i></a>';
				echo '<a title="Rename" role="button" tabindex="0" onclick="renameSnapshot('.$snapshot_js.')"><i class="fa fa-font"></i></a>';
				echo '<a title="Hold, so it cannot be deleted" role="button" tabindex="0" onclick="holdSnapshot('.$snapshot_js.')"><i class="fa fa-pause"></i></a>';
				echo '<a title="Release a hold" role="button" tabindex="0" onclick="releaseSnapshot('.$snapshot_js.')"><i class="fa fa-play"></i></a>';
				echo '<a title="Clone to a new dataset" role="button" tabindex="0" onclick="cloneSnapshot('.$snapshot_js.')"><i class="fa fa-clone"></i></a>';
			echo '</span></td>';
			echo '</tr>';
		endforeach;?>
	</tbody>
	</table>
	</details>
	<div class="zfsm-footer">
		<span id="zfs_status" class="zfsm-status"></span>
		<button id="delete-snaps" type="button" disabled>Delete selected</button>
	</div>
	</div>
</body>
</html>

<script src="<?php autov('/plugins/zfs.master/frontend/ZFSMInventory.js'); ?>"></script>
<script src="<?php autov('/plugins/zfs.master/frontend/ZFSMImpact.js'); ?>"></script>
<script src="<?php autov('/plugins/zfs.master/frontend/ZFSMSnapshotList.js'); ?>"></script>
<script src="<?php autov('/plugins/zfs.master/frontend/ZFSMSnapshotDiff.js'); ?>"></script>
<script src="<?php autov('/plugins/zfs.master/frontend/ZFSMKeys.js'); ?>"></script>
<script>
  var zfsm_csrf_token = (window.parent && typeof window.parent.zfsm_csrf_token === 'string') ? window.parent.zfsm_csrf_token : ((typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '');
  installZFSMActionRefresh(function() {
	if (window.parent && typeof window.parent.requestRefresh === 'function') window.parent.requestRefresh();
  });

  function parseJSONResponse(value) {
	return typeof value === 'string' ? JSON.parse(value) : value;
  }

  function escapeHtml(value) {
	return String(value ?? '').replace(/[&<>"']/g, function(char) {
		return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
	});
  }

  // One reviewed request for the ticked snapshots, which must all be one dataset's: zfs gives them that dataset's name.
  function deleteSnapshots() {
	const checked = $('.snapl-check:checked').map(function() { return this.value; }).get();
	const dataset = zfsmTickedDataset();
	if (!checked.length || dataset === null) return;

	const target = dataset+'@'+checked.map(snapshot => snapshot.split('@')[1]).join(',');
	zfsmFetchReview('<?=$urlzmadmin?>', {cmd: 'previewdestroy', target: target, force: 0, 'csrf_token': zfsm_csrf_token}, function(preview) {
		Swal2.fire({
			title: '<strong>Review: Delete Snapshots</strong>',
			icon: preview.can_proceed ? 'warning' : 'error',
			html: zfsmImpactHtml(preview, zfsmImpactSummary(preview, 'deletes')),
			showConfirmButton: preview.can_proceed,
			confirmButtonText: 'Delete',
			showCancelButton: true
		}).then((result) => {
			if (!result.isConfirmed) return;
			// One request for the reviewed list: the backend checks the token and ZFS deletes all or none.
			showResult('Delete Result', $.post('<?=$urlzmadmin?>',{cmd: 'destroysnapshot', 'snapshot': target, 'recursive': 0, 'token': preview.token, 'csrf_token': zfsm_csrf_token}));
		});
	});
  }

  $("#delete-snaps").click(deleteSnapshots);
  zfsmButtonKeys('.zfsm-snap-table [role="button"]');
  zfsmInitSnapshotList();

  function rollbackSnapshot(snapshot) {
	zfsmFetchReview('<?=$urlzmadmin?>', {cmd: 'previewrollback', 'snapshot': snapshot, 'csrf_token': zfsm_csrf_token}, function(preview) {
		const newer = preview.newer || [];
		const destructive = <?=$destructive_mode ? 'true' : 'false'?>;
		const blocked = preview.error || !preview.can_proceed || (newer.length > 0 && !destructive);
		let heading = 'This discards every change made after the snapshot and cannot be undone.';
		if (newer.length) heading += destructive ? ' Rolling back also destroys these newer items:' : ' These newer items block it. Turn on Destructive Mode in ZFS Master NG settings to destroy them as part of the rollback:';
		const options = {
			title: '<strong>Rollback Snapshot<br>'+escapeHtml(snapshot)+'</strong>',
			icon: blocked ? 'error' : 'warning',
			html: zfsmImpactHtml(preview, heading),
			showConfirmButton: !blocked,
			confirmButtonText: "Rollback",
			showCancelButton: true
		};
		Swal2.fire(options).then((result) => {
			if (!result.isConfirmed) return;
			showResult('Rollback Result', $.post('<?=$urlzmadmin?>',{cmd: 'rollbacksnapshot', 'snapshot': snapshot, 'destroy_newer': newer.length ? 1 : 0, 'token': preview.token, 'csrf_token': zfsm_csrf_token}));
		});
	});
  }

  function diffSnapshot(snapshot) {
	zfsmFetchReview('<?=$urlzmadmin?>', {cmd: 'diffsnapshot', snapshot: snapshot, csrf_token: zfsm_csrf_token}, function(diff) {
		Swal2.fire({title: '<strong>Changes since '+escapeHtml(snapshot)+'</strong>', icon: diff.error ? 'error' : undefined, html: zfsmDiffHtml(diff), width: '60em'});
	}, '');
  }

  function renameSnapshot(snapshot) {
	const currentName = snapshot.split('@').slice(1).join('@');
	Swal2.fire({
		title: 'Rename Snapshot',
		input: 'text',
		inputValue: currentName,
		inputAttributes: {maxlength: 255, autocapitalize: 'off', autocorrect: 'off'},
		showCancelButton: true,
		inputValidator: value => value ? undefined : 'Enter a snapshot name'
	}).then((result) => {
		if (!result.isConfirmed) return;
		showResult('Rename Result', $.post('<?=$urlzmadmin?>', {cmd: 'renamesnapshot', snapshot: snapshot, newname: result.value, csrf_token: zfsm_csrf_token}));
	});
  }

  function holdSnapshot(snapshot) {
	showResult('Hold Result', $.post('<?=$urlzmadmin?>',{cmd: 'holdsnapshot', 'snapshot': snapshot, 'csrf_token': zfsm_csrf_token}));
  }

  function releaseSnapshot(snapshot) {
	showResult('Release Result', $.post('<?=$urlzmadmin?>',{cmd: 'releasesnapshot', 'snapshot': snapshot, 'csrf_token': zfsm_csrf_token}));
  }

  function cloneSnapshot(snapshot) {
	Swal2.fire({
		  title: '<strong>Destination Dataset for '+escapeHtml(snapshot)+'</strong>',
		  input: 'text',
		  inputPlaceholder: 'Dataset',
		  inputAttributes: {
			autocapitalize: 'off',
			autocorrect: 'off'
		  },
		  showConfirmButton: true,
		  showCancelButton: true
	  }).then((result) => {
		  if (result.isConfirmed) {
			  showResult('Clone Result', $.post('<?=$urlzmadmin?>',{cmd: 'clonesnapshot', 'snapshot': snapshot, 'clone': result.value, 'csrf_token': zfsm_csrf_token}));
		  }
	  });
  }

  // Every action ends here, then reloads: a failed request or an unreadable answer still says so, since the change may have happened.
  function showResult(title, request) {
	// The list must not reload under a running action, or its result and the Main tab's refresh would be lost.
	$('#zfsm-children').prop('disabled', true);
	const reload = () => window.location.reload();
	const unknown = reason => Swal2.fire({title: title, icon: 'error', text: reason+' The page will reload to show what changed.'}).then(reload);
	request.done(function(data) {
		let answer = null;
		try { answer = parseJSONResponse(data); } catch (error) { answer = null; }
		if (!answer || typeof answer !== 'object') return unknown('The answer could not be read.');
		Swal2.fire({title: title, icon: 'info', html: formatAnswer(answer)}).then(reload);
	}).fail(function() { unknown('The request failed.'); });
  }

  function formatAnswer(answer, indentLevel = 0) {
    const indent = '&emsp;&emsp;'.repeat(indentLevel); 
    let result = '';

    for (const key in answer) {
        if (typeof answer[key] === 'object') {
            result += `${formatAnswer(answer[key], indentLevel + 1)}`;
        } else {
            result += `${indent}${escapeHtml(key)}: ${escapeHtml(answer[key])}<br>`;
        }
    }

    return result;
  }

</script>
