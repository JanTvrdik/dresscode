<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\{Claim, Decision, Gap, Line, Space, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Indentation, Node, Token};
use function count;


/**
 * The shapes of a list spread over lines, on two axes: how its items stand, `perLine` every one on a line of its own or
 * `fill` sharing lines, which accepts them one on a line too, and where its edges stand, `apart` with the items beginning
 * on the line after the opening token and the closing one on a line of its own, `attached` with the items beginning on
 * the line of the opening token and the closing one following the last item, `closingApart` with the items on the line
 * of the opening token and the closing one on a line of its own. Each axis accepts a list of shapes, the first of them
 * the one written, or none, which leaves the axis as it is; a list without a closing token knows the side of the opening
 * one alone.
 * @internal
 */
final readonly class ListLayout
{
	public const PerLine = 'perLine';
	public const Fill = 'fill';
	public const Apart = 'apart';
	public const Attached = 'attached';
	public const ClosingApart = 'closingApart';


	public function __construct(
		/** @var list<string> */
		public array $items,
		/** @var list<string> */
		public array $edges,
		/** the decision the claims of the items are made for, null for the only requirement of the rule */
		public ?string $itemDecision = null,
		/** the decision the claims of the edges are made for, null for the only requirement of the rule */
		public ?string $edgeDecision = null,
		/** whether the edges the place has are kept, which a list spread for its width writes `apart` all the same */
		public bool $edgesKept = false,
		/** the place of `multiline.items`, which says whether its lines are filled again by their width */
		public ?string $place = null,
	) {
	}


	/**
	 * The requirements of a place, `multiline.items.<place>` and, where something opens the list, `multiline.edges.<place>`,
	 * the shapes said in the words of the place: an item, the items, what opens them and what closes them.
	 * @param  list<string>  $notes  of the items
	 * @return list<Decision>
	 */
	public static function createDecisions(
		string $place,
		string $item,
		string $items,
		string $description,
		?string $open = null,
		?string $close = null,
		array $notes = [],
	): array
	{
		$decisions = [new Decision(
			"multiline.items.$place",
			new Words([
				self::PerLine => "every $item on a line of its own",
				self::Fill => "the $items may share lines, one on a line passing too, and one spanning lines stands on lines of its own",
			], tolerance: true, passes: [self::Fill => self::PerLine]),
			$description,
			$notes,
		)];
		if ($open !== null) {
			$decisions[] = new Decision(
				"multiline.edges.$place",
				new Words([
					self::Apart => "the first $item on the line after $open" . ($close === null ? '' : ", $close on a line of its own"),
					self::Attached => "the first $item on the line of $open"
						. ($close === null ? '' : ", $close right after the last one"),
				] + ($close === null
					? []
					: [self::ClosingApart => "the first $item on the line of $open, $close on a line of its own"]), tolerance: true),
				"Where the $items spread over lines begin against $open" . ($close === null ? '' : " and where $close stands"),
			);
		}

		return $decisions;
	}


	/** The shapes of the place as `multiline.items.<place>` and `multiline.edges.<place>` give them, none where kept. */
	public static function fromValues(Values $values, string $place): self
	{
		$items = "multiline.items.$place";
		$edges = "multiline.edges.$place";
		return new self(
			$values->isKept($items) ? [] : $values->get($items)->getWords(),
			$values->has($edges) && !$values->isKept($edges) ? $values->get($edges)->getWords() : [],
			$items,
			$edges,
			$values->has($edges) && $values->isKept($edges),
			$place,
		);
	}


	/**
	 * The shapes a list its author spread over lines stands in, the items and the edges, null for an axis left as it is:
	 * the items `fill` where it is accepted, which accepts any lines but an item spanning lines that shares one with
	 * another item (`findSharedItems()`), `perLine` where that is accepted too or `fill` is not; the edges the shape the
	 * author wrote where it is accepted, else the first accepted shape keeping the side of the opening token the author
	 * wrote, or the first one.
	 * @param  list<Node>  $items
	 * @return array{?string, ?string}
	 */
	public function findShapes(Token $open, ?Token $close, array $items = [], bool $lastShares = false): array
	{
		$edges = self::findEdges($open, $close);
		$attachedStart = $edges === self::Attached || $edges === self::ClosingApart;
		return [
			match (true) {
				$this->items === [] => null,
				!in_array(self::Fill, $this->items, true) => self::PerLine,
				in_array(self::PerLine, $this->items, true) && self::findSharedItems($items, $lastShares) !== [] => self::PerLine,
				default => self::Fill,
			},
			match (true) {
				$this->edges === [] => null,
				in_array($edges, $this->edges, true) => $edges,
				default => array_find(
					$this->edges,
					fn(string $shape) => ($shape === self::Attached || $shape === self::ClosingApart) === $attachedStart,
				) ?? $this->edges[0],
			},
		];
	}


	/**
	 * The shapes a list spread for its width is written in, the items and the edges, the first of each; edges kept are
	 * written `apart`, the author having written none.
	 * @return array{?string, ?string}
	 */
	public function getFirst(): array
	{
		return [$this->items[0] ?? null, $this->edges[0] ?? ($this->edgesKept ? self::Apart : null)];
	}


	/**
	 * Where the edges of a list spread over lines stand, read from the token after the opening one and the closing token,
	 * which a list without one leaves out; null for the items beginning on a line of their own and the closing token
	 * following them.
	 */
	public static function findEdges(Token $open, ?Token $close): ?string
	{
		$start = $open->getNext()?->startsLine() === true;
		return match (true) {
			$close === null => $start ? self::Apart : self::Attached,
			$start => $close->startsLine() ? self::Apart : null,
			default => $close->startsLine() ? self::ClosingApart : self::Attached,
		};
	}


	/**
	 * The claims of the items, the separators and the closing token of a list in brackets, of the slot `$items` of the
	 * node, as `$decide` gives them for that node.
	 * @param  \Closure(Gap, ?Node): ?array{items: list<?Claim>, close: ?Claim, separator?: Claim}  $decide
	 * @return array<string, array{\Closure(Gap): ?Claim, null}>
	 */
	public static function createClaims(string $items, string $close, \Closure $decide): array
	{
		return [
			"$items:item" => [fn(Gap $gap) => $decide($gap, $gap->value->parent?->parent)['items'][$gap->index ?? 0] ?? null, null],
			"$items:separator" => [fn(Gap $gap) => $decide($gap, $gap->token->parent?->parent)['separator'] ?? null, null],
			$close => [fn(Gap $gap) => $decide($gap, $gap->token->parent)['close'] ?? null, null],
		];
	}


	/**
	 * The claims of a list in brackets, as `claim()` gives them, decided once per pass about the node: one its author
	 * spread as `claimWritten()` gives them, one on a line as `claimSpread()` does for the reason `$findReason` gives,
	 * null where the list stays.
	 * @param  list<Node>  $items
	 * @param  \Closure(): ?string  $findReason
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	public function decide(
		Gap $gap,
		Node $node,
		Token $open,
		array $items,
		Token $close,
		string $spanned,
		\Closure $findReason,
	): ?array
	{
		return $gap->once($node, function () use ($gap, $open, $items, $close, $spanned, $findReason): ?array {
			if (NodeHelpers::isMultiline($open, $items, $close)) {
				return $this->claimWritten($gap, $open, $items, $close, $spanned);
			}

			$because = $this->items === [] ? null : $findReason();
			return $because === null ? null : $this->claimSpread($gap, $open, $items, $close, $because);
		});
	}


	/**
	 * The claims of a list its author spread over lines, written again on the axis whose shape is not accepted, and in
	 * `fill` filled again where `refill()` breaks its lines.
	 * @param  list<Node>  $items
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	public function claimWritten(Gap $gap, Token $open, array $items, ?Token $close, string $because): ?array
	{
		[$itemShape, $edgeShape] = $this->findShapes($open, $close, $items, $gap->style->lineSplits->spreadItem === 'last');
		$packed = $itemShape === self::Fill ? $this->refill($gap, $open, $items) : null;
		return $this->claim($items, $close, $itemShape, $edgeShape, $because, $packed);
	}


	/**
	 * The claims of a list on one line spread in the first shapes, in `fill` its items filling the lines evenly up to
	 * the line length and one on a line where an item cannot be measured on one; null for a list to fill while its line
	 * waits for its indentation.
	 * @param  list<Node>  $items
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	public function claimSpread(Gap $gap, Token $open, array $items, ?Token $close, string $because): ?array
	{
		[$itemShape, $edgeShape] = $this->getFirst();
		$packed = null;
		$limit = $gap->style->maxLineLength;
		if ($itemShape === self::Fill && $limit !== null) {
			if (!NodeHelpers::isLineInPlace($gap, $open)) {
				return null;
			}

			$indentation = Indentation::measure($open->getLineIndentation() . $gap->style->indent, $gap->style->toPhpSyntax());
			$packed = NodeHelpers::packItems($items, $indentation, $limit, $because);
		}

		return $this->claim(
			$items,
			$close,
			$itemShape === self::Fill && $packed === null ? self::PerLine : $itemShape,
			$edgeShape,
			$because,
			$packed,
			whole: true,
		);
	}


	/**
	 * The claims before the items of a list its author spread in `fill` that break its lines again, null before an item
	 * left where it is; null where none breaks. An item spanning lines that shares one with another item
	 * (`findSharedItems()`) gets lines of its own, and a line wider than the line length is split into as few lines as
	 * filling them up to the length takes, each no longer than it needs to be for that, where the place is spread for its
	 * width and the lines have their indentation; the other lines and the first item of the list stay where they are.
	 * @param  list<Node>  $items
	 * @return ?list<?Claim>
	 */
	public function refill(Gap $gap, Token $open, array $items): ?array
	{
		$style = $gap->style;
		$claims = array_fill(0, count($items), null);
		$shared = new Claim(line: Line::Next, because: 'an item spanning lines shares a line with another one');
		foreach (self::findSharedItems($items, $style->lineSplits->spreadItem === 'last') as $i) {
			foreach ([$i, $i + 1] as $j) {
				if ($j > 0 && isset($items[$j]) && $items[$j]->getFirstToken()?->startsLine() === false) {
					$claims[$j] = $shared;
				}
			}
		}

		$limit = $this->place !== null && $style->lineSplits->has($this->place) ? $style->maxLineLength : null;
		$wrapped = [];
		$heads = [$open];
		$indentation = Indentation::measure($open->getLineIndentation() . $style->indent, $style->toPhpSyntax());
		for ($i = 0, $count = count($items); $limit !== null && $i < $count; $i = $j) {
			// the items of a line: the first one and those following it, each after one that ends on the line
			for (
				$j = $i + 1;
				$j < $count
				&& $items[$j]->getFirstToken()?->startsLine() === false
				&& !$items[$j - 1]->isMultiLine();
				$j++
			);

			$head = $items[$i]->getFirstToken();
			$width = $head === null ? 0 : NodeHelpers::measureCodeLine($head, $style);
			if ($head === null || $width <= $limit) {
				continue;
			}

			// the width of the line up to the comma after each item, and up to its end after the last one
			$ends = [];
			for ($k = $i; $k < $j; $k++) {
				$next = $items[$k + 1] ?? null;
				$ends[$k] = $k === $j - 1 || $next?->getFirstToken() === null
					? $width
					: NodeHelpers::measureCodeLine($head, $style, [spl_object_id($next->getFirstToken()) => true]);
			}

			// the items the line breaks before when it fills up to the length, a break shifting those after it to the
			// indentation
			$wrap = function (int $length) use ($i, $j, $ends, $claims, $indentation): array {
				$breaks = [];
				$offset = 0;
				for ($k = $i + 1; $k < $j; $k++) {
					if ($claims[$k] !== null || $ends[$k] - $offset > $length) {
						$breaks[] = $k;
						$offset = $ends[$k - 1] + 1 - $indentation;
					}
				}

				return $breaks;
			};
			$lines = count($wrap($limit));
			$low = $ends[$i];
			for ($k = $i + 1; $k < $j; $k++) {
				$low = max($low, $indentation + $ends[$k] - $ends[$k - 1] - 1);
			}

			$high = max($low, $limit);
			while ($low < $high) { // the narrowest length that still takes no more lines, so that the last does not hold a lone item
				$middle = intdiv($low + $high, 2);
				if (count($wrap($middle)) <= $lines) {
					$high = $middle;
				} else {
					$low = $middle + 1;
				}
			}

			$heads[] = $head;
			$break = new Claim(line: Line::Next, because: "the line is $width characters long, more than $limit");
			foreach ($wrap(min($low, $limit)) as $k) {
				$wrapped[$k] = $claims[$k] ?? $break;
			}
		}

		if ($wrapped !== [] && NodeHelpers::isLineInPlace($gap, ...$heads)) {
			$claims = array_replace($claims, $wrapped);
		}

		return array_filter($claims) === [] ? null : array_values($claims);
	}


	/**
	 * The indexes of the items spanning lines that share a line with another item, the one before ending on its first
	 * line or the one after beginning on its last; the last item may share its first line where `$lastShares`, as the
	 * last argument hugging the parentheses does (`multiline.spreadItem: last`).
	 * @param  list<Node>  $items
	 * @return list<int>
	 */
	private static function findSharedItems(array $items, bool $lastShares): array
	{
		$shared = [];
		foreach ($items as $i => $item) {
			$next = $items[$i + 1] ?? null;
			if (
				$item->isMultiLine()
				&& (($i > 0 && $item->getFirstToken()?->startsLine() === false && ($next !== null || !$lastShares))
					|| $next?->getFirstToken()?->startsLine() === false)
			) {
				$shared[] = $i;
			}
		}

		return $shared;
	}


	/**
	 * The claims of a list written in the shapes, the items and the edges, null for an axis to leave as it is:
	 * before each item, before the closing token and on each comma, which stays on the line of its item; null where both
	 * axes stay. The items of `fill` take the claims `$packed` gives them, the lines they fill, or stay where it is null.
	 * A token that would follow a comment ending its line keeps the line. The claims of the edges give `$edgeBecause`
	 * where there is one, and each claim is made for the decision of its axis, the comma for that of the items; a list
	 * spread whole, for its width or a property of it, is the matter of its items alone.
	 * @param  list<Node>  $items
	 * @param  ?list<?Claim>  $packed
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	public function claim(
		array $items,
		?Token $close,
		?string $itemShape,
		?string $edgeShape,
		string $because,
		?array $packed = null,
		?string $edgeBecause = null,
		bool $whole = false,
	): ?array
	{
		if (($itemShape === null && $edgeShape === null) || $items === []) {
			return null;
		}

		$item = $this->items === [] ? $this->edgeDecision : $this->itemDecision ?? $this->edgeDecision;
		// where the items are written again too, the edges go with them, so that a line breaking for both is one violation
		$rewritten = $whole || $packed !== null || ($itemShape === self::PerLine && array_any(
			array_slice($items, 1),
			fn(Node $node) => $node->getFirstToken()?->startsLine() === false,
		));
		$edge = $rewritten ? $item : $this->edgeDecision ?? $item;
		$break = new Claim(line: Line::Next, because: $because, decision: $this->itemDecision);
		$edgeBreak = new Claim(line: Line::Next, because: $edgeBecause ?? $because, decision: $edge);
		$follow = fn(?Token $token) => $token === null || $token->getPrevious()?->hasCommentUpTo($token) === true
			? null
			: new Claim(line: Line::Same, because: $edgeBecause ?? $because, decision: $edge);
		$claims = [];
		foreach ($items as $i => $node) {
			$claims[] = match (true) {
				$i === 0 => match ($edgeShape) {
					self::Apart => $edgeBreak,
					self::Attached, self::ClosingApart => $follow($node->getFirstToken()),
					default => null,
				},
				$itemShape === self::PerLine => $break,
				$itemShape === self::Fill && $this->itemDecision !== null => ($packed[$i] ?? null)?->withDecision($this->itemDecision),
				$itemShape === self::Fill => $packed[$i] ?? null,
				default => null,
			};
		}

		return [
			'items' => $claims,
			'close' => match ($edgeShape) {
				self::Apart, self::ClosingApart => $close === null ? null : $edgeBreak,
				self::Attached => $follow($close),
				default => null,
			},
			'separator' => new Claim(Space::None, line: Line::Same, because: $because, decision: $item),
		];
	}
}
