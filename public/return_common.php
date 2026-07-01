<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Shared helpers for Punchout return endpoints.
 */

require_once __DIR__.'/../lib/lmdbrexelpunchout.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutconfig.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutimporter.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutsecurity.class.php';

/**
 * Store a returned basket.
 *
 * @param LmdbRexelPunchoutSession        $session       Session
 * @param string                          $protocol      Expected protocol
 * @param string                          $rawPayload    Raw payload
 * @param array<int,array<string,mixed>>  $lines         Normalized lines
 * @param array<string,mixed>|null        $basketPayload Structured basket metadata
 * @return int
 */
function lmdbrexelpunchoutStoreReturn($session, $protocol, $rawPayload, $lines, $basketPayload = null)
{
	global $langs;

	if ($session->protocol !== $protocol) {
		accessforbidden($langs->trans('LmdbRexelPunchoutProtocolMismatch'));
	}
	if ($session->isExpired()) {
		$session->setStatus(LmdbRexelPunchoutSession::STATUS_EXPIRED);
		accessforbidden($langs->trans('LmdbRexelPunchoutSessionExpired'));
	}
	if (!in_array($session->status, array(LmdbRexelPunchoutSession::STATUS_CREATED, LmdbRexelPunchoutSession::STATUS_SENT), true)) {
		accessforbidden($langs->trans('LmdbRexelPunchoutSessionAlreadyUsed'));
	}
	if (empty($lines)) {
		throw new RuntimeException($langs->trans('LmdbRexelPunchoutNoLineReturned'));
	}
	if ($session->storeReturn($rawPayload, $lines, $basketPayload) < 0) {
		throw new RuntimeException($session->error);
	}

	return 1;
}

/**
 * Import a stored return immediately, or redirect to manual reference input.
 *
 * @param LmdbRexelPunchoutSession $session Session
 * @return void
 */
function lmdbrexelpunchoutHandleStoredReturn($session)
{
	global $db;

	$importer = new LmdbRexelPunchoutImporter($db);
	$importUser = lmdbrexelpunchoutLoadImportUser($session);
	if (LmdbRexelPunchoutConfig::getProductRefMode() === LmdbRexelPunchoutConfig::PRODUCT_REF_MODE_MANUAL && count($importer->getLinesNeedingManualProductRefs($session)) > 0) {
		header('Location: '.dol_buildpath('/lmdbrexelpunchout/public/import.php', 1).'?id='.(int) $session->id);
		exit;
	}

	$summary = $importer->importStoredSession($session, $importUser);
	lmdbrexelpunchoutRenderImportResult($session, $summary);
}

/**
 * Load and validate the user stored on the Punchout session.
 *
 * @param LmdbRexelPunchoutSession $session Session
 * @return User
 */
function lmdbrexelpunchoutLoadImportUser($session)
{
	global $db, $langs;

	$importUser = new User($db);
	if ((int) $session->fk_user <= 0 || $importUser->fetch((int) $session->fk_user) <= 0) {
		throw new RuntimeException($langs->trans('LmdbRexelPunchoutImportUserNotFound'));
	}
	if (method_exists($importUser, 'loadRights')) {
		$importUser->loadRights();
	}
	if (!LmdbRexelPunchoutSecurity::canUsePunchout($importUser)) {
		throw new RuntimeException($langs->trans('LmdbRexelPunchoutImportUserNoRight'));
	}

	return $importUser;
}

/**
 * Render an import result page and return to the supplier order.
 *
 * @param LmdbRexelPunchoutSession $session Session
 * @param array<string,mixed>      $summary Import summary
 * @return void
 */
function lmdbrexelpunchoutRenderImportResult($session, $summary)
{
	global $langs;

	$orderUrl = DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $session->fk_commandefourn;
	$message = lmdbrexelpunchoutBuildImportMessage($summary);

	llxHeader('', $langs->trans('LmdbRexelPunchoutImportBasket'));
	print load_fiche_titre($langs->trans('LmdbRexelPunchoutImportBasket'), '', 'technic');
	print '<div class="ok">'.$message.'</div>';
	lmdbrexelpunchoutPrintReturnToSupplierOrderJavascript($orderUrl, 800);
	print '<p>'.lmdbrexelpunchoutGetReturnToSupplierOrderButton($orderUrl, 'button button-save').'</p>';
	llxFooter();
	exit;
}

/**
 * Build the localized import success message.
 *
 * @param array<string,mixed> $summary Import summary
 * @return string
 */
function lmdbrexelpunchoutBuildImportMessage($summary)
{
	global $langs;

	$message = $langs->trans('LmdbRexelPunchoutImportSuccess', (int) $summary['lines_added'], (int) $summary['products_created'], (int) $summary['supplier_prices_updated']);
	if (!empty($summary['warnings']) && is_array($summary['warnings'])) {
		$message .= '<br>'.dol_escape_htmltag(implode(', ', $summary['warnings']));
	}

	return $message;
}

/**
 * Render a return/import error.
 *
 * @param Exception $exception Exception
 * @return void
 */
function lmdbrexelpunchoutRenderReturnError($exception)
{
	global $langs;

	llxHeader('', $langs->trans('LmdbRexelPunchoutReturnTitle'));
	print load_fiche_titre($langs->trans('LmdbRexelPunchoutReturnTitle'), '', 'technic');
	print '<div class="error">'.$langs->trans('LmdbRexelPunchoutReturnFailed').' '.dol_escape_htmltag($exception->getMessage()).'</div>';
	llxFooter();
	exit;
}
