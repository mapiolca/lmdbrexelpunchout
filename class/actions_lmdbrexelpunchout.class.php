<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * \file        class/actions_lmdbrexelpunchout.class.php
 * \ingroup     lmdbrexelpunchout
 * \brief       Hooks for Rexel Punchout.
 */

require_once __DIR__.'/lmdbrexelpunchoutconfig.class.php';
require_once __DIR__.'/lmdbrexelpunchoutsecurity.class.php';

/**
 * Hook class.
 */
class Actionslmdbrexelpunchout
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var array<int,string> */
	public $errors = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints;

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
	 * Add action button on supplier order card.
	 *
	 * @param array<string,mixed> $parameters Parameters
	 * @param object             $object     Current object
	 * @param string             $action     Current action
	 * @param HookManager        $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		$contexts = explode(':', (string) ($parameters['context'] ?? $hookmanager->context ?? ''));
		if (!in_array('ordersuppliercard', $contexts, true)) {
			return 0;
		}

		if (empty($conf->lmdbrexelpunchout->enabled) && !isModEnabled('lmdbrexelpunchout')) {
			return 0;
		}

		if (empty($object->id) || empty($object->socid)) {
			return 0;
		}

		if (!LmdbRexelPunchoutSecurity::canUsePunchout($user)) {
			return 0;
		}

		if (!defined('CommandeFournisseur::STATUS_DRAFT')) {
			return 0;
		}

		if ((int) $object->statut !== CommandeFournisseur::STATUS_DRAFT) {
			return 0;
		}

		if (!empty($object->entity) && (int) $object->entity !== (int) $conf->entity) {
			return 0;
		}

		if ((int) $object->socid !== LmdbRexelPunchoutConfig::getInt('FK_SOC')) {
			return 0;
		}

		if (!LmdbRexelPunchoutConfig::isComplete()) {
			return 0;
		}

		$langs->load('lmdbrexelpunchout@lmdbrexelpunchout');
		$url = dol_buildpath('/lmdbrexelpunchout/public/start.php', 1).'?id='.(int) $object->id.'&token='.urlencode(newToken());
		$mode = LmdbRexelPunchoutConfig::getOpenMode();

		if ($mode === 'popup') {
			print '<a class="butAction" href="'.$url.'" onclick="window.open(this.href, \'lmdbrexelpunchout\', \'width=1200,height=850,scrollbars=yes,resizable=yes\'); return false;">'.$langs->trans('LmdbRexelPunchoutButton').'</a>';
		} elseif ($mode === 'newtab') {
			print '<a class="butAction" target="_blank" rel="noopener" href="'.$url.'">'.$langs->trans('LmdbRexelPunchoutButton').'</a>';
		} elseif ($mode === 'iframe') {
			$this->printPunchoutModalButton($url, (int) $object->id);
		} else {
			print '<a class="butAction" href="'.$url.'">'.$langs->trans('LmdbRexelPunchoutButton').'</a>';
		}

		return 0;
	}

	/**
	 * Print a Dolibarr modal launcher.
	 *
	 * @param string $url     Start URL
	 * @param int    $orderId Supplier order id
	 * @return void
	 */
	private function printPunchoutModalButton($url, $orderId)
	{
		global $langs;

		$modalId = 'lmdbrexelpunchout_modal_'.$orderId;
		$buttonId = 'lmdbrexelpunchout_button_'.$orderId;
		$iframeId = 'lmdbrexelpunchout_iframe_'.$orderId;
		$embedUrl = $url.'&embed=1';
		$fallbackUrl = $url.'&external=1';

		print '<a id="'.$buttonId.'" class="butAction" href="'.dol_escape_htmltag($embedUrl).'">'.$langs->trans('LmdbRexelPunchoutButton').'</a>';
		print '<div id="'.$modalId.'" style="display:none;">';
		print '<iframe id="'.$iframeId.'" src="about:blank" class="centpercent" style="height:72vh;border:1px solid #ddd;"></iframe>';
		print '<div class="opacitymedium marginbottomonly">'.$langs->trans('LmdbRexelPunchoutIframeFallback').'</div>';
		print '<p><a class="button" target="_blank" rel="noopener" href="'.dol_escape_htmltag($fallbackUrl).'">'.$langs->trans('LmdbRexelPunchoutOpenExternal').'</a></p>';
		print '</div>';

		print '<script>';
		print 'jQuery(function($){';
		print 'var modal=$('.json_encode('#'.$modalId).');';
		print 'var frame=$('.json_encode('#'.$iframeId).');';
		print 'var button=$('.json_encode('#'.$buttonId).');';
		print 'var fallbackUrl='.json_encode($fallbackUrl).';';
		print 'if(!$.fn.dialog){button.on("click",function(e){e.preventDefault();window.open(fallbackUrl,"lmdbrexelpunchout","width=1200,height=850,scrollbars=yes,resizable=yes");});return;}';
		print 'modal.dialog({autoOpen:false,modal:true,width:Math.max(320,Math.min($(window).width()-40,1280)),height:Math.max(480,Math.min($(window).height()-40,900)),title:'.json_encode($langs->trans('LmdbRexelPunchoutButton')).',close:function(){frame.attr("src","about:blank");}});';
		print 'button.on("click",function(e){e.preventDefault();frame.attr("src",this.href);modal.dialog("open");});';
		print 'window.lmdbrexelpunchoutCloseModal=function(url){modal.dialog("close");window.location.href=url;};';
		print '});';
		print '</script>';
	}
}
