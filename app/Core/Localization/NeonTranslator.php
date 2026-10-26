<?php

declare(strict_types=1);

namespace App\Core\Localization;

use InvalidArgumentException;
use Nette\Localization\Translator;
use Nette\Neon\Neon;
use Stringable;
use Tracy\ILogger;

/**
 * Flat dotted keys in `<langDir>/<locale>.neon`; a value is a string or a plural map `{one, few, other}`.
 * Plural keys take only an int count, no named parameters. A literal `%name%` cannot be escaped in translations yet.
 * Catalog errors (malformed or missing file) throw in non-strict mode too, deliberately; strict only affects missing keys and parameter mismatches.
 * Plural rules are Czech for every locale until another language needs its own.
 */
final class NeonTranslator implements Translator
{
	/** @var array<string, array<string, string|array{one: string, few: string, other: string}>> */
	private array $catalogs = [];


	public function __construct(
		private readonly string $langDir,
		private readonly CurrentLocale $locale,
		private readonly bool $strict,
		private readonly ILogger $logger,
	) {
	}


	/**
	 * First parameter: int = plural count (`%count%`), array = named `%name%` replacements.
	 */
	public function translate(string|Stringable $message, mixed ...$parameters): string
	{
		$key = (string) $message;
		$entry = $this->catalog($this->locale->get())[$key] ?? null;
		if ($entry === null) {
			return $this->missing($key);
		}

		$first = $parameters[0] ?? null;
		if ($first !== null && !is_int($first) && !is_array($first)) {
			throw new InvalidArgumentException("Parameter of '$key' must be an int count or an array of named values, " . get_debug_type($first) . ' given.');
		}

		$plural = is_array($entry);
		if (is_array($entry)) {
			if (!is_int($first)) {
				throw new InvalidArgumentException("Plural key '$key' needs an int count as the first parameter.");
			}

			$text = $entry[PluralRules::cs($first)];
		} else {
			$text = $entry;
		}

		$this->checkPlaceholders($key, $text, $first, count($parameters), $plural);

		return match (true) {
			is_int($first) => str_replace('%count%', (string) $first, $text),
			is_array($first) => $this->replaceNamed($text, $first),
			default => $text,
		};
	}


	private function checkPlaceholders(string $key, string $template, mixed $first, int $argCount, bool $plural): void
	{
		preg_match_all('~%(\w+)%~', $template, $matches);
		$needed = array_unique($matches[1]);
		$provided = match (true) {
			is_int($first) => ['count'],
			is_array($first) => array_map(strval(...), array_keys($first)),
			default => [],
		};

		foreach (array_diff($needed, $provided) as $name) {
			$this->problem("Placeholder %$name% of '$key' was not replaced.");
		}

		// A plural count always selects the form, so it counts as used even without %count%.
		foreach (array_diff($provided, $needed, $plural ? ['count'] : []) as $name) {
			$this->problem("Parameter '$name' is not used by '$key'.");
		}

		if ($argCount > 1) {
			$this->problem("Extra arguments after the first are ignored by '$key'.");
		}
	}


	/**
	 * @param array<mixed> $values
	 */
	private function replaceNamed(string $text, array $values): string
	{
		$replace = [];
		foreach ($values as $name => $value) {
			if (is_scalar($value) || $value instanceof Stringable) {
				$replace['%' . $name . '%'] = (string) $value;
			}
		}

		return strtr($text, $replace);
	}


	private function missing(string $key): string
	{
		$message = "Missing translation '$key' for locale '{$this->locale->get()}'.";
		if ($this->strict) {
			throw new MissingTranslationException($message);
		}

		$this->logger->log($message, ILogger::WARNING);

		return $key;
	}


	private function problem(string $message): void
	{
		if ($this->strict) {
			throw new InvalidArgumentException($message);
		}

		$this->logger->log($message, ILogger::WARNING);
	}


	/**
	 * @return array<string, string|array{one: string, few: string, other: string}>
	 */
	private function catalog(string $locale): array
	{
		if (!isset($this->catalogs[$locale])) {
			$file = $this->langDir . '/' . $locale . '.neon';
			if (!is_file($file)) {
				throw new InvalidArgumentException("Translation file '$file' not found.");
			}

			$this->catalogs[$locale] = $this->validate(Neon::decodeFile($file), $file);
		}

		return $this->catalogs[$locale];
	}


	/**
	 * @return array<string, string|array{one: string, few: string, other: string}>
	 */
	private function validate(mixed $data, string $file): array
	{
		if (!is_array($data)) {
			throw new InvalidArgumentException("Translation file '$file' must be a map.");
		}

		$catalog = [];
		foreach ($data as $key => $value) {
			if (is_int($key)) {
				throw new InvalidArgumentException("Translation file '$file' must be a map with string keys.");
			}

			if (is_string($value)) {
				$catalog[$key] = $value;
				continue;
			}

			if (
				!is_array($value)
				|| count($value) !== 3
				|| array_diff(array_keys($value), ['one', 'few', 'other']) !== []
			) {
				throw new InvalidArgumentException("Translation '$key' in '$file' must be a string or a map with exactly the keys one, few, other.");
			}

			if (!is_string($value['one']) || !is_string($value['few']) || !is_string($value['other'])) {
				throw new InvalidArgumentException("Plural forms of '$key' in '$file' must be strings.");
			}

			$catalog[$key] = ['one' => $value['one'], 'few' => $value['few'], 'other' => $value['other']];
		}

		return $catalog;
	}
}
