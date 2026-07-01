<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Security helper for Rexel Punchout.
 */
class LmdbRexelPunchoutSecurity
{
	/**
	 * Check a module right with admin bypass.
	 *
	 * @param User   $user   User
	 * @param string $object Right object
	 * @param string $action Right action
	 * @return bool
	 */
	public static function hasRight($user, $object, $action)
	{
		if (!empty($user->admin)) {
			return true;
		}

		if (method_exists($user, 'hasRight')) {
			return (bool) $user->hasRight('lmdbrexelpunchout', $object, $action);
		}

		return !empty($user->rights->lmdbrexelpunchout->{$object}->{$action});
	}

	/**
	 * Check supplier order edit permission with admin bypass.
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function canEditSupplierOrder($user)
	{
		if (!empty($user->admin)) {
			return true;
		}

		if (method_exists($user, 'hasRight')) {
			return (bool) ($user->hasRight('fournisseur', 'commande', 'creer') || $user->hasRight('supplier_order', 'creer'));
		}

		return !empty($user->rights->fournisseur->commande->creer) || !empty($user->rights->supplier_order->creer);
	}

	/**
	 * Check if user may launch Punchout.
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function canUsePunchout($user)
	{
		return self::hasRight($user, 'punchout', 'use') && self::canEditSupplierOrder($user);
	}

	/**
	 * Check if user may read sessions.
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function canReadSessions($user)
	{
		return self::hasRight($user, 'session', 'read');
	}

	/**
	 * Check if user may configure the module.
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function canConfigure($user)
	{
		return self::hasRight($user, 'setup', 'write');
	}

	/**
	 * Check a Dolibarr CSRF token.
	 *
	 * @return bool
	 */
	public static function checkToken()
	{
		$token = GETPOST('token', 'alphanohtml');
		if ($token === '') {
			return false;
		}

		if (function_exists('currentToken')) {
			return hash_equals((string) currentToken(), (string) $token);
		}

		if (!empty($_SESSION['newtoken'])) {
			return hash_equals((string) $_SESSION['newtoken'], (string) $token);
		}

		return true;
	}

	/**
	 * Generate random public token.
	 *
	 * @return string
	 */
	public static function generateToken()
	{
		return bin2hex(random_bytes(32));
	}

	/**
	 * Hash public token.
	 *
	 * @param string $token Public token
	 * @return string
	 */
	public static function hashToken($token)
	{
		return hash('sha256', $token);
	}

	/**
	 * Normalize supplier reference into a Dolibarr product ref suffix.
	 *
	 * @param string $reference Supplier reference
	 * @return string
	 */
	public static function normalizeSupplierReference($reference)
	{
		$reference = preg_replace('/[^A-Za-z0-9._-]+/', '', $reference);
		return strtoupper((string) $reference);
	}
}
