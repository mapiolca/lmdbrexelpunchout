<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Punchout sessions list.
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
require_once __DIR__.'/../class/lmdbrexelpunchoutsecurity.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array('admin', 'orders', 'companies', 'lmdbrexelpunchout@lmdbrexelpunchout'));

if (!$user->admin && !LmdbRexelPunchoutSecurity::canReadSessions($user)) {
	accessforbidden();
}

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
if (!$sortfield) {
	$sortfield = 't.datec';
}
if (!$sortorder) {
	$sortorder = 'DESC';
}
$search_status = GETPOST('search_status', 'alphanohtml');
$form = new Form($db);
$pageUrl = dol_buildpath('/lmdbrexelpunchout/admin/sessions.php', 1);

$param = '';
if ($search_status !== '') {
	$param .= '&search_status='.urlencode($search_status);
}

$sql = 'SELECT t.rowid, t.status, t.datec, t.date_return, t.date_import, t.import_count, t.error_message, c.ref AS order_ref, s.nom AS thirdparty_name, u.login AS user_login';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbrexelpunchout_session AS t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'commande_fournisseur AS c ON c.rowid = t.fk_commandefourn';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = t.fk_soc';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = t.fk_user';
$sql .= ' WHERE t.entity = '.((int) $conf->entity);
if ($search_status !== '') {
	$sql .= " AND t.status = '".$db->escape($search_status)."'";
}
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);

llxHeader('', $langs->trans('LmdbRexelPunchoutSessions'));
lmdbrexelpunchoutPrintAdminHeader('sessions');

print_barre_liste($langs->trans('LmdbRexelPunchoutSessions'), $page, $pageUrl, $param, $sortfield, $sortorder, '', $num, -1, 'technic', 0, '', '', $limit);

print '<form method="GET" action="'.$pageUrl.'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre_filter">';
print '<td></td>';
$statusOptions = array(
	'created' => $langs->trans('LmdbRexelPunchoutStatusCreated'),
	'sent' => $langs->trans('LmdbRexelPunchoutStatusSent'),
	'returned' => $langs->trans('LmdbRexelPunchoutStatusReturned'),
	'imported' => $langs->trans('LmdbRexelPunchoutStatusImported'),
	'expired' => $langs->trans('LmdbRexelPunchoutStatusExpired'),
	'error' => $langs->trans('LmdbRexelPunchoutStatusError'),
);
print '<td>'.$form->selectarray('search_status', $statusOptions, $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td colspan="6" class="right"><input class="button small" type="submit" value="'.$langs->trans('Search').'"> <a class="button small" href="'.$pageUrl.'">'.$langs->trans('Reset').'</a></td>';
print '</tr>';
print '<tr class="liste_titre">';
print_liste_field_titre('Ref', $pageUrl, 'c.ref', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Status', $pageUrl, 't.status', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Company', $pageUrl, 's.nom', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('User', $pageUrl, 'u.login', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('DateCreation', $pageUrl, 't.datec', $param, '', '', $sortfield, $sortorder, 'center');
print_liste_field_titre('LmdbRexelPunchoutReturnedAt', $pageUrl, 't.date_return', $param, '', '', $sortfield, $sortorder, 'center');
print_liste_field_titre('LmdbRexelPunchoutImportedAt', $pageUrl, 't.date_import', $param, '', '', $sortfield, $sortorder, 'center');
print_liste_field_titre('Lines', $pageUrl, 't.import_count', $param, '', '', $sortfield, $sortorder, 'right');
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($obj->order_ref).'</td>';
	print '<td>'.lmdbrexelpunchoutStatusBadge($obj->status).'</td>';
	print '<td>'.dol_escape_htmltag($obj->thirdparty_name).'</td>';
	print '<td>'.dol_escape_htmltag($obj->user_login).'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($obj->datec), 'dayhour').'</td>';
	print '<td class="center">'.($obj->date_return ? dol_print_date($db->jdate($obj->date_return), 'dayhour') : '').'</td>';
	print '<td class="center">'.($obj->date_import ? dol_print_date($db->jdate($obj->date_import), 'dayhour') : '').'</td>';
	print '<td class="right">'.((int) $obj->import_count).'</td>';
	print '</tr>';
	$i++;
}
if ($num == 0) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table>';
print '</form>';

if (function_exists('ajax_combobox')) {
	ajax_combobox('search_status');
}

print dol_get_fiche_end();
llxFooter();

/**
 * Render status badge.
 *
 * @param string $status Status
 * @return string
 */
function lmdbrexelpunchoutStatusBadge($status)
{
	global $langs;

	$classes = array(
		'created' => 'badge-status0',
		'sent' => 'badge-status1',
		'returned' => 'badge-status3',
		'imported' => 'badge-status4',
		'expired' => 'badge-status8',
		'error' => 'badge-status8',
	);
	$labels = array(
		'created' => 'LmdbRexelPunchoutStatusCreated',
		'sent' => 'LmdbRexelPunchoutStatusSent',
		'returned' => 'LmdbRexelPunchoutStatusReturned',
		'imported' => 'LmdbRexelPunchoutStatusImported',
		'expired' => 'LmdbRexelPunchoutStatusExpired',
		'error' => 'LmdbRexelPunchoutStatusError',
	);
	$class = $classes[$status] ?? 'badge-status0';
	return '<span class="badge '.$class.'">'.$langs->trans($labels[$status] ?? 'Unknown').'</span>';
}
