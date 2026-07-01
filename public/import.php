<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Authenticated Punchout import page.
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

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once __DIR__.'/../lib/lmdbrexelpunchout.lib.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutsession.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutimporter.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutsecurity.class.php';

$langs->loadLangs(array('lmdbrexelpunchout@lmdbrexelpunchout', 'errors', 'orders'));

if (!isModEnabled('lmdbrexelpunchout')) {
	accessforbidden();
}
if (!LmdbRexelPunchoutSecurity::canUsePunchout($user)) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$session = new LmdbRexelPunchoutSession($db);
if ($id <= 0 || $session->fetch($id) <= 0) {
	accessforbidden($langs->trans('LmdbRexelPunchoutSessionNotFound'));
}

if ((int) $session->entity !== (int) $conf->entity) {
	accessforbidden($langs->trans('LmdbRexelPunchoutWrongEntity'));
}
if ((int) $session->fk_user !== (int) $user->id && empty($user->admin)) {
	accessforbidden($langs->trans('LmdbRexelPunchoutSessionUserMismatch'));
}

if ($action === 'import') {
	if (!LmdbRexelPunchoutSecurity::checkToken()) {
		accessforbidden('Bad token');
	}
	if ($session->status !== LmdbRexelPunchoutSession::STATUS_RETURNED) {
		accessforbidden($langs->trans('LmdbRexelPunchoutSessionAlreadyUsed'));
	}

	try {
		$importer = new LmdbRexelPunchoutImporter($db);
		$summary = $importer->importStoredSession($session, $user);

		$message = $langs->trans('LmdbRexelPunchoutImportSuccess', (int) $summary['lines_added'], (int) $summary['products_created'], (int) $summary['supplier_prices_updated']);
		if (!empty($summary['warnings']) && is_array($summary['warnings'])) {
			$message .= '<br>'.dol_escape_htmltag(implode(', ', $summary['warnings']));
		}
		$orderUrl = DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $session->fk_commandefourn;
		setEventMessages($message, null, 'mesgs');
		llxHeader('', $langs->trans('LmdbRexelPunchoutImportBasket'));
		print load_fiche_titre($langs->trans('LmdbRexelPunchoutImportBasket'), '', 'technic');
		print '<div class="ok">'.$message.'</div>';
		lmdbrexelpunchoutPrintReturnToSupplierOrderJavascript($orderUrl, 800);
		print '<p>'.lmdbrexelpunchoutGetReturnToSupplierOrderButton($orderUrl, 'button button-save').'</p>';
		llxFooter();
		exit;
	} catch (Exception $e) {
		$session->setStatus(LmdbRexelPunchoutSession::STATUS_ERROR, $e->getMessage());
		setEventMessages($langs->trans('LmdbRexelPunchoutImportFailed').' '.$e->getMessage(), null, 'errors');
		header('Location: '.DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $session->fk_commandefourn);
		exit;
	}
}

llxHeader('', $langs->trans('LmdbRexelPunchoutImportBasket'));
print load_fiche_titre($langs->trans('LmdbRexelPunchoutImportBasket'), '', 'technic');

if ($session->status !== LmdbRexelPunchoutSession::STATUS_RETURNED) {
	print '<div class="warning">'.$langs->trans('LmdbRexelPunchoutSessionAlreadyUsed').'</div>';
} else {
	$lines = $session->fetchLines();
	print '<form method="POST" action="'.dol_buildpath('/lmdbrexelpunchout/public/import.php', 1).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="import">';
	print '<input type="hidden" name="id" value="'.((int) $session->id).'">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('SupplierRef').'</th>';
	print '<th>'.$langs->trans('Label').'</th>';
	print '<th class="right">'.$langs->trans('Qty').'</th>';
	print '<th>'.$langs->trans('Unit').'</th>';
	print '<th class="right">'.$langs->trans('PriceUHT').'</th>';
	print '<th>'.$langs->trans('Currency').'</th>';
	print '</tr>';
	foreach ($lines as $line) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($line['vendor_ref']).'</td>';
		print '<td>'.dol_escape_htmltag($line['label']).'</td>';
		print '<td class="right">'.price($line['qty']).'</td>';
		print '<td>'.dol_escape_htmltag($line['unit_code']).'</td>';
		print '<td class="right">'.price($line['unit_price_ht']).'</td>';
		print '<td>'.dol_escape_htmltag($line['currency']).'</td>';
		print '</tr>';
	}
	if (empty($lines)) {
		print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
	}
	print '</table>';
	print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('LmdbRexelPunchoutImportBasket').'"></div>';
	print '</form>';
}

$orderUrl = DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $session->fk_commandefourn;
lmdbrexelpunchoutPrintReturnToSupplierOrderJavascript();
print '<p>'.lmdbrexelpunchoutGetReturnToSupplierOrderButton($orderUrl, 'button').'</p>';

llxFooter();
