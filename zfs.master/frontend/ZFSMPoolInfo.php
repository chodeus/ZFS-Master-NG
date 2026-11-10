<?php
$plugin = "zfs.master";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

require_once $docroot."/webGui/include/Helpers.php";
require_once $docroot."/plugins/".$plugin."/include/ZFSMBase.php";
require_once $docroot."/plugins/".$plugin."/include/ZFSMHelpers.php";
require_once $docroot."/plugins/".$plugin."/backend/ZFSMOperations.php";

$zpool = (string)($_GET['zpool'] ?? '');
$compatibility = null;
$read_error = 'The pool\'s features could not be read';
// A stuck zpool mustn't hold the popup open: a read that runs out counts as a failed read.
if (!zfsmValidPoolName($zpool)) $read_error = 'Not a pool name';
else try {
	$compatibility = zfsmWithDeadline($GLOBALS['zfsm_popup_read_seconds'] ?? 45, function() use ($zpool) { return zfsmPoolCompatibility($zpool); });
} catch (RuntimeException $error) {
	$read_error = 'Reading the pool\'s features took too long';
}

// What older Unraid can do with a pool of these read-write and read-only floors; '' at 6.12, since older Unraid has no ZFS of its own.
$older_text = function($floor) {
	if ($floor[0] === '6.12') return '';
	if ($floor[1] === $floor[0]) return 'Can\'t import it';
	$by_hand = 'can import it read-only only (zpool import -o readonly=on)';
	return $floor[1] === '6.12' ? ucfirst($by_hand) : 'Unraid '.$floor[1].' and later '.$by_hand.'; older Unraid can\'t import it';
};
$read_write = $older = $after = '';
if ($compatibility !== null && $compatibility['read_write'] === null) {
	$read_write = 'Unknown: '.implode(', ', $compatibility['unknown']).' is newer than this plugin\'s list';
} elseif ($compatibility !== null) {
	$read_write = 'Unraid '.$compatibility['read_write'].' and later';
	$older = $older_text(array($compatibility['read_write'], $compatibility['read_only']));
	$upgraded = $compatibility['after_upgrade'];
	if ($upgraded !== null) {
		$after = 'Unraid '.$upgraded[0].' and later'.($older_text($upgraded) === '' ? '' : '. Older Unraid: '.$older_text($upgraded)).'. Upgrade Pool puts '.implode(' and ', $compatibility['upgrade_in_use']).' in use';
	}
	$set = $compatibility['compatibility'];
	if ($compatibility['upgrade_unread']) $after = 'Unknown: the pool\'s compatibility set ('.$set.') could not be read';
	elseif ($compatibility['upgrade_unknown']) $after = 'Unknown: Upgrade Pool would turn on '.implode(', ', $compatibility['upgrade_unknown']).', which this plugin\'s list doesn\'t know';
	elseif ($upgraded === null && $compatibility['upgrade_limited']) $after = 'No change: the pool\'s compatibility setting ('.$set.') limits what it turns on';
}
$groups = array('in_use' => 'In use: these set the answer above', 'enabled' => 'Enabled, not in use: turning one on can raise the answer', 'off' => 'Off: Unraid\'s Upgrade Pool would turn these on');
$on_upgrade = array('at once' => 'In use as soon as Upgrade Pool runs', 'next import' => 'In use from the first import after Upgrade Pool', 'enabled' => 'Enabled only', '' => 'Unknown');
$html = function($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); };
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
  .zfsm-info{max-width:1120px;margin:18px auto;padding:0 18px}
  .zfsm-count{margin-left:auto;font-weight:normal;opacity:.7}
  /* On a phone a table scrolls sideways on its own, rather than widening the whole popup. */
  .zfsm-table-scroll{overflow-x:auto}
  .zfsm-section>.zfsm-table-scroll,.zfsm-section>.zfsm-all{padding:0}
  .zfsm-subhead{margin:14px 0 4px;font-weight:600}
  .zfsm-all{border-top:1px solid rgba(128,128,128,.3)}
  .zfsm-all>summary{padding:10px 16px;cursor:pointer;font-weight:600}
  .zfsm-all>.zfsm-note{padding:0 16px}
  .zfsm-info-table{width:100%;border-collapse:collapse;margin:0}
  .zfsm-info-table th{text-align:left;font-weight:600;font-size:.85em;text-transform:uppercase;letter-spacing:.06em;opacity:.75;padding:9px 12px;border-bottom:1px solid rgba(128,128,128,.3)}
  /* Unraid's base stylesheet keeps every tbody td on one line; these reasons must wrap to fit the popup. */
  .zfsm-info-table td{padding:9px 12px;border-bottom:1px solid rgba(128,128,128,.15);vertical-align:top;white-space:normal}
  .zfsm-info-table tbody tr:last-child td{border-bottom:none}
  .zfsm-info-table .zfsm-feature,.zfsm-info-table .zfsm-needs{white-space:nowrap}
  .zfsm-info-table .zfsm-feature{font-family:monospace}
  .zfsm-info-table .zfsm-group th{padding-top:18px;font-size:1em;text-transform:none;letter-spacing:0;opacity:1}
  .zfsm-note{margin:10px 0;opacity:.75}
  .zfsm-error{margin:10px 0;color:#e22828}
</style>

<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/default-fonts.css'); ?>">
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/font-awesome.css'); ?>">
<?php foreach (zfsmPopupStylesheets($docroot) as $sheet): ?>
<link type="text/css" rel="stylesheet" href="<?php autov($sheet); ?>">
<?php endforeach; ?>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/zfs.master/styles/zfsm-forms.css'); ?>">

</head>

<body>
	<div class="zfsm-info">
	<details class="zfsm-section" open>
	<summary>Compatibility<span class="zfsm-count">which Unraid can import this pool</span></summary>
	<?php if ($compatibility === null): ?>
	<p class="zfsm-error" id="zfsm-compat-error"><?=$html($read_error)?></p>
	<?php else: ?>
	<div class="zfsm-form">
		<dl><dt>Imports read-write on:</dt><dd id="zfsm-read-write"><?=$html($read_write)?></dd></dl>
		<?php if ($older !== ''): ?><dl><dt>Older Unraid:</dt><dd id="zfsm-older"><?=$html($older)?></dd></dl><?php endif; ?>
		<?php if ($after !== ''): ?><dl><dt>After Upgrade Pool:</dt><dd id="zfsm-after-upgrade"><?=$html($after)?></dd></dl><?php endif; ?>
	</div>
	<?php if ($compatibility['would_raise']): ?>
	<p class="zfsm-subhead">Would need a newer Unraid after:</p>
	<div class="zfsm-table-scroll"><table class="zfsm-info-table" id="zfsm-would-raise"><tbody>
	<?php foreach ($compatibility['would_raise'] as $feature): ?>
		<tr data-raise="<?=$html($feature['name'])?>"><td class="zfsm-needs">Unraid <?=$html($feature['unraid'])?></td><td><?=$html($feature['turned_on_by'].($feature['raises_read_write'] ? '' : ' (then older Unraid can\'t import it even read-only)'))?></td></tr>
	<?php endforeach; ?>
	</tbody></table></div>
	<?php endif; ?>
	<?php $count = count($compatibility['in_use']) + count($compatibility['enabled']) + count($compatibility['off']); ?>
	<?php if ($count): ?>
	<details class="zfsm-all"><summary>All <?=$count?> features</summary>
	<div class="zfsm-table-scroll"><table class="zfsm-info-table" id="zfsm-features">
	<thead><tr><th>Feature</th><th>Needs</th><th>Turned on by</th></tr></thead>
	<?php foreach ($groups as $group => $heading): if (!$compatibility[$group]) continue; ?>
	<tbody data-group="<?=$group?>"><tr class="zfsm-group"><th colspan="3"><?=$html($heading)?></th></tr>
	<?php foreach ($compatibility[$group] as $feature): ?>
		<tr data-feature="<?=$html($feature['name'])?>"><td class="zfsm-feature"><?=$html($feature['name'])?></td><td class="zfsm-needs"><?=$html($feature['unraid'] === null ? 'Not in this plugin\'s list' : 'Unraid '.$feature['unraid'].', older '.($feature['readable'] ? 'read-only' : 'can\'t import'))?></td><td><?=$html($group === 'off' ? $on_upgrade[(string)$feature['on_upgrade']] : ($feature['turned_on_by'] ?? ''))?></td></tr>
	<?php endforeach; ?>
	</tbody>
	<?php endforeach; ?>
	</table></div>
	<p class="zfsm-note">Rewrite Files and Convert warn before they turn on an Enabled feature.</p>
	</details>
	<?php else: ?>
	<p class="zfsm-note" id="zfsm-no-features">This pool has none of the features added since OpenZFS 2.1<?=$compatibility['upgrade_unread'] ? '.' : ', and this Unraid\'s Upgrade Pool adds none.'?></p>
	<?php endif; ?>
	<?php endif; ?>
	</details>
	</div>
</body>
</html>
