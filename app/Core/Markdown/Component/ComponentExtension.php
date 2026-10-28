<?php

declare(strict_types=1);

namespace App\Core\Markdown\Component;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use LogicException;

/**
 * Parses component markers into ComponentBlock nodes. CommonMarkRenderer replaces each of them before rendering.
 */
final class ComponentExtension implements ExtensionInterface, NodeRendererInterface
{
	public function register(EnvironmentBuilderInterface $environment): void
	{
		$environment
			->addBlockStartParser(new ComponentStartParser())
			->addRenderer(ComponentBlock::class, $this)
			->addRenderer(RenderedComponent::class, $this);
	}


	public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
	{
		if ($node instanceof RenderedComponent) {
			return $node->html;
		}

		throw new LogicException('A component marker reached the HTML renderer unreplaced.');
	}
}
