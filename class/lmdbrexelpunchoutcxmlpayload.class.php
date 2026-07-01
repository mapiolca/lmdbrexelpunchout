<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Decode returned cXML PunchOut payloads.
 */
class LmdbRexelPunchoutCxmlPayload
{
	/**
	 * Extract cXML payload from POST values or raw input.
	 *
	 * @param array<string,mixed> $post     POST payload
	 * @param string              $rawInput Raw request body fallback
	 * @return string
	 */
	public static function extract($post, $rawInput = '')
	{
		$base64Payload = self::postValueCaseInsensitive($post, 'cXML-base64');
		if ($base64Payload !== '') {
			$decodedPayload = base64_decode($base64Payload, true);
			if ($decodedPayload === false || trim($decodedPayload) === '') {
				throw new RuntimeException('Invalid cXML-base64 payload');
			}

			return $decodedPayload;
		}

		$rawPayload = self::postValueCaseInsensitive($post, 'cXML-urlencoded');
		if ($rawPayload === '') {
			$rawPayload = $rawInput;
		}

		if (trim($rawPayload) === '') {
			throw new RuntimeException('Empty cXML payload');
		}

		return $rawPayload;
	}

	/**
	 * Read a scalar POST value with case-insensitive key matching.
	 *
	 * @param array<string,mixed> $post POST payload
	 * @param string              $name Expected key
	 * @return string
	 */
	private static function postValueCaseInsensitive($post, $name)
	{
		foreach ($post as $key => $value) {
			if (strcasecmp((string) $key, $name) !== 0 || !is_scalar($value)) {
				continue;
			}

			return (string) $value;
		}

		return '';
	}
}
