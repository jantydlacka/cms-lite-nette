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
	 * Named arguments (`translate('k', name: 'Jana')`) are the same as one array of named values; they cannot be mixed with positional ones.
	 * A plural key takes its count from the positional int or from the named `count`.
	 */
	public function translate(string|Stringable $message, mixed ...$parameters): string
	{
		$key = (string) $message;
		$entry = $this->catalog($this->locale->get())[$key] ?? null;
		if ($entry === null) {
			return $this->missing($key);
		}

		$parameters = $this->normalize($key, $parameters);
		$first = $parameters[0] ?? null;
		if (array_key_exists(0, $parameters) && !is_int($first) && !is_array($first)) {
			throw new InvalidArgumentException("Parameter of '$key' must be an int count or an array of named values, " . get_debug_type($first) . ' given.');
		}

		$plural = is_array($entry);
		if (is_array($entry)) {
			$count = is_array($first) ? ($first['count'] ?? null) : $first;
			if (!is_int($count)) {
				throw new InvalidArgumentException("Plural key '$key' needs an int count, positional or as the named argument 'count'.");
			}

			$text = $entry[PluralRules::cs($count)];
		} else {
			$text = $entry;
		}

		$this->checkPlaceholders($key, $text, $first, count($parameters), $plural);

		return match (true) {
			is_int($first) => str_replace('%count%', (string) $first, $text),
			is_array($first) => $this->replaceNamed($key, $text, $first),
			default => $text,
		};
	}


	/**
	 * @param array<int|string, mixed> $parameters
	 * @return array<int, mixed>
	 */
	private function normalize(string $key, array $parameters): array
	{
		$positional = array_values(array_filter($parameters, is_int(...), ARRAY_FILTER_USE_KEY));
		$named = array_filter($parameters, is_string(...), ARRAY_FILTER_USE_KEY);
		if ($named === []) {
			return $positional;
		}

		if ($positional !== []) {
			$this->problem("Named arguments cannot be combined with positional ones in '$key'; the named ones were ignored.");

			return $positional;
		}

		return [$named];
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
			$this->problem("Translation '$key' got an unused parameter '$name'.");
		}

		if ($argCount > 1) {
			$this->problem("Extra arguments after the first are ignored by '$key'.");
		}
	}


	/**
	 * @param array<mixed> $values
	 */
	private function replaceNamed(string $key, string $text, array $values): string
	{
		$replace = [];
		foreach ($values as $name => $value) {
			// Bool is rejected too: an implicit cast would print '1' or nothing.
			if (is_string($value) || is_int($value) || is_float($value) || $value instanceof Stringable) {
				$replace['%' . $name . '%'] = (string) $value;
			} else {
				$this->problem("Value of '$name' for '$key' must be a string, number or Stringable, " . get_debug_type($value) . ' given.');
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
