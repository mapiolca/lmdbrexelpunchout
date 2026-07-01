<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbrexelpunchoutsession.class.php';

/**
 * Cron jobs for REXEL Punchout.
 */
class LmdbRexelPunchoutCron
{
	/** @var DoliDB */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Expire sessions and purge old payloads.
	 *
	 * @return int
	 */
	public function runCleanup()
	{
		if (!isModEnabled('lmdbrexelpunchout')) {
			return 0;
		}

		$session = new LmdbRexelPunchoutSession($this->db);
		$expired = $session->expireOldSessions();
		if ($expired < 0) {
			dol_syslog('LmdbRexelPunchoutCron::runCleanup expire error: '.$session->error, LOG_ERR);
			return -1;
		}

		$purged = $session->purgeOldPayloads();
		if ($purged < 0) {
			dol_syslog('LmdbRexelPunchoutCron::runCleanup purge error: '.$session->error, LOG_ERR);
			return -1;
		}

		dol_syslog('LmdbRexelPunchoutCron::runCleanup expired='.$expired.' purged='.$purged, LOG_INFO);

		return 1;
	}
}
