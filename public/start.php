<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Start Rexel cXML Punchout session.
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once __DIR__.'/../lib/lmdbrexelpunchout.lib.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutconfig.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutsecurity.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutsession.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutcxmlclient.class.php';

$langs->loadLangs(array('lmdbrexelpunchout@lmdbrexelpunchout', 'errors', 'orders'));

if (!isModEnabled('lmdbrexelpunchout')) {
	accessforbidden();
}
if (!LmdbRexelPunchoutSecurity::checkToken()) {
	accessforbidden('Bad token');
}
if (!LmdbRexelPunchoutSecurity::canUsePunchout($user)) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$embed = GETPOSTINT('embed');
$external = GETPOSTINT('external');
$order = new CommandeFournisseur($db);
if ($id <= 0 || $order->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
$order->fetch_thirdparty();

if ((int) $order->entity !== (int) $conf->entity) {
	accessforbidden($langs->trans('LmdbRexelPunchoutWrongEntity'));
}
if ((int) $order->socid !== LmdbRexelPunchoutConfig::getInt('FK_SOC')) {
	accessforbidden($langs->trans('LmdbRexelPunchoutWrongSupplier'));
}
if ((int) $order->statut !== CommandeFournisseur::STATUS_DRAFT) {
	accessforbidden($langs->trans('LmdbRexelPunchoutOrderMustBeDraft'));
}
if (!LmdbRexelPunchoutConfig::isComplete()) {
	accessforbidden($langs->trans('LmdbRexelPunchoutIncompleteConfiguration'));
}

$rawToken = LmdbRexelPunchoutSecurity::generateToken();
$session = new LmdbRexelPunchoutSession($db);
if ($session->createFromOrder($order, $user, $rawToken) <= 0) {
	setEventMessages($session->error, $session->errors, 'errors');
	header('Location: '.DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $order->id);
	exit;
}

$returnUrl = LmdbRexelPunchoutConfig::getReturnUrl($rawToken, (int) $conf->entity);
$session->setStatus(LmdbRexelPunchoutSession::STATUS_SENT);

try {
	$client = new LmdbRexelPunchoutCxmlClient();
	$targetUrl = $client->getStartPageUrl($returnUrl, $rawToken);
	renderLaunchPage($targetUrl, $order->id, $embed, $external);
} catch (Exception $e) {
	$session->setStatus(LmdbRexelPunchoutSession::STATUS_ERROR, $e->getMessage());
	setEventMessages($langs->trans('LmdbRexelPunchoutLaunchFailed').' '.$e->getMessage(), null, 'errors');
	header('Location: '.DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $order->id);
	exit;
}

/**
 * Render launch page.
 *
 * @param string $targetUrl Target URL
 * @param int    $orderId   Supplier order id
 * @param int    $embed     1 when launched in the order modal iframe
 * @param int    $external  1 to force external opening
 * @return void
 */
function renderLaunchPage($targetUrl, $orderId, $embed = 0, $external = 0)
{
	global $langs;

	$mode = $external ? 'popup' : LmdbRexelPunchoutConfig::getOpenMode();

	if ($mode !== 'iframe' || $embed) {
		header('Location: '.$targetUrl);
		exit;
	}

	llxHeader('', $langs->trans('LmdbRexelPunchoutButton'));
	print load_fiche_titre($langs->trans('LmdbRexelPunchoutButton'), '', 'technic');
	print '<iframe src="'.dol_escape_htmltag($targetUrl).'" class="centpercent" style="height:75vh;border:1px solid #ddd;"></iframe>';
	print '<div class="opacitymedium">'.$langs->trans('LmdbRexelPunchoutIframeFallback').'</div>';
	print '<p><a class="button" target="_blank" rel="noopener" href="'.dol_escape_htmltag($targetUrl).'">'.$langs->trans('LmdbRexelPunchoutOpenExternal').'</a></p>';
	$orderUrl = DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $orderId;
	lmdbrexelpunchoutPrintReturnToSupplierOrderJavascript();
	print '<p>'.lmdbrexelpunchoutGetReturnToSupplierOrderButton($orderUrl, 'button').'</p>';
	llxFooter();
	exit;
}
