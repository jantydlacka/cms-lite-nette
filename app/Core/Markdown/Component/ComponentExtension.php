<?php

declare(strict_types=1);

namespace App\Core\Markdown\Component;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Parses component markers into ComponentBlock nodes. They render to nothing so far.
 */
final class ComponentExtension implements ExtensionInterface, NodeRendererInterface
{
	public function register(EnvironmentBuilderInterface $environment): void
	{
		$environment
			->addBlockStartParser(new ComponentStartParser())
			->addRenderer(ComponentBlock::class, $this);
	}


	public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
	{
		return '';
	}
}
