<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeInterface;
use Nette\Http\IResponse;

/** Keeps status and headers in memory: header() is not observable under the CLI. */
final class RecordingHttpResponse implements IResponse
{
	/** Set by the container like on Nette\Http\Response. */
	public string $cookieDomain = '';

	public string $cookiePath = '/';

	public bool $cookieSecure = false;

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


	public function setCookie(
		string $name,
		string $value,
		string|int|DateTimeInterface|null $expire,
		?string $path = null,
		?string $domain = null,
		?bool $secure = null,
		?bool $httpOnly = null,
	): static {
		return $this->addHeader('Set-Cookie', $name . '=' . $value);
	}


	public function deleteCookie(string $name, ?string $path = null, ?string $domain = null, ?bool $secure = null): void
	{
		$this->addHeader('Set-Cookie', $name . '=');
	}
}
