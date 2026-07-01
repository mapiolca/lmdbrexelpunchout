<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * \file        lib/lmdbrexelpunchout.lib.php
 * \ingroup     lmdbrexelpunchout
 * \brief       Common helpers for Rexel Punchout.
 */

/**
 * Prepare admin tabs.
 *
 * @return array<int,array<int,string>>
 */
function lmdbrexelpunchoutAdminPrepareHead()
{
	global $langs;

	$langs->loadLangs(array('lmdbrexelpunchout@lmdbrexelpunchout', 'admin'));

	$head = array();
	$h = 0;

	$head[$h][0] = dol_buildpath('/lmdbrexelpunchout/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbrexelpunchout/admin/compatibility.php', 1);
	$head[$h][1] = $langs->trans('Compatibility');
	$head[$h][2] = 'compatibility';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbrexelpunchout/admin/sessions.php', 1);
	$head[$h][1] = $langs->trans('LmdbRexelPunchoutSessions');
	$head[$h][2] = 'sessions';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbrexelpunchout/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	return $head;
}

/**
 * Return link to Dolibarr module list.
 *
 * @return string
 */
function lmdbrexelpunchoutModuleListLink()
{
	global $langs;

	return '<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword='.urlencode('lmdbrexelpunchout').'">'.$langs->trans('BackToModuleList').'</a>';
}

/**
 * Render admin page header.
 *
 * @param string $activeTab Active tab code
 * @return void
 */
function lmdbrexelpunchoutPrintAdminHeader($activeTab)
{
	global $langs;

	$head = lmdbrexelpunchoutAdminPrepareHead();
	print load_fiche_titre($langs->trans('LmdbRexelPunchoutSetup'), lmdbrexelpunchoutModuleListLink(), 'title_setup');
	print dol_get_fiche_head($head, $activeTab, '', -1);

	$helpKeys = array(
		'settings' => 'LmdbRexelPunchoutSettingsPageHelp',
		'compatibility' => 'LmdbRexelPunchoutCompatibilityPageHelp',
		'sessions' => 'LmdbRexelPunchoutSessionsPageHelp',
		'about' => 'LmdbRexelPunchoutAboutPageHelp',
	);
	if (!empty($helpKeys[$activeTab])) {
		print '<span class="opacitymedium">'.$langs->trans($helpKeys[$activeTab]).'</span><br><br>';
	}
}

/**
 * Print browser-side helper used to leave a Punchout modal/popup and reload the supplier order card.
 *
 * @param string $orderUrl    Supplier order URL for automatic return
 * @param int    $autoDelayMs Automatic return delay in milliseconds, negative to disable
 * @return void
 */
function lmdbrexelpunchoutPrintReturnToSupplierOrderJavascript($orderUrl = '', $autoDelayMs = -1)
{
	static $functionPrinted = false;

	if ($functionPrinted && ($orderUrl === '' || $autoDelayMs < 0)) {
		return;
	}

	print '<script>';
	if (!$functionPrinted) {
		print 'function lmdbrexelpunchoutReturnToSupplierOrder(url){';
		print 'if(window.parent&&window.parent!==window&&typeof window.parent.lmdbrexelpunchoutCloseModal==="function"){window.parent.lmdbrexelpunchoutCloseModal(url);return false;}';
		print 'if(window.opener&&!window.opener.closed){window.opener.location.href=url;window.close();return false;}';
		print 'if(window.top&&window.top!==window.self){window.top.location.href=url;return false;}';
		print 'window.location.href=url;return false;';
		print '}';
		$functionPrinted = true;
	}
	if ($orderUrl !== '' && $autoDelayMs >= 0) {
		print 'window.setTimeout(function(){lmdbrexelpunchoutReturnToSupplierOrder('.json_encode($orderUrl).');}, '.((int) $autoDelayMs).');';
	}
	print '</script>';
}

/**
 * Build a supplier order return button that works inside modal iframe, popup, or normal page.
 *
 * @param string $orderUrl Supplier order URL
 * @param string $cssClass Button CSS classes
 * @return string
 */
function lmdbrexelpunchoutGetReturnToSupplierOrderButton($orderUrl, $cssClass = 'button')
{
	global $langs;

	return '<a class="'.dol_escape_htmltag($cssClass).'" href="'.dol_escape_htmltag($orderUrl).'" onclick="return lmdbrexelpunchoutReturnToSupplierOrder(this.href);">'.$langs->trans('BackToSupplierOrder').'</a>';
}

/**
 * Check if a value exists in an associative array.
 *
 * @param array<string,mixed> $array Source array
 * @param string             $key   Key
 * @return string
 */
function lmdbrexelpunchoutArrayString($array, $key)
{
	return isset($array[$key]) ? (string) $array[$key] : '';
}
