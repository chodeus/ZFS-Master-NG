// Sizes in the units Settings picked: ZFS's (GiB, as zpool prints them) or Unraid's (GB, as its Main page prints them).
// Mirrors zfsmSize in include/ZFSMHelpers.php: tests/fixtures/zfs_sizes.json and unraid_sizes.json check both.
function zfsmSize(bytes, decimals) {
	const config = typeof zfsm_config === 'object' && zfsm_config ? zfsm_config : {};
	const display = typeof zfsm_display === 'object' && zfsm_display ? zfsm_display : {};
	return config.size_units === 'decimal' ? zfsmUnraidSize(bytes, display, decimals) : fromBytesToString(bytes);
}

// ZFS's units, as the Main tab always printed them; also used directly for record size, quota and block size.
function fromBytesToString(bytes) {
	const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB'];
	bytes = Number(bytes);
	bytes = Number.isFinite(bytes) ? Math.max(bytes, 0) : 0;
	const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
	const limitedPow = Math.min(pow, units.length - 1);
	bytes /= Math.pow(1024, limitedPow);
	return (Math.round((bytes + Number.EPSILON) * 10) / 10) + ' ' + units[limitedPow];
}

// Unraid's my_scale (webGui/include/Helpers.php) at base 1000; tests/fixtures/unraid_sizes.json holds its own answers.
function zfsmUnraidSize(bytes, display, decimals) {
	const units = ['', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];
	// ?? as in PHP's zfsmSize: a null setting means Unraid's default, as a missing one does.
	const scale = display.scale ?? -1;
	const number = display.number ?? '.,';
	let value = Math.max(Number(bytes) || 0, 0);
	if (scale == 0 && (typeof decimals !== 'number' || decimals < 0)) return zfsmNumberFormat(value, 0, String(number));
	let base = value ? Math.floor(Math.log(value) / Math.log(1000)) : 0;
	if (scale > 0 && base > scale) base = Number(scale);
	base = Math.min(base, units.length - 1);
	value /= Math.pow(1000, base);
	const places = zfsmUnraidDecimals(value, decimals);
	if (scale < 0 && zfsmRound(value, -1) == 1000) { value = 1; base++; }
	return zfsmNumberFormat(value, places, String(number))+' '+units[base]+'B';
}

// my_scale's decimals: none from 100 up, one from 10, else two unless whole; a negative count means at most that many.
function zfsmUnraidDecimals(value, decimals) {
	if (typeof decimals !== 'number') return value >= 100 ? 0 : (value >= 10 ? 1 : (zfsmRound(value * 100, 0) % 100 === 0 ? 0 : 2));
	if (decimals < 0) return value >= 100 || zfsmRound(value * 10, 0) % 10 === 0 ? 0 : -decimals;
	return decimals;
}

// PHP's round(): half away from zero, after cutting the float noise that makes 1.005 read as 1.00499...
function zfsmRound(value, places) {
	const factor = Math.pow(10, places);
	const scaled = Number((Math.abs(value) * factor).toPrecision(15));
	return Math.sign(value) * Math.floor(scaled + 0.5) / factor;
}

// PHP's number_format with Unraid's separators: the thousands one only from 10,000 up, as my_scale passes it.
function zfsmNumberFormat(value, decimals, number) {
	const [whole, fraction] = zfsmRound(value, decimals).toFixed(decimals).split('.');
	const grouped = value > 9999 ? whole.replace(/\B(?=(\d{3})+(?!\d))/g, number.charAt(1)) : whole;
	return fraction === undefined ? grouped : grouped+number.charAt(0)+fraction;
}
