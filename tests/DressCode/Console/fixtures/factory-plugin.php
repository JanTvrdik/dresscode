<?php declare(strict_types=1);

// a plugin whose rule has a dependency its manifest builds it with, loaded by the configuration in every process

use DressCode\{Decision, Domain, NodeRule, Plugin, PluginManifest, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;


#[RuleInfo(Stage::Structure)]
final class WorkerDependentRule extends NodeRule
{
	public function __construct(
		private readonly string $label,
	) {
	}


	public static function getDecisions(): array
	{
		return [new Decision('workerFactory.variables', Domain::state('forbidden'), 'A variable is reported')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, "A variable, $this->label.");
	}
}


final class WorkerFactoryPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			rules: [WorkerDependentRule::class => fn() => new WorkerDependentRule('built by the factory')],
			section: 'workerFactory',
		);
	}
}
