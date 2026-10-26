<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Support;

final class LanguageCode
{
	/**
	 * Language tag: 2-3 letters, optionally followed by subtags joined by "-" or "_" (e.g. de, pt-BR, zh_Hant).
	 */
	private const PATTERN = '/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*\z/i';

	public static function isValid(string $code): bool
	{
		return preg_match(self::PATTERN, $code) === 1;
	}

	/**
	 * Comparable form of a code, so pt_BR, pt-BR and PT-BR refer to the same language.
	 */
	public static function normalize(string $code): string
	{
		return strtoupper(str_replace('_', '-', $code));
	}
}
