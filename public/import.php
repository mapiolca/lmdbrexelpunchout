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
require_once __DIR__.'/return_common.php';

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
$importer = new LmdbRexelPunchoutImporter($db);
$manualMode = LmdbRexelPunchoutConfig::getProductRefMode() === LmdbRexelPunchoutConfig::PRODUCT_REF_MODE_MANUAL;
$manualLines = array();

if ((int) $session->entity !== (int) $conf->entity) {
	accessforbidden($langs->trans('LmdbRexelPunchoutWrongEntity'));
}
if ((int) $session->fk_user !== (int) $user->id && empty($user->admin)) {
	accessforbidden($langs->trans('LmdbRexelPunchoutSessionUserMismatch'));
}
if ($session->status === LmdbRexelPunchoutSession::STATUS_RETURNED && $manualMode) {
	$manualLines = $importer->getLinesNeedingManualProductRefs($session);
}

if ($action === 'import') {
	if (!LmdbRexelPunchoutSecurity::checkToken()) {
		accessforbidden('Bad token');
	}
	if ($session->status !== LmdbRexelPunchoutSession::STATUS_RETURNED) {
		accessforbidden($langs->trans('LmdbRexelPunchoutSessionAlreadyUsed'));
	}
	if (!$manualMode) {
		accessforbidden($langs->trans('LmdbRexelPunchoutManualProductRefNotExpected'));
	}

	$manualProductRefs = array();
	$manualInputErrors = array();
	foreach ($manualLines as $lineForManualRef) {
		$lineId = (int) $lineForManualRef['rowid'];
		$manualRef = trim(GETPOST('manual_product_ref_'.$lineId, 'alphanohtml'));
		if ($manualRef === '') {
			$manualInputErrors[] = $langs->trans('LmdbRexelPunchoutManualProductRefRequired', $lineForManualRef['vendor_ref']);
		} elseif (preg_match('/[<>]/', $manualRef)) {
			$manualInputErrors[] = $langs->trans('LmdbRexelPunchoutManualProductRefInvalid', $lineForManualRef['vendor_ref']);
		} else {
			$manualProductRefs[$lineId] = $manualRef;
		}
	}

	if (!empty($manualInputErrors)) {
		setEventMessages('', $manualInputErrors, 'errors');
		$action = '';
	}
}

if ($action === 'import') {
	try {
		$summary = $importer->importStoredSession($session, $user, $manualProductRefs);
		lmdbrexelpunchoutRenderImportResult($session, $summary);
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
} elseif (!$manualMode) {
	print '<div class="warning">'.$langs->trans('LmdbRexelPunchoutManualProductRefNotExpected').'</div>';
} elseif (empty($manualLines)) {
	print '<div class="ok">'.$langs->trans('LmdbRexelPunchoutNoManualProductRefNeeded').'</div>';
} else {
	print '<form method="POST" action="'.dol_buildpath('/lmdbrexelpunchout/public/import.php', 1).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="import">';
	print '<input type="hidden" name="id" value="'.((int) $session->id).'">';
	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('LmdbRexelPunchoutManualProductRefIntro').'</div>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('SupplierRef').'</th>';
	print '<th>'.$langs->trans('LmdbRexelPunchoutManualProductRef').'</th>';
	print '<th>'.$langs->trans('Label').'</th>';
	print '<th class="right">'.$langs->trans('Qty').'</th>';
	print '<th>'.$langs->trans('Unit').'</th>';
	print '<th class="right">'.$langs->trans('PriceUHT').'</th>';
	print '<th>'.$langs->trans('Currency').'</th>';
	print '</tr>';
	foreach ($manualLines as $line) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($line['vendor_ref']).'</td>';
		$fieldName = 'manual_product_ref_'.((int) $line['rowid']);
		print '<td><input class="flat minwidth150" name="'.$fieldName.'" value="'.dol_escape_htmltag(GETPOST($fieldName, 'alphanohtml')).'" placeholder="'.dol_escape_htmltag($line['vendor_ref']).'"></td>';
		print '<td>'.dol_escape_htmltag($line['label']).'</td>';
		print '<td class="right">'.price($line['qty']).'</td>';
		print '<td>'.dol_escape_htmltag($line['unit_code']).'</td>';
		print '<td class="right">'.price($line['unit_price_ht']).'</td>';
		print '<td>'.dol_escape_htmltag($line['currency']).'</td>';
		print '</tr>';
	}
	print '</table>';
	print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('LmdbRexelPunchoutImportBasket').'"></div>';
	print '</form>';
}

$orderUrl = DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $session->fk_commandefourn;
lmdbrexelpunchoutPrintReturnToSupplierOrderJavascript();
print '<p>'.lmdbrexelpunchoutGetReturnToSupplierOrderButton($orderUrl, 'button').'</p>';

llxFooter();
