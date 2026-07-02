<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Helpers for structured returned cXML basket metadata.
 */
class LmdbRexelPunchoutBasket
{
	const MIN_UNQUALIFIED_DELTA = 0.01;

	/**
	 * Calculate the positive unqualified delta between the cXML total and item lines.
	 *
	 * @param array<string,mixed> $basket          Structured basket metadata
	 * @param string              $defaultCurrency Default currency
	 * @return array{detected:bool,amount:float,currency:string,total_amount:float,lines_amount:float}
	 */
	public static function calculateUnqualifiedDelta($basket, $defaultCurrency)
	{
		$empty = array(
			'detected' => false,
			'amount' => 0.0,
			'currency' => strtoupper($defaultCurrency),
			'total_amount' => 0.0,
			'lines_amount' => 0.0,
		);

		if (!isset($basket['header']) || !is_array($basket['header'])) {
			return $empty;
		}
		$header = $basket['header'];
		if (!isset($header['total']) || !is_array($header['total'])) {
			return $empty;
		}
		$total = $header['total'];
		$totalDetected = !empty($total['has_value']) || array_key_exists('amount', $total) || array_key_exists('currency', $total);
		if (!$totalDetected || !isset($basket['lines']) || !is_array($basket['lines']) || empty($basket['lines'])) {
			return $empty;
		}

		$totalAmount = (float) ($total['amount'] ?? 0);
		$currency = strtoupper((string) ($total['currency'] ?? $defaultCurrency));
		$linesAmount = self::calculateLinesAmount($basket['lines']);
		$delta = round($totalAmount - $linesAmount, 2);

		return array(
			'detected' => $delta >= self::MIN_UNQUALIFIED_DELTA,
			'amount' => $delta,
			'currency' => $currency,
			'total_amount' => $totalAmount,
			'lines_amount' => $linesAmount,
		);
	}

	/**
	 * Check if the basket already exposes a positive explicit charge.
	 *
	 * @param array<string,mixed> $basket Structured basket metadata
	 * @return bool
	 */
	public static function hasPositiveExplicitCharge($basket)
	{
		if (!isset($basket['header']) || !is_array($basket['header'])) {
			return false;
		}

		foreach (array('shipping', 'tax', 'deee') as $key) {
			$value = $basket['header'][$key] ?? null;
			if (is_array($value) && (float) ($value['amount'] ?? 0) >= self::MIN_UNQUALIFIED_DELTA) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculate line amount from normalized basket lines.
	 *
	 * @param array<int|string,mixed> $lines Basket lines
	 * @return float
	 */
	private static function calculateLinesAmount($lines)
	{
		$amount = 0.0;
		foreach ($lines as $line) {
			if (!is_array($line)) {
				continue;
			}
			$amount += (float) ($line['qty'] ?? 0) * (float) ($line['unit_price_ht'] ?? 0);
		}

		return round($amount, 2);
	}
}
