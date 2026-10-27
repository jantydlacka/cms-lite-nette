<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeInterface;
use Nette\Http\IResponse;
use Nette\Http\SameSite;

/** Keeps status and headers in memory: header() is not observable under the CLI. */
final class RecordingHttpResponse implements IResponse
{
	/** Set by the container like on Nette\Http\Response. */
	public string $cookieDomain = '';

	public string $cookiePath = '/';

	public bool $cookieSecure = false;

	/** @var list<array{name: string, value: string, expire: string|int|DateTimeInterface|null, path: ?string, domain: ?string, secure: ?bool, httpOnly: ?bool, sameSite: ?string, partitioned: bool}> */
	private array $cookies = [];

	private int $code = self::S200_OK;

	/** @var array<string, string> lower-cased name => value */
	private array $headers = [];


	public function setCode(int $code, ?string $reason = null): static
	{
		$this->code = $code;

		return $this;
	}


	public function getCode(): int
	{
		return $this->code;
	}


	public function setHeader(string $name, ?string $value): static
	{
		if ($value === null) {
			unset($this->headers[strtolower($name)]);
		} else {
			$this->headers[strtolower($name)] = $value;
		}

		return $this;
	}


	public function addHeader(string $name, string $value): static
	{
		$key = strtolower($name);
		$this->headers[$key] = isset($this->headers[$key]) ? $this->headers[$key] . ', ' . $value : $value;

		return $this;
	}


	public function setContentType(string $type, ?string $charset = null): static
	{
		return $this->setHeader('Content-Type', $type . ($charset !== null ? '; charset=' . $charset : ''));
	}


	public function redirect(string $url, int $code = self::S302_Found): void
	{
		$this->setCode($code);
		$this->setHeader('Location', $url);
	}


	public function setExpiration(?string $expire): static
	{
		return $this->setHeader('Expires', $expire);
	}


	public function isSent(): bool
	{
		return false;
	}


	public function getHeader(string $header): ?string
	{
		return $this->headers[strtolower($header)] ?? null;
	}


	/** @return array<string, string> */
	public function getHeaders(): array
	{
		return $this->headers;
	}


	/**
	 * Cookies in the order they were set, with their attributes; a deletion has an empty value.
	 *
	 * @return list<array{name: string, value: string, expire: string|int|DateTimeInterface|null, path: ?string, domain: ?string, secure: ?bool, httpOnly: ?bool, sameSite: ?string, partitioned: bool}>
	 */
	public function getCookies(): array
	{
		return $this->cookies;
	}


	public function setCookie(
		string $name,
		string $value,
		string|int|DateTimeInterface|null $expire,
		?string $path = null,
		?string $domain = null,
		?bool $secure = null,
		?bool $httpOnly = null,
		SameSite|string|null $sameSite = null,
		bool $partitioned = false,
	): static {
		$sameSite = $sameSite instanceof SameSite ? $sameSite->value : $sameSite;
		$this->cookies[] = compact('name', 'value', 'expire', 'path', 'domain', 'secure', 'httpOnly', 'sameSite', 'partitioned');

		return $this->addHeader('Set-Cookie', $name . '=' . $value);
	}


	public function deleteCookie(string $name, ?string $path = null, ?string $domain = null, ?bool $secure = null): void
	{
		$this->cookies[] = ['name' => $name, 'value' => '', 'expire' => 1, 'path' => $path, 'domain' => $domain, 'secure' => $secure, 'httpOnly' => null, 'sameSite' => null, 'partitioned' => false];
		$this->addHeader('Set-Cookie', $name . '=');
	}
}
