<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Shared helpers for Punchout return endpoints.
 */

require_once __DIR__.'/../lib/lmdbrexelpunchout.lib.php';

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
 * Render final return page and offer authenticated import.
 *
 * @param LmdbRexelPunchoutSession $session Session
 * @return void
 */
function lmdbrexelpunchoutRenderReturnStored($session)
{
	global $langs;

	$orderUrl = DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $session->fk_commandefourn;
	$importUrl = dol_buildpath('/lmdbrexelpunchout/public/import.php', 1).'?id='.(int) $session->id;

	llxHeader('', $langs->trans('LmdbRexelPunchoutReturnTitle'));
	print load_fiche_titre($langs->trans('LmdbRexelPunchoutReturnTitle'), '', 'technic');
	print '<div class="ok">'.$langs->trans('LmdbRexelPunchoutBasketStored').'</div>';
	print '<p><a class="button button-save" href="'.dol_escape_htmltag($importUrl).'">'.$langs->trans('LmdbRexelPunchoutOpenImportPage').'</a></p>';
	print '<p>'.lmdbrexelpunchoutGetReturnToSupplierOrderButton($orderUrl, 'button button-save').'</p>';
	llxFooter();
	exit;
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
