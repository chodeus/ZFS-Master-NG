<?php
$plugin = "zfs.master";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$urlzmadmin = "/plugins/".$plugin."/backend/ZFSMAdmin.php";
require_once $docroot."/webGui/include/Helpers.php";
require_once $docroot."/plugins/".$plugin."/include/ZFSMBase.php";
require_once $docroot."/plugins/".$plugin."/include/ZFSMHelpers.php";
require_once $docroot."/plugins/".$plugin."/backend/ZFSMOperations.php";

$zfsm_cfg = loadConfig(parse_plugin_cfg($plugin, true));

$zpool = $_GET['zpool'] ?? '';
$zpool_datasets = getZFSPoolDatasets($zpool, $zfsm_cfg['dataset_exclusion'], $zfsm_cfg['znapzend_data']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<title>ZFS Master NG - Create Dataset</title>

<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">

<script src="<?php autov('/webGui/javascript/dynamix.js'); ?>"></script>
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/default-fonts.css'); ?>">
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/font-awesome.css'); ?>">
<?php foreach (zfsmPopupStylesheets($docroot) as $sheet): ?>
<link type="text/css" rel="stylesheet" href="<?php autov($sheet); ?>">
<?php endforeach; ?>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/zfs.master/styles/zfsm-forms.css'); ?>">
<script src="<?php autov('/plugins/zfs.master/assets/sweetalert2.all.min.js'); ?>"></script>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/zfs.master/assets/sweetalert2.min.css'); ?>">
<style>
  .zfsm-form{max-width:760px;margin:16px auto}
  .zfsm-pool{color:var(--orange-500, #ff8c2f);font-weight:600;min-width:0;overflow-wrap:anywhere}
  .zfsm-footer{display:flex;justify-content:center;padding:12px 0}
</style>

</head>

<body>
	<form id="dataset-form" name="dataset-form" class="zfsm-form" method="POST">
		<input type="hidden" id="zpool" name="zpool" value="<?php echo htmlspecialchars($zpool, ENT_QUOTES, 'UTF-8'); ?>">
		<details class="zfsm-section" open>
			<summary>Basic</summary>
			<div>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Where the dataset goes in the pool, for example appdata/plex.">Name:</dt><dd><span class="zfsm-pool" id="pool"><?php echo htmlspecialchars($zpool, ENT_QUOTES, 'UTF-8'); ?></span>/<input type="text" id="name" class="zfsm-wide" name="name" maxlength="768" pattern="[A-Za-z0-9_.: \/\-]+" placeholder="Path inside the pool" list="zpool-datasets" required></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="No leaves the dataset unmounted.">Mount:</dt><dd><select id="mount" name="mount"><option value="yes" selected>Yes</option><option value="no">No</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Leave empty to mount it under its parent, or enter a full path.">Mount point:</dt><dd><input type="text" id="mountpoint" class="zfsm-wide" name="mountpoint" placeholder="Default"></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Record when files are read. Off saves disk writes.">Access time:</dt><dd><select id="atime" name="atime"><option value="inherit" selected>Inherit</option><option value="off">Off</option><option value="on">On</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="How file names are compared. Can only be set now.">Case sensitivity:</dt><dd><select id="casesensitivity" name="casesensitivity"><option value="inherit" selected>Inherit</option><option value="sensitive">Sensitive</option><option value="insensitive">Insensitive</option><option value="mixed">Mixed</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Compress data as it is written. lz4 is fast; zstd saves more space.">Compression:</dt><dd><select id="compression" name="compression"><option value="inherit" selected>Inherit</option><option value="off">Off</option><option value="lz4">lz4</option><option value="gzip">gzip</option><option value="zstd">zstd</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Most space this dataset and its children can use. Empty means no limit.">Quota:</dt><dd><input type="text" id="quota" name="quota" size="8" inputmode="decimal" pattern="[0-9]+(\.[0-9]+)?" placeholder="None"><select id="quotaunit" name="quotaunit"><option value="M" selected>MiB</option><option value="G">GiB</option><option value="T">TiB</option></select></dd></dl>
			</div>
		</details>
		<details class="zfsm-section">
			<summary>Advanced</summary>
			<div>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Lock the dataset with a passphrase. Can only be set now.">Encryption:</dt><dd><select id="encryption" name="encryption"><option value="yes">Yes</option><option value="no" selected>No</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="At least 8 characters. You need it to unlock the dataset after a reboot.">Passphrase:</dt><dd><input id="passphrase" class="zfsm-wide" name="passphrase" autocomplete="off" type="password" minlength="8" disabled></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="How file attributes are stored. sa is faster on Linux.">Extended attributes:</dt><dd><select id="xattr" name="xattr"><option value="inherit" selected>Inherit</option><option value="sa">sa</option><option value="on">on</option><option value="off">off</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Largest block size. Bigger suits media; smaller suits databases.">Record size:</dt><dd><select id="recordsize" name="recordsize"><option value="inherit" selected>Inherit</option><option value="512">512</option><option value="4K">4KB</option><option value="8K">8KB</option><option value="16K">16KB</option><option value="64K">64KB</option><option value="128K">128KB</option><option value="1M">1MB</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="What ZFS keeps in RAM: everything, only metadata, or nothing.">Primary cache:</dt><dd><select id="primarycache" name="primarycache"><option value="inherit" selected>Inherit</option><option value="all">All</option><option value="metadata">Metadata</option><option value="none">None</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Block all changes to the dataset's files.">Read only:</dt><dd><select id="readonly" name="readonly"><option value="inherit" selected>Inherit</option><option value="off">Off</option><option value="on">On</option></select></dd></dl>
				<dl><dt class="zfsm-tip" tabindex="0" data-tip="Disabled is faster but can lose the last writes in a power cut.">Sync:</dt><dd><select id="sync" name="sync"><option value="inherit" selected>Inherit</option><option value="standard">Standard</option><option value="always">Always</option><option value="disabled">Disabled</option></select></dd></dl>
			</div>
		</details>
		<datalist id="zpool-datasets">
		<?php generatePoolDatasetOptions($zpool_datasets); ?>
		</datalist>
		<div class="zfsm-footer"><button type="submit">Create</button></div>
	</form>
</body>
</html>

<script src="<?php autov('/plugins/zfs.master/frontend/ZFSMInventory.js'); ?>"></script>
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

  function getFormData(formId) {
	let formData = {};
    let inputs = $(formId).serializeArray();
    $.each(inputs, function (i, input) {
        formData[input.name] = input.value;
    });
    
	return formData;
   }
   
  $("#dataset-form").submit(function(e){
        e.preventDefault();
		createDataset();
  });
  
  $("select[name='mount']").change(function() {
	 $("#mountpoint").attr('disabled', $(this).val() == 'no');
  });

  $("select[name='encryption']").change(function() {
	$("#passphrase").prop('disabled', $(this).val() == 'no').prop('required', $(this).val() == 'yes');
  });
   
  function createDataset() {
	let formData = getFormData("#dataset-form");
		
	$.post('<?=$urlzmadmin?>',{cmd: 'createdataset', 'data': formData, 'csrf_token': zfsm_csrf_token}, function(data){
		const answer = parseJSONResponse(data);
		const result = {title: 'Create Result', icon: 'info', html: formatAnswer(answer)};
		// Refresh on every answer: "Created, but ..." is a failure whose dataset exists. The iframe's AJAX event may not run after removal.
		if (typeof top.requestRefresh === 'function') top.requestRefresh();
		// The Main page's dialogs open under this Shadowbox, so a failure shows here and the form stays open.
		if (answer.failed && Object.keys(answer.failed).length > 0) {
			Swal2.fire(Object.assign(result, {icon: 'error'}));
			return;
		}
		top.Swal2.fire(result);
		top.Shadowbox.close();
	});
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
