<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\{ClassLikeNode, FunctionLikeNode, MemberNode, NodeList, ParameterNode, StatementNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ConstNode, FunctionNode};


/**
 * A doc comment written as a plain one, which neither reflection nor the tools read. An annotation in a block
 * comment, `/* @var *\/` or `/**@var *\/` without the blank, is turned into a doc comment where one means it:
 * any annotation above a declaration, an inline `@var` above another statement. The comment opening the file is
 * its header and is left. PHP gives a doc comment to the next declaration even over the statements between, so
 * the fix is risky where a declaration would take it. A plain comment right above a property is reported; one
 * separated by a blank line documents nothing, one below a doc comment only adds to it, and one above properties
 * that follow one another without a blank line heads the group.
 */
#[RuleInfo(Stage::Finishing, modifiesComments: true)]
final class NoPlainPhpdocsRule extends NodeRule
{
	private const Annotation = 'phpdoc.annotation.plainComment';
	private const Property = 'phpdoc.property.plainComment';

	private bool $annotation = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Annotation,
				Domain::state('forbidden'),
				'An annotation in a plain comment, `/* @var */` or `/**@var` without the blank, where a doc comment means it: any annotation above a declaration, an inline `@var` above another statement',
			),
			new Decision(
				self::Property,
				Domain::state('forbidden'),
				'A plain comment describing a property, `//` or `/* */` where `/** */` belongs, reported',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->annotation = $values->find(self::Annotation) !== null;
	}


	public function getVisitedNodes(): array
	{
		return [StatementNode::class, MemberNode::class, ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof StatementNode && !$node instanceof MemberNode && !$node instanceof ParameterNode)
			|| ($node instanceof ParameterNode && !$node->promoted)
			|| ($first = $node->getFirstToken()) === null
		) {
			return;
		}

		$claimed = $this->annotation ? $this->reportAnnotations($node, $first, $context) : [];
		if ($node instanceof PropertyNode) {
			$this->reportProperty($node, $first, $claimed, $context);
		}
	}


	/**
	 * Reports the comments above the node holding an annotation and turns them into doc comments.
	 * @return list<Trivia>  the comments reported
	 */
	private function reportAnnotations(Node $node, Token $first, RuleContext $context): array
	{
		$declaration = $node instanceof ClassLikeNode
			|| $node instanceof FunctionNode
			|| $node instanceof ConstNode
			|| $node instanceof MemberNode
			|| $node instanceof ParameterNode;
		$opening = true; // the first comment, which opens the file where no token precedes
		$documented = false; // a doc comment above, or one the fix made, wins over the one the fix would make
		$claimed = [];
		foreach ($first->leadingTrivia as $trivia) {
			if (!$trivia->isComment() || $trivia->inInterpolation) {
				continue;
			} elseif ($trivia->is(Trivia::DocComment)) {
				$documented = true;
			} elseif (
				self::hasAnnotation($trivia->text, $declaration)
				&& !($opening && $first->getPrevious() === null)
			) {
				$claimed[] = $trivia;
				$risky = !$documented && self::isTakenByDeclaration($node);
				if ($context->report(
					$first,
					'The annotation must be written in a doc comment, not a plain comment.',
					self::Annotation,
					trivia: $trivia,
					risk: $risky ? Risk::BehaviorChanges : null,
					fixable: !$documented,
					because: $risky ? 'reflection then reads it as the doc comment of a declaration' : null,
				)) {
					$first->replaceTrivia($trivia, Trivia::fromText(self::toDocComment($trivia->text)));
				}

				$documented = true;
			}

			$opening = false;
		}

		return $claimed;
	}


	/**
	 * Whether a block comment holds a line starting with an annotation a doc comment would mean there, any above
	 * a declaration and an inline `@var` elsewhere; an opening of three stars is a ruler.
	 */
	private static function hasAnnotation(string $comment, bool $declaration): bool
	{
		$tag = $declaration ? '[a-z]' : '(?:(?:phpstan|psalm|phan)-)?var\b';
		return str_starts_with($comment, '/*')
			&& !str_starts_with($comment, '/***')
			&& preg_match('~^[ \t]*(?:/\*\*?|\*)?[ \t]*@' . $tag . '~im', $comment) === 1;
	}


	/**
	 * Whether a declaration would take the doc comment heading the node: the node itself, or one in it or after it
	 * in its list, PHP keeping the doc comment until a declaration takes it.
	 */
	private static function isTakenByDeclaration(Node $node): bool
	{
		$list = $node->parent instanceof NodeList ? $node->parent->getItems() : [$node];
		return array_any(
			array_slice($list, (int) array_search($node, $list, strict: true)),
			fn($item) => self::takesDocComment($item) || $item->find(Node::class, self::takesDocComment(...)) !== [],
		);
	}


	/** Whether reflection reads the doc comment of the node. */
	private static function takesDocComment(Node $node): bool
	{
		return $node instanceof ClassLikeNode
			|| $node instanceof FunctionLikeNode
			|| $node instanceof PropertyNode
			|| $node instanceof ClassConstNode
			|| $node instanceof EnumCaseNode
			|| ($node instanceof ParameterNode && $node->promoted);
	}


	private static function toDocComment(string $comment): string
	{
		$rest = substr($comment, str_starts_with($comment, '/**') ? 3 : 2);
		return '/**' . (ctype_space($rest[0]) ? '' : ' ') . $rest;
	}


	/**
	 * @param list<Trivia>  $claimed
	 */
	private function reportProperty(PropertyNode $node, Token $first, array $claimed, RuleContext $context): void
	{
		$comment = null;
		$documented = false; // a doc comment stands in the run of comments right above
		$lineBreaks = 0;
		foreach ($first->leadingTrivia as $trivia) {
			if ($trivia->isComment()) {
				$documented = ($documented && $lineBreaks < 2) || $trivia->is(Trivia::DocComment);
				$comment = $trivia;
				$lineBreaks = 0;
			} elseif ($trivia->isLineEnding()) {
				$lineBreaks++;
			}
		}

		// a comment above properties following one another on the next lines heads them all, it documents none
		$next = $node->getNextSibling();
		$heads = $next instanceof PropertyNode && $next->getFirstToken()?->countBlankLinesBefore() === 0;
		if (
			$comment !== null
			&& $lineBreaks < 2
			&& !$comment->inInterpolation
			&& !$documented
			&& !$heads
			&& !in_array($comment, $claimed, true)
		) {
			$context->report(
				$node,
				'The property must be documented with a doc comment, not a plain comment.',
				self::Property,
				trivia: $comment,
				fixable: false,
			);
		}
	}
}
