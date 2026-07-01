<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Compatibility page.
 */

$res = 0;
if (!$res && file_exists('../../../main.inc.php')) {
	$res = include '../../../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once __DIR__.'/../lib/lmdbrexelpunchout.lib.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutcompatibility.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutsecurity.class.php';

$langs->loadLangs(array('admin', 'lmdbrexelpunchout@lmdbrexelpunchout'));

if (!$user->admin && !LmdbRexelPunchoutSecurity::canConfigure($user)) {
	accessforbidden();
}

llxHeader('', $langs->trans('Compatibility'));
lmdbrexelpunchoutPrintAdminHeader('compatibility');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Environment').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('DetectedPHPVersion').'</td><td>'.dol_escape_htmltag(PHP_VERSION).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DetectedDolibarrVersion').'</td><td>'.(defined('DOL_VERSION') ? dol_escape_htmltag(DOL_VERSION) : '').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MinimumPHPVersion').'</td><td>8.0</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MinimumDolibarrVersion').'</td><td>20.0</td></tr>';
print '</table>';

print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Code').'</th>';
print '<th>'.$langs->trans('Label').'</th>';
print '<th>'.$langs->trans('Description').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '<th>'.$langs->trans('Reason').'</th>';
print '</tr>';

foreach (LmdbRexelPunchoutCompatibility::getFeatureStatuses() as $code => $feature) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($code).'</td>';
	print '<td>'.$langs->trans($feature['label']).'</td>';
	print '<td>'.$langs->trans($feature['description']).'</td>';
	print '<td>'.($feature['available'] ? '<span class="badge badge-status4">'.$langs->trans('Available').'</span>' : '<span class="badge badge-status8">'.$langs->trans('Unavailable').'</span>').'</td>';
	print '<td>'.($feature['reason'] !== '' ? $langs->trans($feature['reason']) : '').'</td>';
	print '</tr>';
}
print '</table>';

print dol_get_fiche_end();
llxFooter();
