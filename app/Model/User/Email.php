<?php

declare(strict_types=1);

namespace App\Model\User;

use Nette\Utils\Strings;
use Nette\Utils\Validators;

final class Email
{
	public const MAX_LENGTH = 254;

	/**
	 * Deviation characters of UTS #46 mapped the transitional way, as the browser (input type=email) sends them.
	 * A nontransitional browser would send another ASCII form for ß/ς domains (known limitation).
	 */
	private const TRANSITIONAL = ['ß' => 'ss', 'ς' => 'σ', "\u{200C}" => '', "\u{200D}" => ''];


	/** Trims, lowercases and converts a non-ASCII domain to punycode; never throws (unconvertible domain kept, see isValid()). */
	public static function normalize(string $email): string
	{
		$email = mb_strtolower(Strings::trim($email));
		$at = strrpos($email, '@');
		if ($at === false) {
			return $email;
		}

		$domain = substr($email, $at + 1);
		if ($domain === '' || preg_match('~^[\x00-\x7F]*$~', $domain) === 1) {
			return $email;
		}

		$ascii = idn_to_ascii(strtr($domain, self::TRANSITIONAL), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

		return $ascii === false ? $email : substr($email, 0, $at + 1) . $ascii;
	}


	/** For a normalized address: ASCII only (the browser sends no other), well-formed, at most 254 bytes. */
	public static function isValid(string $email): bool
	{
		return $email !== ''
			&& strlen($email) <= self::MAX_LENGTH
			&& preg_match('~^[\x21-\x7E]+$~', $email) === 1
			&& Validators::isEmail($email);
	}
}
