<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Public cXML return endpoint.
 */

if (!defined('NOSESSION')) {
	define('NOSESSION', 1);
}
if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', 1);
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}

$preloadEntity = filter_input(INPUT_GET, 'entity', FILTER_VALIDATE_INT);
if (!$preloadEntity) {
	$preloadEntity = filter_input(INPUT_POST, 'entity', FILTER_VALIDATE_INT);
}
if ($preloadEntity > 0 && !defined('DOLENTITY')) {
	define('DOLENTITY', (int) $preloadEntity);
}

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

require_once __DIR__.'/../class/lmdbrexelpunchoutsession.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutparser.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutcxmlpayload.class.php';
require_once __DIR__.'/return_common.php';

$langs->loadLangs(array('lmdbrexelpunchout@lmdbrexelpunchout', 'errors'));

$token = GETPOST('token', 'alphanohtml');
$entity = GETPOSTINT('entity');

$session = new LmdbRexelPunchoutSession($db);
if ($token === '' || $entity <= 0 || $session->fetchByToken($token, $entity) <= 0) {
	accessforbidden($langs->trans('LmdbRexelPunchoutInvalidToken'));
}
if ($session->protocol !== 'CXML') {
	accessforbidden($langs->trans('LmdbRexelPunchoutProtocolMismatch'));
}
if ($session->isExpired()) {
	$session->setStatus(LmdbRexelPunchoutSession::STATUS_EXPIRED);
	accessforbidden($langs->trans('LmdbRexelPunchoutSessionExpired'));
}
if (!in_array($session->status, array(LmdbRexelPunchoutSession::STATUS_CREATED, LmdbRexelPunchoutSession::STATUS_SENT), true)) {
	accessforbidden($langs->trans('LmdbRexelPunchoutSessionAlreadyUsed'));
}

try {
	$rawPayload = LmdbRexelPunchoutCxmlPayload::extract($_POST, (string) file_get_contents('php://input'));
	$parser = new LmdbRexelPunchoutParser();
	$basket = $parser->parseCxmlBasket($rawPayload);
	lmdbrexelpunchoutStoreReturn($session, 'CXML', $rawPayload, $basket['lines'], $basket);
	lmdbrexelpunchoutRenderReturnStored($session);
} catch (Exception $e) {
	$session->setStatus(LmdbRexelPunchoutSession::STATUS_ERROR, $e->getMessage());
	lmdbrexelpunchoutRenderReturnError($e);
}
