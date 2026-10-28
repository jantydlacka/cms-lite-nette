<?php

declare(strict_types=1);

namespace App\Model\Content;

use InvalidArgumentException;

final class ComponentRegistry
{
	/** @param array<string, ContentComponent> $components by name */
	public function __construct(private readonly array $components)
	{
		foreach ($components as $name => $component) {
			if ($component->name() !== $name) {
				throw new InvalidArgumentException("Component registered as \"$name\" calls itself \"{$component->name()}\".");
			}
		}
	}


	public function get(string $name): ?ContentComponent
	{
		return $this->components[$name] ?? null;
	}


	/** @return list<string> */
	public function names(): array
	{
		return array_keys($this->components);
	}
}
