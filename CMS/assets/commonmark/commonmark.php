<?php
/**
 * 365CMS-Bündel: league/commonmark mit Abhängigkeiten (Teilmenge), eine Datei mit geklammerten namespace-Blöcken.
 * GENERIERT, nicht von Hand ändern. Erzeugt aus .github/deps/composer.lock mit .github/deps/build-commonmark-bundle.php.
 * Lizenzen: siehe LICENSE neben dieser Datei (BSD-3-Clause bzw. MIT; nette/* unter BSD-3-Clause gewählt).
 * Copyright (c) Colin O'Dell und Mitwirkende (league/commonmark, league/config); Dragonfly Development Inc.,
 *   Beau Simensen (dflydev/dot-access-data); David Grudl (nette/schema, nette/utils).
 * Pakete:
 *   league/commonmark        2.10.7   8eb53e906fd60c67038d22f1f1cab420ed58b8be
 *   league/config            1.2.0    754b3604fb2984c71f4af4a9cbe7b57f346ec1f3
 *   dflydev/dot-access-data  3.0.3    a23a2bf4f31d3518f3ecb38660c95715dfead60f
 *   nette/schema             1.4.0    af9fa8a86bfca9b02b10346220db26e62ef942ec
 *   nette/utils              4.1.6    6bf24518accf32cf845bca305e0b92078700fd01
 * Namensräume verschoben unter CMS\Vendor\ (z. B. CMS\Vendor\League\CommonMark\MarkdownConverter).
 * Dateien: 240, Typen: 258
 */

declare(strict_types=1);

namespace {
    if (!\defined('ABSPATH')) {
        return;
    }
}

// dflydev/dot-access-data/src/DataInterface.php


namespace CMS\Vendor\Dflydev\DotAccessData {

use CMS\Vendor\Dflydev\DotAccessData\Exception\DataException;
use CMS\Vendor\Dflydev\DotAccessData\Exception\InvalidPathException;

interface DataInterface
{
    public const PRESERVE = 0;
    public const REPLACE = 1;
    public const MERGE = 2;

public function append(string $key, $value = null): void;

public function set(string $key, $value = null): void;

public function remove(string $key): void;

public function get(string $key, $default = null);

public function has(string $key): bool;

public function getData(string $key): DataInterface;

public function import(array $data, int $mode = self::REPLACE): void;

public function importData(DataInterface $data, int $mode = self::REPLACE): void;

public function export(): array;
}
}

// dflydev/dot-access-data/src/Data.php


namespace CMS\Vendor\Dflydev\DotAccessData {

use ArrayAccess;
use CMS\Vendor\Dflydev\DotAccessData\Exception\DataException;
use CMS\Vendor\Dflydev\DotAccessData\Exception\InvalidPathException;
use CMS\Vendor\Dflydev\DotAccessData\Exception\MissingPathException;

class Data implements DataInterface, ArrayAccess
{
    private const DELIMITERS = ['.', '/'];

protected $data;

public function __construct(array $data = [])
    {
        $this->data = $data;
    }

public function append(string $key, $value = null): void
    {
        $currentValue =& $this->data;
        $keyPath = self::keyToPathArray($key);

        $endKey = array_pop($keyPath);
        foreach ($keyPath as $currentKey) {
            if (! isset($currentValue[$currentKey])) {
                $currentValue[$currentKey] = [];
            }
            $currentValue =& $currentValue[$currentKey];
        }

        if (!isset($currentValue[$endKey])) {
            $currentValue[$endKey] = [];
        }

        if (!is_array($currentValue[$endKey])) {

$currentValue[$endKey] = [$currentValue[$endKey]];
        }

        $currentValue[$endKey][] = $value;
    }

public function set(string $key, $value = null): void
    {
        $currentValue =& $this->data;
        $keyPath = self::keyToPathArray($key);

        $endKey = array_pop($keyPath);
        foreach ($keyPath as $currentKey) {
            if (!isset($currentValue[$currentKey])) {
                $currentValue[$currentKey] = [];
            }
            if (!is_array($currentValue[$currentKey])) {
                throw new DataException(sprintf('Key path "%s" within "%s" cannot be indexed into (is not an array)', $currentKey, self::formatPath($key)));
            }
            $currentValue =& $currentValue[$currentKey];
        }
        $currentValue[$endKey] = $value;
    }

public function remove(string $key): void
    {
        $currentValue =& $this->data;
        $keyPath = self::keyToPathArray($key);

        $endKey = array_pop($keyPath);
        foreach ($keyPath as $currentKey) {
            if (!isset($currentValue[$currentKey])) {
                return;
            }
            $currentValue =& $currentValue[$currentKey];
        }
        unset($currentValue[$endKey]);
    }

public function get(string $key, $default = null)
    {

        $hasDefault = \func_num_args() > 1;

        $currentValue = $this->data;
        $keyPath = self::keyToPathArray($key);

        foreach ($keyPath as $currentKey) {
            if (!is_array($currentValue) || !array_key_exists($currentKey, $currentValue)) {
                if ($hasDefault) {
                    return $default;
                }

                throw new MissingPathException($key, sprintf('No data exists at the given path: "%s"', self::formatPath($keyPath)));
            }

            $currentValue = $currentValue[$currentKey];
        }

        return $currentValue === null ? $default : $currentValue;
    }

public function has(string $key): bool
    {
        $currentValue = $this->data;

        foreach (self::keyToPathArray($key) as $currentKey) {
            if (
                !is_array($currentValue) ||
                !array_key_exists($currentKey, $currentValue)
            ) {
                return false;
            }
            $currentValue = $currentValue[$currentKey];
        }

        return true;
    }

public function getData(string $key): DataInterface
    {
        $value = $this->get($key);
        if (is_array($value) && Util::isAssoc($value)) {
            return new Data($value);
        }

        throw new DataException(sprintf('Value at "%s" could not be represented as a DataInterface', self::formatPath($key)));
    }

public function import(array $data, int $mode = self::REPLACE): void
    {
        $this->data = Util::mergeAssocArray($this->data, $data, $mode);
    }

public function importData(DataInterface $data, int $mode = self::REPLACE): void
    {
        $this->import($data->export(), $mode);
    }

public function export(): array
    {
        return $this->data;
    }

#[\ReturnTypeWillChange]
    public function offsetExists($key)
    {
        return $this->has($key);
    }

#[\ReturnTypeWillChange]
    public function offsetGet($key)
    {
        return $this->get($key, null);
    }

#[\ReturnTypeWillChange]
    public function offsetSet($key, $value)
    {
        $this->set($key, $value);
    }

#[\ReturnTypeWillChange]
    public function offsetUnset($key)
    {
        $this->remove($key);
    }

protected static function keyToPathArray(string $path): array
    {
        if (\strlen($path) === 0) {
            throw new InvalidPathException('Path cannot be an empty string');
        }

        $path = \str_replace(self::DELIMITERS, '.', $path);

        return \explode('.', $path);
    }

protected static function formatPath($path): string
    {
        if (is_string($path)) {
            $path = self::keyToPathArray($path);
        }

        return implode(' » ', $path);
    }
}
}

// dflydev/dot-access-data/src/Exception/DataException.php


namespace CMS\Vendor\Dflydev\DotAccessData\Exception {

class DataException extends \RuntimeException
{
}
}

// dflydev/dot-access-data/src/Exception/InvalidPathException.php


namespace CMS\Vendor\Dflydev\DotAccessData\Exception {

class InvalidPathException extends DataException
{
}
}

// dflydev/dot-access-data/src/Exception/MissingPathException.php


namespace CMS\Vendor\Dflydev\DotAccessData\Exception {

use Throwable;

class MissingPathException extends DataException
{

    protected $path;

    public function __construct(string $path, string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        $this->path = $path;

        parent::__construct($message, $code, $previous);
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
}

// dflydev/dot-access-data/src/Util.php


namespace CMS\Vendor\Dflydev\DotAccessData {

class Util
{

    public static function isAssoc(array $arr): bool
    {
        return !count($arr) || count(array_filter(array_keys($arr), 'is_string')) == count($arr);
    }

public static function mergeAssocArray($to, $from, int $mode = DataInterface::REPLACE)
    {
        if ($mode === DataInterface::MERGE && self::isList($to) && self::isList($from)) {
            return array_merge($to, $from);
        }

        if (is_array($from) && is_array($to)) {
            foreach ($from as $k => $v) {
                if (!isset($to[$k])) {
                    $to[$k] = $v;
                } else {
                    $to[$k] = self::mergeAssocArray($to[$k], $v, $mode);
                }
            }

            return $to;
        }

        return $mode === DataInterface::PRESERVE ? $to : $from;
    }

private static function isList($value): bool
    {
        return is_array($value) && array_values($value) === $value;
    }
}
}

// league/commonmark/src/ConverterInterface.php


namespace CMS\Vendor\League\CommonMark {

use CMS\Vendor\League\CommonMark\Exception\CommonMarkException;
use CMS\Vendor\League\CommonMark\Output\RenderedContentInterface;
use CMS\Vendor\League\Config\Exception\ConfigurationExceptionInterface;

interface ConverterInterface
{

    public function convert(string $input): RenderedContentInterface;
}
}

// league/commonmark/src/Delimiter/Bracket.php


namespace CMS\Vendor\League\CommonMark\Delimiter {

use CMS\Vendor\League\CommonMark\Node\Node;

final class Bracket
{
    private Node $node;
    private ?Bracket $previous;
    private bool $hasNext = false;
    private int $position;
    private bool $image;
    private bool $active = true;

    public function __construct(Node $node, ?Bracket $previous, int $position, bool $image)
    {
        $this->node     = $node;
        $this->previous = $previous;
        $this->position = $position;
        $this->image    = $image;
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    public function getPrevious(): ?Bracket
    {
        return $this->previous;
    }

    public function hasNext(): bool
    {
        return $this->hasNext;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isImage(): bool
    {
        return $this->image;
    }

public function isActive(): bool
    {
        return $this->active;
    }

public function setHasNext(bool $hasNext): void
    {
        $this->hasNext = $hasNext;
    }

public function setActive(bool $active): void
    {
        $this->active = $active;
    }
}
}

// league/commonmark/src/Delimiter/DelimiterInterface.php


namespace CMS\Vendor\League\CommonMark\Delimiter {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractStringContainer;

interface DelimiterInterface
{
    public function canClose(): bool;

    public function canOpen(): bool;

public function isActive(): bool;

public function setActive(bool $active): void;

    public function getChar(): string;

    public function getIndex(): ?int;

    public function getNext(): ?DelimiterInterface;

    public function setNext(?DelimiterInterface $next): void;

    public function getLength(): int;

    public function setLength(int $length): void;

    public function getOriginalLength(): int;

    public function getInlineNode(): AbstractStringContainer;

    public function getPrevious(): ?DelimiterInterface;

    public function setPrevious(?DelimiterInterface $previous): void;
}
}

// league/commonmark/src/Delimiter/Delimiter.php


namespace CMS\Vendor\League\CommonMark\Delimiter {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractStringContainer;

final class Delimiter implements DelimiterInterface
{

    private string $char;

private int $length;

private int $originalLength;

private AbstractStringContainer $inlineNode;

private ?DelimiterInterface $previous = null;

private ?DelimiterInterface $next = null;

private bool $canOpen;

private bool $canClose;

private bool $active;

private ?int $index = null;

    public function __construct(string $char, int $numDelims, AbstractStringContainer $node, bool $canOpen, bool $canClose, ?int $index = null)
    {
        $this->char           = $char;
        $this->length         = $numDelims;
        $this->originalLength = $numDelims;
        $this->inlineNode     = $node;
        $this->canOpen        = $canOpen;
        $this->canClose       = $canClose;
        $this->active         = true;
        $this->index          = $index;
    }

    public function canClose(): bool
    {
        return $this->canClose;
    }

    public function canOpen(): bool
    {
        return $this->canOpen;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getChar(): string
    {
        return $this->char;
    }

    public function getIndex(): ?int
    {
        return $this->index;
    }

    public function getNext(): ?DelimiterInterface
    {
        return $this->next;
    }

    public function setNext(?DelimiterInterface $next): void
    {
        $this->next = $next;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function setLength(int $length): void
    {
        $this->length = $length;
    }

    public function getOriginalLength(): int
    {
        return $this->originalLength;
    }

    public function getInlineNode(): AbstractStringContainer
    {
        return $this->inlineNode;
    }

    public function getPrevious(): ?DelimiterInterface
    {
        return $this->previous;
    }

    public function setPrevious(?DelimiterInterface $previous): void
    {
        $this->previous = $previous;
    }
}
}

// league/commonmark/src/Parser/Inline/InlineParserInterface.php


namespace CMS\Vendor\League\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;

interface InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch;

    public function parse(InlineParserContext $inlineContext): bool;
}
}

// league/commonmark/src/Delimiter/DelimiterParser.php


namespace CMS\Vendor\League\CommonMark\Delimiter {

use CMS\Vendor\League\CommonMark\Delimiter\Processor\DelimiterProcessorCollection;
use CMS\Vendor\League\CommonMark\Delimiter\Processor\DelimiterProcessorInterface;
use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class DelimiterParser implements InlineParserInterface
{
    private DelimiterProcessorCollection $collection;

    public function __construct(DelimiterProcessorCollection $collection)
    {
        $this->collection = $collection;
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::oneOf(...$this->collection->getDelimiterCharacters());
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $character = $inlineContext->getFullMatch();
        $numDelims = 0;
        $cursor    = $inlineContext->getCursor();
        $processor = $this->collection->getDelimiterProcessor($character);

        \assert($processor !== null);

        $charBefore = $cursor->peek(-1);
        if ($charBefore === null) {
            $charBefore = "\n";
        }

        while ($cursor->peek($numDelims) === $character) {
            ++$numDelims;
        }

        if ($numDelims < $processor->getMinLength()) {
            return false;
        }

        $cursor->advanceBy($numDelims);

        $charAfter = $cursor->getCurrentCharacter();
        if ($charAfter === null) {
            $charAfter = "\n";
        }

        [$canOpen, $canClose] = self::determineCanOpenOrClose($charBefore, $charAfter, $character, $processor);

        if (! ($canOpen || $canClose)) {
            $inlineContext->getContainer()->appendChild(new Text(\str_repeat($character, $numDelims)));

            return true;
        }

        $node = new Text(\str_repeat($character, $numDelims), [
            'delim' => true,
        ]);
        $inlineContext->getContainer()->appendChild($node);

$delimiter = new Delimiter($character, $numDelims, $node, $canOpen, $canClose, $inlineContext->getCursor()->getPosition());
        $inlineContext->getDelimiterStack()->push($delimiter);

        return true;
    }

private static function determineCanOpenOrClose(string $charBefore, string $charAfter, string $character, DelimiterProcessorInterface $delimiterProcessor): array
    {
        $afterIsWhitespace   = \preg_match(RegexHelper::REGEX_UNICODE_WHITESPACE_CHAR, $charAfter);
        $afterIsPunctuation  = \preg_match(RegexHelper::REGEX_PUNCTUATION, $charAfter);
        $beforeIsWhitespace  = \preg_match(RegexHelper::REGEX_UNICODE_WHITESPACE_CHAR, $charBefore);
        $beforeIsPunctuation = \preg_match(RegexHelper::REGEX_PUNCTUATION, $charBefore);

        $leftFlanking  = ! $afterIsWhitespace && (! $afterIsPunctuation || $beforeIsWhitespace || $beforeIsPunctuation);
        $rightFlanking = ! $beforeIsWhitespace && (! $beforeIsPunctuation || $afterIsWhitespace || $afterIsPunctuation);

        if ($character === '_') {
            $canOpen  = $leftFlanking && (! $rightFlanking || $beforeIsPunctuation);
            $canClose = $rightFlanking && (! $leftFlanking || $afterIsPunctuation);
        } else {
            $canOpen  = $leftFlanking && $character === $delimiterProcessor->getOpeningCharacter();
            $canClose = $rightFlanking && $character === $delimiterProcessor->getClosingCharacter();
        }

        return [$canOpen, $canClose];
    }
}
}

// league/commonmark/src/Delimiter/DelimiterStack.php


namespace CMS\Vendor\League\CommonMark\Delimiter {

use CMS\Vendor\League\CommonMark\Delimiter\Processor\CacheableDelimiterProcessorInterface;
use CMS\Vendor\League\CommonMark\Delimiter\Processor\DelimiterProcessorCollection;
use CMS\Vendor\League\CommonMark\Node\Inline\AdjacentTextMerger;
use CMS\Vendor\League\CommonMark\Node\Node;

final class DelimiterStack
{

    private ?DelimiterInterface $top = null;

private ?Bracket $brackets = null;

private $missingIndexCache;

private int $remainingDelimiters = 0;

    public function __construct(int $maximumStackSize = PHP_INT_MAX)
    {
        $this->remainingDelimiters = $maximumStackSize;

        if (\PHP_VERSION_ID >= 80000) {

            $this->missingIndexCache = new \WeakMap();
        } else {
            $this->missingIndexCache = new \SplObjectStorage();
        }
    }

    public function push(DelimiterInterface $newDelimiter): void
    {
        if ($this->remainingDelimiters-- <= 0) {
            return;
        }

        $newDelimiter->setPrevious($this->top);

        if ($this->top !== null) {
            $this->top->setNext($newDelimiter);
        }

        $this->top = $newDelimiter;
    }

public function addBracket(Node $node, int $index, bool $image): void
    {
        if ($this->brackets !== null) {
            $this->brackets->setHasNext(true);
        }

        $this->brackets = new Bracket($node, $this->brackets, $index, $image);
    }

public function getLastBracket(): ?Bracket
    {
        return $this->brackets;
    }

    private function findEarliest(int $stackBottom): ?DelimiterInterface
    {

        $delimiter   = $this->top;
        $lastChecked = null;

        while ($delimiter !== null && self::getIndex($delimiter) > $stackBottom) {
            $lastChecked = $delimiter;
            $delimiter   = $delimiter->getPrevious();
        }

        return $lastChecked;
    }

public function removeBracket(): void
    {
        if ($this->brackets === null) {
            return;
        }

        $this->brackets = $this->brackets->getPrevious();

        if ($this->brackets !== null) {
            $this->brackets->setHasNext(false);
        }
    }

    public function removeDelimiter(DelimiterInterface $delimiter): void
    {
        if ($delimiter->getPrevious() !== null) {

            $delimiter->getPrevious()->setNext($delimiter->getNext());
        }

        if ($delimiter->getNext() === null) {

            $this->top = $delimiter->getPrevious();
        } else {

            $delimiter->getNext()->setPrevious($delimiter->getPrevious());
        }

$delimiter->setPrevious(null);
        $delimiter->setNext(null);

unset($this->missingIndexCache[$delimiter]);
    }

    private function removeDelimiterAndNode(DelimiterInterface $delimiter): void
    {
        $delimiter->getInlineNode()->detach();
        $this->removeDelimiter($delimiter);
    }

    private function removeDelimitersBetween(DelimiterInterface $opener, DelimiterInterface $closer): void
    {
        $delimiter      = $closer->getPrevious();
        $openerPosition = self::getIndex($opener);
        while ($delimiter !== null && self::getIndex($delimiter) > $openerPosition) {
            $previous = $delimiter->getPrevious();
            $this->removeDelimiter($delimiter);
            $delimiter = $previous;
        }
    }

public function removeAll($stackBottom = null): void
    {
        $stackBottomPosition = \is_int($stackBottom) ? $stackBottom : self::getIndex($stackBottom);

        while ($this->top && $this->getIndex($this->top) > $stackBottomPosition) {
            $this->removeDelimiter($this->top);
        }
    }

public function removeEarlierMatches(string $character): void
    {
        $opener = $this->top;
        while ($opener !== null) {
            if ($opener->getChar() === $character) {
                $opener->setActive(false);
            }

            $opener = $opener->getPrevious();
        }
    }

public function deactivateLinkOpeners(): void
    {
        $opener = $this->brackets;
        while ($opener !== null && $opener->isActive()) {
            $opener->setActive(false);
            $opener = $opener->getPrevious();
        }
    }

public function searchByCharacter($characters): ?DelimiterInterface
    {
        if (! \is_array($characters)) {
            $characters = [$characters];
        }

        $opener = $this->top;
        while ($opener !== null) {
            if (\in_array($opener->getChar(), $characters, true)) {
                break;
            }

            $opener = $opener->getPrevious();
        }

        return $opener;
    }

public function processDelimiters($stackBottom, DelimiterProcessorCollection $processors): void
    {

        $openersBottom = [];

        $stackBottomPosition = \is_int($stackBottom) ? $stackBottom : self::getIndex($stackBottom);

$closer = $this->findEarliest($stackBottomPosition);

while ($closer !== null) {
            $closingDelimiterChar = $closer->getChar();

            $delimiterProcessor = $processors->getDelimiterProcessor($closingDelimiterChar);
            if (! $closer->canClose() || $delimiterProcessor === null) {
                $closer = $closer->getNext();
                continue;
            }

            if ($delimiterProcessor instanceof CacheableDelimiterProcessorInterface) {
                $openersBottomCacheKey = $delimiterProcessor->getCacheKey($closer);
            } else {
                $openersBottomCacheKey = $closingDelimiterChar;
            }

            $openingDelimiterChar = $delimiterProcessor->getOpeningCharacter();

            $useDelims            = 0;
            $openerFound          = false;
            $potentialOpenerFound = false;
            $opener               = $closer->getPrevious();
            while ($opener !== null && ($openerPosition = self::getIndex($opener)) > $stackBottomPosition && $openerPosition >= ($openersBottom[$openersBottomCacheKey] ?? 0)) {
                if ($opener->canOpen() && $opener->getChar() === $openingDelimiterChar) {
                    $potentialOpenerFound = true;
                    $useDelims            = $delimiterProcessor->getDelimiterUse($opener, $closer);
                    if ($useDelims > 0) {
                        $openerFound = true;
                        break;
                    }
                }

                $opener = $opener->getPrevious();
            }

            if (! $openerFound) {

if ($potentialOpenerFound === false || $delimiterProcessor instanceof CacheableDelimiterProcessorInterface) {
                    $openersBottom[$openersBottomCacheKey] = self::getIndex($closer);
                }

                if (! $potentialOpenerFound && ! $closer->canOpen()) {

$next = $closer->getNext();
                    $this->removeDelimiter($closer);
                    $closer = $next;
                } else {
                    $closer = $closer->getNext();
                }

                continue;
            }

            \assert($opener !== null);

            $openerNode = $opener->getInlineNode();
            $closerNode = $closer->getInlineNode();

$opener->setLength($opener->getLength() - $useDelims);
            $closer->setLength($closer->getLength() - $useDelims);

            $openerNode->setLiteral(\substr($openerNode->getLiteral(), 0, -$useDelims));
            $closerNode->setLiteral(\substr($closerNode->getLiteral(), 0, -$useDelims));

            $this->removeDelimitersBetween($opener, $closer);

AdjacentTextMerger::mergeTextNodesBetweenExclusive($openerNode, $closerNode);
            $delimiterProcessor->process($openerNode, $closerNode, $useDelims);

if ($opener->getLength() === 0) {
                $this->removeDelimiterAndNode($opener);
            }

if ($closer->getLength() === 0) {
                $next = $closer->getNext();
                $this->removeDelimiterAndNode($closer);
                $closer = $next;
            }
        }

$this->removeAll($stackBottomPosition);
    }

public function __destruct()
    {
        while ($this->top) {
            $this->removeDelimiter($this->top);
        }

        while ($this->brackets) {
            $this->removeBracket();
        }
    }

private function getIndex(?DelimiterInterface $delimiter): int
    {
        if ($delimiter === null) {
            return -1;
        }

        if (($index = $delimiter->getIndex()) !== null) {
            return $index;
        }

        if (isset($this->missingIndexCache[$delimiter])) {
            return $this->missingIndexCache[$delimiter];
        }

        $prev = $delimiter->getPrevious();
        $next = $delimiter->getNext();

        $i = 0;
        do {
            $i++;
            if ($prev === null) {
                break;
            }

            if ($prev->getIndex() !== null) {
                return $this->missingIndexCache[$delimiter] = $prev->getIndex() + $i;
            }
        } while ($prev = $prev->getPrevious());

        $j = 0;
        do {
            $j++;
            if ($next === null) {
                break;
            }

            if ($next->getIndex() !== null) {
                return $this->missingIndexCache[$delimiter] = $next->getIndex() - $j;
            }
        } while ($next = $next->getNext());

return $this->missingIndexCache[$delimiter] = $this->getIndex($delimiter->getPrevious()) + 1;
    }
}
}

// league/commonmark/src/Delimiter/Processor/DelimiterProcessorInterface.php


namespace CMS\Vendor\League\CommonMark\Delimiter\Processor {

use CMS\Vendor\League\CommonMark\Delimiter\DelimiterInterface;
use CMS\Vendor\League\CommonMark\Node\Inline\AbstractStringContainer;

interface DelimiterProcessorInterface
{

    public function getOpeningCharacter(): string;

public function getClosingCharacter(): string;

public function getMinLength(): int;

public function getDelimiterUse(DelimiterInterface $opener, DelimiterInterface $closer): int;

public function process(AbstractStringContainer $opener, AbstractStringContainer $closer, int $delimiterUse): void;
}
}

// league/commonmark/src/Delimiter/Processor/CacheableDelimiterProcessorInterface.php


namespace CMS\Vendor\League\CommonMark\Delimiter\Processor {

use CMS\Vendor\League\CommonMark\Delimiter\DelimiterInterface;

interface CacheableDelimiterProcessorInterface extends DelimiterProcessorInterface
{

    public function getCacheKey(DelimiterInterface $closer): string;
}
}

// league/commonmark/src/Delimiter/Processor/DelimiterProcessorCollectionInterface.php


namespace CMS\Vendor\League\CommonMark\Delimiter\Processor {

use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;

interface DelimiterProcessorCollectionInterface extends \Countable
{

    public function add(DelimiterProcessorInterface $processor): void;

public function getDelimiterProcessor(string $char): ?DelimiterProcessorInterface;

public function getDelimiterCharacters(): array;
}
}

// league/commonmark/src/Delimiter/Processor/DelimiterProcessorCollection.php


namespace CMS\Vendor\League\CommonMark\Delimiter\Processor {

use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;

final class DelimiterProcessorCollection implements DelimiterProcessorCollectionInterface
{

    private array $processorsByChar = [];

    public function add(DelimiterProcessorInterface $processor): void
    {
        $opening = $processor->getOpeningCharacter();
        $closing = $processor->getClosingCharacter();

        if ($opening === $closing) {
            $old = $this->processorsByChar[$opening] ?? null;
            if ($old !== null && $old->getOpeningCharacter() === $old->getClosingCharacter()) {
                $this->addStaggeredDelimiterProcessorForChar($opening, $old, $processor);
            } else {
                $this->addDelimiterProcessorForChar($opening, $processor);
            }
        } else {
            $this->addDelimiterProcessorForChar($opening, $processor);
            $this->addDelimiterProcessorForChar($closing, $processor);
        }
    }

    public function getDelimiterProcessor(string $char): ?DelimiterProcessorInterface
    {
        return $this->processorsByChar[$char] ?? null;
    }

public function getDelimiterCharacters(): array
    {
        return \array_keys($this->processorsByChar);
    }

    private function addDelimiterProcessorForChar(string $delimiterChar, DelimiterProcessorInterface $processor): void
    {
        if (isset($this->processorsByChar[$delimiterChar])) {
            throw new InvalidArgumentException(\sprintf('Delim processor for character "%s" already exists', $processor->getOpeningCharacter()));
        }

        $this->processorsByChar[$delimiterChar] = $processor;
    }

    private function addStaggeredDelimiterProcessorForChar(string $opening, DelimiterProcessorInterface $old, DelimiterProcessorInterface $new): void
    {
        if ($old instanceof StaggeredDelimiterProcessor) {
            $s = $old;
        } else {
            $s = new StaggeredDelimiterProcessor($opening, $old);
        }

        $s->add($new);
        $this->processorsByChar[$opening] = $s;
    }

    public function count(): int
    {
        return \count($this->processorsByChar);
    }
}
}

// league/commonmark/src/Delimiter/Processor/StaggeredDelimiterProcessor.php


namespace CMS\Vendor\League\CommonMark\Delimiter\Processor {

use CMS\Vendor\League\CommonMark\Delimiter\DelimiterInterface;
use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;
use CMS\Vendor\League\CommonMark\Node\Inline\AbstractStringContainer;

final class StaggeredDelimiterProcessor implements DelimiterProcessorInterface
{

    private string $delimiterChar;

private int $minLength = 0;

private array $processors = [];

    public function __construct(string $char, DelimiterProcessorInterface $processor)
    {
        $this->delimiterChar = $char;
        $this->add($processor);
    }

    public function getOpeningCharacter(): string
    {
        return $this->delimiterChar;
    }

    public function getClosingCharacter(): string
    {
        return $this->delimiterChar;
    }

    public function getMinLength(): int
    {
        return $this->minLength;
    }

public function add(DelimiterProcessorInterface $processor): void
    {
        $len = $processor->getMinLength();

        if (isset($this->processors[$len])) {
            throw new InvalidArgumentException(\sprintf('Cannot add two delimiter processors for char "%s" and minimum length %d', $this->delimiterChar, $len));
        }

        $this->processors[$len] = $processor;
        \krsort($this->processors);

        $this->minLength = \min($this->minLength, $len);
    }

    public function getDelimiterUse(DelimiterInterface $opener, DelimiterInterface $closer): int
    {
        return $this->findProcessor($opener->getLength())->getDelimiterUse($opener, $closer);
    }

    public function process(AbstractStringContainer $opener, AbstractStringContainer $closer, int $delimiterUse): void
    {
        $this->findProcessor($delimiterUse)->process($opener, $closer, $delimiterUse);
    }

    private function findProcessor(int $len): DelimiterProcessorInterface
    {

        foreach ($this->processors as $processor) {
            if ($processor->getMinLength() <= $len) {
                return $processor;
            }
        }

$first = \reset($this->processors);
        \assert($first instanceof DelimiterProcessorInterface);

        return $first;
    }
}
}

// league/config/src/ConfigurationProviderInterface.php


namespace CMS\Vendor\League\Config {

interface ConfigurationProviderInterface
{
    public function getConfiguration(): ConfigurationInterface;
}
}

// league/commonmark/src/Environment/EnvironmentBuilderInterface.php


namespace CMS\Vendor\League\CommonMark\Environment {

use CMS\Vendor\League\CommonMark\Delimiter\Processor\DelimiterProcessorInterface;
use CMS\Vendor\League\CommonMark\Exception\AlreadyInitializedException;
use CMS\Vendor\League\CommonMark\Extension\ExtensionInterface;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\Config\ConfigurationProviderInterface;

interface EnvironmentBuilderInterface extends ConfigurationProviderInterface
{

    public function addExtension(ExtensionInterface $extension): EnvironmentBuilderInterface;

public function addBlockStartParser(BlockStartParserInterface $parser, int $priority = 0): EnvironmentBuilderInterface;

public function addInlineParser(InlineParserInterface $parser, int $priority = 0): EnvironmentBuilderInterface;

public function addDelimiterProcessor(DelimiterProcessorInterface $processor): EnvironmentBuilderInterface;

public function addRenderer(string $nodeClass, NodeRendererInterface $renderer, int $priority = 0): EnvironmentBuilderInterface;

public function addEventListener(string $eventClass, callable $listener, int $priority = 0): EnvironmentBuilderInterface;
}
}

// league/commonmark/src/Environment/EnvironmentInterface.php


namespace CMS\Vendor\League\CommonMark\Environment {

use CMS\Vendor\League\CommonMark\Delimiter\Processor\DelimiterProcessorCollection;
use CMS\Vendor\League\CommonMark\Extension\ExtensionInterface;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Normalizer\TextNormalizerInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\Config\ConfigurationProviderInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

interface EnvironmentInterface extends ConfigurationProviderInterface, EventDispatcherInterface
{

    public function getExtensions(): iterable;

public function getBlockStartParsers(): iterable;

public function getInlineParsers(): iterable;

    public function getDelimiterProcessors(): DelimiterProcessorCollection;

public function getRenderersForClass(string $nodeClass): iterable;

    public function getSlugNormalizer(): TextNormalizerInterface;
}
}

// league/commonmark/src/Environment/Environment.php


namespace CMS\Vendor\League\CommonMark\Environment {

use CMS\Vendor\League\CommonMark\Delimiter\DelimiterParser;
use CMS\Vendor\League\CommonMark\Delimiter\Processor\DelimiterProcessorCollection;
use CMS\Vendor\League\CommonMark\Delimiter\Processor\DelimiterProcessorInterface;
use CMS\Vendor\League\CommonMark\Event\DocumentParsedEvent;
use CMS\Vendor\League\CommonMark\Event\ListenerData;
use CMS\Vendor\League\CommonMark\Exception\AlreadyInitializedException;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use CMS\Vendor\League\CommonMark\Extension\ConfigurableExtensionInterface;
use CMS\Vendor\League\CommonMark\Extension\ExtensionInterface;
use CMS\Vendor\League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use CMS\Vendor\League\CommonMark\Normalizer\SlugNormalizer;
use CMS\Vendor\League\CommonMark\Normalizer\TextNormalizerInterface;
use CMS\Vendor\League\CommonMark\Normalizer\UniqueSlugNormalizer;
use CMS\Vendor\League\CommonMark\Normalizer\UniqueSlugNormalizerInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\SkipLinesStartingWithLettersParser;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlFilter;
use CMS\Vendor\League\CommonMark\Util\PrioritizedList;
use CMS\Vendor\League\Config\Configuration;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;
use CMS\Vendor\Nette\Schema\Expect;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

final class Environment implements EnvironmentInterface, EnvironmentBuilderInterface, ListenerProviderInterface
{

    private array $extensions = [];

private array $uninitializedExtensions = [];

private bool $extensionsInitialized = false;

private PrioritizedList $blockStartParsers;

private PrioritizedList $inlineParsers;

private DelimiterProcessorCollection $delimiterProcessors;

private array $renderersByClass = [];

private PrioritizedList $listenerData;

    private ?EventDispatcherInterface $eventDispatcher = null;

private Configuration $config;

    private ?TextNormalizerInterface $slugNormalizer = null;

public function __construct(array $config = [])
    {
        $this->config = self::createDefaultConfiguration();
        $this->config->merge($config);

        $this->blockStartParsers   = new PrioritizedList();
        $this->inlineParsers       = new PrioritizedList();
        $this->listenerData        = new PrioritizedList();
        $this->delimiterProcessors = new DelimiterProcessorCollection();

$this->addBlockStartParser(new SkipLinesStartingWithLettersParser(), 249);
    }

    public function getConfiguration(): ConfigurationInterface
    {
        return $this->config->reader();
    }

public function mergeConfig(array $config): void
    {
        @\trigger_error('Environment::mergeConfig() is deprecated since league/commonmark v2.0 and will be removed in v3.0. Configuration should be set when instantiating the environment instead.', \E_USER_DEPRECATED);

        $this->assertUninitialized('Failed to modify configuration.');

        $this->config->merge($config);
    }

    public function addBlockStartParser(BlockStartParserInterface $parser, int $priority = 0): EnvironmentBuilderInterface
    {
        $this->assertUninitialized('Failed to add block start parser.');

        $this->blockStartParsers->add($parser, $priority);
        $this->injectEnvironmentAndConfigurationIfNeeded($parser);

        return $this;
    }

    public function addInlineParser(InlineParserInterface $parser, int $priority = 0): EnvironmentBuilderInterface
    {
        $this->assertUninitialized('Failed to add inline parser.');

        $this->inlineParsers->add($parser, $priority);
        $this->injectEnvironmentAndConfigurationIfNeeded($parser);

        return $this;
    }

    public function addDelimiterProcessor(DelimiterProcessorInterface $processor): EnvironmentBuilderInterface
    {
        $this->assertUninitialized('Failed to add delimiter processor.');
        $this->delimiterProcessors->add($processor);
        $this->injectEnvironmentAndConfigurationIfNeeded($processor);

        return $this;
    }

    public function addRenderer(string $nodeClass, NodeRendererInterface $renderer, int $priority = 0): EnvironmentBuilderInterface
    {
        $this->assertUninitialized('Failed to add renderer.');

        if (! isset($this->renderersByClass[$nodeClass])) {
            $this->renderersByClass[$nodeClass] = new PrioritizedList();
        }

        $this->renderersByClass[$nodeClass]->add($renderer, $priority);
        $this->injectEnvironmentAndConfigurationIfNeeded($renderer);

        return $this;
    }

public function getBlockStartParsers(): iterable
    {
        if (! $this->extensionsInitialized) {
            $this->initializeExtensions();
        }

        return $this->blockStartParsers->getIterator();
    }

    public function getDelimiterProcessors(): DelimiterProcessorCollection
    {
        if (! $this->extensionsInitialized) {
            $this->initializeExtensions();
        }

        return $this->delimiterProcessors;
    }

public function getRenderersForClass(string $nodeClass): iterable
    {
        if (! $this->extensionsInitialized) {
            $this->initializeExtensions();
        }

if (isset($this->renderersByClass[$nodeClass])) {
            return $this->renderersByClass[$nodeClass];
        }

while (\class_exists($parent ??= $nodeClass) && $parent = \get_parent_class($parent)) {
            if (! isset($this->renderersByClass[$parent])) {
                continue;
            }

return $this->renderersByClass[$nodeClass] = $this->renderersByClass[$parent];
        }

        return [];
    }

public function getExtensions(): iterable
    {
        return $this->extensions;
    }

public function addExtension(ExtensionInterface $extension): EnvironmentBuilderInterface
    {
        $this->assertUninitialized('Failed to add extension.');

        $this->extensions[]              = $extension;
        $this->uninitializedExtensions[] = $extension;

        if ($extension instanceof ConfigurableExtensionInterface) {
            $extension->configureSchema($this->config);
        }

        return $this;
    }

    private function initializeExtensions(): void
    {

        $this->getSlugNormalizer();

while (\count($this->uninitializedExtensions) > 0) {
            foreach ($this->uninitializedExtensions as $i => $extension) {
                $extension->register($this);
                unset($this->uninitializedExtensions[$i]);
            }
        }

        $this->extensionsInitialized = true;

if ($this->delimiterProcessors->count() > 0) {
            $this->inlineParsers->add(new DelimiterParser($this->delimiterProcessors), PHP_INT_MIN);
        }
    }

    private function injectEnvironmentAndConfigurationIfNeeded(object $object): void
    {
        if ($object instanceof EnvironmentAwareInterface) {
            $object->setEnvironment($this);
        }

        if ($object instanceof ConfigurationAwareInterface) {
            $object->setConfiguration($this->config->reader());
        }
    }

public static function createCommonMarkEnvironment(array $config = []): Environment
    {
        $environment = new self($config);
        $environment->addExtension(new CommonMarkCoreExtension());

        return $environment;
    }

public static function createGFMEnvironment(array $config = []): Environment
    {
        $environment = new self($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return $environment;
    }

    public function addEventListener(string $eventClass, callable $listener, int $priority = 0): EnvironmentBuilderInterface
    {
        $this->assertUninitialized('Failed to add event listener.');

        $this->listenerData->add(new ListenerData($eventClass, $listener), $priority);

        if (\is_object($listener)) {
            $this->injectEnvironmentAndConfigurationIfNeeded($listener);
        } elseif (\is_array($listener) && \is_object($listener[0])) {
            $this->injectEnvironmentAndConfigurationIfNeeded($listener[0]);
        }

        return $this;
    }

    public function dispatch(object $event): object
    {
        if (! $this->extensionsInitialized) {
            $this->initializeExtensions();
        }

        if ($this->eventDispatcher !== null) {
            return $this->eventDispatcher->dispatch($event);
        }

        foreach ($this->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                return $event;
            }

            $listener($event);
        }

        return $event;
    }

    public function setEventDispatcher(EventDispatcherInterface $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listenerData as $listenerData) {
            \assert($listenerData instanceof ListenerData);

if (! \is_a($event, $listenerData->getEvent())) {
                continue;
            }

            yield function (object $event) use ($listenerData) {
                if (! $this->extensionsInitialized) {
                    $this->initializeExtensions();
                }

                return \call_user_func($listenerData->getListener(), $event);
            };
        }
    }

public function getInlineParsers(): iterable
    {
        if (! $this->extensionsInitialized) {
            $this->initializeExtensions();
        }

        return $this->inlineParsers->getIterator();
    }

    public function getSlugNormalizer(): TextNormalizerInterface
    {
        if ($this->slugNormalizer === null) {
            $normalizer = $this->config->get('slug_normalizer/instance');
            \assert($normalizer instanceof TextNormalizerInterface);
            $this->injectEnvironmentAndConfigurationIfNeeded($normalizer);

            if ($this->config->get('slug_normalizer/unique') !== UniqueSlugNormalizerInterface::DISABLED && ! $normalizer instanceof UniqueSlugNormalizerInterface) {

                $reserved   = $this->config->get('slug_normalizer/reserved');
                $normalizer = new UniqueSlugNormalizer($normalizer, $reserved);
            }

            if ($normalizer instanceof UniqueSlugNormalizerInterface) {
                if ($this->config->get('slug_normalizer/unique') === UniqueSlugNormalizerInterface::PER_DOCUMENT) {
                    $this->addEventListener(DocumentParsedEvent::class, [$normalizer, 'clearHistory'], -1000);
                }
            }

            $this->slugNormalizer = $normalizer;
        }

        return $this->slugNormalizer;
    }

private function assertUninitialized(string $message): void
    {
        if ($this->extensionsInitialized) {
            throw new AlreadyInitializedException($message . ' Extensions have already been initialized.');
        }
    }

    public static function createDefaultConfiguration(): Configuration
    {
        return new Configuration([
            'html_input' => Expect::anyOf(HtmlFilter::STRIP, HtmlFilter::ALLOW, HtmlFilter::ESCAPE)->default(HtmlFilter::ALLOW),
            'allow_unsafe_links' => Expect::bool(true),
            'max_nesting_level' => Expect::type('int')->default(PHP_INT_MAX),
            'max_delimiters_per_line' => Expect::type('int')->default(PHP_INT_MAX),
            'renderer' => Expect::structure([
                'block_separator' => Expect::string("\n"),
                'inner_separator' => Expect::string("\n"),
                'soft_break' => Expect::string("\n"),
            ]),
            'xml' => Expect::structure([
                'max_indentation_level' => Expect::int()->min(0)->default(16),
            ]),
            'slug_normalizer' => Expect::structure([
                'instance' => Expect::type(TextNormalizerInterface::class)->default(new SlugNormalizer()),
                'max_length' => Expect::int()->min(0)->default(255),
                'unique' => Expect::anyOf(UniqueSlugNormalizerInterface::DISABLED, UniqueSlugNormalizerInterface::PER_ENVIRONMENT, UniqueSlugNormalizerInterface::PER_DOCUMENT)->default(UniqueSlugNormalizerInterface::PER_DOCUMENT),
                'reserved' => Expect::listOf('string')->default([]),
            ]),
        ]);
    }
}
}

// league/commonmark/src/Environment/EnvironmentAwareInterface.php


namespace CMS\Vendor\League\CommonMark\Environment {

interface EnvironmentAwareInterface
{
    public function setEnvironment(EnvironmentInterface $environment): void;
}
}

// league/commonmark/src/Event/AbstractEvent.php


namespace CMS\Vendor\League\CommonMark\Event {

use Psr\EventDispatcher\StoppableEventInterface;

abstract class AbstractEvent implements StoppableEventInterface
{

    private bool $propagationStopped = false;

final public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

final public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
}

// league/commonmark/src/Event/DocumentParsedEvent.php


namespace CMS\Vendor\League\CommonMark\Event {

use CMS\Vendor\League\CommonMark\Node\Block\Document;

final class DocumentParsedEvent extends AbstractEvent
{

    private Document $document;

    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }
}
}

// league/commonmark/src/Event/DocumentPreParsedEvent.php


namespace CMS\Vendor\League\CommonMark\Event {

use CMS\Vendor\League\CommonMark\Input\MarkdownInputInterface;
use CMS\Vendor\League\CommonMark\Node\Block\Document;

final class DocumentPreParsedEvent extends AbstractEvent
{

    private Document $document;

    private MarkdownInputInterface $markdown;

    public function __construct(Document $document, MarkdownInputInterface $markdown)
    {
        $this->document = $document;
        $this->markdown = $markdown;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getMarkdown(): MarkdownInputInterface
    {
        return $this->markdown;
    }

    public function replaceMarkdown(MarkdownInputInterface $markdownInput): void
    {
        $this->markdown = $markdownInput;
    }
}
}

// league/commonmark/src/Event/DocumentPreRenderEvent.php


namespace CMS\Vendor\League\CommonMark\Event {

use CMS\Vendor\League\CommonMark\Node\Block\Document;

final class DocumentPreRenderEvent extends AbstractEvent
{

    private Document $document;

private string $format;

    public function __construct(Document $document, string $format)
    {
        $this->document = $document;
        $this->format   = $format;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getFormat(): string
    {
        return $this->format;
    }
}
}

// league/commonmark/src/Event/DocumentRenderedEvent.php


namespace CMS\Vendor\League\CommonMark\Event {

use CMS\Vendor\League\CommonMark\Output\RenderedContentInterface;

final class DocumentRenderedEvent extends AbstractEvent
{
    private RenderedContentInterface $output;

    public function __construct(RenderedContentInterface $output)
    {
        $this->output = $output;
    }

public function getOutput(): RenderedContentInterface
    {
        return $this->output;
    }

public function replaceOutput(RenderedContentInterface $output): void
    {
        $this->output = $output;
    }
}
}

// league/commonmark/src/Event/ListenerData.php


namespace CMS\Vendor\League\CommonMark\Event {

final class ListenerData
{

    private string $event;

private $listener;

public function __construct(string $event, callable $listener)
    {
        $this->event    = $event;
        $this->listener = $listener;
    }

public function getEvent(): string
    {
        return $this->event;
    }

    public function getListener(): callable
    {
        return $this->listener;
    }
}
}

// league/commonmark/src/Exception/CommonMarkException.php


namespace CMS\Vendor\League\CommonMark\Exception {

interface CommonMarkException extends \Throwable
{
}
}

// league/commonmark/src/Exception/LogicException.php


namespace CMS\Vendor\League\CommonMark\Exception {

class LogicException extends \LogicException implements CommonMarkException
{
}
}

// league/commonmark/src/Exception/AlreadyInitializedException.php


namespace CMS\Vendor\League\CommonMark\Exception {

class AlreadyInitializedException extends LogicException implements CommonMarkException
{
}
}

// league/commonmark/src/Exception/InvalidArgumentException.php


namespace CMS\Vendor\League\CommonMark\Exception {

class InvalidArgumentException extends \InvalidArgumentException implements CommonMarkException
{
}
}

// league/commonmark/src/Exception/UnexpectedEncodingException.php


namespace CMS\Vendor\League\CommonMark\Exception {

final class UnexpectedEncodingException extends \RuntimeException implements CommonMarkException
{
}
}

// league/commonmark/src/Extension/Attributes/Util/AttributesHelper.php


namespace CMS\Vendor\League\CommonMark\Extension\Attributes\Util {

use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class AttributesHelper
{
    private const SINGLE_ATTRIBUTE = '\s*([.]-?[_a-z][^\s.}]*|[#][^\s}]+|' . RegexHelper::PARTIAL_ATTRIBUTENAME . RegexHelper::PARTIAL_ATTRIBUTEVALUESPEC . ')\s*';
    private const ATTRIBUTE_LIST   = '/\G{:?(' . self::SINGLE_ATTRIBUTE . ')+}/i';

private const WHITESPACE = " \t\n\r\0\x0B\x0C";

public static function parseAttributes(Cursor $cursor): array
    {
        $state = $cursor->saveState();
        $cursor->advanceToNextNonSpaceOrNewline();

if ($cursor->getCharacter() !== '{') {
            $cursor->restoreState($state);

            return [];
        }

$attributeExpression = $cursor->matchInPlace(self::ATTRIBUTE_LIST);
        if ($attributeExpression === null) {
            $cursor->restoreState($state);

            return [];
        }

$attributeExpression = \ltrim(\substr($attributeExpression, 1, -1), ':');
        $attributeCursor     = new Cursor($attributeExpression);

$attributes = [];
        while ($attribute = \trim((string) $attributeCursor->matchInPlace('/\G' . self::SINGLE_ATTRIBUTE . '/i'), self::WHITESPACE)) {
            if ($attribute[0] === '#') {
                $attributes['id'] = \substr($attribute, 1);

                continue;
            }

            if ($attribute[0] === '.') {
                $attributes['class'][] = \substr($attribute, 1);

                continue;
            }

[$name, $value] = \explode('=', $attribute, 2);

            if ($value === 'true') {
                $attributes[$name] = true;
                continue;
            }

            $first = $value[0];
            $last  = \substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'") && \strlen($value) > 1) {
                $value = \substr($value, 1, -1);
            }

            if (\strtolower(\trim($name, self::WHITESPACE)) === 'class') {
                foreach (\array_filter(\explode(' ', \trim($value, self::WHITESPACE))) as $class) {
                    $attributes['class'][] = $class;
                }
            } else {
                $attributes[\trim($name, self::WHITESPACE)] = \trim($value, self::WHITESPACE);
            }
        }

        if (isset($attributes['class'])) {
            $attributes['class'] = \implode(' ', (array) $attributes['class']);
        }

        return $attributes;
    }

public static function mergeAttributes($attributes1, $attributes2): array
    {
        $attributes = [];
        foreach ([$attributes1, $attributes2] as $arg) {
            if ($arg instanceof Node) {
                $arg = $arg->data->get('attributes');
            }

$arg = (array) $arg;
            if (isset($arg['class'])) {
                foreach (self::classList($arg['class']) as $class) {
                    $attributes['class'][] = $class;
                }

                unset($arg['class']);
            }

            $attributes = \array_merge($attributes, $arg);
        }

        if (isset($attributes['class'])) {
            $attributes['class'] = \implode(' ', $attributes['class']);
        }

        return $attributes;
    }

public static function classList($class): array
    {
        if (\is_string($class)) {
            return \array_values(\array_filter(\explode(' ', \trim($class))));
        }

        return \array_values((array) $class);
    }

public static function filterAttributes(array $attributes, array $allowList, bool $allowUnsafeLinks): array
    {
        $allowList = \array_fill_keys($allowList, true);

        foreach ($attributes as $name => $value) {

if (\preg_match('/^' . RegexHelper::PARTIAL_ATTRIBUTENAME . '$/i', (string) $name) !== 1) {
                unset($attributes[$name]);
                continue;
            }

            $attrNameLower = \strtolower((string) $name);

if (! $allowUnsafeLinks && ($attrNameLower === 'href' || $attrNameLower === 'src') && \is_string($value) && RegexHelper::isLinkPotentiallyUnsafe($value)) {
                unset($attributes[$name]);
                continue;
            }

if ($allowList === []) {

                if (\str_starts_with($attrNameLower, 'on')) {
                    unset($attributes[$name]);
                }

                continue;
            }

if (! isset($allowList[$name])) {
                unset($attributes[$name]);
            }
        }

        return $attributes;
    }
}
}

// league/commonmark/src/Extension/ExtensionInterface.php


namespace CMS\Vendor\League\CommonMark\Extension {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;

interface ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void;
}
}

// league/commonmark/src/Extension/ConfigurableExtensionInterface.php


namespace CMS\Vendor\League\CommonMark\Extension {

use CMS\Vendor\League\Config\ConfigurationBuilderInterface;

interface ConfigurableExtensionInterface extends ExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void;
}
}

// league/commonmark/src/Extension/Autolink/AutolinkExtension.php


namespace CMS\Vendor\League\CommonMark\Extension\Autolink {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CMS\Vendor\League\CommonMark\Extension\ConfigurableExtensionInterface;
use CMS\Vendor\League\Config\ConfigurationBuilderInterface;
use CMS\Vendor\Nette\Schema\Expect;

final class AutolinkExtension implements ConfigurableExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        $builder->addSchema('autolink', Expect::structure([
            'allowed_protocols' => Expect::listOf('string')->default(['http', 'https', 'ftp'])->mergeDefaults(false),
            'default_protocol' => Expect::string()->default('http'),
        ]));
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addInlineParser(new EmailAutolinkParser());
        $environment->addInlineParser(new UrlAutolinkParser(
            $environment->getConfiguration()->get('autolink.allowed_protocols'),
            $environment->getConfiguration()->get('autolink.default_protocol'),
        ));
    }
}
}

// league/commonmark/src/Extension/Autolink/EmailAutolinkParser.php


namespace CMS\Vendor\League\CommonMark\Extension\Autolink {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;

final class EmailAutolinkParser implements InlineParserInterface
{
    private const REGEX = '[A-Za-z0-9.\-_+]+@[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_.]+';

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(self::REGEX);
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $email = $inlineContext->getFullMatch();

        if (\in_array(\substr($email, -1), ['-', '_'], true)) {
            return false;
        }

if (\substr($email, -1) === '.') {
            $email = \substr($email, 0, -1);
        }

        $inlineContext->getCursor()->advanceBy(\strlen($email));
        $inlineContext->getContainer()->appendChild(new Link('mailto:' . $email, $email));

        return true;
    }
}
}

// league/commonmark/src/Extension/Autolink/UrlAutolinkParser.php


namespace CMS\Vendor\League\CommonMark\Extension\Autolink {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;

final class UrlAutolinkParser implements InlineParserInterface
{
    private const ALLOWED_AFTER = [null, ' ', "\t", "\n", "\x0b", "\x0c", "\x0d", '*', '_', '~', '('];

private const REGEX = '~
        (
            # Must start with a supported scheme + auth, or "www"
            (?:
                (?:%s)://                                                                            # protocol
                (?:(?:(?:[\_\.\pL\pN-]|%%[0-9A-Fa-f]{2})+:)?((?:[\_\.\pL\pN-]|%%[0-9A-Fa-f]{2})+)@)? # basic auth
            |www\.)
            (?:
                (?:
                    (?:xn--[a-z0-9-]++\.)*+xn--[a-z0-9-]++            # a domain name using punycode
                        |
                    (?:[\pL\pN\pS\pM\-\_]++\.){1,127}[\pL\pN\pM]++    # a multi-level domain name; total length must be 253 bytes or less
                        |
                    [a-z0-9\-\_]++                                    # a single-level domain name
                )\.?
                    |                                                 # or
                \d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}                    # an IP address
                    |                                                 # or
                \[
                    (?:(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){6})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:::(?:(?:(?:[0-9a-f]{1,4})):){5})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){4})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,1}(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){3})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,2}(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){2})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,3}(?:(?:[0-9a-f]{1,4})))?::(?:(?:[0-9a-f]{1,4})):)(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,4}(?:(?:[0-9a-f]{1,4})))?::)(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,5}(?:(?:[0-9a-f]{1,4})))?::)(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,6}(?:(?:[0-9a-f]{1,4})))?::))))
                \]  # an IPv6 address
            )
            (?::[0-9]+)?                              # a port (optional)
            (?:/ (?:[\pL\pN\-._\~!$&\'()*+,;=:@]|%%[0-9A-Fa-f]{2})* )*        # a path
            (?:\? (?:[\pL\pN\-._\~!$&\'\[\]()*+,;=:@/?]|%%[0-9A-Fa-f]{2})* )? # a query (optional)
            (?:\# (?:[\pL\pN\-._\~!$&\'()*+,;=:@/?]|%%[0-9A-Fa-f]{2})* )?     # a fragment (optional)
        )~ixuA';

private array $prefixes = ['www.'];

private string $finalRegex;

    private string $defaultProtocol;

public function __construct(array $allowedProtocols = ['http', 'https', 'ftp'], string $defaultProtocol = 'http')
    {

        $this->finalRegex = \sprintf(self::REGEX, \implode('|', $allowedProtocols));

        foreach ($allowedProtocols as $protocol) {
            $this->prefixes[] = $protocol . '://';
        }

        $this->defaultProtocol = $defaultProtocol;
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::oneOf(...$this->prefixes);
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();

$previousChar = $cursor->peek(-1);
        if (! \in_array($previousChar, self::ALLOWED_AFTER, true)) {
            return false;
        }

if (! \preg_match($this->finalRegex, $cursor->getLine(), $matches, 0, $cursor->getBytePosition())) {
            return false;
        }

$url = \rtrim($matches[0], '?!.,:*_~');

if (\preg_match('/(.+)(&[A-Za-z0-9]+;)$/', $url, $matches)) {
            $url = $matches[1];
        }

if (\substr($url, -1) === ')' && ($diff = self::diffParens($url)) > 0) {
            $url = \substr($url, 0, -$diff);
        }

        $cursor->advanceBy(\mb_strlen($url, 'UTF-8'));

if (\substr($url, 0, 4) === 'www.') {
            $inlineContext->getContainer()->appendChild(new Link($this->defaultProtocol . '://' . $url, $url));

            return true;
        }

        $inlineContext->getContainer()->appendChild(new Link($url, $url));

        return true;
    }

private static function diffParens(string $content): int
    {

\preg_match_all('/[()]/', $content, $matches);

        $charCount = ['(' => 0, ')' => 0];
        foreach ($matches[0] as $char) {
            $charCount[$char]++;
        }

        return $charCount[')'] - $charCount['('];
    }
}
}

// league/commonmark/src/Extension/CommonMark/CommonMarkCoreExtension.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Delimiter\Processor\EmphasisDelimiterProcessor;
use CMS\Vendor\League\CommonMark\Extension\ConfigurableExtensionInterface;
use CMS\Vendor\League\CommonMark\Node as CoreNode;
use CMS\Vendor\League\CommonMark\Parser as CoreParser;
use CMS\Vendor\League\CommonMark\Renderer as CoreRenderer;
use CMS\Vendor\League\Config\ConfigurationBuilderInterface;
use CMS\Vendor\Nette\Schema\Expect;

final class CommonMarkCoreExtension implements ConfigurableExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        $builder->addSchema('commonmark', Expect::structure([
            'use_asterisk' => Expect::bool(true),
            'use_underscore' => Expect::bool(true),
            'enable_strong' => Expect::bool(true),
            'enable_em' => Expect::bool(true),
            'unordered_list_markers' => Expect::listOf('string')->min(1)->default(['*', '+', '-'])->mergeDefaults(false),
        ]));
    }

public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addBlockStartParser(new Parser\Block\BlockQuoteStartParser(),     70)
            ->addBlockStartParser(new Parser\Block\HeadingStartParser(),        60)
            ->addBlockStartParser(new Parser\Block\FencedCodeStartParser(),     50)
            ->addBlockStartParser(new Parser\Block\HtmlBlockStartParser(),      40)
            ->addBlockStartParser(new Parser\Block\ThematicBreakStartParser(),  20)
            ->addBlockStartParser(new Parser\Block\ListBlockStartParser(),      10)
            ->addBlockStartParser(new Parser\Block\IndentedCodeStartParser(), -100)

            ->addInlineParser(new CoreParser\Inline\NewlineParser(), 200)
            ->addInlineParser(new Parser\Inline\BacktickParser(),    150)
            ->addInlineParser(new Parser\Inline\EscapableParser(),    80)
            ->addInlineParser(new Parser\Inline\EntityParser(),       70)
            ->addInlineParser(new Parser\Inline\AutolinkParser(),     50)
            ->addInlineParser(new Parser\Inline\HtmlInlineParser(),   40)
            ->addInlineParser(new Parser\Inline\CloseBracketParser(), 30)
            ->addInlineParser(new Parser\Inline\OpenBracketParser(),  20)
            ->addInlineParser(new Parser\Inline\BangParser(),         10)

            ->addRenderer(Node\Block\BlockQuote::class,    new Renderer\Block\BlockQuoteRenderer(),    0)
            ->addRenderer(CoreNode\Block\Document::class,  new CoreRenderer\Block\DocumentRenderer(),  0)
            ->addRenderer(Node\Block\FencedCode::class,    new Renderer\Block\FencedCodeRenderer(),    0)
            ->addRenderer(Node\Block\Heading::class,       new Renderer\Block\HeadingRenderer(),       0)
            ->addRenderer(Node\Block\HtmlBlock::class,     new Renderer\Block\HtmlBlockRenderer(),     0)
            ->addRenderer(Node\Block\IndentedCode::class,  new Renderer\Block\IndentedCodeRenderer(),  0)
            ->addRenderer(Node\Block\ListBlock::class,     new Renderer\Block\ListBlockRenderer(),     0)
            ->addRenderer(Node\Block\ListItem::class,      new Renderer\Block\ListItemRenderer(),      0)
            ->addRenderer(CoreNode\Block\Paragraph::class, new CoreRenderer\Block\ParagraphRenderer(), 0)
            ->addRenderer(Node\Block\ThematicBreak::class, new Renderer\Block\ThematicBreakRenderer(), 0)

            ->addRenderer(Node\Inline\Code::class,        new Renderer\Inline\CodeRenderer(),        0)
            ->addRenderer(Node\Inline\Emphasis::class,    new Renderer\Inline\EmphasisRenderer(),    0)
            ->addRenderer(Node\Inline\HtmlInline::class,  new Renderer\Inline\HtmlInlineRenderer(),  0)
            ->addRenderer(Node\Inline\Image::class,       new Renderer\Inline\ImageRenderer(),       0)
            ->addRenderer(Node\Inline\Link::class,        new Renderer\Inline\LinkRenderer(),        0)
            ->addRenderer(CoreNode\Inline\Newline::class, new CoreRenderer\Inline\NewlineRenderer(), 0)
            ->addRenderer(Node\Inline\Strong::class,      new Renderer\Inline\StrongRenderer(),      0)
            ->addRenderer(CoreNode\Inline\Text::class,    new CoreRenderer\Inline\TextRenderer(),    0)
        ;

        if ($environment->getConfiguration()->get('commonmark/use_asterisk')) {
            $environment->addDelimiterProcessor(new EmphasisDelimiterProcessor('*'));
        }

        if ($environment->getConfiguration()->get('commonmark/use_underscore')) {
            $environment->addDelimiterProcessor(new EmphasisDelimiterProcessor('_'));
        }
    }
}
}

// league/config/src/ConfigurationAwareInterface.php


namespace CMS\Vendor\League\Config {

interface ConfigurationAwareInterface
{
    public function setConfiguration(ConfigurationInterface $configuration): void;
}
}

// league/commonmark/src/Extension/CommonMark/Delimiter/Processor/EmphasisDelimiterProcessor.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Delimiter\Processor {

use CMS\Vendor\League\CommonMark\Delimiter\DelimiterInterface;
use CMS\Vendor\League\CommonMark\Delimiter\Processor\CacheableDelimiterProcessorInterface;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use CMS\Vendor\League\CommonMark\Node\Inline\AbstractStringContainer;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class EmphasisDelimiterProcessor implements CacheableDelimiterProcessorInterface, ConfigurationAwareInterface
{

    private string $char;

private ConfigurationInterface $config;

public function __construct(string $char)
    {
        $this->char = $char;
    }

    public function getOpeningCharacter(): string
    {
        return $this->char;
    }

    public function getClosingCharacter(): string
    {
        return $this->char;
    }

    public function getMinLength(): int
    {
        return 1;
    }

    public function getDelimiterUse(DelimiterInterface $opener, DelimiterInterface $closer): int
    {

        if (($opener->canClose() || $closer->canOpen()) && $closer->getOriginalLength() % 3 !== 0 && ($opener->getOriginalLength() + $closer->getOriginalLength()) % 3 === 0) {
            return 0;
        }

if ($opener->getLength() >= 2 && $closer->getLength() >= 2) {
            if ($this->config->get('commonmark/enable_strong')) {
                return 2;
            }

            return 0;
        }

        if ($this->config->get('commonmark/enable_em')) {
            return 1;
        }

        return 0;
    }

    public function process(AbstractStringContainer $opener, AbstractStringContainer $closer, int $delimiterUse): void
    {
        if ($delimiterUse === 1) {
            $emphasis = new Emphasis($this->char);
        } elseif ($delimiterUse === 2) {
            $emphasis = new Strong($this->char . $this->char);
        } else {
            return;
        }

        $next = $opener->next();
        while ($next !== null && $next !== $closer) {
            $tmp = $next->next();
            $emphasis->appendChild($next);
            $next = $tmp;
        }

        $opener->insertAfter($emphasis);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function getCacheKey(DelimiterInterface $closer): string
    {
        return \sprintf(
            '%s-%s-%d-%d',
            $this->char,
            $closer->canOpen() ? 'canOpen' : 'cannotOpen',
            $closer->getOriginalLength() % 3,

\min($closer->getLength(), 2),
        );
    }
}
}

// league/commonmark/src/Node/Node.php


namespace CMS\Vendor\League\CommonMark\Node {

use CMS\Vendor\Dflydev\DotAccessData\Data;
use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;

abstract class Node
{

    public Data $data;

protected int $depth = 0;

protected ?Node $parent = null;

protected ?Node $previous = null;

protected ?Node $next = null;

protected ?Node $firstChild = null;

protected ?Node $lastChild = null;

    public function __construct()
    {
        $this->data = new Data([
            'attributes' => [],
        ]);
    }

    public function previous(): ?Node
    {
        return $this->previous;
    }

    public function next(): ?Node
    {
        return $this->next;
    }

    public function parent(): ?Node
    {
        return $this->parent;
    }

    protected function setParent(?Node $node = null): void
    {
        $this->parent = $node;
        $this->depth  = $node === null ? 0 : $node->depth + 1;
    }

public function insertAfter(Node $sibling): void
    {
        $sibling->detach();
        $sibling->next = $this->next;

        if ($sibling->next) {
            $sibling->next->previous = $sibling;
        }

        $sibling->previous = $this;
        $this->next        = $sibling;
        $sibling->setParent($this->parent);

        if (! $sibling->next && $sibling->parent) {
            $sibling->parent->lastChild = $sibling;
        }
    }

public function insertBefore(Node $sibling): void
    {
        $sibling->detach();
        $sibling->previous = $this->previous;

        if ($sibling->previous) {
            $sibling->previous->next = $sibling;
        }

        $sibling->next  = $this;
        $this->previous = $sibling;
        $sibling->setParent($this->parent);

        if (! $sibling->previous && $sibling->parent) {
            $sibling->parent->firstChild = $sibling;
        }
    }

    public function replaceWith(Node $replacement): void
    {
        $replacement->detach();
        $this->insertAfter($replacement);
        $this->detach();
    }

    public function detach(): void
    {
        if ($this->previous) {
            $this->previous->next = $this->next;
        } elseif ($this->parent) {
            $this->parent->firstChild = $this->next;
        }

        if ($this->next) {
            $this->next->previous = $this->previous;
        } elseif ($this->parent) {
            $this->parent->lastChild = $this->previous;
        }

        $this->parent   = null;
        $this->next     = null;
        $this->previous = null;
        $this->depth    = 0;
    }

    public function hasChildren(): bool
    {
        return $this->firstChild !== null;
    }

    public function firstChild(): ?Node
    {
        return $this->firstChild;
    }

    public function lastChild(): ?Node
    {
        return $this->lastChild;
    }

public function children(): iterable
    {
        $children = [];
        for ($current = $this->firstChild; $current !== null; $current = $current->next) {
            $children[] = $current;
        }

        return $children;
    }

    public function appendChild(Node $child): void
    {
        if ($this->lastChild) {
            $this->lastChild->insertAfter($child);
        } else {
            $child->detach();
            $child->setParent($this);
            $this->lastChild = $this->firstChild = $child;
        }
    }

public function prependChild(Node $child): void
    {
        if ($this->firstChild) {
            $this->firstChild->insertBefore($child);
        } else {
            $child->detach();
            $child->setParent($this);
            $this->lastChild = $this->firstChild = $child;
        }
    }

public function detachChildren(): void
    {
        foreach ($this->children() as $children) {
            $children->setParent(null);
        }

        $this->firstChild = $this->lastChild = null;
    }

public function replaceChildren(iterable $children): void
    {
        $this->detachChildren();
        foreach ($children as $item) {
            $this->appendChild($item);
        }
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function walker(): NodeWalker
    {
        return new NodeWalker($this);
    }

    public function iterator(int $flags = 0): NodeIterator
    {
        return new NodeIterator($this, $flags);
    }

public function __clone()
    {

        $this->parent   = null;
        $this->previous = null;
        $this->next     = null;

$children         = $this->children();
        $this->firstChild = $this->lastChild = null;

foreach ($children as $child) {
            $this->appendChild(clone $child);
        }

$this->data = clone $this->data;
    }

    public static function assertInstanceOf(Node $node): void
    {
        if (! $node instanceof static) {
            throw new InvalidArgumentException(\sprintf('Incompatible node type: expected %s, got %s', static::class, \get_class($node)));
        }
    }
}
}

// league/commonmark/src/Node/Block/AbstractBlock.php


namespace CMS\Vendor\League\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;
use CMS\Vendor\League\CommonMark\Node\Node;

abstract class AbstractBlock extends Node
{
    protected ?int $startLine = null;

    protected ?int $endLine = null;

    protected function setParent(?Node $node = null): void
    {
        if ($node && ! $node instanceof self) {
            throw new InvalidArgumentException('Parent of block must also be block (cannot be inline)');
        }

        parent::setParent($node);
    }

    public function setStartLine(?int $startLine): void
    {
        $this->startLine = $startLine;
        if ($this->endLine === null) {
            $this->endLine = $startLine;
        }
    }

    public function getStartLine(): ?int
    {
        return $this->startLine;
    }

    public function setEndLine(?int $endLine): void
    {
        $this->endLine = $endLine;
    }

    public function getEndLine(): ?int
    {
        return $this->endLine;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/BlockQuote.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

class BlockQuote extends AbstractBlock
{
}
}

// league/commonmark/src/Node/StringContainerInterface.php


namespace CMS\Vendor\League\CommonMark\Node {

interface StringContainerInterface
{
    public function setLiteral(string $literal): void;

    public function getLiteral(): string;
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/FencedCode.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\StringContainerInterface;

final class FencedCode extends AbstractBlock implements StringContainerInterface
{
    private ?string $info = null;

    private string $literal = '';

    private int $length;

    private string $char;

    private int $offset;

    public function __construct(int $length, string $char, int $offset)
    {
        parent::__construct();

        $this->length = $length;
        $this->char   = $char;
        $this->offset = $offset;
    }

    public function getInfo(): ?string
    {
        return $this->info;
    }

public function getInfoWords(): array
    {
        return \preg_split('/\s+/', $this->info ?? '') ?: [];
    }

    public function setInfo(string $info): void
    {
        $this->info = $info;
    }

    public function getLiteral(): string
    {
        return $this->literal;
    }

    public function setLiteral(string $literal): void
    {
        $this->literal = $literal;
    }

    public function getChar(): string
    {
        return $this->char;
    }

    public function setChar(string $char): void
    {
        $this->char = $char;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function setLength(int $length): void
    {
        $this->length = $length;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/Heading.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

final class Heading extends AbstractBlock
{
    private int $level;

    public function __construct(int $level)
    {
        parent::__construct();

        $this->level = $level;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }
}
}

// league/commonmark/src/Node/RawMarkupContainerInterface.php


namespace CMS\Vendor\League\CommonMark\Node {

interface RawMarkupContainerInterface extends StringContainerInterface
{
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/HtmlBlock.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\RawMarkupContainerInterface;

final class HtmlBlock extends AbstractBlock implements RawMarkupContainerInterface
{

    public const TYPE_1_CODE_CONTAINER = 1;
    public const TYPE_2_COMMENT        = 2;
    public const TYPE_3                = 3;
    public const TYPE_4                = 4;
    public const TYPE_5_CDATA          = 5;
    public const TYPE_6_BLOCK_ELEMENT  = 6;
    public const TYPE_7_MISC_ELEMENT   = 7;

private int $type;

    private string $literal = '';

public function __construct(int $type)
    {
        parent::__construct();

        $this->type = $type;
    }

public function getType(): int
    {
        return $this->type;
    }

public function setType(int $type): void
    {
        $this->type = $type;
    }

    public function getLiteral(): string
    {
        return $this->literal;
    }

    public function setLiteral(string $literal): void
    {
        $this->literal = $literal;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/IndentedCode.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\StringContainerInterface;

final class IndentedCode extends AbstractBlock implements StringContainerInterface
{
    private string $literal = '';

    public function getLiteral(): string
    {
        return $this->literal;
    }

    public function setLiteral(string $literal): void
    {
        $this->literal = $literal;
    }
}
}

// league/commonmark/src/Node/Block/TightBlockInterface.php


namespace CMS\Vendor\League\CommonMark\Node\Block {

interface TightBlockInterface
{
    public function isTight(): bool;

    public function setTight(bool $tight): void;
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/ListBlock.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\Block\TightBlockInterface;

class ListBlock extends AbstractBlock implements TightBlockInterface
{
    public const TYPE_BULLET  = 'bullet';
    public const TYPE_ORDERED = 'ordered';

    public const DELIM_PERIOD = 'period';
    public const DELIM_PAREN  = 'paren';

    protected bool $tight = false;

protected ListData $listData;

    public function __construct(ListData $listData)
    {
        parent::__construct();

        $this->listData = $listData;
    }

    public function getListData(): ListData
    {
        return $this->listData;
    }

    public function isTight(): bool
    {
        return $this->tight;
    }

    public function setTight(bool $tight): void
    {
        $this->tight = $tight;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/ListData.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

class ListData
{
    public ?int $start = null;

    public int $padding = 0;

public string $type;

public ?string $delimiter = null;

    public ?string $bulletChar = null;

    public int $markerOffset;

    public function equals(ListData $data): bool
    {
        return $this->type === $data->type &&
            $this->delimiter === $data->delimiter &&
            $this->bulletChar === $data->bulletChar;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/ListItem.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

class ListItem extends AbstractBlock
{

    protected ListData $listData;

    public function __construct(ListData $listData)
    {
        parent::__construct();

        $this->listData = $listData;
    }

    public function getListData(): ListData
    {
        return $this->listData;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Block/ThematicBreak.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

class ThematicBreak extends AbstractBlock
{
}
}

// league/commonmark/src/Node/Inline/AbstractInline.php


namespace CMS\Vendor\League\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Node;

abstract class AbstractInline extends Node
{
}
}

// league/commonmark/src/Extension/CommonMark/Node/Inline/AbstractWebResource.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractInline;

abstract class AbstractWebResource extends AbstractInline
{
    protected string $url;

    public function __construct(string $url)
    {
        parent::__construct();

        $this->url = $url;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }
}
}

// league/commonmark/src/Node/Inline/AbstractStringContainer.php


namespace CMS\Vendor\League\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\StringContainerInterface;

abstract class AbstractStringContainer extends AbstractInline implements StringContainerInterface
{
    protected string $literal = '';

public function __construct(string $contents = '', array $data = [])
    {
        parent::__construct();

        $this->literal = $contents;
        if (\count($data) > 0) {
            $this->data->import($data);
        }
    }

    public function getLiteral(): string
    {
        return $this->literal;
    }

    public function setLiteral(string $literal): void
    {
        $this->literal = $literal;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Inline/Code.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractStringContainer;

class Code extends AbstractStringContainer
{
}
}

// league/commonmark/src/Node/Inline/DelimitedInterface.php


namespace CMS\Vendor\League\CommonMark\Node\Inline {

interface DelimitedInterface
{
    public function getOpeningDelimiter(): string;

    public function getClosingDelimiter(): string;
}
}

// league/commonmark/src/Extension/CommonMark/Node/Inline/Emphasis.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractInline;
use CMS\Vendor\League\CommonMark\Node\Inline\DelimitedInterface;

final class Emphasis extends AbstractInline implements DelimitedInterface
{
    private string $delimiter;

    public function __construct(string $delimiter = '_')
    {
        parent::__construct();

        $this->delimiter = $delimiter;
    }

    public function getOpeningDelimiter(): string
    {
        return $this->delimiter;
    }

    public function getClosingDelimiter(): string
    {
        return $this->delimiter;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Inline/HtmlInline.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractStringContainer;
use CMS\Vendor\League\CommonMark\Node\RawMarkupContainerInterface;

final class HtmlInline extends AbstractStringContainer implements RawMarkupContainerInterface
{
}
}

// league/commonmark/src/Extension/CommonMark/Node/Inline/Image.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Text;

class Image extends AbstractWebResource
{
    protected ?string $title = null;

    public function __construct(string $url, ?string $label = null, ?string $title = null)
    {
        parent::__construct($url);

        if ($label !== null && $label !== '') {
            $this->appendChild(new Text($label));
        }

        $this->title = $title;
    }

    public function getTitle(): ?string
    {
        if ($this->title === '') {
            return null;
        }

        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Inline/Link.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Text;

class Link extends AbstractWebResource
{
    protected ?string $title = null;

    public function __construct(string $url, ?string $label = null, ?string $title = null)
    {
        parent::__construct($url);

        if ($label !== null && $label !== '') {
            $this->appendChild(new Text($label));
        }

        $this->title = $title;
    }

    public function getTitle(): ?string
    {
        if ($this->title === '') {
            return null;
        }

        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Node/Inline/Strong.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractInline;
use CMS\Vendor\League\CommonMark\Node\Inline\DelimitedInterface;

final class Strong extends AbstractInline implements DelimitedInterface
{
    private string $delimiter;

    public function __construct(string $delimiter = '**')
    {
        parent::__construct();

        $this->delimiter = $delimiter;
    }

    public function getOpeningDelimiter(): string
    {
        return $this->delimiter;
    }

    public function getClosingDelimiter(): string
    {
        return $this->delimiter;
    }
}
}

// league/commonmark/src/Parser/Block/BlockContinueParserInterface.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Parser\Cursor;

interface BlockContinueParserInterface
{

    public function getBlock(): AbstractBlock;

public function isContainer(): bool;

public function canHaveLazyContinuationLines(): bool;

public function canContain(AbstractBlock $childBlock): bool;

public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue;

public function addLine(string $line): void;

public function closeBlock(): void;
}
}

// league/commonmark/src/Parser/Block/AbstractBlockContinueParser.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

abstract class AbstractBlockContinueParser implements BlockContinueParserInterface
{
    public function isContainer(): bool
    {
        return false;
    }

    public function canHaveLazyContinuationLines(): bool
    {
        return false;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return false;
    }

    public function addLine(string $line): void
    {
    }

    public function closeBlock(): void
    {
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/BlockQuoteParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;

final class BlockQuoteParser extends AbstractBlockContinueParser
{

    private BlockQuote $block;

    public function __construct()
    {
        $this->block = new BlockQuote();
    }

    public function getBlock(): BlockQuote
    {
        return $this->block;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if (! $cursor->isIndented() && $cursor->getNextNonSpaceCharacter() === '>') {
            $cursor->advanceToNextNonSpaceOrTab();
            $cursor->advanceBy(1);
            $cursor->advanceBySpaceOrTab();

            return BlockContinue::at($cursor);
        }

        return BlockContinue::none();
    }
}
}

// league/commonmark/src/Parser/Block/BlockStartParserInterface.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;

interface BlockStartParserInterface
{

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart;
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/BlockQuoteStartParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;

final class BlockQuoteStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented()) {
            return BlockStart::none();
        }

        if ($cursor->getNextNonSpaceCharacter() !== '>') {
            return BlockStart::none();
        }

        $cursor->advanceToNextNonSpaceOrTab();
        $cursor->advanceBy(1);
        $cursor->advanceBySpaceOrTab();

        return BlockStart::of(new BlockQuoteParser())->at($cursor);
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/FencedCodeParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Util\ArrayCollection;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class FencedCodeParser extends AbstractBlockContinueParser
{

    private FencedCode $block;

private ArrayCollection $strings;

    public function __construct(int $fenceLength, string $fenceChar, int $fenceOffset)
    {
        $this->block   = new FencedCode($fenceLength, $fenceChar, $fenceOffset);
        $this->strings = new ArrayCollection();
    }

    public function getBlock(): FencedCode
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {

        if (! $cursor->isIndented() && $cursor->getNextNonSpaceCharacter() === $this->block->getChar()) {
            $match = RegexHelper::matchFirst('/^(?:`{3,}|~{3,})(?=[ \t]*$)/', $cursor->getLine(), $cursor->getNextNonSpacePosition());
            if ($match !== null && \strlen($match[0]) >= $this->block->getLength()) {

                return BlockContinue::finished();
            }
        }

$fenceOffset = $this->block->getOffset();
        while ($fenceOffset > 0 && $cursor->advanceBySpaceOrTab()) {
            $fenceOffset--;
        }

        return BlockContinue::at($cursor);
    }

    public function addLine(string $line): void
    {
        $this->strings[] = $line;
    }

    public function closeBlock(): void
    {

        $firstLine = $this->strings->first();
        if ($firstLine === false) {
            $firstLine = '';
        }

        $this->block->setInfo(RegexHelper::unescape(\trim($firstLine)));

        if ($this->strings->count() === 1) {
            $this->block->setLiteral('');
        } else {
            $this->block->setLiteral(\implode("\n", $this->strings->slice(1)) . "\n");
        }
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/FencedCodeStartParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;

final class FencedCodeStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || ! \in_array($cursor->getNextNonSpaceCharacter(), ['`', '~'], true)) {
            return BlockStart::none();
        }

        $indent = $cursor->getIndent();
        $fence  = $cursor->matchInPlace('/\G[ \t]*(?:`{3,}+(?!.*`)|~{3,})/');
        if ($fence === null) {
            return BlockStart::none();
        }

$fence = \ltrim($fence, " \t");

        return BlockStart::of(new FencedCodeParser(\strlen($fence), $fence[0], $indent))->at($cursor);
    }
}
}

// league/commonmark/src/Parser/Block/BlockContinueParserWithInlinesInterface.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\InlineParserEngineInterface;

interface BlockContinueParserWithInlinesInterface extends BlockContinueParserInterface
{

    public function parseInlines(InlineParserEngineInterface $inlineParser): void;
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/HeadingParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserWithInlinesInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\InlineParserEngineInterface;

final class HeadingParser extends AbstractBlockContinueParser implements BlockContinueParserWithInlinesInterface
{

    private Heading $block;

    private string $content;

    public function __construct(int $level, string $content)
    {
        $this->block   = new Heading($level);
        $this->content = $content;
    }

    public function getBlock(): Heading
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::none();
    }

    public function parseInlines(InlineParserEngineInterface $inlineParser): void
    {
        $inlineParser->parse($this->content, $this->block);
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/HeadingStartParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

class HeadingStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || ! \in_array($cursor->getNextNonSpaceCharacter(), ['#', '-', '='], true)) {
            return BlockStart::none();
        }

        $cursor->advanceToNextNonSpaceOrTab();

        if ($atxHeading = self::getAtxHeader($cursor)) {
            return BlockStart::of($atxHeading)->at($cursor);
        }

        $setextHeadingLevel = self::getSetextHeadingLevel($cursor);
        if ($setextHeadingLevel > 0) {
            $content = $parserState->getParagraphContent();
            if ($content !== null) {
                $cursor->advanceToEnd();

                return BlockStart::of(new HeadingParser($setextHeadingLevel, $content))
                    ->at($cursor)
                    ->replaceActiveBlockParser();
            }
        }

        return BlockStart::none();
    }

    private static function getAtxHeader(Cursor $cursor): ?HeadingParser
    {
        $match = RegexHelper::matchFirst('/^#{1,6}(?:[ \t]+|$)/', $cursor->getRemainder());
        if (! $match) {
            return null;
        }

        $cursor->advanceToNextNonSpaceOrTab();
        $cursor->advanceBy(\strlen($match[0]));

        $level = \strlen(\trim($match[0]));
        $str   = $cursor->getRemainder();
        $str   = \preg_replace('/^[ \t]*#+[ \t]*$/', '', $str);
        \assert(\is_string($str));
        $str = \preg_replace('/[ \t]+#+[ \t]*$/', '', $str);
        \assert(\is_string($str));

        return new HeadingParser($level, $str);
    }

    private static function getSetextHeadingLevel(Cursor $cursor): int
    {
        $match = RegexHelper::matchFirst('/^(?:=+|-+)[ \t]*$/', $cursor->getRemainder());
        if ($match === null) {
            return 0;
        }

        return $match[0][0] === '=' ? 1 : 2;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/HtmlBlockParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class HtmlBlockParser extends AbstractBlockContinueParser
{

    private HtmlBlock $block;

    private string $content = '';

    private bool $finished = false;

public function __construct(int $blockType)
    {
        $this->block = new HtmlBlock($blockType);
    }

    public function getBlock(): HtmlBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($this->finished) {
            return BlockContinue::none();
        }

        if ($cursor->isBlank() && \in_array($this->block->getType(), [HtmlBlock::TYPE_6_BLOCK_ELEMENT, HtmlBlock::TYPE_7_MISC_ELEMENT], true)) {
            return BlockContinue::none();
        }

        return BlockContinue::at($cursor);
    }

    public function addLine(string $line): void
    {
        if ($this->content !== '') {
            $this->content .= "\n";
        }

        $this->content .= $line;

if ($this->block->getType() <= HtmlBlock::TYPE_5_CDATA) {
            if (\preg_match(RegexHelper::getHtmlBlockCloseRegex($this->block->getType()), $line) === 1) {
                $this->finished = true;
            }
        }
    }

    public function closeBlock(): void
    {
        $this->block->setLiteral($this->content);
        $this->content = '';
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/HtmlBlockStartParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class HtmlBlockStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== '<') {
            return BlockStart::none();
        }

        $tmpCursor = clone $cursor;
        $tmpCursor->advanceToNextNonSpaceOrTab();
        $line = $tmpCursor->getRemainder();

        for ($blockType = 1; $blockType <= 7; $blockType++) {

$match = RegexHelper::matchAt(
                RegexHelper::getHtmlBlockOpenRegex($blockType),
                $line
            );

            if ($match !== null && ($blockType < 7 || $this->isType7BlockAllowed($cursor, $parserState))) {
                return BlockStart::of(new HtmlBlockParser($blockType))->at($cursor);
            }
        }

        return BlockStart::none();
    }

    private function isType7BlockAllowed(Cursor $cursor, MarkdownParserStateInterface $parserState): bool
    {

        if ($parserState->getLastMatchedBlockParser()->getBlock() instanceof Paragraph) {
            return false;
        }

return ! $parserState->getActiveBlockParser()->canHaveLazyContinuationLines();
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/IndentedCodeParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Util\ArrayCollection;

final class IndentedCodeParser extends AbstractBlockContinueParser
{

    private IndentedCode $block;

private ArrayCollection $strings;

    public function __construct()
    {
        $this->block   = new IndentedCode();
        $this->strings = new ArrayCollection();
    }

    public function getBlock(): IndentedCode
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($cursor->isIndented()) {
            $cursor->advanceBy(Cursor::INDENT_LEVEL, true);

            return BlockContinue::at($cursor);
        }

        if ($cursor->isBlank()) {
            $cursor->advanceToNextNonSpaceOrTab();

            return BlockContinue::at($cursor);
        }

        return BlockContinue::none();
    }

    public function addLine(string $line): void
    {
        $this->strings[] = $line;
    }

    public function closeBlock(): void
    {
        $lines = $this->strings->toArray();

while (\preg_match('/^[ \t]*$/', \end($lines))) {
            \array_pop($lines);
        }

        $this->block->setLiteral(\implode("\n", $lines) . "\n");
        $this->block->setEndLine($this->block->getStartLine() + \count($lines) - 1);
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/IndentedCodeStartParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;

final class IndentedCodeStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if (! $cursor->isIndented()) {
            return BlockStart::none();
        }

        if ($parserState->getActiveBlockParser()->getBlock() instanceof Paragraph) {
            return BlockStart::none();
        }

        if ($cursor->isBlank()) {
            return BlockStart::none();
        }

        $cursor->advanceBy(Cursor::INDENT_LEVEL, true);

        return BlockStart::of(new IndentedCodeParser())->at($cursor);
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/ListBlockParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListData;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;

final class ListBlockParser extends AbstractBlockContinueParser
{

    private ListBlock $block;

    public function __construct(ListData $listData)
    {
        $this->block = new ListBlock($listData);
    }

    public function getBlock(): ListBlock
    {
        return $this->block;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return $childBlock instanceof ListItem;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {

return BlockContinue::at($cursor);
    }

    public function closeBlock(): void
    {
        $item = $this->block->firstChild();
        while ($item instanceof AbstractBlock) {

            if ($item->next() !== null && self::endsWithBlankLine($item)) {
                $this->block->setTight(false);
                break;
            }

$subitem = $item->firstChild();
            while ($subitem instanceof AbstractBlock) {
                if ($subitem->next() && self::endsWithBlankLine($subitem)) {
                    $this->block->setTight(false);
                    break 2;
                }

                $subitem = $subitem->next();
            }

            $item = $item->next();
        }

        $lastChild = $this->block->lastChild();
        if ($lastChild instanceof AbstractBlock) {
            $this->block->setEndLine($lastChild->getEndLine());
        }
    }

    private static function endsWithBlankLine(AbstractBlock $block): bool
    {
        $next = $block->next();

        return $next instanceof AbstractBlock && $block->getEndLine() !== $next->getStartLine() - 1;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/ListBlockStartParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListData;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class ListBlockStartParser implements BlockStartParserInterface, ConfigurationAwareInterface
{

    private ?ConfigurationInterface $config = null;

private ?string $listMarkerRegex = null;

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented()) {
            return BlockStart::none();
        }

        $listData = $this->parseList($cursor, $parserState->getParagraphContent() !== null);
        if ($listData === null) {
            return BlockStart::none();
        }

        $listItemParser = new ListItemParser($listData);

$matched = $parserState->getLastMatchedBlockParser();
        if (! ($matched instanceof ListBlockParser) || ! $listData->equals($matched->getBlock()->getListData())) {
            $listBlockParser = new ListBlockParser($listData);

$listBlockParser->getBlock()->setTight(true);

            return BlockStart::of($listBlockParser, $listItemParser)->at($cursor);
        }

        return BlockStart::of($listItemParser)->at($cursor);
    }

    private function parseList(Cursor $cursor, bool $inParagraph): ?ListData
    {
        $indent = $cursor->getIndent();

        $tmpCursor = clone $cursor;
        $tmpCursor->advanceToNextNonSpaceOrTab();
        $rest = $tmpCursor->getRemainder();

        if (\preg_match($this->listMarkerRegex ?? $this->generateListMarkerRegex(), $rest) === 1) {
            $data               = new ListData();
            $data->markerOffset = $indent;
            $data->type         = ListBlock::TYPE_BULLET;
            $data->delimiter    = null;
            $data->bulletChar   = $rest[0];
            $markerLength       = 1;
        } elseif (($matches = RegexHelper::matchFirst('/^(\d{1,9})([.)])/', $rest)) && (! $inParagraph || $matches[1] === '1')) {
            $data               = new ListData();
            $data->markerOffset = $indent;
            $data->type         = ListBlock::TYPE_ORDERED;
            $data->start        = (int) $matches[1];
            $data->delimiter    = $matches[2] === '.' ? ListBlock::DELIM_PERIOD : ListBlock::DELIM_PAREN;
            $data->bulletChar   = null;
            $markerLength       = \strlen($matches[0]);
        } else {
            return null;
        }

$nextChar = $tmpCursor->peek($markerLength);
        if (! ($nextChar === null || $nextChar === "\t" || $nextChar === ' ')) {
            return null;
        }

if ($inParagraph && ! RegexHelper::matchAt(RegexHelper::REGEX_NON_SPACE, $rest, $markerLength)) {
            return null;
        }

        $cursor->advanceToNextNonSpaceOrTab();
        $cursor->advanceBy($markerLength, true);
        $data->padding = self::calculateListMarkerPadding($cursor, $markerLength);

        return $data;
    }

    private static function calculateListMarkerPadding(Cursor $cursor, int $markerLength): int
    {
        $start          = $cursor->saveState();
        $spacesStartCol = $cursor->getColumn();

        while ($cursor->getColumn() - $spacesStartCol < 5) {
            if (! $cursor->advanceBySpaceOrTab()) {
                break;
            }
        }

        $blankItem         = $cursor->isAtEnd();
        $spacesAfterMarker = $cursor->getColumn() - $spacesStartCol;

        if ($spacesAfterMarker >= 5 || $spacesAfterMarker < 1 || $blankItem) {
            $cursor->restoreState($start);
            $cursor->advanceBySpaceOrTab();

            return $markerLength + 1;
        }

        return $markerLength + $spacesAfterMarker;
    }

private function generateListMarkerRegex(): string
    {

        if ($this->config === null) {
            return $this->listMarkerRegex = '/^[*+-]/';
        }

        $markers = $this->config->get('commonmark/unordered_list_markers');
        \assert(\is_array($markers));

        return $this->listMarkerRegex = '/^[' . \preg_quote(\implode('', $markers), '/') . ']/';
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/ListItemParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListData;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;

final class ListItemParser extends AbstractBlockContinueParser
{

    private ListItem $block;

    public function __construct(ListData $listData)
    {
        $this->block = new ListItem($listData);
    }

    public function getBlock(): ListItem
    {
        return $this->block;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return ! $childBlock instanceof ListItem;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($cursor->isBlank()) {
            if ($this->block->firstChild() === null) {

                return BlockContinue::none();
            }

            $cursor->advanceToNextNonSpaceOrTab();

            return BlockContinue::at($cursor);
        }

        $contentIndent = $this->block->getListData()->markerOffset + $this->getBlock()->getListData()->padding;
        if ($cursor->getIndent() >= $contentIndent) {
            $cursor->advanceBy($contentIndent, true);

            return BlockContinue::at($cursor);
        }

return BlockContinue::none();
    }

    public function closeBlock(): void
    {
        if (($lastChild = $this->block->lastChild()) instanceof AbstractBlock) {
            $this->block->setEndLine($lastChild->getEndLine());
        } else {

            $this->block->setEndLine($this->block->getStartLine());
        }
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/ThematicBreakParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;

final class ThematicBreakParser extends AbstractBlockContinueParser
{

    private ThematicBreak $block;

    public function __construct()
    {
        $this->block = new ThematicBreak();
    }

    public function getBlock(): ThematicBreak
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {

        return BlockContinue::none();
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Block/ThematicBreakStartParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class ThematicBreakStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented()) {
            return BlockStart::none();
        }

        $match = RegexHelper::matchAt(RegexHelper::REGEX_THEMATIC_BREAK, $cursor->getLine(), $cursor->getNextNonSpacePosition());
        if ($match === null) {
            return BlockStart::none();
        }

$cursor->advanceToEnd();

        return BlockStart::of(new ThematicBreakParser())->at($cursor);
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Inline/AutolinkParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;
use CMS\Vendor\League\CommonMark\Util\UrlEncoder;

final class AutolinkParser implements InlineParserInterface
{
    private const EMAIL_REGEX      = '<([a-zA-Z0-9.!#$%&\'*+\\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>';
    private const OTHER_LINK_REGEX = '<([A-Za-z][A-Za-z0-9.+-]{1,31}:[^<>\x00-\x20]*)>';

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(self::EMAIL_REGEX . '|' . self::OTHER_LINK_REGEX);
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $matches = $inlineContext->getMatches();

        if ($matches[1] !== '') {
            $inlineContext->getContainer()->appendChild(new Link('mailto:' . UrlEncoder::unescapeAndEncode($matches[1]), $matches[1]));

            return true;
        }

        if ($matches[2] !== '') {
            $inlineContext->getContainer()->appendChild(new Link(UrlEncoder::unescapeAndEncode($matches[2]), $matches[2]));

            return true;
        }

        return false;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Inline/BacktickParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;

final class BacktickParser implements InlineParserInterface
{

    private const MAX_BACKTICKS = 1000;

private ?\WeakReference $lastCursor = null;
    private bool $lastCursorScanned     = false;

private array $seenBackticks = [];

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('`+');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $ticks  = $inlineContext->getFullMatch();
        $cursor = $inlineContext->getCursor();
        $cursor->advanceBy($inlineContext->getFullMatchLength());

        $currentPosition = $cursor->getPosition();
        $previousState   = $cursor->saveState();

        if ($this->findMatchingTicks(\strlen($ticks), $cursor)) {
            $code = $cursor->getSubstring($currentPosition, $cursor->getPosition() - $currentPosition - \strlen($ticks));

            $c = \str_replace("\n", ' ', $code);

            if (
                $c !== '' &&
                $c[0] === ' ' &&
                \substr($c, -1, 1) === ' ' &&
                \strspn($c, ' ') !== \strlen($c)
            ) {
                $c = \substr($c, 1, -1);
            }

            $inlineContext->getContainer()->appendChild(new Code($c));

            return true;
        }

$cursor->restoreState($previousState);
        $inlineContext->getContainer()->appendChild(new Text($ticks));

        return true;
    }

private function findMatchingTicks(int $openTickLength, Cursor $cursor): bool
    {

        if ($this->lastCursor === null || $this->lastCursor->get() !== $cursor) {
            $this->seenBackticks     = [];
            $this->lastCursor        = \WeakReference::create($cursor);
            $this->lastCursorScanned = false;
        }

        if ($openTickLength > self::MAX_BACKTICKS) {
            return false;
        }

if ($this->lastCursorScanned && isset($this->seenBackticks[$openTickLength]) && $this->seenBackticks[$openTickLength] <= $cursor->getPosition()) {
            return false;
        }

        while ($ticks = $cursor->matchInPlace('/`{1,' . self::MAX_BACKTICKS . '}/')) {
            $numTicks = \strlen($ticks);

if ($numTicks === $openTickLength) {
                return true;
            }

if ($numTicks <= self::MAX_BACKTICKS) {
                $this->seenBackticks[$numTicks] = $cursor->getPosition() - $numTicks;
            }
        }

$this->lastCursorScanned = true;

        return false;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Inline/BangParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;

final class BangParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string('![');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $cursor->advanceBy(2);

        $node = new Text('![', ['delim' => true]);
        $inlineContext->getContainer()->appendChild($node);

$inlineContext->getDelimiterStack()->addBracket($node, $cursor->getPosition(), true);

        return true;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Inline/CloseBracketParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Delimiter\Bracket;
use CMS\Vendor\League\CommonMark\Environment\EnvironmentAwareInterface;
use CMS\Vendor\League\CommonMark\Environment\EnvironmentInterface;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CMS\Vendor\League\CommonMark\Extension\Mention\Mention;
use CMS\Vendor\League\CommonMark\Node\Inline\AdjacentTextMerger;
use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;
use CMS\Vendor\League\CommonMark\Reference\ReferenceInterface;
use CMS\Vendor\League\CommonMark\Reference\ReferenceMapInterface;
use CMS\Vendor\League\CommonMark\Util\LinkParserHelper;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class CloseBracketParser implements InlineParserInterface, EnvironmentAwareInterface
{

    private EnvironmentInterface $environment;

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string(']');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {

        $opener = $inlineContext->getDelimiterStack()->getLastBracket();
        if ($opener === null) {
            return false;
        }

        if (! $opener->isImage() && ! $opener->isActive()) {

            $inlineContext->getDelimiterStack()->removeBracket();

            return false;
        }

        $cursor = $inlineContext->getCursor();

        $startPos      = $cursor->getPosition();
        $previousState = $cursor->saveState();

        $cursor->advanceBy(1);

if ($result = $this->tryParseInlineLinkAndTitle($cursor)) {
            $link = $result;
        } elseif ($link = $this->tryParseReference($cursor, $inlineContext->getReferenceMap(), $opener, $startPos)) {
            $reference = $link;
            $link      = ['url' => $link->getDestination(), 'title' => $link->getTitle()];
        } else {

            $inlineContext->getDelimiterStack()->removeBracket();
            $cursor->restoreState($previousState);

            return false;
        }

        $inline = $this->createInline($link['url'], $link['title'], $opener->isImage(), $reference ?? null);
        $opener->getNode()->replaceWith($inline);
        while (($label = $inline->next()) !== null) {

if ($label instanceof Mention) {
                $label->replaceWith($replacement = new Text($label->getPrefix() . $label->getIdentifier()));
                $inline->appendChild($replacement);
            } elseif ($label instanceof Link) {
                foreach ($label->children() as $child) {
                    $label->insertBefore($child);
                }

                $label->detach();
            } else {
                $inline->appendChild($label);
            }
        }

$delimiterStack = $inlineContext->getDelimiterStack();
        $stackBottom    = $opener->getPosition();
        $delimiterStack->processDelimiters($stackBottom, $this->environment->getDelimiterProcessors());
        $delimiterStack->removeBracket();
        $delimiterStack->removeAll($stackBottom);

AdjacentTextMerger::mergeChildNodes($inline);

if (! $opener->isImage()) {
            $inlineContext->getDelimiterStack()->deactivateLinkOpeners();
        }

        return true;
    }

    public function setEnvironment(EnvironmentInterface $environment): void
    {
        $this->environment = $environment;
    }

private function tryParseInlineLinkAndTitle(Cursor $cursor): ?array
    {
        if ($cursor->getCurrentCharacter() !== '(') {
            return null;
        }

        $previousState = $cursor->saveState();

        $cursor->advanceBy(1);
        $cursor->advanceToNextNonSpaceOrNewline();
        if (($dest = LinkParserHelper::parseLinkDestination($cursor)) === null) {
            $cursor->restoreState($previousState);

            return null;
        }

        $cursor->advanceToNextNonSpaceOrNewline();
        $previousCharacter = $cursor->peek(-1);

        \assert(\is_string($previousCharacter));

        $title = '';

        if (\preg_match(RegexHelper::REGEX_WHITESPACE_CHAR, $previousCharacter)) {
            $title = LinkParserHelper::parseLinkTitle($cursor) ?? '';
        }

        $cursor->advanceToNextNonSpaceOrNewline();

        if ($cursor->getCurrentCharacter() !== ')') {
            $cursor->restoreState($previousState);

            return null;
        }

        $cursor->advanceBy(1);

        return ['url' => $dest, 'title' => $title];
    }

    private function tryParseReference(Cursor $cursor, ReferenceMapInterface $referenceMap, Bracket $opener, int $startPos): ?ReferenceInterface
    {
        $savePos     = $cursor->saveState();
        $beforeLabel = $cursor->getPosition();
        $n           = LinkParserHelper::parseLinkLabel($cursor);
        if ($n > 2) {
            $start  = $beforeLabel + 1;
            $length = $n - 2;
        } elseif (! $opener->hasNext()) {

$start  = $opener->getPosition();
            $length = $startPos - $start;

if ($length > 999) {
                $cursor->restoreState($savePos);

                return null;
            }
        } else {
            $cursor->restoreState($savePos);

            return null;
        }

        $referenceLabel = $cursor->getSubstring($start, $length);

        if ($n === 0) {

            $cursor->restoreState($savePos);
        }

        return $referenceMap->get($referenceLabel);
    }

    private function createInline(string $url, string $title, bool $isImage, ?ReferenceInterface $reference = null): AbstractWebResource
    {
        if ($isImage) {
            $inline = new Image($url, null, $title);
        } else {
            $inline = new Link($url, null, $title);
        }

        if ($reference) {
            $inline->data->set('reference', $reference);
        }

        return $inline;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Inline/EntityParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;
use CMS\Vendor\League\CommonMark\Util\Html5EntityDecoder;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class EntityParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(RegexHelper::PARTIAL_ENTITY);
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $entity = $inlineContext->getFullMatch();

        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new Text(Html5EntityDecoder::decode($entity)));

        return true;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Inline/EscapableParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Newline;
use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class EscapableParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string('\\');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor   = $inlineContext->getCursor();
        $nextChar = $cursor->peek();

        if ($nextChar === "\n") {
            $cursor->advanceBy(2);
            $inlineContext->getContainer()->appendChild(new Newline(Newline::HARDBREAK));

            return true;
        }

        if ($nextChar !== null && RegexHelper::isEscapable($nextChar)) {
            $cursor->advanceBy(2);
            $inlineContext->getContainer()->appendChild(new Text($nextChar));

            return true;
        }

        $cursor->advanceBy(1);
        $inlineContext->getContainer()->appendChild(new Text('\\'));

        return true;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Inline/HtmlInlineParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class HtmlInlineParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(RegexHelper::PARTIAL_HTMLTAG)->caseSensitive();
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $inline = $inlineContext->getFullMatch();

        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new HtmlInline($inline));

        return true;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Parser/Inline/OpenBracketParser.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;

final class OpenBracketParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string('[');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getCursor()->advanceBy(1);
        $node = new Text('[', ['delim' => true]);
        $inlineContext->getContainer()->appendChild($node);

$inlineContext->getDelimiterStack()->addBracket($node, $inlineContext->getCursor()->getPosition(), false);

        return true;
    }
}
}

// league/commonmark/src/Renderer/NodeRendererInterface.php


namespace CMS\Vendor\League\CommonMark\Renderer {

use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;
use CMS\Vendor\League\CommonMark\Node\Node;

interface NodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer);
}
}

// league/commonmark/src/Xml/XmlNodeRendererInterface.php


namespace CMS\Vendor\League\CommonMark\Xml {

use CMS\Vendor\League\CommonMark\Node\Node;

interface XmlNodeRendererInterface
{
    public function getXmlTagName(Node $node): string;

public function getXmlAttributes(Node $node): array;
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Block/BlockQuoteRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class BlockQuoteRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        BlockQuote::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        $filling        = $childRenderer->renderNodes($node->children());
        $innerSeparator = $childRenderer->getInnerSeparator();
        if ($filling === '') {
            return new HtmlElement('blockquote', $attrs, $innerSeparator);
        }

        return new HtmlElement(
            'blockquote',
            $attrs,
            $innerSeparator . $filling . $innerSeparator
        );
    }

    public function getXmlTagName(Node $node): string
    {
        return 'block_quote';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Block/FencedCodeRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Util\Xml;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class FencedCodeRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        FencedCode::assertInstanceOf($node);

        $attrs = $node->data->getData('attributes');

        $infoWords = $node->getInfoWords();
        if (\count($infoWords) !== 0 && $infoWords[0] !== '') {
            $class = $infoWords[0];
            if (! \str_starts_with($class, 'language-')) {
                $class = 'language-' . $class;
            }

            $attrs->append('class', $class);
        }

        return new HtmlElement(
            'pre',
            [],
            new HtmlElement('code', $attrs->export(), Xml::escape($node->getLiteral()))
        );
    }

    public function getXmlTagName(Node $node): string
    {
        return 'code_block';
    }

public function getXmlAttributes(Node $node): array
    {
        FencedCode::assertInstanceOf($node);

        if (($info = $node->getInfo()) === null || $info === '') {
            return [];
        }

        return ['info' => $info];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Block/HeadingRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class HeadingRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Heading::assertInstanceOf($node);

        $tag = 'h' . $node->getLevel();

        $attrs = $node->data->get('attributes');

        return new HtmlElement($tag, $attrs, $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'heading';
    }

public function getXmlAttributes(Node $node): array
    {
        Heading::assertInstanceOf($node);

        return ['level' => $node->getLevel()];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Block/HtmlBlockRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlFilter;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class HtmlBlockRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{

    private ConfigurationInterface $config;

public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        HtmlBlock::assertInstanceOf($node);

        $htmlInput = $this->config->get('html_input');

        return HtmlFilter::filter($node->getLiteral(), $htmlInput);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'html_block';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Block/IndentedCodeRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Util\Xml;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class IndentedCodeRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        IndentedCode::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        return new HtmlElement(
            'pre',
            [],
            new HtmlElement('code', $attrs, Xml::escape($node->getLiteral()))
        );
    }

    public function getXmlTagName(Node $node): string
    {
        return 'code_block';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Block/ListBlockRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class ListBlockRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        ListBlock::assertInstanceOf($node);

        $listData = $node->getListData();

        $tag = $listData->type === ListBlock::TYPE_BULLET ? 'ul' : 'ol';

        $attrs = $node->data->get('attributes');

        if ($listData->start !== null && $listData->start !== 1) {
            $attrs['start'] = (string) $listData->start;
        }

        $innerSeparator = $childRenderer->getInnerSeparator();

        return new HtmlElement($tag, $attrs, $innerSeparator . $childRenderer->renderNodes($node->children()) . $innerSeparator);
    }

    public function getXmlTagName(Node $node): string
    {
        return 'list';
    }

public function getXmlAttributes(Node $node): array
    {
        ListBlock::assertInstanceOf($node);

        $data = $node->getListData();

        if ($data->type === ListBlock::TYPE_BULLET) {
            return [
                'type' => $data->type,
                'tight' => $node->isTight() ? 'true' : 'false',
            ];
        }

        return [
            'type' => $data->type,
            'start' => $data->start ?? 1,
            'tight' => $node->isTight(),
            'delimiter' => $data->delimiter ?? ListBlock::DELIM_PERIOD,
        ];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Block/ListItemRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Node\Block\TightBlockInterface;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class ListItemRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        ListItem::assertInstanceOf($node);

        $contents = $childRenderer->renderNodes($node->children());

        $inTightList = ($parent = $node->parent()) && $parent instanceof TightBlockInterface && $parent->isTight();

        if ($this->needsBlockSeparator($node->firstChild(), $inTightList)) {
            $contents = "\n" . $contents;
        }

        if ($this->needsBlockSeparator($node->lastChild(), $inTightList)) {
            $contents .= "\n";
        }

        $attrs = $node->data->get('attributes');

        return new HtmlElement('li', $attrs, $contents);
    }

    public function getXmlTagName(Node $node): string
    {
        return 'item';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }

    private function needsBlockSeparator(?Node $child, bool $inTightList): bool
    {
        if ($child instanceof Paragraph && $inTightList) {
            return false;
        }

        return $child instanceof AbstractBlock;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Block/ThematicBreakRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class ThematicBreakRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        ThematicBreak::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        return new HtmlElement('hr', $attrs, '', true);
    }

    public function getXmlTagName(Node $node): string
    {
        return 'thematic_break';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Inline/CodeRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Util\Xml;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class CodeRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Code::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        return new HtmlElement('code', $attrs, Xml::escape($node->getLiteral()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'code';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Inline/EmphasisRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class EmphasisRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Emphasis::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        return new HtmlElement('em', $attrs, $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'emph';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Inline/HtmlInlineRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlFilter;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class HtmlInlineRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{

    private ConfigurationInterface $config;

public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        HtmlInline::assertInstanceOf($node);

        $htmlInput = $this->config->get('html_input');

        return HtmlFilter::filter($node->getLiteral(), $htmlInput);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'html_inline';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Inline/ImageRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use CMS\Vendor\League\CommonMark\Node\Inline\Newline;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Node\NodeIterator;
use CMS\Vendor\League\CommonMark\Node\StringContainerInterface;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class ImageRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{

    private ConfigurationInterface $config;

public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Image::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        $forbidUnsafeLinks = ! $this->config->get('allow_unsafe_links');
        if ($forbidUnsafeLinks && RegexHelper::isLinkPotentiallyUnsafe($node->getUrl())) {
            $attrs['src'] = '';
        } else {
            $attrs['src'] = $node->getUrl();
        }

        $attrs['alt'] = $this->getAltText($node);

        if (($title = $node->getTitle()) !== null) {
            $attrs['title'] = $title;
        }

        return new HtmlElement('img', $attrs, '', true);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'image';
    }

public function getXmlAttributes(Node $node): array
    {
        Image::assertInstanceOf($node);

        return [
            'destination' => $node->getUrl(),
            'title' => $node->getTitle() ?? '',
        ];
    }

    private function getAltText(Image $node): string
    {
        $altText = '';

        foreach ((new NodeIterator($node)) as $n) {
            if ($n instanceof StringContainerInterface) {
                $altText .= $n->getLiteral();
            } elseif ($n instanceof Newline) {
                $altText .= "\n";
            }
        }

        return $altText;
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Inline/LinkRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class LinkRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{

    private ConfigurationInterface $config;

public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Link::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        $forbidUnsafeLinks = ! $this->config->get('allow_unsafe_links');
        if (! ($forbidUnsafeLinks && RegexHelper::isLinkPotentiallyUnsafe($node->getUrl()))) {
            $attrs['href'] = $node->getUrl();
        }

        if (($title = $node->getTitle()) !== null) {
            $attrs['title'] = $title;
        }

        if (isset($attrs['target']) && $attrs['target'] === '_blank' && ! isset($attrs['rel'])) {
            $attrs['rel'] = 'noopener noreferrer';
        }

        return new HtmlElement('a', $attrs, $childRenderer->renderNodes($node->children()));
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'link';
    }

public function getXmlAttributes(Node $node): array
    {
        Link::assertInstanceOf($node);

        return [
            'destination' => $node->getUrl(),
            'title' => $node->getTitle() ?? '',
        ];
    }
}
}

// league/commonmark/src/Extension/CommonMark/Renderer/Inline/StrongRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Inline {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class StrongRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Strong::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        return new HtmlElement('strong', $attrs, $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'strong';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/DisallowedRawHtml/DisallowedRawHtmlExtension.php


namespace CMS\Vendor\League\CommonMark\Extension\DisallowedRawHtml {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block\HtmlBlockRenderer;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Inline\HtmlInlineRenderer;
use CMS\Vendor\League\CommonMark\Extension\ConfigurableExtensionInterface;
use CMS\Vendor\League\Config\ConfigurationBuilderInterface;
use CMS\Vendor\Nette\Schema\Expect;

final class DisallowedRawHtmlExtension implements ConfigurableExtensionInterface
{
    private const DEFAULT_DISALLOWED_TAGS = [
        'title',
        'textarea',
        'style',
        'xmp',
        'iframe',
        'noembed',
        'noframes',
        'script',
        'plaintext',
    ];

    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        $builder->addSchema('disallowed_raw_html', Expect::structure([
            'disallowed_tags' => Expect::listOf('string')->default(self::DEFAULT_DISALLOWED_TAGS)->mergeDefaults(false),
        ]));
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(HtmlBlock::class, new DisallowedRawHtmlRenderer(new HtmlBlockRenderer()), 50);
        $environment->addRenderer(HtmlInline::class, new DisallowedRawHtmlRenderer(new HtmlInlineRenderer()), 50);
    }
}
}

// league/commonmark/src/Extension/DisallowedRawHtml/DisallowedRawHtmlRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\DisallowedRawHtml {

use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class DisallowedRawHtmlRenderer implements NodeRendererInterface, ConfigurationAwareInterface
{

    private NodeRendererInterface $innerRenderer;

private ConfigurationInterface $config;

    public function __construct(NodeRendererInterface $innerRenderer)
    {
        $this->innerRenderer = $innerRenderer;
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        $rendered = (string) $this->innerRenderer->render($node, $childRenderer);

        if ($rendered === '') {
            return '';
        }

        $tags = (array) $this->config->get('disallowed_raw_html/disallowed_tags');
        if (\count($tags) === 0) {
            return $rendered;
        }

$regex = \sprintf('/<(\/?(?:%s)(?:[\s\/>]|$))/i', \implode('|', \array_map('preg_quote', $tags)));

return \preg_replace($regex, '&lt;$1', $rendered);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;

        if ($this->innerRenderer instanceof ConfigurationAwareInterface) {
            $this->innerRenderer->setConfiguration($configuration);
        }
    }
}
}

// league/commonmark/src/Extension/GithubFlavoredMarkdownExtension.php


namespace CMS\Vendor\League\CommonMark\Extension {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CMS\Vendor\League\CommonMark\Extension\Autolink\AutolinkExtension;
use CMS\Vendor\League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use CMS\Vendor\League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use CMS\Vendor\League\CommonMark\Extension\Table\TableExtension;
use CMS\Vendor\League\CommonMark\Extension\TaskList\TaskListExtension;

final class GithubFlavoredMarkdownExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new DisallowedRawHtmlExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new TaskListExtension());
    }
}
}

// league/commonmark/src/Extension/Mention/Mention.php


namespace CMS\Vendor\League\CommonMark\Extension\Mention {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CMS\Vendor\League\CommonMark\Node\Inline\Text;

class Mention extends Link
{
    private string $name;

    private string $prefix;

    private string $identifier;

    public function __construct(string $name, string $prefix, string $identifier, ?string $label = null)
    {
        $this->name       = $name;
        $this->prefix     = $prefix;
        $this->identifier = $identifier;

        parent::__construct('', $label ?? \sprintf('%s%s', $prefix, $identifier));
    }

    public function getLabel(): ?string
    {
        if (($labelNode = $this->findLabelNode()) === null) {
            return null;
        }

        return $labelNode->getLiteral();
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function hasUrl(): bool
    {
        return $this->url !== '';
    }

public function setLabel(string $label): self
    {
        if (($labelNode = $this->findLabelNode()) === null) {
            $labelNode = new Text();
            $this->prependChild($labelNode);
        }

        $labelNode->setLiteral($label);

        return $this;
    }

    private function findLabelNode(): ?Text
    {
        foreach ($this->children() as $child) {
            if ($child instanceof Text) {
                return $child;
            }
        }

        return null;
    }
}
}

// league/commonmark/src/Extension/Strikethrough/Strikethrough.php


namespace CMS\Vendor\League\CommonMark\Extension\Strikethrough {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractInline;
use CMS\Vendor\League\CommonMark\Node\Inline\DelimitedInterface;

final class Strikethrough extends AbstractInline implements DelimitedInterface
{
    private string $delimiter;

    public function __construct(string $delimiter = '~~')
    {
        parent::__construct();

        $this->delimiter = $delimiter;
    }

    public function getOpeningDelimiter(): string
    {
        return $this->delimiter;
    }

    public function getClosingDelimiter(): string
    {
        return $this->delimiter;
    }
}
}

// league/commonmark/src/Extension/Strikethrough/StrikethroughDelimiterProcessor.php


namespace CMS\Vendor\League\CommonMark\Extension\Strikethrough {

use CMS\Vendor\League\CommonMark\Delimiter\DelimiterInterface;
use CMS\Vendor\League\CommonMark\Delimiter\Processor\CacheableDelimiterProcessorInterface;
use CMS\Vendor\League\CommonMark\Node\Inline\AbstractStringContainer;

final class StrikethroughDelimiterProcessor implements CacheableDelimiterProcessorInterface
{
    public function getOpeningCharacter(): string
    {
        return '~';
    }

    public function getClosingCharacter(): string
    {
        return '~';
    }

    public function getMinLength(): int
    {
        return 1;
    }

    public function getDelimiterUse(DelimiterInterface $opener, DelimiterInterface $closer): int
    {
        if ($opener->getLength() > 2 && $closer->getLength() > 2) {
            return 0;
        }

        if ($opener->getLength() !== $closer->getLength()) {
            return 0;
        }

return $opener->getLength();
    }

    public function process(AbstractStringContainer $opener, AbstractStringContainer $closer, int $delimiterUse): void
    {
        $strikethrough = new Strikethrough(\str_repeat('~', $delimiterUse));

        $tmp = $opener->next();
        while ($tmp !== null && $tmp !== $closer) {
            $next = $tmp->next();
            $strikethrough->appendChild($tmp);
            $tmp = $next;
        }

        $opener->insertAfter($strikethrough);
    }

    public function getCacheKey(DelimiterInterface $closer): string
    {

return '~' . \min($closer->getLength(), 3);
    }
}
}

// league/commonmark/src/Extension/Strikethrough/StrikethroughExtension.php


namespace CMS\Vendor\League\CommonMark\Extension\Strikethrough {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CMS\Vendor\League\CommonMark\Extension\ExtensionInterface;

final class StrikethroughExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addDelimiterProcessor(new StrikethroughDelimiterProcessor());
        $environment->addRenderer(Strikethrough::class, new StrikethroughRenderer());
    }
}
}

// league/commonmark/src/Extension/Strikethrough/StrikethroughRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\Strikethrough {

use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class StrikethroughRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Strikethrough::assertInstanceOf($node);

        return new HtmlElement('del', $node->data->get('attributes'), $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'strikethrough';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/Table/Table.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

final class Table extends AbstractBlock
{
}
}

// league/commonmark/src/Extension/Table/TableCell.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

final class TableCell extends AbstractBlock
{
    public const TYPE_HEADER = 'header';
    public const TYPE_DATA   = 'data';

    public const ALIGN_LEFT   = 'left';
    public const ALIGN_RIGHT  = 'right';
    public const ALIGN_CENTER = 'center';

private string $type = self::TYPE_DATA;

private ?string $align = null;

public function __construct(string $type = self::TYPE_DATA, ?string $align = null)
    {
        parent::__construct();

        $this->type  = $type;
        $this->align = $align;
    }

public function getType(): string
    {
        return $this->type;
    }

public function setType(string $type): void
    {
        $this->type = $type;
    }

public function getAlign(): ?string
    {
        return $this->align;
    }

public function setAlign(?string $align): void
    {
        $this->align = $align;
    }
}
}

// league/commonmark/src/Extension/Table/TableCellRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Extension\Attributes\Util\AttributesHelper;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class TableCellRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    private const DEFAULT_ATTRIBUTES = [
        TableCell::ALIGN_LEFT   => ['align' => 'left'],
        TableCell::ALIGN_CENTER => ['align' => 'center'],
        TableCell::ALIGN_RIGHT  => ['align' => 'right'],
    ];

private array $alignmentAttributes;

public function __construct(array $alignmentAttributes = self::DEFAULT_ATTRIBUTES)
    {
        $this->alignmentAttributes = $alignmentAttributes;
    }

public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        TableCell::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');
        if (($alignment = $node->getAlign()) !== null) {
            $attrs = AttributesHelper::mergeAttributes($attrs, $this->alignmentAttributes[$alignment]);
        }

        $tag = $node->getType() === TableCell::TYPE_HEADER ? 'th' : 'td';

        return new HtmlElement($tag, $attrs, $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'table_cell';
    }

public function getXmlAttributes(Node $node): array
    {
        TableCell::assertInstanceOf($node);

        $ret = ['type' => $node->getType()];

        if (($align = $node->getAlign()) !== null) {
            $ret['align'] = $align;
        }

        return $ret;
    }
}
}

// league/commonmark/src/Extension/Table/TableExtension.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CMS\Vendor\League\CommonMark\Extension\ConfigurableExtensionInterface;
use CMS\Vendor\League\CommonMark\Renderer\HtmlDecorator;
use CMS\Vendor\League\Config\ConfigurationBuilderInterface;
use CMS\Vendor\Nette\Schema\Expect;

final class TableExtension implements ConfigurableExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        $attributeArraySchema = Expect::arrayOf(
            Expect::type('string|string[]|bool'),
            'string'
        )->mergeDefaults(false);

        $builder->addSchema('table', Expect::structure([
            'wrap' => Expect::structure([
                'enabled' => Expect::bool()->default(false),
                'tag' => Expect::string()->default('div'),
                'attributes' => Expect::arrayOf(Expect::string()),
            ]),
            'alignment_attributes' => Expect::structure([
                'left' => (clone $attributeArraySchema)->default(['align' => 'left']),
                'center' => (clone $attributeArraySchema)->default(['align' => 'center']),
                'right' => (clone $attributeArraySchema)->default(['align' => 'right']),
            ]),
            'max_autocompleted_cells' => Expect::int()->min(0)->default(TableParser::DEFAULT_MAX_AUTOCOMPLETED_CELLS),
        ]));
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $tableRenderer = new TableRenderer();
        if ($environment->getConfiguration()->get('table/wrap/enabled')) {
            $tableRenderer = new HtmlDecorator($tableRenderer, $environment->getConfiguration()->get('table/wrap/tag'), $environment->getConfiguration()->get('table/wrap/attributes'));
        }

        $environment
            ->addBlockStartParser(new TableStartParser($environment->getConfiguration()->get('table/max_autocompleted_cells')))

            ->addRenderer(Table::class, $tableRenderer)
            ->addRenderer(TableSection::class, new TableSectionRenderer())
            ->addRenderer(TableRow::class, new TableRowRenderer())
            ->addRenderer(TableCell::class, new TableCellRenderer($environment->getConfiguration()->get('table/alignment_attributes')));
    }
}
}

// league/commonmark/src/Extension/Table/TableParser.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserWithInlinesInterface;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\InlineParserEngineInterface;
use CMS\Vendor\League\CommonMark\Util\ArrayCollection;

final class TableParser extends AbstractBlockContinueParser implements BlockContinueParserWithInlinesInterface
{

    public const DEFAULT_MAX_AUTOCOMPLETED_CELLS = 10_000;

private Table $block;

private ArrayCollection $bodyLines;

private array $columns;

private array $headerCells;

private bool $nextIsSeparatorLine = true;

    private int $remainingAutocompletedCells;

private bool $canHaveLazyContinuationLines = false;

public function __construct(array $columns, array $headerCells, int $remainingAutocompletedCells = self::DEFAULT_MAX_AUTOCOMPLETED_CELLS)
    {
        $this->block                       = new Table();
        $this->bodyLines                   = new ArrayCollection();
        $this->columns                     = $columns;
        $this->headerCells                 = $headerCells;
        $this->remainingAutocompletedCells = $remainingAutocompletedCells;
    }

    public function canHaveLazyContinuationLines(): bool
    {
        return $this->canHaveLazyContinuationLines;
    }

    public function getBlock(): Table
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {

$this->canHaveLazyContinuationLines = \strpos($cursor->getLine(), '|') === false;

        if ($this->canHaveLazyContinuationLines) {
            return BlockContinue::none();
        }

        return BlockContinue::at($cursor);
    }

    public function addLine(string $line): void
    {
        $this->canHaveLazyContinuationLines = false;

        if ($this->nextIsSeparatorLine) {
            $this->nextIsSeparatorLine = false;
        } else {
            $this->bodyLines[] = $line;
        }
    }

    public function parseInlines(InlineParserEngineInterface $inlineParser): void
    {
        $headerColumns = \count($this->headerCells);

        $head = new TableSection(TableSection::TYPE_HEAD);
        $this->block->appendChild($head);

        $headerRow = new TableRow();
        $head->appendChild($headerRow);
        for ($i = 0; $i < $headerColumns; $i++) {
            $cell      = $this->headerCells[$i];
            $tableCell = $this->parseCell($cell, $i, $inlineParser);
            $tableCell->setType(TableCell::TYPE_HEADER);
            $headerRow->appendChild($tableCell);
        }

        $body = null;
        foreach ($this->bodyLines as $rowLine) {
            $cells = self::split($rowLine);
            $row   = new TableRow();

for ($i = 0; $i < $headerColumns; $i++) {

                if (! isset($cells[$i]) && $this->remainingAutocompletedCells-- <= 0) {

                    return;
                }

                $cell      = $cells[$i] ?? '';
                $tableCell = $this->parseCell($cell, $i, $inlineParser);
                $row->appendChild($tableCell);
            }

            if ($body === null) {

                $body = new TableSection();
                $this->block->appendChild($body);
            }

            $body->appendChild($row);
        }
    }

    private function parseCell(string $cell, int $column, InlineParserEngineInterface $inlineParser): TableCell
    {
        $tableCell = new TableCell(TableCell::TYPE_DATA, $this->columns[$column] ?? null);

        if ($cell !== '') {
            $inlineParser->parse(\trim($cell), $tableCell);
        }

        return $tableCell;
    }

public static function split(string $line): array
    {

$line   = \trim($line);
        $length = \strlen($line);

        $i = 0;
        if ($length > 0 && $line[0] === '|') {
            $i = 1;
        }

        $cells = [];
        $sb    = '';

        for (; $i < $length; $i++) {
            switch ($c = $line[$i]) {
                case '\\':
                    if ($i + 1 < $length && $line[$i + 1] === '|') {

$sb .= '|';
                        $i++;
                    } else {

                        $sb .= '\\';
                    }

                    break;
                case '|':
                    $cells[] = $sb;
                    $sb      = '';
                    break;
                default:
                    $sb .= $c;
            }
        }

        if ($sb !== '') {
            $cells[] = $sb;
        }

        return $cells;
    }
}
}

// league/commonmark/src/Extension/Table/TableRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class TableRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Table::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        $separator = $childRenderer->getInnerSeparator();

        $children = $childRenderer->renderNodes($node->children());

        return new HtmlElement('table', $attrs, $separator . \trim($children) . $separator);
    }

    public function getXmlTagName(Node $node): string
    {
        return 'table';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/Table/TableRow.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

final class TableRow extends AbstractBlock
{
}
}

// league/commonmark/src/Extension/Table/TableRowRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class TableRowRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        TableRow::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        $separator = $childRenderer->getInnerSeparator();

        return new HtmlElement('tr', $attrs, $separator . $childRenderer->renderNodes($node->children()) . $separator);
    }

    public function getXmlTagName(Node $node): string
    {
        return 'table_row';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Extension/Table/TableSection.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

final class TableSection extends AbstractBlock
{
    public const TYPE_HEAD = 'head';
    public const TYPE_BODY = 'body';

private string $type;

public function __construct(string $type = self::TYPE_BODY)
    {
        parent::__construct();

        $this->type = $type;
    }

public function getType(): string
    {
        return $this->type;
    }

    public function isHead(): bool
    {
        return $this->type === self::TYPE_HEAD;
    }

    public function isBody(): bool
    {
        return $this->type === self::TYPE_BODY;
    }
}
}

// league/commonmark/src/Extension/Table/TableSectionRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class TableSectionRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        TableSection::assertInstanceOf($node);

        if (! $node->hasChildren()) {
            return '';
        }

        $attrs = $node->data->get('attributes');

        $separator = $childRenderer->getInnerSeparator();

        $tag = $node->getType() === TableSection::TYPE_HEAD ? 'thead' : 'tbody';

        return new HtmlElement($tag, $attrs, $separator . $childRenderer->renderNodes($node->children()) . $separator);
    }

    public function getXmlTagName(Node $node): string
    {
        return 'table_section';
    }

public function getXmlAttributes(Node $node): array
    {
        TableSection::assertInstanceOf($node);

        return [
            'type' => $node->getType(),
        ];
    }
}
}

// league/commonmark/src/Extension/Table/TableStartParser.php


namespace CMS\Vendor\League\CommonMark\Extension\Table {

use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\ParagraphParser;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;

final class TableStartParser implements BlockStartParserInterface
{
    private int $maxAutocompletedCells;

    public function __construct(int $maxAutocompletedCells = TableParser::DEFAULT_MAX_AUTOCOMPLETED_CELLS)
    {
        $this->maxAutocompletedCells = $maxAutocompletedCells;
    }

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        $paragraph = $parserState->getParagraphContent();
        if ($paragraph === null) {
            return BlockStart::none();
        }

$columns = self::parseSeparator($cursor);
        if (\count($columns) === 0) {
            return BlockStart::none();
        }

        $lastLineBreak = \strrpos($paragraph, "\n");
        $lastLine      = $lastLineBreak === false ? $paragraph : \substr($paragraph, $lastLineBreak + 1);

$headerCells = TableParser::split($lastLine);
        if (\count($headerCells) !== \count($columns)) {
            return BlockStart::none();
        }

        $cursor->advanceToEnd();

        $parsers = [];

        if ($lastLineBreak !== false) {
            $p = new ParagraphParser();
            $p->addLine(\substr($paragraph, 0, $lastLineBreak));
            $parsers[] = $p;
        }

        $parsers[] = new TableParser($columns, $headerCells, $this->maxAutocompletedCells);

        return BlockStart::of(...$parsers)
            ->at($cursor)
            ->replaceActiveBlockParser();
    }

private static function parseSeparator(Cursor $cursor): array
    {

$line   = $cursor->getRemainder();
        $length = \strlen($line);

        $columns = [];
        $pipes   = 0;
        $valid   = false;

        for ($i = 0; $i < $length;) {
            switch ($c = $line[$i]) {
                case '|':
                    $i++;
                    $pipes++;
                    if ($pipes > 1) {

                        return [];
                    }

$valid = true;
                    break;
                case '-':
                case ':':
                    if ($pipes === 0 && \count($columns) > 0) {

                        return [];
                    }

                    $left  = false;
                    $right = false;
                    if ($c === ':') {
                        $left = true;
                        $i++;
                    }

                    $dashes = \strspn($line, '-', $i);
                    if ($dashes === 0) {

                        return [];
                    }

                    $i += $dashes;

                    if ($i < $length && $line[$i] === ':') {
                        $right = true;
                        $i++;
                    }

                    $columns[] = self::getAlignment($left, $right);

                    $pipes = 0;
                    break;
                case ' ':
                case "\t":

                    $i += \strspn($line, " \t", $i);
                    break;
                default:

                    return [];
            }
        }

        if (! $valid) {
            return [];
        }

        return $columns;
    }

private static function getAlignment(bool $left, bool $right): ?string
    {
        if ($left && $right) {
            return TableCell::ALIGN_CENTER;
        }

        if ($left) {
            return TableCell::ALIGN_LEFT;
        }

        if ($right) {
            return TableCell::ALIGN_RIGHT;
        }

        return null;
    }
}
}

// league/commonmark/src/Extension/TaskList/TaskListExtension.php


namespace CMS\Vendor\League\CommonMark\Extension\TaskList {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CMS\Vendor\League\CommonMark\Extension\ExtensionInterface;

final class TaskListExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addInlineParser(new TaskListItemMarkerParser(), 35);
        $environment->addRenderer(TaskListItemMarker::class, new TaskListItemMarkerRenderer());
    }
}
}

// league/commonmark/src/Extension/TaskList/TaskListItemMarker.php


namespace CMS\Vendor\League\CommonMark\Extension\TaskList {

use CMS\Vendor\League\CommonMark\Node\Inline\AbstractInline;

final class TaskListItemMarker extends AbstractInline
{

    private bool $checked;

    public function __construct(bool $isCompleted)
    {
        parent::__construct();

        $this->checked = $isCompleted;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function setChecked(bool $checked): void
    {
        $this->checked = $checked;
    }
}
}

// league/commonmark/src/Extension/TaskList/TaskListItemMarkerParser.php


namespace CMS\Vendor\League\CommonMark\Extension\TaskList {

use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class TaskListItemMarkerParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::oneOf('[ ]', '[x]');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $container = $inlineContext->getContainer();

if ($container->hasChildren() || ! ($container instanceof Paragraph && $container->parent() && $container->parent() instanceof ListItem)) {
            return false;
        }

        $cursor   = $inlineContext->getCursor();
        $oldState = $cursor->saveState();

        $cursor->advanceBy(3);

$nextCharacter = $cursor->getCurrentCharacter();
        if ($nextCharacter === null || ! RegexHelper::isWhitespace($nextCharacter) || $cursor->getNextNonSpaceCharacter() === null) {
            $cursor->restoreState($oldState);

            return false;
        }

        $isChecked = $inlineContext->getFullMatch() !== '[ ]';

        $container->appendChild(new TaskListItemMarker($isChecked));

        return true;
    }
}
}

// league/commonmark/src/Extension/TaskList/TaskListItemMarkerRenderer.php


namespace CMS\Vendor\League\CommonMark\Extension\TaskList {

use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class TaskListItemMarkerRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        TaskListItemMarker::assertInstanceOf($node);

        $attrs    = $node->data->get('attributes');
        $checkbox = new HtmlElement('input', $attrs, '', true);

        if ($node->isChecked()) {
            $checkbox->setAttribute('checked', '');
        }

        $checkbox->setAttribute('disabled', '');
        $checkbox->setAttribute('type', 'checkbox');

        return $checkbox;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'task_list_item_marker';
    }

public function getXmlAttributes(Node $node): array
    {
        TaskListItemMarker::assertInstanceOf($node);

        if ($node->isChecked()) {
            return ['checked' => 'checked'];
        }

        return [];
    }
}
}

// league/commonmark/src/Input/MarkdownInputInterface.php


namespace CMS\Vendor\League\CommonMark\Input {

interface MarkdownInputInterface
{
    public function getContent(): string;

public function getLines(): iterable;

    public function getLineCount(): int;
}
}

// league/commonmark/src/Input/MarkdownInput.php


namespace CMS\Vendor\League\CommonMark\Input {

use CMS\Vendor\League\CommonMark\Exception\UnexpectedEncodingException;

class MarkdownInput implements MarkdownInputInterface
{

    private ?array $lines = null;

private string $content;

private ?int $lineCount = null;

private int $lineOffset;

    public function __construct(string $content, int $lineOffset = 0)
    {
        if (! \mb_check_encoding($content, 'UTF-8')) {
            throw new UnexpectedEncodingException('Unexpected encoding - UTF-8 or ASCII was expected');
        }

if (\substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = \substr($content, 3);
        }

        $this->content    = $content;
        $this->lineOffset = $lineOffset;
    }

    public function getContent(): string
    {
        return $this->content;
    }

public function getLines(): iterable
    {
        $this->splitLinesIfNeeded();

        \assert($this->lines !== null);

foreach ($this->lines as $i => $line) {
            yield $this->lineOffset + $i + 1 => $line;
        }
    }

    public function getLineCount(): int
    {
        $this->splitLinesIfNeeded();

        \assert($this->lineCount !== null);

        return $this->lineCount;
    }

    private function splitLinesIfNeeded(): void
    {
        if ($this->lines !== null) {
            return;
        }

        $lines = \preg_split('/\r\n|\n|\r/', $this->content);
        if ($lines === false) {
            throw new UnexpectedEncodingException('Failed to split Markdown content by line');
        }

        $this->lines = $lines;

if (\end($this->lines) === '') {
            \array_pop($this->lines);
        }

        $this->lineCount = \count($this->lines);
    }
}
}

// league/commonmark/src/MarkdownConverterInterface.php


namespace CMS\Vendor\League\CommonMark {

use CMS\Vendor\League\CommonMark\Exception\CommonMarkException;
use CMS\Vendor\League\CommonMark\Output\RenderedContentInterface;

interface MarkdownConverterInterface
{

    public function convertToHtml(string $markdown): RenderedContentInterface;
}
}

// league/commonmark/src/MarkdownConverter.php


namespace CMS\Vendor\League\CommonMark {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentInterface;
use CMS\Vendor\League\CommonMark\Exception\CommonMarkException;
use CMS\Vendor\League\CommonMark\Output\RenderedContentInterface;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParser;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserInterface;
use CMS\Vendor\League\CommonMark\Renderer\HtmlRenderer;
use CMS\Vendor\League\CommonMark\Renderer\MarkdownRendererInterface;

class MarkdownConverter implements ConverterInterface, MarkdownConverterInterface
{

    protected EnvironmentInterface $environment;

protected MarkdownParserInterface $markdownParser;

protected MarkdownRendererInterface $htmlRenderer;

    public function __construct(EnvironmentInterface $environment)
    {
        $this->environment = $environment;

        $this->markdownParser = new MarkdownParser($environment);
        $this->htmlRenderer   = new HtmlRenderer($environment);
    }

    public function getEnvironment(): EnvironmentInterface
    {
        return $this->environment;
    }

public function convert(string $input): RenderedContentInterface
    {
        $documentAST = $this->markdownParser->parse($input);

        return $this->htmlRenderer->renderDocument($documentAST);
    }

public function convertToHtml(string $markdown): RenderedContentInterface
    {
        \trigger_deprecation('league/commonmark', '2.2.0', 'Calling "convertToHtml()" on a %s class is deprecated, use "convert()" instead.', self::class);

        return $this->convert($markdown);
    }

public function __invoke(string $markdown): RenderedContentInterface
    {
        return $this->convert($markdown);
    }
}
}

// league/commonmark/src/Node/Block/Document.php


namespace CMS\Vendor\League\CommonMark\Node\Block {

use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Reference\ReferenceMap;
use CMS\Vendor\League\CommonMark\Reference\ReferenceMapInterface;

class Document extends AbstractBlock
{

    protected ReferenceMapInterface $referenceMap;

    public function __construct(?ReferenceMapInterface $referenceMap = null)
    {
        parent::__construct();

        $this->setStartLine(1);

        $this->referenceMap = $referenceMap ?? new ReferenceMap();
    }

    public function getReferenceMap(): ReferenceMapInterface
    {
        return $this->referenceMap;
    }

    public function canContain(AbstractBlock $block): bool
    {
        return true;
    }

    public function isCode(): bool
    {
        return false;
    }

    public function matchesNextLine(Cursor $cursor): bool
    {
        return true;
    }
}
}

// league/commonmark/src/Node/Block/Paragraph.php


namespace CMS\Vendor\League\CommonMark\Node\Block {

class Paragraph extends AbstractBlock
{

    public bool $onlyContainsLinkReferenceDefinitions = false;
}
}

// league/commonmark/src/Node/Inline/AdjacentTextMerger.php


namespace CMS\Vendor\League\CommonMark\Node\Inline {

use CMS\Vendor\League\CommonMark\Node\Node;

final class AdjacentTextMerger
{
    public static function mergeChildNodes(Node $node): void
    {

        if ($node->firstChild() === $node->lastChild() || $node->firstChild() === null || $node->lastChild() === null) {
            return;
        }

self::mergeTextNodesInclusive($node->firstChild(), $node->lastChild());
    }

    public static function mergeTextNodesBetweenExclusive(Node $fromNode, Node $toNode): void
    {

        if ($fromNode === $toNode || $fromNode->next() === $toNode || $fromNode->next() === null || $toNode->previous() === null) {
            return;
        }

self::mergeTextNodesInclusive($fromNode->next(), $toNode->previous());
    }

    public static function mergeWithDirectlyAdjacentNodes(Text $node): void
    {
        $start = ($previous = $node->previous()) instanceof Text ? $previous : $node;
        $end   = ($next = $node->next()) instanceof Text ? $next : $node;

        self::mergeIfNeeded($start, $end);
    }

    private static function mergeTextNodesInclusive(Node $fromNode, Node $toNode): void
    {
        $first = null;
        $last  = null;

        $node = $fromNode;
        while ($node !== null) {
            if ($node instanceof Text) {
                if ($first === null) {
                    $first = $node;
                }

                $last = $node;
            } else {
                self::mergeIfNeeded($first, $last);
                $first = null;
                $last  = null;
            }

            if ($node === $toNode) {
                break;
            }

            $node = $node->next();
        }

        self::mergeIfNeeded($first, $last);
    }

    private static function mergeIfNeeded(?Text $first, ?Text $last): void
    {
        if ($first === null || $last === null || $first === $last) {

            return;
        }

        $node = $first->next();
        $stop = $last->next();
        while ($node !== $stop && $node instanceof Text) {
            $first->append($node->getLiteral());
            $unlink = $node;
            $node   = $node->next();
            $unlink->detach();
        }
    }
}
}

// league/commonmark/src/Node/Inline/Newline.php


namespace CMS\Vendor\League\CommonMark\Node\Inline {

final class Newline extends AbstractInline
{

    public const HARDBREAK = 0;
    public const SOFTBREAK = 1;

private int $type;

    public function __construct(int $breakType = self::HARDBREAK)
    {
        parent::__construct();

        $this->type = $breakType;
    }

public function getType(): int
    {
        return $this->type;
    }
}
}

// league/commonmark/src/Node/Inline/Text.php


namespace CMS\Vendor\League\CommonMark\Node\Inline {

final class Text extends AbstractStringContainer
{
    public function append(string $literal): void
    {
        $this->literal .= $literal;
    }
}
}

// league/commonmark/src/Node/NodeIterator.php


namespace CMS\Vendor\League\CommonMark\Node {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

final class NodeIterator implements \IteratorAggregate
{
    public const FLAG_BLOCKS_ONLY = 1;

    private Node $node;
    private bool $blocksOnly;

    public function __construct(Node $node, int $flags = 0)
    {
        $this->node       = $node;
        $this->blocksOnly = ($flags & self::FLAG_BLOCKS_ONLY) === self::FLAG_BLOCKS_ONLY;
    }

public function getIterator(): \Generator
    {
        $stack = [$this->node];
        $index = 0;

        while ($stack) {
            $node = \array_pop($stack);

            yield $index++ => $node;

$child = $node->lastChild();
            while ($child !== null) {
                if (! $this->blocksOnly || $child instanceof AbstractBlock) {
                    $stack[] = $child;
                }

                $child = $child->previous();
            }
        }
    }
}
}

// league/commonmark/src/Node/NodeWalker.php


namespace CMS\Vendor\League\CommonMark\Node {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

final class NodeWalker
{

    private Node $root;

private ?Node $current = null;

private bool $entering;

    public function __construct(Node $root)
    {
        $this->root     = $root;
        $this->current  = $this->root;
        $this->entering = true;
    }

public function next(): ?NodeWalkerEvent
    {
        $current  = $this->current;
        $entering = $this->entering;
        if ($current === null) {
            return null;
        }

        if ($entering && ($current instanceof AbstractBlock || $current->hasChildren())) {
            if ($current->firstChild()) {
                $this->current  = $current->firstChild();
                $this->entering = true;
            } else {
                $this->entering = false;
            }
        } elseif ($current === $this->root) {
            $this->current = null;
        } elseif ($current->next() === null) {
            $this->current  = $current->parent();
            $this->entering = false;
        } else {
            $this->current  = $current->next();
            $this->entering = true;
        }

        return new NodeWalkerEvent($current, $entering);
    }

public function resumeAt(Node $node, bool $entering = true): void
    {
        $this->current  = $node;
        $this->entering = $entering;
    }
}
}

// league/commonmark/src/Node/NodeWalkerEvent.php


namespace CMS\Vendor\League\CommonMark\Node {

final class NodeWalkerEvent
{

    private Node $node;

private bool $isEntering;

    public function __construct(Node $node, bool $isEntering = true)
    {
        $this->node       = $node;
        $this->isEntering = $isEntering;
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    public function isEntering(): bool
    {
        return $this->isEntering;
    }
}
}

// league/commonmark/src/Normalizer/TextNormalizerInterface.php


namespace CMS\Vendor\League\CommonMark\Normalizer {

interface TextNormalizerInterface
{

    public function normalize(string $text, array $context = []): string;
}
}

// league/commonmark/src/Normalizer/SlugNormalizer.php


namespace CMS\Vendor\League\CommonMark\Normalizer {

use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class SlugNormalizer implements TextNormalizerInterface, ConfigurationAwareInterface
{

    private int $defaultMaxLength = 255;

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->defaultMaxLength = $configuration->get('slug_normalizer/max_length');
    }

public function normalize(string $text, array $context = []): string
    {

        $slug = ($context['prefix'] ?? '') . $text;

        $slug = \trim($slug);

        $slug = \mb_strtolower($slug, 'UTF-8');

        $slug = \preg_replace('/\s+/u', '-', $slug) ?? $slug;

        $slug = \preg_replace('/[^\p{L}\p{Nd}\p{Nl}\p{M}-]+/u', '', $slug) ?? $slug;

        if ($length = $context['length'] ?? $this->defaultMaxLength) {
            $slug = \mb_substr($slug, 0, $length, 'UTF-8');
        }

return $slug;
    }
}
}

// league/commonmark/src/Normalizer/TextNormalizer.php


namespace CMS\Vendor\League\CommonMark\Normalizer {

final class TextNormalizer implements TextNormalizerInterface
{

    public function normalize(string $text, array $context = []): string
    {

$text = \preg_replace('/[ \t\r\n]+/', ' ', \trim($text));
        \assert(\is_string($text));

if (\mb_check_encoding($text, 'ASCII')) {
            return \strtolower($text);
        }

        return \mb_convert_case($text, \MB_CASE_FOLD, 'UTF-8');
    }
}
}

// league/commonmark/src/Normalizer/UniqueSlugNormalizerInterface.php


namespace CMS\Vendor\League\CommonMark\Normalizer {

interface UniqueSlugNormalizerInterface extends TextNormalizerInterface
{
    public const DISABLED        = false;
    public const PER_ENVIRONMENT = 'environment';
    public const PER_DOCUMENT    = 'document';

public function clearHistory(): void;
}
}

// league/commonmark/src/Normalizer/UniqueSlugNormalizer.php


namespace CMS\Vendor\League\CommonMark\Normalizer {

final class UniqueSlugNormalizer implements UniqueSlugNormalizerInterface
{
    private TextNormalizerInterface $innerNormalizer;

private array $reserved = [];

private array $alreadyUsed;

public function __construct(TextNormalizerInterface $innerNormalizer, iterable $reservedSlugs = [])
    {
        $this->innerNormalizer = $innerNormalizer;

        foreach ($reservedSlugs as $slug) {
            $this->reserved[$slug] = 1;
        }

        $this->alreadyUsed = $this->reserved;
    }

    public function clearHistory(): void
    {
        $this->alreadyUsed = $this->reserved;
    }

public function normalize(string $text, array $context = []): string
    {
        $normalized = $this->innerNormalizer->normalize($text, $context);

if (isset($this->alreadyUsed[$normalized])) {
            $suffix = $this->alreadyUsed[$normalized];
            while (isset($this->alreadyUsed["$normalized-$suffix"])) {
                ++$suffix;
            }

            $this->alreadyUsed[$normalized] = $suffix + 1;

            $normalized = "$normalized-$suffix";
        }

        $this->alreadyUsed[$normalized] = 1;

        return $normalized;
    }
}
}

// league/commonmark/src/Output/RenderedContentInterface.php


namespace CMS\Vendor\League\CommonMark\Output {

use CMS\Vendor\League\CommonMark\Node\Block\Document;

interface RenderedContentInterface extends \Stringable
{

    public function getDocument(): Document;

public function getContent(): string;
}
}

// league/commonmark/src/Output/RenderedContent.php


namespace CMS\Vendor\League\CommonMark\Output {

use CMS\Vendor\League\CommonMark\Node\Block\Document;

class RenderedContent implements RenderedContentInterface, \Stringable
{

    private Document $document;

private string $content;

    public function __construct(Document $document, string $content)
    {
        $this->document = $document;
        $this->content  = $content;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getContent(): string
    {
        return $this->content;
    }

public function __toString(): string
    {
        return $this->content;
    }
}
}

// league/commonmark/src/Parser/Block/BlockContinue.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\CursorState;

final class BlockContinue
{

    private ?CursorState $cursorState = null;

private bool $finalize;

    private function __construct(?CursorState $cursorState = null, bool $finalize = false)
    {
        $this->cursorState = $cursorState;
        $this->finalize    = $finalize;
    }

    public function getCursorState(): ?CursorState
    {
        return $this->cursorState;
    }

    public function isFinalize(): bool
    {
        return $this->finalize;
    }

public static function none(): ?self
    {
        return null;
    }

public static function at(Cursor $cursor): self
    {
        return new self($cursor->saveState(), false);
    }

public static function finished(): self
    {
        return new self(null, true);
    }
}
}

// league/commonmark/src/Parser/Block/BlockStart.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\CursorState;

final class BlockStart
{

    private array $blockParsers;

private ?CursorState $cursorState = null;

private bool $replaceActiveBlockParser = false;

    private bool $isAborting = false;

    private function __construct(BlockContinueParserInterface ...$blockParsers)
    {
        $this->blockParsers = $blockParsers;
    }

public function getBlockParsers(): iterable
    {
        return $this->blockParsers;
    }

    public function getCursorState(): ?CursorState
    {
        return $this->cursorState;
    }

    public function isReplaceActiveBlockParser(): bool
    {
        return $this->replaceActiveBlockParser;
    }

public function isAborting(): bool
    {
        return $this->isAborting;
    }

public function at(Cursor $cursor): self
    {
        $this->cursorState = $cursor->saveState();

        return $this;
    }

public function replaceActiveBlockParser(): self
    {
        $this->replaceActiveBlockParser = true;

        return $this;
    }

public static function none(): ?self
    {
        return null;
    }

public static function of(BlockContinueParserInterface ...$blockParsers): self
    {
        return new self(...$blockParsers);
    }

public static function abort(): self
    {
        $ret             = new self();
        $ret->isAborting = true;

        return $ret;
    }
}
}

// league/commonmark/src/Parser/Block/DocumentBlockParser.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\Block\Document;
use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Reference\ReferenceMapInterface;

final class DocumentBlockParser extends AbstractBlockContinueParser
{

    private Document $document;

    public function __construct(ReferenceMapInterface $referenceMap)
    {
        $this->document = new Document($referenceMap);
    }

    public function getBlock(): Document
    {
        return $this->document;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::at($cursor);
    }

    public function closeBlock(): void
    {
        $this->removeLinkReferenceDefinitions();
    }

    private function removeLinkReferenceDefinitions(): void
    {
        $emptyNodes = [];

        $walker = $this->document->walker();
        while ($event = $walker->next()) {
            $node = $event->getNode();

if ($event->isEntering() && $node instanceof Paragraph && $node->onlyContainsLinkReferenceDefinitions) {
                $emptyNodes[] = $node;
            }
        }

        foreach ($emptyNodes as $node) {
            $node->detach();
        }
    }
}
}

// league/commonmark/src/Parser/Block/ParagraphParser.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\InlineParserEngineInterface;
use CMS\Vendor\League\CommonMark\Reference\ReferenceInterface;
use CMS\Vendor\League\CommonMark\Reference\ReferenceParser;

final class ParagraphParser extends AbstractBlockContinueParser implements BlockContinueParserWithInlinesInterface
{

    private Paragraph $block;

private ReferenceParser $referenceParser;

    public function __construct()
    {
        $this->block           = new Paragraph();
        $this->referenceParser = new ReferenceParser();
    }

    public function canHaveLazyContinuationLines(): bool
    {
        return true;
    }

    public function getBlock(): Paragraph
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($cursor->isBlank()) {
            return BlockContinue::none();
        }

        return BlockContinue::at($cursor);
    }

    public function addLine(string $line): void
    {
        $this->referenceParser->parse($line);
    }

    public function closeBlock(): void
    {
        $this->block->onlyContainsLinkReferenceDefinitions = $this->referenceParser->hasReferences() && $this->referenceParser->getParagraphContent() === '';
    }

    public function parseInlines(InlineParserEngineInterface $inlineParser): void
    {
        $content = $this->getContentString();
        if ($content !== '') {
            $inlineParser->parse($content, $this->block);
        }
    }

    public function getContentString(): string
    {
        return $this->referenceParser->getParagraphContent();
    }

public function getReferences(): iterable
    {
        return $this->referenceParser->getReferences();
    }
}
}

// league/commonmark/src/Parser/Block/SkipLinesStartingWithLettersParser.php


namespace CMS\Vendor\League\CommonMark\Parser\Block {

use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;

final class SkipLinesStartingWithLettersParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if (! $cursor->isIndented() && RegexHelper::isLetter($cursor->getNextNonSpaceCharacter())) {
            $cursor->advanceToNextNonSpaceOrTab();

            return BlockStart::abort();
        }

        return BlockStart::none();
    }
}
}

// league/commonmark/src/Parser/Cursor.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Exception\UnexpectedEncodingException;

class Cursor
{
    public const INDENT_LEVEL = 4;

private const BYTE_OFFSET_CHECKPOINT_INTERVAL = 16;

private string $line;

private int $length;

private int $currentPosition = 0;

    private int $column = 0;

    private int $indent = 0;

    private int $previousPosition = 0;

    private ?int $nextNonSpaceCache = null;

    private bool $partiallyConsumedTab = false;

private $lastTabPosition;

private bool $isMultibyte;

private array $byteOffsetCheckpoints = [];

private int $lastByteOffsetPosition = 0;

private int $lastByteOffset = 0;

private array $charCache = [];

public function __construct(string $line)
    {
        if (! \mb_check_encoding($line, 'UTF-8')) {
            throw new UnexpectedEncodingException('Unexpected encoding - UTF-8 or ASCII was expected');
        }

        $this->line            = $line;
        $this->length          = \mb_strlen($line, 'UTF-8') ?: 0;
        $this->isMultibyte     = $this->length !== \strlen($line);
        $this->lastTabPosition = $this->isMultibyte ? \mb_strrpos($line, "\t", 0, 'UTF-8') : \strrpos($line, "\t");

        if (! $this->isMultibyte) {
            return;
        }

$this->byteOffsetCheckpoints = [0];
    }

private function byteOffset(int $index): int
    {
        if ($index === $this->lastByteOffsetPosition) {
            return $this->lastByteOffset;
        }

$checkpoint = \intdiv($index, self::BYTE_OFFSET_CHECKPOINT_INTERVAL);
        if (! isset($this->byteOffsetCheckpoints[$checkpoint])) {
            $checkpoint = \count($this->byteOffsetCheckpoints) - 1;
        }

        $position = $checkpoint * self::BYTE_OFFSET_CHECKPOINT_INTERVAL;
        $byte     = $this->byteOffsetCheckpoints[$checkpoint];

if ($this->lastByteOffsetPosition > $position && $this->lastByteOffsetPosition <= $index) {
            $position = $this->lastByteOffsetPosition;
            $byte     = $this->lastByteOffset;
        }

        $lineBytes = \strlen($this->line);
        while ($position < $index) {
            $byte++;
            while ($byte < $lineBytes && (\ord($this->line[$byte]) & 0xC0) === 0x80) {
                $byte++;
            }

            $position++;
            if ($position % self::BYTE_OFFSET_CHECKPOINT_INTERVAL === 0) {
                $this->byteOffsetCheckpoints[\intdiv($position, self::BYTE_OFFSET_CHECKPOINT_INTERVAL)] = $byte;
            }
        }

        $this->lastByteOffsetPosition = $index;
        $this->lastByteOffset         = $byte;

        return $byte;
    }

private function charAt(int $index): string
    {
        if (isset($this->charCache[$index])) {
            return $this->charCache[$index];
        }

        $startByte = $this->byteOffset($index);

$lead  = \ord($this->line[$startByte]);
        $width = $lead < 0x80 ? 1 : ($lead < 0xE0 ? 2 : ($lead < 0xF0 ? 3 : 4));

        return $this->charCache[$index] = \substr($this->line, $startByte, $width);
    }

public function getNextNonSpacePosition(): int
    {
        if ($this->nextNonSpaceCache !== null) {
            return $this->nextNonSpaceCache;
        }

        if ($this->currentPosition >= $this->length) {
            return $this->length;
        }

        $cols = $this->column;

$byteOffset = $this->isMultibyte ? $this->byteOffset($this->currentPosition) : $this->currentPosition;

if ($this->lastTabPosition === false || $this->currentPosition > $this->lastTabPosition) {
            $this->indent = \strspn($this->line, ' ', $byteOffset);

            return $this->nextNonSpaceCache = $this->currentPosition + $this->indent;
        }

        for ($i = $this->currentPosition; $i < $this->length; $i++, $byteOffset++) {
            $c = $this->line[$byteOffset];

            if ($c === ' ') {
                $cols++;
            } elseif ($c === "\t") {
                $cols += 4 - ($cols % 4);
            } else {
                break;
            }
        }

        $this->indent = $cols - $this->column;

        return $this->nextNonSpaceCache = $i;
    }

public function getNextNonSpaceCharacter(): ?string
    {
        $index = $this->getNextNonSpacePosition();
        if ($index >= $this->length) {
            return null;
        }

        if ($this->isMultibyte) {
            return $this->charAt($index);
        }

        return $this->line[$index];
    }

public function getIndent(): int
    {
        if ($this->nextNonSpaceCache === null) {
            $this->getNextNonSpacePosition();
        }

        return $this->indent;
    }

public function isIndented(): bool
    {
        if ($this->nextNonSpaceCache === null) {
            $this->getNextNonSpacePosition();
        }

        return $this->indent >= self::INDENT_LEVEL;
    }

    public function getCharacter(?int $index = null): ?string
    {
        if ($index === null) {
            $index = $this->currentPosition;
        }

if ($index < 0 || $index >= $this->length) {
            return null;
        }

        if ($this->isMultibyte) {
            return $this->charAt($index);
        }

        return $this->line[$index];
    }

public function getCurrentCharacter(): ?string
    {
        if ($this->currentPosition >= $this->length) {
            return null;
        }

        if ($this->isMultibyte) {
            return $this->charAt($this->currentPosition);
        }

        return $this->line[$this->currentPosition];
    }

public function peek(int $offset = 1): ?string
    {
        return $this->getCharacter($this->currentPosition + $offset);
    }

public function isBlank(): bool
    {
        return $this->nextNonSpaceCache === $this->length || $this->getNextNonSpacePosition() === $this->length;
    }

public function advance(): void
    {
        $this->advanceBy(1);
    }

public function advanceBy(int $characters, bool $advanceByColumns = false): void
    {
        $this->previousPosition  = $this->currentPosition;
        $this->nextNonSpaceCache = null;

        if ($this->currentPosition >= $this->length || $characters === 0) {
            return;
        }

if ($this->lastTabPosition === false || $this->currentPosition > $this->lastTabPosition) {
            $length                     = \min($characters, $this->length - $this->currentPosition);
            $this->partiallyConsumedTab = false;
            $this->currentPosition     += $length;
            $this->column              += $length;

            return;
        }

        if ($this->isMultibyte) {
            $startByte    = $this->byteOffset($this->currentPosition);
            $endByte      = $this->byteOffset(\min($this->currentPosition + $characters, $this->length));
            $nextFewChars = \substr($this->line, $startByte, $endByte - $startByte);
        } else {
            $nextFewChars = \substr($this->line, $this->currentPosition, $characters);
        }

        if ($characters === 1) {
            $asArray = [$nextFewChars];
        } elseif ($this->isMultibyte) {

            $asArray = \mb_str_split($nextFewChars, 1, 'UTF-8');
        } else {
            $asArray = \str_split($nextFewChars);
        }

        foreach ($asArray as $c) {
            if ($c === "\t") {
                $charsToTab = 4 - ($this->column % 4);
                if ($advanceByColumns) {
                    $this->partiallyConsumedTab = $charsToTab > $characters;
                    $charsToAdvance             = $charsToTab > $characters ? $characters : $charsToTab;
                    $this->column              += $charsToAdvance;
                    $this->currentPosition     += $this->partiallyConsumedTab ? 0 : 1;
                    $characters                -= $charsToAdvance;
                } else {
                    $this->partiallyConsumedTab = false;
                    $this->column              += $charsToTab;
                    $this->currentPosition++;
                    $characters--;
                }
            } else {
                $this->partiallyConsumedTab = false;
                $this->currentPosition++;
                $this->column++;
                $characters--;
            }

            if ($characters <= 0) {
                break;
            }
        }
    }

public function advanceBySpaceOrTab(): bool
    {
        $character = $this->getCurrentCharacter();

        if ($character === ' ' || $character === "\t") {
            $this->advanceBy(1, true);

            return true;
        }

        return false;
    }

public function advanceToNextNonSpaceOrTab(): int
    {
        $newPosition = $this->nextNonSpaceCache ?? $this->getNextNonSpacePosition();
        if ($newPosition === $this->currentPosition) {
            return 0;
        }

        $this->advanceBy($newPosition - $this->currentPosition);
        $this->partiallyConsumedTab = false;

$this->nextNonSpaceCache = $this->currentPosition;
        $this->indent            = 0;

        return $this->currentPosition - $this->previousPosition;
    }

public function advanceToNextNonSpaceOrNewline(): int
    {
        $currentCharacter = $this->getCurrentCharacter();

if ($currentCharacter !== ' ' && $currentCharacter !== "\n") {
            $this->previousPosition = $this->currentPosition;

            return 0;
        }

$byteOffset = $this->isMultibyte ? $this->byteOffset($this->currentPosition) : $this->currentPosition;

        $increment = \strspn($this->line, ' ', $byteOffset);
        if (($this->line[$byteOffset + $increment] ?? '') === "\n") {
            $increment++;
            $increment += \strspn($this->line, ' ', $byteOffset + $increment);
        }

        $this->advanceBy($increment);

        return $this->currentPosition - $this->previousPosition;
    }

public function advanceToEnd(): int
    {
        $this->previousPosition  = $this->currentPosition;
        $this->nextNonSpaceCache = null;

        $this->currentPosition = $this->length;

        return $this->currentPosition - $this->previousPosition;
    }

    public function getRemainder(): string
    {
        if ($this->currentPosition >= $this->length) {
            return '';
        }

        $prefix   = '';
        $position = $this->currentPosition;
        if ($this->partiallyConsumedTab) {
            $position++;
            $charsToTab = 4 - ($this->column % 4);
            $prefix     = \str_repeat(' ', $charsToTab);
        }

        $subString = $this->isMultibyte ?
            \substr($this->line, $this->byteOffset($position)) :
            \substr($this->line, $position);

        return $prefix . $subString;
    }

    public function getLine(): string
    {
        return $this->line;
    }

    public function isAtEnd(): bool
    {
        return $this->currentPosition >= $this->length;
    }

public function match(string $regex): ?string
    {
        $subject = $this->getRemainder();

        if (! \preg_match($regex, $subject, $matches, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

if ($this->isMultibyte) {
            $offset      = \mb_strlen(\substr($subject, 0, $matches[0][1]), 'UTF-8');
            $matchLength = \mb_strlen($matches[0][0], 'UTF-8');
        } else {
            $offset      = $matches[0][1];
            $matchLength = \strlen($matches[0][0]);
        }

        $advance = $offset + $matchLength;

if ($this->partiallyConsumedTab) {
            $charsToTab = 4 - ($this->column % 4);
            if ($advance < $charsToTab) {
                $this->advanceBy($advance, true);

                return $matches[0][0];
            }

            $this->advanceBy($charsToTab, true);
            $advance -= $charsToTab;
        }

        $this->advanceBy($advance);

        return $matches[0][0];
    }

public function matchInPlace(string $regex): ?string
    {

if ($this->partiallyConsumedTab) {
            return $this->match($regex);
        }

        $bytePosition = $this->isMultibyte ? $this->byteOffset($this->currentPosition) : $this->currentPosition;

        if (! \preg_match($regex, $this->line, $matches, \PREG_OFFSET_CAPTURE, $bytePosition)) {
            return null;
        }

if ($this->isMultibyte) {

$offset      = \mb_strlen(\substr($this->line, $bytePosition, $matches[0][1] - $bytePosition), 'UTF-8');
            $matchLength = \mb_strlen($matches[0][0], 'UTF-8');
        } else {
            $offset      = $matches[0][1] - $this->currentPosition;
            $matchLength = \strlen($matches[0][0]);
        }

        $this->advanceBy($offset + $matchLength);

        return $matches[0][0];
    }

public function saveState(): CursorState
    {
        return new CursorState([
            $this->currentPosition,
            $this->previousPosition,
            $this->nextNonSpaceCache,
            $this->indent,
            $this->column,
            $this->partiallyConsumedTab,
        ]);
    }

public function restoreState(CursorState $state): void
    {
        [
            $this->currentPosition,
            $this->previousPosition,
            $this->nextNonSpaceCache,
            $this->indent,
            $this->column,
            $this->partiallyConsumedTab,
        ] = $state->toArray();
    }

    public function getPosition(): int
    {
        return $this->currentPosition;
    }

public function getBytePosition(): int
    {
        if (! $this->isMultibyte) {
            return $this->currentPosition;
        }

        return $this->byteOffset($this->currentPosition);
    }

    public function getPreviousText(): string
    {
        if ($this->isMultibyte) {
            $startByte = $this->byteOffset($this->previousPosition);

            return \substr($this->line, $startByte, $this->byteOffset($this->currentPosition) - $startByte);
        }

        return \substr($this->line, $this->previousPosition, $this->currentPosition - $this->previousPosition);
    }

    public function getSubstring(int $start, ?int $length = null): string
    {
        if ($this->isMultibyte) {

if ($start < 0 || ($length !== null && $length < 0)) {
                return \mb_substr($this->line, $start, $length, 'UTF-8');
            }

            if ($start >= $this->length) {
                return '';
            }

            $startByte = $this->byteOffset($start);

            if ($length === null) {
                return \substr($this->line, $startByte);
            }

            return \substr($this->line, $startByte, $this->byteOffset($start + \min($length, $this->length - $start)) - $startByte);
        }

        if ($length !== null) {
            return \substr($this->line, $start, $length);
        }

        return \substr($this->line, $start);
    }

    public function getColumn(): int
    {
        return $this->column;
    }
}
}

// league/commonmark/src/Parser/CursorState.php


namespace CMS\Vendor\League\CommonMark\Parser {

final class CursorState
{

    private array $state;

public function __construct(array $state)
    {
        $this->state = $state;
    }

public function toArray(): array
    {
        return $this->state;
    }
}
}

// league/commonmark/src/Parser/Inline/InlineParserMatch.php


namespace CMS\Vendor\League\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;

final class InlineParserMatch
{
    private string $regex;

    private bool $caseSensitive;

    private function __construct(string $regex, bool $caseSensitive = false)
    {
        $this->regex         = $regex;
        $this->caseSensitive = $caseSensitive;
    }

    public function caseSensitive(): self
    {
        $this->caseSensitive = true;

        return $this;
    }

public function getRegex(): string
    {
        return '/' . $this->regex . '/' . ($this->caseSensitive ? '' : 'i');
    }

public static function string(string $str): self
    {
        return new self(\preg_quote($str, '/'));
    }

public static function oneOf(string ...$str): self
    {
        return new self(\implode('|', \array_map(static fn (string $str): string => \preg_quote($str, '/'), $str)));
    }

public static function regex(string $regex): self
    {
        return new self($regex);
    }

    public static function join(self ...$definitions): self
    {
        $regex         = '';
        $caseSensitive = null;
        foreach ($definitions as $definition) {
            $regex .= '(' . $definition->regex . ')';

            if ($caseSensitive === null) {
                $caseSensitive = $definition->caseSensitive;
            } elseif ($caseSensitive !== $definition->caseSensitive) {
                throw new InvalidArgumentException('Case-sensitive and case-insensitive definitions cannot be combined');
            }
        }

        return new self($regex, $caseSensitive ?? false);
    }
}
}

// league/commonmark/src/Parser/Inline/NewlineParser.php


namespace CMS\Vendor\League\CommonMark\Parser\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Newline;
use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\InlineParserContext;

final class NewlineParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('\\n');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getCursor()->advanceBy(1);

$spaces     = 0;
        $lastInline = $inlineContext->getContainer()->lastChild();
        if ($lastInline instanceof Text) {
            $trimmed = \rtrim($lastInline->getLiteral(), ' ');
            $spaces  = \strlen($lastInline->getLiteral()) - \strlen($trimmed);
            if ($spaces) {
                $lastInline->setLiteral($trimmed);
            }
        }

        if ($spaces >= 2) {
            $inlineContext->getContainer()->appendChild(new Newline(Newline::HARDBREAK));
        } else {
            $inlineContext->getContainer()->appendChild(new Newline(Newline::SOFTBREAK));
        }

        return true;
    }
}
}

// league/commonmark/src/Parser/InlineParserContext.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Delimiter\DelimiterStack;
use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Reference\ReferenceMapInterface;

final class InlineParserContext
{

    private AbstractBlock $container;

private ReferenceMapInterface $referenceMap;

private Cursor $cursor;

private DelimiterStack $delimiterStack;

private array $matches;

    public function __construct(Cursor $contents, AbstractBlock $container, ReferenceMapInterface $referenceMap, int $maxDelimitersPerLine = PHP_INT_MAX)
    {
        $this->referenceMap   = $referenceMap;
        $this->container      = $container;
        $this->cursor         = $contents;
        $this->delimiterStack = new DelimiterStack($maxDelimitersPerLine);
    }

    public function getContainer(): AbstractBlock
    {
        return $this->container;
    }

    public function getReferenceMap(): ReferenceMapInterface
    {
        return $this->referenceMap;
    }

    public function getCursor(): Cursor
    {
        return $this->cursor;
    }

    public function getDelimiterStack(): DelimiterStack
    {
        return $this->delimiterStack;
    }

public function getFullMatch(): string
    {
        return $this->matches[0];
    }

public function getFullMatchLength(): int
    {
        return \mb_strlen($this->matches[0], 'UTF-8');
    }

public function getMatches(): array
    {
        return $this->matches;
    }

public function getSubMatches(): array
    {
        return \array_slice($this->matches, 1);
    }

public function withMatches(array $matches): InlineParserContext
    {
        $ctx = clone $this;

        $ctx->matches = $matches;

        return $ctx;
    }
}
}

// league/commonmark/src/Parser/InlineParserEngineInterface.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;

interface InlineParserEngineInterface
{

    public function parse(string $contents, AbstractBlock $block): void;
}
}

// league/commonmark/src/Parser/InlineParserEngine.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentInterface;
use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\Inline\AdjacentTextMerger;
use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CMS\Vendor\League\CommonMark\Reference\ReferenceMapInterface;

final class InlineParserEngine implements InlineParserEngineInterface
{

    private EnvironmentInterface $environment;

private ReferenceMapInterface $referenceMap;

private array $parsers = [];

    public function __construct(EnvironmentInterface $environment, ReferenceMapInterface $referenceMap)
    {
        $this->environment  = $environment;
        $this->referenceMap = $referenceMap;

        foreach ($environment->getInlineParsers() as $parser) {
            \assert($parser instanceof InlineParserInterface);
            $regex = $parser->getMatchDefinition()->getRegex();

            $this->parsers[] = [$parser, $regex, \strlen($regex) !== \mb_strlen($regex, 'UTF-8')];
        }
    }

    public function parse(string $contents, AbstractBlock $block): void
    {
        $contents = \trim($contents);
        $cursor   = new Cursor($contents);

        $inlineParserContext = new InlineParserContext($cursor, $block, $this->referenceMap, $this->environment->getConfiguration()->get('max_delimiters_per_line'));

foreach ($this->matchParsers($contents) as $matchPosition => $parsers) {
            $currentPosition = $cursor->getPosition();

            if ($currentPosition > $matchPosition) {
                continue;
            }

if ($currentPosition < $matchPosition) {
                $cursor->advanceBy($matchPosition - $currentPosition);
                $this->addPlainText($cursor->getPreviousText(), $block);
            }

$parsed = false;
            foreach ($parsers as [$parser, $matches]) {
                if ($parser->parse($inlineParserContext->withMatches($matches))) {

                    $parsed = true;
                    break;
                }
            }

            if ($parsed) {
                continue;
            }

$this->addPlainText((string) $cursor->getCurrentCharacter(), $block);
            $cursor->advance();
        }

if (! $cursor->isAtEnd()) {
            $this->addPlainText($cursor->getRemainder(), $block);
        }

$delimiterStack = $inlineParserContext->getDelimiterStack();
        $delimiterStack->processDelimiters(null, $this->environment->getDelimiterProcessors());
        $delimiterStack->removeAll();

AdjacentTextMerger::mergeChildNodes($block);
    }

    private function addPlainText(string $text, AbstractBlock $container): void
    {
        $lastInline = $container->lastChild();
        if ($lastInline instanceof Text && ! $lastInline->data->has('delim')) {
            $lastInline->append($text);
        } else {
            $container->appendChild(new Text($text));
        }
    }

private function matchParsers(string $contents): array
    {
        $contents    = \trim($contents);
        $isMultibyte = ! \mb_check_encoding($contents, 'ASCII');

        $ret = [];

        foreach ($this->parsers as [$parser, $regex, $isRegexMultibyte]) {
            if ($isMultibyte || $isRegexMultibyte) {
                $regex .= 'u';
            }

if (! \preg_match_all($regex, $contents, $matches, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER)) {
                continue;
            }

$lastByteOffset = 0;
            $lastCharOffset = 0;
            foreach ($matches as $match) {
                if ($isMultibyte) {

$lastCharOffset += \mb_strlen(\substr($contents, $lastByteOffset, $match[0][1] - $lastByteOffset), 'UTF-8');
                    $lastByteOffset  = $match[0][1];
                    $offset          = $lastCharOffset;
                } else {
                    $offset = \intval($match[0][1]);
                }

$m = \array_column($match, 0);

                if ($m === []) {
                    continue;
                }

$ret[$offset][] = [$parser, $m];
            }
        }

\ksort($ret);

        return $ret;
    }
}
}

// league/commonmark/src/Parser/MarkdownParserInterface.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Exception\CommonMarkException;
use CMS\Vendor\League\CommonMark\Node\Block\Document;

interface MarkdownParserInterface
{

    public function parse(string $input): Document;
}
}

// league/commonmark/src/Parser/MarkdownParser.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentInterface;
use CMS\Vendor\League\CommonMark\Event\DocumentParsedEvent;
use CMS\Vendor\League\CommonMark\Event\DocumentPreParsedEvent;
use CMS\Vendor\League\CommonMark\Exception\CommonMarkException;
use CMS\Vendor\League\CommonMark\Input\MarkdownInput;
use CMS\Vendor\League\CommonMark\Node\Block\Document;
use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserWithInlinesInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStart;
use CMS\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\DocumentBlockParser;
use CMS\Vendor\League\CommonMark\Parser\Block\ParagraphParser;
use CMS\Vendor\League\CommonMark\Reference\MemoryLimitedReferenceMap;
use CMS\Vendor\League\CommonMark\Reference\ReferenceInterface;
use CMS\Vendor\League\CommonMark\Reference\ReferenceMap;

final class MarkdownParser implements MarkdownParserInterface
{

    private EnvironmentInterface $environment;

private int $maxNestingLevel;

private ReferenceMap $referenceMap;

private int $lineNumber = 0;

private Cursor $cursor;

private array $activeBlockParsers = [];

private array $closedBlockParsers = [];

    public function __construct(EnvironmentInterface $environment)
    {
        $this->environment = $environment;
    }

    private function initialize(): void
    {
        $this->referenceMap       = new ReferenceMap();
        $this->lineNumber         = 0;
        $this->activeBlockParsers = [];
        $this->closedBlockParsers = [];

        $this->maxNestingLevel = $this->environment->getConfiguration()->get('max_nesting_level');
    }

public function parse(string $input): Document
    {
        $this->initialize();

        $documentParser = new DocumentBlockParser($this->referenceMap);
        $this->activateBlockParser($documentParser);

        $preParsedEvent = new DocumentPreParsedEvent($documentParser->getBlock(), new MarkdownInput($input));
        $this->environment->dispatch($preParsedEvent);
        $markdownInput = $preParsedEvent->getMarkdown();

        foreach ($markdownInput->getLines() as $lineNumber => $line) {
            $this->lineNumber = $lineNumber;
            $this->parseLine($line);
        }

$this->closeBlockParsers(\count($this->activeBlockParsers), $this->lineNumber);
        $this->processInlines(\strlen($input));

        $this->environment->dispatch(new DocumentParsedEvent($documentParser->getBlock()));

        return $documentParser->getBlock();
    }

private function parseLine(string $line): void
    {

        $line = \str_replace("\0", "\u{FFFD}", $line);

        $this->cursor = new Cursor($line);

        $matches = $this->parseBlockContinuation();
        if ($matches === null) {
            return;
        }

        $unmatchedBlocks = \count($this->activeBlockParsers) - $matches;
        $blockParser     = $this->activeBlockParsers[$matches - 1];
        $startedNewBlock = false;

$tryBlockStarts = $blockParser->getBlock() instanceof Paragraph || $blockParser->isContainer();
        while ($tryBlockStarts) {

            if ($this->cursor->isBlank()) {
                $this->cursor->advanceToEnd();
                break;
            }

            if ($blockParser->getBlock()->getDepth() >= $this->maxNestingLevel) {
                break;
            }

            $blockStart = $this->findBlockStart($blockParser);
            if ($blockStart === null || $blockStart->isAborting()) {
                $this->cursor->advanceToNextNonSpaceOrTab();
                break;
            }

            if (($state = $blockStart->getCursorState()) !== null) {
                $this->cursor->restoreState($state);
            }

            $startedNewBlock = true;

if ($unmatchedBlocks > 0) {
                $this->closeBlockParsers($unmatchedBlocks, $this->lineNumber - 1);
                $unmatchedBlocks = 0;
            }

            $oldBlockLineStart = null;
            if ($blockStart->isReplaceActiveBlockParser()) {
                $oldBlockLineStart = $this->prepareActiveBlockParserForReplacement();
            }

            foreach ($blockStart->getBlockParsers() as $newBlockParser) {
                $blockParser    = $this->addChild($newBlockParser, $oldBlockLineStart);
                $tryBlockStarts = $newBlockParser->isContainer();
            }
        }

if (! $startedNewBlock && ! $this->cursor->isBlank() && $this->getActiveBlockParser()->canHaveLazyContinuationLines()) {
            $this->getActiveBlockParser()->addLine($this->cursor->getRemainder());
        } else {

            if ($unmatchedBlocks > 0) {
                $this->closeBlockParsers($unmatchedBlocks, $this->lineNumber - 1);
            }

            if (! $blockParser->isContainer()) {
                $this->getActiveBlockParser()->addLine($this->cursor->getRemainder());
            } elseif (! $this->cursor->isBlank()) {
                $this->addChild(new ParagraphParser());
                $this->getActiveBlockParser()->addLine($this->cursor->getRemainder());
            }
        }
    }

    private function parseBlockContinuation(): ?int
    {

$matches = 1;
        for ($i = 1; $i < \count($this->activeBlockParsers); $i++) {
            $blockParser   = $this->activeBlockParsers[$i];
            $blockContinue = $blockParser->tryContinue(clone $this->cursor, $this->getActiveBlockParser());
            if ($blockContinue === null) {
                break;
            }

            if ($blockContinue->isFinalize()) {
                $this->closeBlockParsers(\count($this->activeBlockParsers) - $i, $this->lineNumber);

                return null;
            }

            if (($state = $blockContinue->getCursorState()) !== null) {
                $this->cursor->restoreState($state);
            }

            $matches++;
        }

        return $matches;
    }

    private function findBlockStart(BlockContinueParserInterface $lastMatchedBlockParser): ?BlockStart
    {
        $matchedBlockParser = new MarkdownParserState($this->getActiveBlockParser(), $lastMatchedBlockParser);

        foreach ($this->environment->getBlockStartParsers() as $blockStartParser) {
            \assert($blockStartParser instanceof BlockStartParserInterface);
            if (($result = $blockStartParser->tryStart(clone $this->cursor, $matchedBlockParser)) !== null) {
                return $result;
            }
        }

        return null;
    }

    private function closeBlockParsers(int $count, int $endLineNumber): void
    {
        for ($i = 0; $i < $count; $i++) {
            $blockParser = $this->deactivateBlockParser();
            $this->finalize($blockParser, $endLineNumber);

if ($blockParser instanceof BlockContinueParserWithInlinesInterface) {

                $this->closedBlockParsers[] = $blockParser;
            }
        }
    }

private function finalize(BlockContinueParserInterface $blockParser, int $endLineNumber): void
    {
        if ($blockParser instanceof ParagraphParser) {
            $this->updateReferenceMap($blockParser->getReferences());
        }

        $blockParser->getBlock()->setEndLine($endLineNumber);
        $blockParser->closeBlock();
    }

private function processInlines(int $inputSize): void
    {
        $p = new InlineParserEngine($this->environment, new MemoryLimitedReferenceMap($this->referenceMap, $inputSize));

        foreach ($this->closedBlockParsers as $blockParser) {
            $blockParser->parseInlines($p);
        }
    }

private function addChild(BlockContinueParserInterface $blockParser, ?int $startLineNumber = null): BlockContinueParserInterface
    {
        $blockParser->getBlock()->setStartLine($startLineNumber ?? $this->lineNumber);

        while (! $this->getActiveBlockParser()->canContain($blockParser->getBlock())) {
            $this->closeBlockParsers(1, ($startLineNumber ?? $this->lineNumber) - 1);
        }

        $this->getActiveBlockParser()->getBlock()->appendChild($blockParser->getBlock());
        $this->activateBlockParser($blockParser);

        return $blockParser;
    }

    private function activateBlockParser(BlockContinueParserInterface $blockParser): void
    {
        $this->activeBlockParsers[] = $blockParser;
    }

private function deactivateBlockParser(): BlockContinueParserInterface
    {
        $popped = \array_pop($this->activeBlockParsers);
        if ($popped === null) {
            throw new ParserLogicException('The last block parser should not be deactivated');
        }

        return $popped;
    }

private function prepareActiveBlockParserForReplacement(): ?int
    {

        $old = $this->deactivateBlockParser();

        if ($old instanceof ParagraphParser) {
            $this->updateReferenceMap($old->getReferences());
        }

        $old->getBlock()->detach();

        return $old->getBlock()->getStartLine();
    }

private function updateReferenceMap(iterable $references): void
    {
        foreach ($references as $reference) {
            if (! $this->referenceMap->contains($reference->getLabel())) {
                $this->referenceMap->add($reference);
            }
        }
    }

public function getActiveBlockParser(): BlockContinueParserInterface
    {
        $active = \end($this->activeBlockParsers);
        if ($active === false) {
            throw new ParserLogicException('No active block parsers are available');
        }

        return $active;
    }
}
}

// league/commonmark/src/Parser/MarkdownParserStateInterface.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;

interface MarkdownParserStateInterface
{

    public function getActiveBlockParser(): BlockContinueParserInterface;

public function getLastMatchedBlockParser(): BlockContinueParserInterface;

public function getParagraphContent(): ?string;
}
}

// league/commonmark/src/Parser/MarkdownParserState.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use CMS\Vendor\League\CommonMark\Parser\Block\ParagraphParser;

final class MarkdownParserState implements MarkdownParserStateInterface
{

    private BlockContinueParserInterface $activeBlockParser;

private BlockContinueParserInterface $lastMatchedBlockParser;

    public function __construct(BlockContinueParserInterface $activeBlockParser, BlockContinueParserInterface $lastMatchedBlockParser)
    {
        $this->activeBlockParser      = $activeBlockParser;
        $this->lastMatchedBlockParser = $lastMatchedBlockParser;
    }

    public function getActiveBlockParser(): BlockContinueParserInterface
    {
        return $this->activeBlockParser;
    }

    public function getLastMatchedBlockParser(): BlockContinueParserInterface
    {
        return $this->lastMatchedBlockParser;
    }

    public function getParagraphContent(): ?string
    {
        if (! $this->lastMatchedBlockParser instanceof ParagraphParser) {
            return null;
        }

        $paragraphParser = $this->lastMatchedBlockParser;
        $content         = $paragraphParser->getContentString();

        return $content === '' ? null : $content;
    }
}
}

// league/commonmark/src/Parser/ParserLogicException.php


namespace CMS\Vendor\League\CommonMark\Parser {

use CMS\Vendor\League\CommonMark\Exception\CommonMarkException;

class ParserLogicException extends \LogicException implements CommonMarkException
{
}
}

// league/commonmark/src/Reference/ReferenceMapInterface.php


namespace CMS\Vendor\League\CommonMark\Reference {

interface ReferenceMapInterface extends \IteratorAggregate, \Countable
{
    public function add(ReferenceInterface $reference): void;

    public function contains(string $label): bool;

    public function get(string $label): ?ReferenceInterface;
}
}

// league/commonmark/src/Reference/MemoryLimitedReferenceMap.php


namespace CMS\Vendor\League\CommonMark\Reference {

final class MemoryLimitedReferenceMap implements ReferenceMapInterface
{
    private ReferenceMapInterface $decorated;

    private const MINIMUM_SIZE = 100_000;

    private int $remaining;

    public function __construct(ReferenceMapInterface $decorated, int $maxSize)
    {
        $this->decorated = $decorated;
        $this->remaining = \max(self::MINIMUM_SIZE, $maxSize);
    }

    public function add(ReferenceInterface $reference): void
    {
        $this->decorated->add($reference);
    }

    public function contains(string $label): bool
    {
        return $this->decorated->contains($label);
    }

    public function get(string $label): ?ReferenceInterface
    {
        $reference = $this->decorated->get($label);
        if ($reference === null) {
            return null;
        }

$this->remaining -= \strlen($reference->getDestination()) + \strlen($reference->getTitle());
        if ($this->remaining < 0) {
            return null;
        }

        return $reference;
    }

public function getIterator(): \Traversable
    {
        return $this->decorated->getIterator();
    }

    public function count(): int
    {
        return $this->decorated->count();
    }
}
}

// league/commonmark/src/Reference/ReferenceInterface.php


namespace CMS\Vendor\League\CommonMark\Reference {

interface ReferenceInterface
{
    public function getLabel(): string;

    public function getDestination(): string;

    public function getTitle(): string;
}
}

// league/commonmark/src/Reference/Reference.php


namespace CMS\Vendor\League\CommonMark\Reference {

final class Reference implements ReferenceInterface
{

    private string $label;

private string $destination;

private string $title;

    public function __construct(string $label, string $destination, string $title)
    {
        $this->label       = $label;
        $this->destination = $destination;
        $this->title       = $title;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}
}

// league/commonmark/src/Reference/ReferenceMap.php


namespace CMS\Vendor\League\CommonMark\Reference {

use CMS\Vendor\League\CommonMark\Normalizer\TextNormalizer;

final class ReferenceMap implements ReferenceMapInterface
{

    private TextNormalizer $normalizer;

private array $references = [];

    public function __construct()
    {
        $this->normalizer = new TextNormalizer();
    }

    public function add(ReferenceInterface $reference): void
    {

        $key = $this->normalizer->normalize($reference->getLabel());

        $this->references[$key] = $reference;
    }

    public function contains(string $label): bool
    {
        if ($this->references === []) {
            return false;
        }

        $label = $this->normalizer->normalize($label);

        return isset($this->references[$label]);
    }

    public function get(string $label): ?ReferenceInterface
    {
        if ($this->references === []) {
            return null;
        }

        $label = $this->normalizer->normalize($label);

        return $this->references[$label] ?? null;
    }

public function getIterator(): \Traversable
    {
        foreach ($this->references as $normalizedLabel => $reference) {
            yield $normalizedLabel => $reference;
        }
    }

    public function count(): int
    {
        return \count($this->references);
    }
}
}

// league/commonmark/src/Reference/ReferenceParser.php


namespace CMS\Vendor\League\CommonMark\Reference {

use CMS\Vendor\League\CommonMark\Parser\Cursor;
use CMS\Vendor\League\CommonMark\Util\LinkParserHelper;

final class ReferenceParser
{

    private const START_DEFINITION = 0;

    private const LABEL = 1;

    private const DESTINATION = 2;

    private const START_TITLE = 3;

    private const TITLE = 4;

    private const PARAGRAPH = 5;

private string $paragraph = '';

private array $references = [];

private int $state = self::START_DEFINITION;

private ?string $label = null;

private ?string $destination = null;

private string $title = '';

private ?string $titleDelimiter = null;

private bool $referenceValid = false;

    public function getParagraphContent(): string
    {
        return $this->paragraph;
    }

public function getReferences(): iterable
    {
        if ($this->referenceValid && $this->state === self::TITLE) {

            $this->title = '';
        }

        $this->finishReference();

        return $this->references;
    }

    public function hasReferences(): bool
    {
        return $this->references !== [];
    }

    public function parse(string $line): void
    {
        if ($this->paragraph !== '') {
            $this->paragraph .= "\n";
        }

        $this->paragraph .= $line;

        $cursor = new Cursor($line);
        while (! $cursor->isAtEnd()) {
            $result = false;
            switch ($this->state) {
                case self::PARAGRAPH:

return;
                case self::START_DEFINITION:
                    $result = $this->parseStartDefinition($cursor);
                    break;
                case self::LABEL:
                    $result = $this->parseLabel($cursor);
                    break;
                case self::DESTINATION:
                    $result = $this->parseDestination($cursor);
                    break;
                case self::START_TITLE:
                    $result = $this->parseStartTitle($cursor);
                    break;
                case self::TITLE:
                    $result = $this->parseTitle($cursor);
                    break;
                default:

                    break;
            }

            if (! $result) {
                $this->state = self::PARAGRAPH;

                return;
            }
        }
    }

    private function parseStartDefinition(Cursor $cursor): bool
    {
        $cursor->advanceToNextNonSpaceOrTab();
        if ($cursor->isAtEnd() || $cursor->getCurrentCharacter() !== '[') {
            return false;
        }

        $this->state = self::LABEL;
        $this->label = '';

        $cursor->advance();
        if ($cursor->isAtEnd()) {
            $this->label .= "\n";
        }

        return true;
    }

    private function parseLabel(Cursor $cursor): bool
    {
        $cursor->advanceToNextNonSpaceOrTab();

        $partialLabel = LinkParserHelper::parsePartialLinkLabel($cursor);
        if ($partialLabel === null) {
            return false;
        }

        \assert($this->label !== null);
        $this->label .= $partialLabel;

        if ($cursor->isAtEnd()) {

            $this->label .= "\n";

            return true;
        }

        if ($cursor->getCurrentCharacter() !== ']') {
            return false;
        }

        $cursor->advance();

if ($cursor->getCurrentCharacter() !== ':') {
            return false;
        }

        $cursor->advance();

if (\mb_strlen($this->label, 'UTF-8') > 999) {
            return false;
        }

if (\trim($this->label) === '') {
            return false;
        }

        $cursor->advanceToNextNonSpaceOrTab();

        $this->state = self::DESTINATION;

        return true;
    }

    private function parseDestination(Cursor $cursor): bool
    {
        $cursor->advanceToNextNonSpaceOrTab();

        $destination = LinkParserHelper::parseLinkDestination($cursor);
        if ($destination === null) {
            return false;
        }

        $this->destination = $destination;

        $advanced = $cursor->advanceToNextNonSpaceOrTab();
        if ($cursor->isAtEnd()) {

$this->referenceValid = true;
            $this->paragraph      = '';
        } elseif ($advanced === 0) {

            return false;
        }

        $this->state = self::START_TITLE;

        return true;
    }

    private function parseStartTitle(Cursor $cursor): bool
    {
        $cursor->advanceToNextNonSpaceOrTab();
        if ($cursor->isAtEnd()) {
            $this->state = self::START_DEFINITION;

            return true;
        }

        $this->titleDelimiter = null;
        switch ($c = $cursor->getCurrentCharacter()) {
            case '"':
            case "'":
                $this->titleDelimiter = $c;
                break;
            case '(':
                $this->titleDelimiter = ')';
                break;
            default:

                break;
        }

        if ($this->titleDelimiter !== null) {
            $this->state = self::TITLE;
            $cursor->advance();
            if ($cursor->isAtEnd()) {
                $this->title .= "\n";
            }
        } else {
            $this->finishReference();

            $this->state = self::START_DEFINITION;
        }

        return true;
    }

    private function parseTitle(Cursor $cursor): bool
    {
        \assert($this->titleDelimiter !== null);
        $title = LinkParserHelper::parsePartialLinkTitle($cursor, $this->titleDelimiter);

        if ($title === null) {

            $this->title = '';

            return false;
        }

$endDelimiterFound = false;
        if (\substr($title, -1) === $this->titleDelimiter) {
            $endDelimiterFound = true;

            $title = \substr($title, 0, -1);
        }

        $this->title .= $title;

        if (! $endDelimiterFound && $cursor->isAtEnd()) {

            $this->title .= "\n";

            return true;
        }

$cursor->advanceToNextNonSpaceOrTab();
        if (! $cursor->isAtEnd()) {

$this->title = '';

            return false;
        }

        $this->referenceValid = true;
        $this->finishReference();
        $this->paragraph = '';

$this->state = self::START_DEFINITION;

        return true;
    }

    private function finishReference(): void
    {
        if (! $this->referenceValid) {
            return;
        }

$this->references[] = new Reference($this->label, $this->destination, $this->title);

        $this->label          = null;
        $this->referenceValid = false;
        $this->destination    = null;
        $this->title          = '';
        $this->titleDelimiter = null;
    }
}
}

// league/commonmark/src/Renderer/Block/DocumentRenderer.php


namespace CMS\Vendor\League\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Node\Block\Document;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class DocumentRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Document::assertInstanceOf($node);

        $wholeDoc = $childRenderer->renderNodes($node->children());

        return $wholeDoc === '' ? '' : $wholeDoc . "\n";
    }

    public function getXmlTagName(Node $node): string
    {
        return 'document';
    }

public function getXmlAttributes(Node $node): array
    {
        return [
            'xmlns' => 'http://commonmark.org/xml/1.0',
        ];
    }
}
}

// league/commonmark/src/Renderer/Block/ParagraphRenderer.php


namespace CMS\Vendor\League\CommonMark\Renderer\Block {

use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Node\Block\TightBlockInterface;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class ParagraphRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        Paragraph::assertInstanceOf($node);

        if ($this->inTightList($node)) {
            return $childRenderer->renderNodes($node->children());
        }

        $attrs = $node->data->get('attributes');

        return new HtmlElement('p', $attrs, $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'paragraph';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }

    private function inTightList(Paragraph $node): bool
    {

        $i = 2;
        while (($node = $node->parent()) && $i--) {
            if ($node instanceof TightBlockInterface) {
                return $node->isTight();
            }
        }

        return false;
    }
}
}

// league/commonmark/src/Renderer/ChildNodeRendererInterface.php


namespace CMS\Vendor\League\CommonMark\Renderer {

use CMS\Vendor\League\CommonMark\Node\Node;

interface ChildNodeRendererInterface
{

    public function renderNodes(iterable $nodes): string;

    public function getBlockSeparator(): string;

    public function getInnerSeparator(): string;
}
}

// league/commonmark/src/Renderer/MarkdownRendererInterface.php


namespace CMS\Vendor\League\CommonMark\Renderer {

use CMS\Vendor\League\CommonMark\Node\Block\Document;
use CMS\Vendor\League\CommonMark\Output\RenderedContentInterface;

interface MarkdownRendererInterface
{

    public function renderDocument(Document $document): RenderedContentInterface;
}
}

// league/commonmark/src/Renderer/DocumentRendererInterface.php


namespace CMS\Vendor\League\CommonMark\Renderer {

use CMS\Vendor\League\CommonMark\Node\Block\Document;
use CMS\Vendor\League\CommonMark\Output\RenderedContentInterface;

interface DocumentRendererInterface extends MarkdownRendererInterface
{

    public function renderDocument(Document $document): RenderedContentInterface;
}
}

// league/commonmark/src/Renderer/HtmlDecorator.php


namespace CMS\Vendor\League\CommonMark\Renderer {

use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;

final class HtmlDecorator implements NodeRendererInterface
{
    private NodeRendererInterface $inner;
    private string $tag;

    private array $attributes;
    private bool $selfClosing;

public function __construct(NodeRendererInterface $inner, string $tag, array $attributes = [], bool $selfClosing = false)
    {
        $this->inner       = $inner;
        $this->tag         = $tag;
        $this->attributes  = $attributes;
        $this->selfClosing = $selfClosing;
    }

public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        return new HtmlElement($this->tag, $this->attributes, $this->inner->render($node, $childRenderer), $this->selfClosing);
    }
}
}

// league/commonmark/src/Renderer/HtmlRenderer.php


namespace CMS\Vendor\League\CommonMark\Renderer {

use CMS\Vendor\League\CommonMark\Environment\EnvironmentInterface;
use CMS\Vendor\League\CommonMark\Event\DocumentPreRenderEvent;
use CMS\Vendor\League\CommonMark\Event\DocumentRenderedEvent;
use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\Block\Document;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Output\RenderedContent;
use CMS\Vendor\League\CommonMark\Output\RenderedContentInterface;

final class HtmlRenderer implements DocumentRendererInterface, ChildNodeRendererInterface
{

    private EnvironmentInterface $environment;

    public function __construct(EnvironmentInterface $environment)
    {
        $this->environment = $environment;
    }

    public function renderDocument(Document $document): RenderedContentInterface
    {
        $this->environment->dispatch(new DocumentPreRenderEvent($document, 'html'));

        $output = new RenderedContent($document, (string) $this->renderNode($document));

        $event = new DocumentRenderedEvent($output);
        $this->environment->dispatch($event);

        return $event->getOutput();
    }

public function renderNodes(iterable $nodes): string
    {
        $output = '';

        $isFirstItem = true;

        foreach ($nodes as $node) {
            if (! $isFirstItem && $node instanceof AbstractBlock) {
                $output .= $this->getBlockSeparator();
            }

            $output .= $this->renderNode($node);

            $isFirstItem = false;
        }

        return $output;
    }

private function renderNode(Node $node)
    {
        $renderers = $this->environment->getRenderersForClass(\get_class($node));

        foreach ($renderers as $renderer) {
            \assert($renderer instanceof NodeRendererInterface);
            if (($result = $renderer->render($node, $this)) !== null) {
                return $result;
            }
        }

        throw new NoMatchingRendererException('Unable to find corresponding renderer for node type ' . \get_class($node));
    }

    public function getBlockSeparator(): string
    {
        return $this->environment->getConfiguration()->get('renderer/block_separator');
    }

    public function getInnerSeparator(): string
    {
        return $this->environment->getConfiguration()->get('renderer/inner_separator');
    }
}
}

// league/commonmark/src/Renderer/Inline/NewlineRenderer.php


namespace CMS\Vendor\League\CommonMark\Renderer\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Newline;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use CMS\Vendor\League\Config\ConfigurationAwareInterface;
use CMS\Vendor\League\Config\ConfigurationInterface;

final class NewlineRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{

    private ConfigurationInterface $config;

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Newline::assertInstanceOf($node);

        if ($node->getType() === Newline::HARDBREAK) {
            return "<br />\n";
        }

        return $this->config->get('renderer/soft_break');
    }

public function getXmlTagName(Node $node): string
    {
        Newline::assertInstanceOf($node);

        return $node->getType() === Newline::SOFTBREAK ? 'softbreak' : 'linebreak';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Renderer/Inline/TextRenderer.php


namespace CMS\Vendor\League\CommonMark\Renderer\Inline {

use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\Xml;
use CMS\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class TextRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Text::assertInstanceOf($node);

        return Xml::escape($node->getLiteral());
    }

    public function getXmlTagName(Node $node): string
    {
        return 'text';
    }

public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
}

// league/commonmark/src/Renderer/NoMatchingRendererException.php


namespace CMS\Vendor\League\CommonMark\Renderer {

use CMS\Vendor\League\CommonMark\Exception\LogicException;

class NoMatchingRendererException extends LogicException
{
}
}

// league/commonmark/src/Util/ArrayCollection.php


namespace CMS\Vendor\League\CommonMark\Util {

final class ArrayCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{

    private array $elements;

public function __construct(array $elements = [])
    {
        $this->elements = $elements;
    }

public function first()
    {
        return \reset($this->elements);
    }

public function last()
    {
        return \end($this->elements);
    }

#[\ReturnTypeWillChange]
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->elements);
    }

public function count(): int
    {
        return \count($this->elements);
    }

public function offsetExists($offset): bool
    {
        return \array_key_exists($offset, $this->elements);
    }

#[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->elements[$offset] ?? null;
    }

#[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->elements[] = $value;
        } else {
            $this->elements[$offset] = $value;
        }
    }

#[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        if (! \array_key_exists($offset, $this->elements)) {
            return;
        }

        unset($this->elements[$offset]);
    }

public function slice(int $offset, ?int $length = null): array
    {
        return \array_slice($this->elements, $offset, $length, true);
    }

public function toArray(): array
    {
        return $this->elements;
    }
}
}

// league/commonmark/src/Util/Html5EntityDecoder.php


namespace CMS\Vendor\League\CommonMark\Util {

final class Html5EntityDecoder
{

    public static function decode(string $entity): string
    {
        if (\substr($entity, -1) !== ';') {
            return $entity;
        }

        if (\substr($entity, 0, 2) === '&#') {
            if (\strtolower(\substr($entity, 2, 1)) === 'x') {
                return self::fromHex(\substr($entity, 3, -1));
            }

            return self::fromDecimal(\substr($entity, 2, -1));
        }

        return \html_entity_decode($entity, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

private static function fromDecimal($number): string
    {

if (empty($number) || $number > 0x2FFFF) {
            return self::fromHex('fffd');
        }

        $entity = '&#' . $number . ';';

        $converted = \mb_decode_numericentity($entity, [0x0, 0x2FFFF, 0, 0xFFFF], 'UTF-8');

        if ($converted === $entity) {
            return self::fromHex('fffd');
        }

        return $converted;
    }

private static function fromHex(string $hexChars): string
    {
        return self::fromDecimal(\hexdec($hexChars));
    }
}
}

// league/commonmark/src/Util/HtmlElement.php


namespace CMS\Vendor\League\CommonMark\Util {

final class HtmlElement implements \Stringable
{

    private string $tagName;

private array $attributes = [];

private $contents;

private bool $selfClosing;

public function __construct(string $tagName, array $attributes = [], $contents = '', bool $selfClosing = false)
    {
        $this->tagName     = $tagName;
        $this->selfClosing = $selfClosing;

        foreach ($attributes as $name => $value) {
            $this->setAttribute($name, $value);
        }

        $this->setContents($contents ?? '');
    }

public function getTagName(): string
    {
        return $this->tagName;
    }

public function getAllAttributes(): array
    {
        return $this->attributes;
    }

public function getAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

public function setAttribute(string $key, $value = true): self
    {
        if (\is_array($value)) {
            $this->attributes[$key] = \implode(' ', \array_unique($value));
        } else {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

public function getContents(bool $asString = true)
    {
        if (! $asString) {
            return $this->contents;
        }

        return $this->getContentsAsString();
    }

public function setContents($contents): self
    {
        $this->contents = $contents ?? '';

        return $this;
    }

public function __toString(): string
    {
        $result = '<' . $this->tagName;

        foreach ($this->attributes as $key => $value) {
            if ($value === true) {
                $result .= ' ' . $key;
            } elseif ($value === false) {
                continue;
            } else {
                $result .= ' ' . $key . '="' . Xml::escape($value) . '"';
            }
        }

        if ($this->contents !== '') {
            $result .= '>' . $this->getContentsAsString() . '</' . $this->tagName . '>';
        } elseif ($this->selfClosing && $this->tagName === 'input') {
            $result .= '>';
        } elseif ($this->selfClosing) {
            $result .= ' />';
        } else {
            $result .= '></' . $this->tagName . '>';
        }

        return $result;
    }

private function getContentsAsString(): string
    {
        if (\is_string($this->contents)) {
            return $this->contents;
        }

        if (\is_array($this->contents)) {
            return \implode('', $this->contents);
        }

        return (string) $this->contents;
    }
}
}

// league/commonmark/src/Util/HtmlFilter.php


namespace CMS\Vendor\League\CommonMark\Util {

use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;

final class HtmlFilter
{

    public const ALLOW = 'allow';

    public const ESCAPE = 'escape';

    public const STRIP = 'strip';

public static function filter(string $html, string $filter): string
    {
        switch ($filter) {
            case self::STRIP:
                return '';
            case self::ESCAPE:
                return \htmlspecialchars($html, \ENT_NOQUOTES);
            case self::ALLOW:
                return $html;
            default:
                throw new InvalidArgumentException(\sprintf('Invalid filter provided: "%s"', $filter));
        }
    }
}
}

// league/commonmark/src/Util/LinkParserHelper.php


namespace CMS\Vendor\League\CommonMark\Util {

use CMS\Vendor\League\CommonMark\Parser\Cursor;

final class LinkParserHelper
{

    public static function parseLinkDestination(Cursor $cursor): ?string
    {
        if ($cursor->getCurrentCharacter() === '<') {
            return self::parseDestinationBraces($cursor);
        }

        $destination = self::manuallyParseLinkDestination($cursor);
        if ($destination === null) {
            return null;
        }

        return UrlEncoder::unescapeAndEncode(
            RegexHelper::unescape($destination)
        );
    }

    public static function parseLinkLabel(Cursor $cursor): int
    {
        $match = $cursor->matchInPlace('/\G\[(?:[^\\\\\[\]]|\\\\.){0,1000}\]/');
        if ($match === null) {
            return 0;
        }

        $length = \mb_strlen($match, 'UTF-8');

        if ($length > 1001) {
            return 0;
        }

        return $length;
    }

    public static function parsePartialLinkLabel(Cursor $cursor): ?string
    {
        return $cursor->matchInPlace('/\G(?:[^\\\\\[\]]++|\\\\.?)*+/');
    }

public static function parseLinkTitle(Cursor $cursor): ?string
    {
        if ($title = $cursor->matchInPlace('/\G' . RegexHelper::PARTIAL_LINK_TITLE_UNANCHORED . '/')) {

            return RegexHelper::unescape(\substr($title, 1, -1));
        }

        return null;
    }

    public static function parsePartialLinkTitle(Cursor $cursor, string $endDelimiter): ?string
    {

        $excluded     = \preg_quote($endDelimiter === ')' ? '()' : $endDelimiter, '/');
        $endDelimiter = \preg_quote($endDelimiter, '/');
        $regex        = \sprintf('/(%s|[^%s\x00])*(?:%s)?/', RegexHelper::PARTIAL_ESCAPED_CHAR, $excluded, $endDelimiter);
        if (($partialTitle = $cursor->matchInPlace($regex)) === null) {
            return null;
        }

        return RegexHelper::unescape($partialTitle);
    }

    private static function manuallyParseLinkDestination(Cursor $cursor): ?string
    {

$line       = $cursor->getLine();
        $start      = $cursor->getBytePosition();
        $openParens = 0;
        $len        = \strlen($line) - $start;
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$start + $i];
            if ($c === '\\' && $i + 1 < $len && RegexHelper::isEscapable($line[$start + $i + 1])) {
                $i++;
            } elseif ($c === '(') {
                $openParens++;

                if ($openParens > 32) {
                    return null;
                }
            } elseif ($c === ')') {
                if ($openParens < 1) {
                    break;
                }

                $openParens--;
            } elseif (\ord($c) <= 32 && RegexHelper::isWhitespace($c)) {
                break;
            }
        }

        if ($openParens !== 0) {
            return null;
        }

        if ($i === 0 && (! isset($c) || $c !== ')')) {
            return null;
        }

        $destination = \substr($line, $start, $i);
        $cursor->advanceBy(\mb_strlen($destination, 'UTF-8'));

        return $destination;
    }

private static ?\WeakReference $lastCursor       = null;
    private static bool $lastCursorLacksClosingBrace = false;

    private static function parseDestinationBraces(Cursor $cursor): ?string
    {

if (self::$lastCursor !== null && self::$lastCursor->get() === $cursor) {
            if (self::$lastCursorLacksClosingBrace) {
                return null;
            }
        } else {
            self::$lastCursor = \WeakReference::create($cursor);
        }

        if ($res = $cursor->matchInPlace('/\G' . RegexHelper::PARTIAL_LINK_DESTINATION_BRACES . '/')) {
            self::$lastCursorLacksClosingBrace = false;

return UrlEncoder::unescapeAndEncode(
                RegexHelper::unescape(\substr($res, 1, -1))
            );
        }

        self::$lastCursorLacksClosingBrace = true;

        return null;
    }
}
}

// league/commonmark/src/Util/PrioritizedList.php


namespace CMS\Vendor\League\CommonMark\Util {

final class PrioritizedList implements \IteratorAggregate
{

    private array $list = [];

private ?\Traversable $optimized = null;

public function add($item, int $priority): void
    {
        $this->list[$priority][] = $item;
        $this->optimized         = null;
    }

#[\ReturnTypeWillChange]
    public function getIterator(): \Traversable
    {
        if ($this->optimized === null) {
            \krsort($this->list);

            $sorted = [];
            foreach ($this->list as $group) {
                foreach ($group as $item) {
                    $sorted[] = $item;
                }
            }

            $this->optimized = new \ArrayIterator($sorted);
        }

        return $this->optimized;
    }
}
}

// league/commonmark/src/Util/RegexHelper.php


namespace CMS\Vendor\League\CommonMark\Util {

use CMS\Vendor\League\CommonMark\Exception\InvalidArgumentException;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;

final class RegexHelper
{

    public const PARTIAL_ENTITY                = '&(?>#x[a-f0-9]{1,6}|#[0-9]{1,7}|[a-z][a-z0-9]{1,31});';
    public const PARTIAL_ESCAPABLE             = '[!"#$%&\'()*+,.\/:;<=>?@[\\\\\]^_`{|}~-]';
    public const PARTIAL_ESCAPED_CHAR          = '\\\\' . self::PARTIAL_ESCAPABLE;
    public const PARTIAL_IN_DOUBLE_QUOTES      = '"(' . self::PARTIAL_ESCAPED_CHAR . '|[^"\x00])*"';
    public const PARTIAL_IN_SINGLE_QUOTES      = '\'(' . self::PARTIAL_ESCAPED_CHAR . '|[^\'\x00])*\'';
    public const PARTIAL_IN_PARENS             = '\\((' . self::PARTIAL_ESCAPED_CHAR . '|[^)\x00])*\\)';
    public const PARTIAL_REG_CHAR              = '[^\\\\()\x00-\x20]';
    public const PARTIAL_IN_PARENS_NOSP        = '\((' . self::PARTIAL_REG_CHAR . '|' . self::PARTIAL_ESCAPED_CHAR . '|\\\\)*\)';
    public const PARTIAL_TAGNAME               = '[A-Za-z][A-Za-z0-9-]*';
    public const PARTIAL_BLOCKTAGNAME          = '(?:address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h1|head|header|hr|html|iframe|legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|search|section|summary|table|tbody|td|tfoot|th|thead|title|tr|track|ul)';
    public const PARTIAL_ATTRIBUTENAME         = '[A-Za-z_:][A-Za-z0-9:._-]*';
    public const PARTIAL_UNQUOTEDVALUE         = '[^"\'=<>`\x00-\x20]+';
    public const PARTIAL_SINGLEQUOTEDVALUE     = '\'[^\']*\'';
    public const PARTIAL_DOUBLEQUOTEDVALUE     = '"[^"]*"';
    public const PARTIAL_ATTRIBUTEVALUE        = '(?:' . self::PARTIAL_UNQUOTEDVALUE . '|' . self::PARTIAL_SINGLEQUOTEDVALUE . '|' . self::PARTIAL_DOUBLEQUOTEDVALUE . ')';
    public const PARTIAL_ATTRIBUTEVALUESPEC    = '(?:' . '\s*=' . '\s*' . self::PARTIAL_ATTRIBUTEVALUE . ')';
    public const PARTIAL_ATTRIBUTE             = '(?:' . '\s+' . self::PARTIAL_ATTRIBUTENAME . self::PARTIAL_ATTRIBUTEVALUESPEC . '?)';
    public const PARTIAL_OPENTAG               = '<' . self::PARTIAL_TAGNAME . self::PARTIAL_ATTRIBUTE . '*+' . '\s*+\/?+>';
    public const PARTIAL_CLOSETAG              = '<\/' . self::PARTIAL_TAGNAME . '\s*+[>]';
    public const PARTIAL_OPENBLOCKTAG          = '<' . self::PARTIAL_BLOCKTAGNAME . self::PARTIAL_ATTRIBUTE . '*+' . '\s*+\/?+>';
    public const PARTIAL_CLOSEBLOCKTAG         = '<\/' . self::PARTIAL_BLOCKTAGNAME . '\s*+[>]';
    public const PARTIAL_HTMLCOMMENT           = '(?:<!-->|<!--->|<!--[\s\S]*?-->)';
    public const PARTIAL_PROCESSINGINSTRUCTION = '[<][?][\s\S]*?[?][>]';
    public const PARTIAL_DECLARATION           = '<![A-Za-z]+' . '[^>]*>';
    public const PARTIAL_CDATA                 = '<!\[CDATA\[[\s\S]*?]\]>';
    public const PARTIAL_HTMLTAG               = '(?:' . self::PARTIAL_OPENTAG . '|' . self::PARTIAL_CLOSETAG . '|' . self::PARTIAL_HTMLCOMMENT . '|' .
        self::PARTIAL_PROCESSINGINSTRUCTION . '|' . self::PARTIAL_DECLARATION . '|' . self::PARTIAL_CDATA . ')';
    public const PARTIAL_HTMLBLOCKOPEN         = '<(?:' . self::PARTIAL_BLOCKTAGNAME . '(?:[\s\/>]|$)' . '|' .
        '\/' . self::PARTIAL_BLOCKTAGNAME . '(?:[\s>]|$)' . '|' . '[?!])';

    public const PARTIAL_LINK_TITLE_UNANCHORED = '(?:"(' . self::PARTIAL_ESCAPED_CHAR . '|[^"\x00])*+"' .
        '|' . '\'(' . self::PARTIAL_ESCAPED_CHAR . '|[^\'\x00])*+\'' .
        '|' . '\((' . self::PARTIAL_ESCAPED_CHAR . '|[^()\x00])*+\))';

    public const PARTIAL_LINK_TITLE = '^' . self::PARTIAL_LINK_TITLE_UNANCHORED;

    public const REGEX_PUNCTUATION        = '/^[\p{P}\p{S}]/u';
    public const REGEX_UNSAFE_PROTOCOL    = '/^(?:javascript|vbscript|file|data):/i';
    public const REGEX_SAFE_DATA_PROTOCOL = '/^data:image\/(?:png|gif|jpeg|webp)/i';
    public const REGEX_NON_SPACE          = '/[^ \t\f\v\r\n]/';

    public const REGEX_WHITESPACE_CHAR         = '/^[ \t\n\x0b\x0c\x0d]/';
    public const REGEX_UNICODE_WHITESPACE_CHAR = '/^\pZ|\s/u';
    public const REGEX_THEMATIC_BREAK          = '/^(?:(?:\*[ \t]*){3,}|(?:_[ \t]*){3,}|(?:-[ \t]*){3,})$/';

    public const PARTIAL_LINK_DESTINATION_BRACES = '(?:<(?:[^<>\\n\\\\\\x00]|\\\\.)*>)';

    public const REGEX_LINK_DESTINATION_BRACES = '/^' . self::PARTIAL_LINK_DESTINATION_BRACES . '/';

public static function isEscapable(string $character): bool
    {
        return \preg_match('/' . self::PARTIAL_ESCAPABLE . '/', $character) === 1;
    }

    public static function isWhitespace(string $character): bool
    {

        return $character !== '' && \strpos(" \t\n\x0b\x0c\x0d", $character) !== false;
    }

public static function isLetter(?string $character): bool
    {
        if ($character === null) {
            return false;
        }

        return \preg_match('/[\pL]/u', $character) === 1;
    }

public static function matchAt(string $regex, string $string, int $offset = 0): ?int
    {
        $matches = [];
        $string  = \mb_substr($string, $offset, null, 'UTF-8');
        if (! \preg_match($regex, $string, $matches, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

$charPos = \mb_strlen(\mb_strcut($string, 0, $matches[0][1], 'UTF-8'), 'UTF-8');

        return $offset + $charPos;
    }

public static function matchFirst(string $pattern, string $subject, int $offset = 0): ?array
    {
        if ($offset !== 0) {
            $subject = \substr($subject, $offset);
        }

        \preg_match_all($pattern, $subject, $matches, \PREG_SET_ORDER);

        if ($matches === []) {
            return null;
        }

        return $matches[0] ?: null;
    }

public static function unescape(string $string): string
    {
        $allEscapedChar = '/\\\\(' . self::PARTIAL_ESCAPABLE . ')/';

        $escaped = \preg_replace($allEscapedChar, '$1', $string);
        \assert(\is_string($escaped));

        return \preg_replace_callback('/' . self::PARTIAL_ENTITY . '/i', static fn ($e) => Html5EntityDecoder::decode($e[0]), $escaped);
    }

public static function getHtmlBlockOpenRegex(int $type): string
    {
        switch ($type) {
            case HtmlBlock::TYPE_1_CODE_CONTAINER:
                return '/^<(?:script|pre|textarea|style)(?:\s|>|$)/i';
            case HtmlBlock::TYPE_2_COMMENT:
                return '/^<!--/';
            case HtmlBlock::TYPE_3:
                return '/^<[?]/';
            case HtmlBlock::TYPE_4:
                return '/^<![A-Z]/i';
            case HtmlBlock::TYPE_5_CDATA:
                return '/^<!\[CDATA\[/i';
            case HtmlBlock::TYPE_6_BLOCK_ELEMENT:
                return '%^</?+(?:address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h[123456]|head|header|hr|html|iframe|legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|section|source|summary|table|tbody|td|tfoot|th|thead|title|tr|track|ul)(?:\s++|[/]?+[>]|$)%i';
            case HtmlBlock::TYPE_7_MISC_ELEMENT:
                return '/^(?:' . self::PARTIAL_OPENTAG . '|' . self::PARTIAL_CLOSETAG . ')\\s*$/i';
            default:
                throw new InvalidArgumentException('Invalid HTML block type');
        }
    }

public static function getHtmlBlockCloseRegex(int $type): string
    {
        switch ($type) {
            case HtmlBlock::TYPE_1_CODE_CONTAINER:
                return '%<\/(?:script|pre|textarea|style)>%i';
            case HtmlBlock::TYPE_2_COMMENT:
                return '/-->/';
            case HtmlBlock::TYPE_3:
                return '/\?>/';
            case HtmlBlock::TYPE_4:
                return '/>/';
            case HtmlBlock::TYPE_5_CDATA:
                return '/\]\]>/';
            default:
                throw new InvalidArgumentException('Invalid HTML block type');
        }
    }

public static function isLinkPotentiallyUnsafe(string $url): bool
    {

$url = \ltrim(\str_replace(["\t", "\n", "\r"], '', $url), "\x00..\x20");

        return \preg_match(self::REGEX_UNSAFE_PROTOCOL, $url) !== 0 && \preg_match(self::REGEX_SAFE_DATA_PROTOCOL, $url) === 0;
    }
}
}

// league/commonmark/src/Util/UrlEncoder.php


namespace CMS\Vendor\League\CommonMark\Util {

use CMS\Vendor\League\CommonMark\Exception\UnexpectedEncodingException;

final class UrlEncoder
{
    private const ENCODE_CACHE = ['%00', '%01', '%02', '%03', '%04', '%05', '%06', '%07', '%08', '%09', '%0A', '%0B', '%0C', '%0D', '%0E', '%0F', '%10', '%11', '%12', '%13', '%14', '%15', '%16', '%17', '%18', '%19', '%1A', '%1B', '%1C', '%1D', '%1E', '%1F', '%20', '!', '%22', '#', '$', '%25', '&', "'", '(', ')', '*', '+', ',', '-', '.', '/', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ':', ';', '%3C', '=', '%3E', '?', '@', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', '%5B', '%5C', '%5D', '%5E', '_', '%60', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', '%7B', '%7C', '%7D', '~', '%7F'];

public static function unescapeAndEncode(string $uri): string
    {

        if (\preg_match('/^[A-Za-z0-9~!@#$&*()\-_=+;:,.\/?]+$/', $uri)) {
            return $uri;
        }

        if (! \mb_check_encoding($uri, 'UTF-8')) {
            throw new UnexpectedEncodingException('Unexpected encoding - UTF-8 or ASCII was expected');
        }

        $result = '';

        $chars = \mb_str_split($uri, 1, 'UTF-8');

        $l = \count($chars);
        for ($i = 0; $i < $l; $i++) {
            $code = $chars[$i];
            if ($code === '%' && $i + 2 < $l) {
                if (\preg_match('/^[0-9a-f]{2}$/i', $chars[$i + 1] . $chars[$i + 2]) === 1) {
                    $result .= '%' . $chars[$i + 1] . $chars[$i + 2];
                    $i      += 2;
                    continue;
                }
            }

            if (\strlen($code) === 1 && \ord($code) < 128) {
                $result .= self::ENCODE_CACHE[\ord($code)];
                continue;
            }

            $result .= \rawurlencode($code);
        }

        return $result;
    }
}
}

// league/commonmark/src/Util/Xml.php


namespace CMS\Vendor\League\CommonMark\Util {

final class Xml
{

    public static function escape(string $string): string
    {
        return \str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $string);
    }
}
}

// league/config/src/MutableConfigurationInterface.php


namespace CMS\Vendor\League\Config {

use CMS\Vendor\League\Config\Exception\UnknownOptionException;

interface MutableConfigurationInterface
{

    public function set(string $key, $value): void;

public function merge(array $config = []): void;
}
}

// league/config/src/SchemaBuilderInterface.php


namespace CMS\Vendor\League\Config {

use CMS\Vendor\Nette\Schema\Schema;

interface SchemaBuilderInterface
{

    public function addSchema(string $key, Schema $schema): void;
}
}

// league/config/src/ConfigurationBuilderInterface.php


namespace CMS\Vendor\League\Config {

interface ConfigurationBuilderInterface extends MutableConfigurationInterface, SchemaBuilderInterface
{
}
}

// league/config/src/ConfigurationInterface.php


namespace CMS\Vendor\League\Config {

use CMS\Vendor\League\Config\Exception\UnknownOptionException;
use CMS\Vendor\League\Config\Exception\ValidationException;

interface ConfigurationInterface
{

    public function get(string $key);

public function exists(string $key): bool;
}
}

// league/config/src/Configuration.php


namespace CMS\Vendor\League\Config {

use CMS\Vendor\Dflydev\DotAccessData\Data;
use CMS\Vendor\Dflydev\DotAccessData\DataInterface;
use CMS\Vendor\Dflydev\DotAccessData\Exception\DataException;
use CMS\Vendor\Dflydev\DotAccessData\Exception\InvalidPathException;
use CMS\Vendor\Dflydev\DotAccessData\Exception\MissingPathException;
use CMS\Vendor\League\Config\Exception\UnknownOptionException;
use CMS\Vendor\League\Config\Exception\ValidationException;
use CMS\Vendor\Nette\Schema\Expect;
use CMS\Vendor\Nette\Schema\Processor;
use CMS\Vendor\Nette\Schema\Schema;
use CMS\Vendor\Nette\Schema\ValidationException as NetteValidationException;

final class Configuration implements ConfigurationBuilderInterface, ConfigurationInterface
{

    private Data $userConfig;

private array $configSchemas = [];

private Data $finalConfig;

private array $cache = [];

private ConfigurationInterface $reader;

public function __construct(array $baseSchemas = [])
    {
        $this->configSchemas = $baseSchemas;
        $this->userConfig    = new Data();
        $this->finalConfig   = new Data();

        $this->reader = new ReadOnlyConfiguration($this);
    }

public function addSchema(string $key, Schema $schema): void
    {
        $this->invalidate();

        $this->configSchemas[$key] = $schema;
    }

public function merge(array $config = []): void
    {
        $this->invalidate();

        $this->userConfig->import($config, DataInterface::REPLACE);
    }

public function set(string $key, $value): void
    {
        $this->invalidate();

        try {
            $this->userConfig->set($key, $value);
        } catch (DataException $ex) {
            throw new UnknownOptionException($ex->getMessage(), $key, (int) $ex->getCode(), $ex);
        }
    }

public function get(string $key)
    {
        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $this->build(self::getTopLevelKey($key));

            return $this->cache[$key] = $this->finalConfig->get($key);
        } catch (InvalidPathException | MissingPathException $ex) {
            throw new UnknownOptionException($ex->getMessage(), $key, (int) $ex->getCode(), $ex);
        }
    }

public function exists(string $key): bool
    {
        if (\array_key_exists($key, $this->cache)) {
            return true;
        }

        try {
            $this->build(self::getTopLevelKey($key));

            return $this->finalConfig->has($key);
        } catch (InvalidPathException | UnknownOptionException $ex) {
            return false;
        }
    }

public function reader(): ConfigurationInterface
    {
        return $this->reader;
    }

private function invalidate(): void
    {
        $this->cache       = [];
        $this->finalConfig = new Data();
    }

private function build(string $topLevelKey): void
    {
        if ($this->finalConfig->has($topLevelKey)) {
            return;
        }

        if (! isset($this->configSchemas[$topLevelKey])) {
            throw new UnknownOptionException(\sprintf('Missing config schema for "%s"', $topLevelKey), $topLevelKey);
        }

        try {
            $userData = [$topLevelKey => $this->userConfig->get($topLevelKey)];
        } catch (DataException $ex) {
            $userData = [];
        }

        try {
            $schema    = $this->configSchemas[$topLevelKey];
            $processor = new Processor();

            $processed = $processor->process(Expect::structure([$topLevelKey => $schema]), $userData);

            $this->raiseAnyDeprecationNotices($processor->getWarnings());

            $this->finalConfig->import((array) self::convertStdClassesToArrays($processed));
        } catch (NetteValidationException $ex) {
            throw new ValidationException($ex);
        }
    }

private static function convertStdClassesToArrays($data)
    {
        if ($data instanceof \stdClass) {
            $data = (array) $data;
        }

        if (\is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::convertStdClassesToArrays($v);
            }
        }

        return $data;
    }

private function raiseAnyDeprecationNotices(array $warnings): void
    {
        foreach ($warnings as $warning) {
            @\trigger_error($warning, \E_USER_DEPRECATED);
        }
    }

private static function getTopLevelKey(string $path): string
    {
        if (\strlen($path) === 0) {
            throw new InvalidPathException('Path cannot be an empty string');
        }

        $path = \str_replace(['.', '/'], '.', $path);

        $firstDelimiter = \strpos($path, '.');
        if ($firstDelimiter === false) {
            return $path;
        }

        return \substr($path, 0, $firstDelimiter);
    }
}
}

// league/config/src/Exception/ConfigurationExceptionInterface.php


namespace CMS\Vendor\League\Config\Exception {

interface ConfigurationExceptionInterface extends \Throwable
{
}
}

// league/config/src/Exception/InvalidConfigurationException.php


namespace CMS\Vendor\League\Config\Exception {

class InvalidConfigurationException extends \UnexpectedValueException implements ConfigurationExceptionInterface
{

    public static function forConfigOption(string $option, $valueGiven, ?string $description = null): self
    {
        $message = \sprintf('Invalid config option for "%s": %s', $option, self::getDebugValue($valueGiven));
        if ($description !== null) {
            $message .= \sprintf(' (%s)', $description);
        }

        return new self($message);
    }

private static function getDebugValue($value): string
    {
        if (\is_object($value)) {
            return \get_class($value);
        }

        return \print_r($value, true);
    }
}
}

// league/config/src/Exception/UnknownOptionException.php


namespace CMS\Vendor\League\Config\Exception {

use Throwable;

final class UnknownOptionException extends \InvalidArgumentException implements ConfigurationExceptionInterface
{
    private string $path;

    public function __construct(string $message, string $path, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->path = $path;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
}

// league/config/src/Exception/ValidationException.php


namespace CMS\Vendor\League\Config\Exception {

use CMS\Vendor\Nette\Schema\ValidationException as NetteException;

final class ValidationException extends InvalidConfigurationException
{

    private array $messages;

    public function __construct(NetteException $innerException)
    {
        parent::__construct($innerException->getMessage(), (int) $innerException->getCode(), $innerException);

        $this->messages = $innerException->getMessages();
    }

public function getMessages(): array
    {
        return $this->messages;
    }
}
}

// league/config/src/ReadOnlyConfiguration.php


namespace CMS\Vendor\League\Config {

final class ReadOnlyConfiguration implements ConfigurationInterface
{
    private Configuration $config;

    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

public function get(string $key)
    {
        return $this->config->get($key);
    }

    public function exists(string $key): bool
    {
        return $this->config->exists($key);
    }
}
}

// nette/schema/src/Schema/Context.php


namespace CMS\Vendor\Nette\Schema {

use function count;

final class Context
{
	public bool $skipDefaults = false;

public ?array $keepNullAt = null;

public array $path = [];

	public bool $isKey = false;

public array $errors = [];

public array $warnings = [];

public array $dynamics = [];

public function addError(string $message, string $code, array $variables = []): Message
	{
		$variables['isKey'] = $this->isKey;
		return $this->errors[] = new Message($message, $code, $this->path, $variables);
	}

public function addWarning(string $message, string $code, array $variables = []): Message
	{
		$variables['isKey'] = $this->isKey;
		return $this->warnings[] = new Message($message, $code, $this->path, $variables);
	}

public function createChecker(): \Closure
	{
		$count = count($this->errors);
		return fn(): bool => $count === count($this->errors);
	}
}
}

// nette/schema/src/Schema/DynamicParameter.php


namespace CMS\Vendor\Nette\Schema {

interface DynamicParameter
{
}
}

// nette/schema/src/Schema/Elements/Base.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\Schema\{Context, Helpers};
use function count, is_string;

trait Base
{
	private bool $required = false;
	private mixed $default = null;

private ?\Closure $before = null;

private array $transforms = [];
	private ?string $deprecated = null;
	private ?string $description = null;

private ?\Closure $mergeWith = null;

public function default(mixed $value): static
	{
		$this->default = $value;
		return $this;
	}

public function required(bool $state = true): static
	{
		$this->required = $state;
		return $this;
	}

public function before(callable $handler): static
	{
		if ($this->before) {
			trigger_error(
				'Calling before() repeatedly is deprecated, it replaces the previous handler while in 2.0 the handlers will be chained; merge them into one.',
				E_USER_DEPRECATED,
			);
		}

		$this->before = $handler(...);
		return $this;
	}

public function mergeWith(callable $fn): static
	{
		$this->mergeWith = $fn(...);
		return $this;
	}

public function castTo(string $type): static
	{
		return $this->transform(Helpers::getCastStrategy($type));
	}

public function transform(callable $handler): static
	{
		$this->transforms[] = $handler(...);
		return $this;
	}

public function assert(callable $handler, ?string $description = null): static
	{
		$expected = $description ?? (is_string($handler) ? "$handler()" : '#' . count($this->transforms));
		return $this->transform(function ($value, Context $context) use ($handler, $description, $expected) {
			if ($handler($value)) {
				return $value;
			}
			$context->addError(
				'Failed assertion ' . ($description ? "'%assertion%'" : '%assertion%') . ' for %label% %path% with value %value%.',
				Nette\Schema\Message::FailedAssertion,
				['value' => $value, 'assertion' => $expected],
			);
			return null;
		});
	}

public function deprecated(string $message = 'The item %path% is deprecated.'): static
	{
		$this->deprecated = $message;
		return $this;
	}

public function description(string $description): static
	{
		$this->description = $description;
		return $this;
	}

protected function describeBase(): array
	{
		return ['required' => $this->required, 'description' => $this->description];
	}

public function completeDefault(Context $context): mixed
	{
		if ($this->required) {
			$context->addError(
				'The mandatory item %path% is missing.',
				Nette\Schema\Message::MissingItem,
			);
			return null;
		}

		return $this->default;
	}

public function doNormalize(mixed $value, Context $context): mixed
	{
		if ($this->before) {
			$value = ($this->before)($value);
		}

		return $value;
	}

private function doDeprecation(Context $context): void
	{
		if ($this->deprecated !== null) {
			$context->addWarning(
				$this->deprecated,
				Nette\Schema\Message::Deprecated,
			);
		}
	}

private function doTransform(mixed $value, Context $context): mixed
	{
		$isOk = $context->createChecker();
		foreach ($this->transforms as $handler) {
			$value = $handler($value, $context);
			if (!$isOk()) {
				return null;
			}
		}
		return $value;
	}

private function doValidate(mixed $value, string $expected, Context $context): bool
	{
		$isOk = $context->createChecker();
		Helpers::validateType($value, $expected, $context);
		return $isOk();
	}

private static function doValidateRange(mixed $value, array $range, Context $context, string $types = ''): bool
	{
		$isOk = $context->createChecker();
		Helpers::validateRange($value, $range, $context, $types);
		return $isOk();
	}

private function doFinalize(mixed $value, Context $context): mixed
	{
		return $this->doTransform($value, $context);
	}
}
}

// nette/schema/src/Schema/Schema.php


namespace CMS\Vendor\Nette\Schema {

interface Schema
{

	function normalize(mixed $value, Context $context);

function merge(mixed $value, mixed $base);

function complete(mixed $value, Context $context);

function completeDefault(Context $context);
}
}

// nette/schema/src/Schema/Elements/AnyOf.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\Schema\{Context, Helpers, Kind, Schema};
use function is_array;

final class AnyOf implements Schema
{
	use Base;

private array $set;
	private bool $dynamic = false;

public function __construct(mixed ...$set)
	{
		if (!$set) {
			throw new Nette\InvalidStateException('The enumeration must not be empty.');
		}

		$this->set = $set;
	}

public function firstIsDefault(): self
	{
		$this->default = $this->set[0];
		return $this;
	}

public function nullable(): self
	{
		$this->set[] = null;
		return $this;
	}

public function dynamic(): self
	{
		$this->dynamic = true;
		return $this;
	}

public function describe(): array
	{
		$values = $variants = [];
		$nullable = false;
		foreach ($this->set as $item) {
			if ($item === null) {
				$nullable = true;
			} elseif (!$item instanceof Schema) {
				$values[] = $item;
			} else {
				$variants[] = $item;
			}
		}

		return [
			'kind' => match (true) {
				(bool) $variants => Kind::Union,
				(bool) $values => Kind::Enum,
				default => $nullable ? Kind::Null : Kind::Any,
			},
			'values' => $values,
			'variants' => $variants,
			'nullable' => $nullable,
			'dynamic' => $this->dynamic,
		] + $this->describeBase();
	}

public function normalize(mixed $value, Context $context): mixed
	{
		return $this->doNormalize($value, $context);
	}

public function merge(mixed $value, mixed $base): mixed
	{
		if (is_array($value) && isset($value[Helpers::PreventMerging])) {
			unset($value[Helpers::PreventMerging]);
			return $value;
		}

		if ($this->mergeWith) {
			return ($this->mergeWith)($value, $base);
		}

		return Helpers::merge($value, $base);
	}

public function complete(mixed $value, Context $context): mixed
	{
		$this->doDeprecation($context);
		$isOk = $context->createChecker();
		$value = $this->findAlternative($value, $context);
		$isOk() && $value = $this->doTransform($value, $context);
		return $isOk() ? $value : null;
	}

private function findAlternative(mixed $value, Context $context): mixed
	{
		$keepNullAt = $value === null && in_array(null, $this->set, true)
			? $context->path
			: $context->keepNullAt;

		$expecteds = $innerErrors = [];
		foreach ($this->set as $item) {
			if ($item instanceof Schema) {
				$dolly = new Context;
				$dolly->skipDefaults = $context->skipDefaults;
				$dolly->isKey = $context->isKey;
				$dolly->path = $context->path;
				$dolly->keepNullAt = $keepNullAt;
				$res = $item->complete($item->normalize($value, $dolly), $dolly);
				if (!$dolly->errors) {
					$context->warnings = [...$context->warnings, ...$dolly->warnings];
					$context->dynamics = [...$context->dynamics, ...$dolly->dynamics];
					return $res;
				}

				foreach ($dolly->errors as $error) {
					if ($error->path !== $context->path || empty($error->variables['expected'])) {
						$innerErrors[] = $error;
					} else {
						$expecteds[] = $error->variables['expected'];
					}
				}
			} else {
				if ($item === $value) {
					return $value;
				}

				$expecteds[] = Nette\Schema\Helpers::formatValue($item);
			}
		}

		if ($this->dynamic && $value instanceof Nette\Schema\DynamicParameter) {
			return $value;
		}

		if ($innerErrors) {
			$context->errors = [...$context->errors, ...$innerErrors];
		} else {
			$context->addError(
				'The %label% %path% expects to be %expected%, %value% given.',
				Nette\Schema\Message::TypeMismatch,
				[
					'value' => $value,
					'expected' => implode('|', array_unique($expecteds)),
				],
			);
		}

		return null;
	}

public function completeDefault(Context $context): mixed
	{
		if ($this->required) {
			$context->addError(
				'The mandatory item %path% is missing.',
				Nette\Schema\Message::MissingItem,
			);
			return null;
		}

		if ($this->default instanceof Schema) {
			return $this->default->completeDefault($context);
		}

		return $this->default;
	}
}
}

// nette/schema/src/Schema/Elements/Type.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\Schema\{Context, DynamicParameter, Helpers, Kind, MergeMode, Message, Schema, TypeExpression};
use CMS\Vendor\Nette\Utils\Validators;
use function array_key_exists, is_array, is_int, is_string;
use const INF;

class Type implements Schema
{
	use Base;

	private ?Schema $itemsValue = null;
	private ?Schema $itemsKey = null;

private array $range = [null, null];
	private ?string $pattern = null;
	private bool $merge = true;
	private ?MergeMode $mergeMode = null;

public function __construct(
		private string $type,
	) {
		$defaults = ['list' => [], 'array' => []];
		$this->default = strpos($type, '[]') ? [] : $defaults[$type] ?? null;
	}

public function nullable(): static
	{
		$this->type = 'null|' . $this->type;
		return $this;
	}

public function mergeDefaults(bool $state = true): static
	{
		$this->unionDeprecated('mergeDefaults');
		$this->merge = $state;
		return $this;
	}

protected function mergeMode(MergeMode $mode): static
	{
		$this->mergeMode = $mode;
		return $this;
	}

public function dynamic(): static
	{
		$this->type = DynamicParameter::class . '|' . $this->type;
		return $this;
	}

public function min(int|float|null $min): static
	{
		$this->unionDeprecated('min');
		$this->range[0] = $min;
		return $this;
	}

public function max(int|float|null $max): static
	{
		$this->unionDeprecated('max');
		$this->range[1] = $max;
		return $this;
	}

public function items(string|Schema $valueType = 'mixed', string|Schema|null $keyType = null): static
	{
		$this->unionDeprecated('items');
		$this->itemsValue = $valueType instanceof Schema
			? $valueType
			: Nette\Schema\Expect::type($valueType);
		$this->itemsKey = $keyType instanceof Schema || $keyType === null
			? $keyType
			: Nette\Schema\Expect::type($keyType);
		return $this;
	}

public function pattern(?string $pattern): static
	{
		$this->unionDeprecated('pattern');
		$this->pattern = $pattern;
		return $this;
	}

private function unionDeprecated(string $option): void
	{
		if (static::class === self::class && $this->getParsed()['kind'] === Kind::Union) {
			trigger_error(
				"$option() on the union '$this->type' is deprecated, give it to the variants of anyOf() instead.",
				E_USER_DEPRECATED,
			);
		}
	}

public function describe(): array
	{
		return $this->narrow($this->getParsed()) + $this->describeBase();
	}

protected function getParsed(): array
	{
		return TypeExpression::parse($this->type);
	}

private function narrow(array $item): array
	{
		if ($item['kind'] === Kind::Union) {
			$item['variants'] = array_map($this->narrow(...), $item['variants']);
		}
		if (array_key_exists('min', $item)) {
			$item['min'] = $this->range[0] === null ? $item['min'] : max($this->range[0], $item['min'] ?? -INF);
			$item['max'] = $this->range[1] === null ? $item['max'] : min($this->range[1], $item['max'] ?? INF);
		}
		if (array_key_exists('pattern', $item)) {
			$item['pattern'] = $this->pattern ?? $item['pattern'];
		}
		if (array_key_exists('items', $item)) {
			$item['items'] = $this->itemsValue ?? $item['items'];
			$item['keys'] = $this->itemsKey ?? $item['keys'];
		}
		return $item;
	}

public function normalize(mixed $value, Context $context): mixed
	{
		if ($prevent = (is_array($value) && isset($value[Helpers::PreventMerging]))) {
			unset($value[Helpers::PreventMerging]);
		}

		$value = $this->doNormalize($value, $context);
		if (is_array($value)) {
			$value = $this->normalizeItems($value, $context);
		}

		if ($prevent && is_array($value)) {
			$value[Helpers::PreventMerging] = true;
		}

		return $value;
	}

protected function normalizeItems(array $value, Context $context): array
	{
		if (!$this->itemsValue) {
			return $value;
		}

		$res = [];
		foreach ($value as $key => $val) {
			$context->path[] = $key;
			$context->isKey = true;
			$key = $this->itemsKey
				? $this->itemsKey->normalize($key, $context)
				: $key;
			if (!is_int($key) && !is_string($key)) {
				$context->addError(
					'The %label% %path% expects to be int|string, %value% given.',
					Message::TypeMismatch,
					['value' => $key, 'expected' => 'int|string'],
				);
				$context->isKey = false;
				array_pop($context->path);
				continue;
			}

			$context->isKey = false;
			$res[$key] = $this->itemsValue->normalize($val, $context);
			array_pop($context->path);
		}

		return $res;
	}

public function merge(mixed $value, mixed $base): mixed
	{
		if (is_array($value) && isset($value[Helpers::PreventMerging])) {
			unset($value[Helpers::PreventMerging]);
			return $value;
		}

		if ($this->mergeWith) {
			return ($this->mergeWith)($value, $base);
		}

		return $this->mergeValues($value, $base);
	}

protected function mergeValues(mixed $value, mixed $base): mixed
	{
		if ($this->mergeMode === MergeMode::Replace) {
			return $value;
		}

		if (is_array($value) && is_array($base) && ($this->itemsValue || $this->mergeMode)) {
			$index = $this->mergeMode === MergeMode::OverwriteKeys ? null : 0;
			foreach ($value as $key => $val) {
				if ($key === $index) {
					$base[] = $val;
					$index++;
				} else {
					$base[$key] = array_key_exists($key, $base) && $this->itemsValue
						? $this->itemsValue->merge($val, $base[$key])
						: $val;
				}
			}

			return $base;
		}

		return Helpers::merge($value, $base);
	}

public function complete(mixed $value, Context $context): mixed
	{
		$merge = $this->merge;
		if (is_array($value) && isset($value[Helpers::PreventMerging])) {
			unset($value[Helpers::PreventMerging]);
			$merge = false;
		}

		if ($value === null && is_array($this->default) && !Validators::is(null, $this->type) && $context->keepNullAt !== $context->path) {
			$value = [];
		}

		$this->doDeprecation($context);

		$isOk = $context->createChecker();
		Helpers::validateType($value, $this->type, $context);
		$isOk() && Helpers::validateRange($value, $this->range, $context, $this->type);
		$isOk() && $value !== null && $this->pattern !== null && Helpers::validatePattern($value, $this->pattern, $context);
		$isOk() && is_array($value) && $this->validateItems($value, $context);
		$isOk() && $merge && $value !== null && $value = Helpers::merge($value, $this->default);
		$isOk() && $value = $this->doTransform($value, $context);
		if (!$isOk()) {
			return null;
		}

		if ($value instanceof DynamicParameter && $this->type !== DynamicParameter::class) {
			$expected = $this->type . ($this->range === [null, null] ? '' : ':' . implode('..', $this->range));
			$context->dynamics[] = [$value, str_replace(DynamicParameter::class . '|', '', $expected), $context->path];
		}
		return $value;
	}

protected function validateItems(array &$value, Context $context): void
	{
		if (!($itemsValue = $this->itemsValue)) {
			return;
		}

		$res = [];
		foreach ($value as $key => $val) {
			$context->path[] = $key;
			$context->isKey = true;
			$isKeyOk = $context->createChecker();
			$key = $this->itemsKey ? $this->itemsKey->complete($key, $context) : $key;
			if ($isKeyOk() && !is_int($key) && !is_string($key)) {
				$context->addError(
					'The %label% %path% expects to be int|string, %value% given.',
					Message::TypeMismatch,
					['value' => $key, 'expected' => 'int|string'],
				);
			}

			$context->isKey = false;
			$keyOk = $isKeyOk();
			$val = $itemsValue->complete($val, $context);
			if ($keyOk) {
				$res[$key] = $val;
			}

			array_pop($context->path);
		}
		$value = $res;
	}
}
}

// nette/schema/src/Schema/Elements/ArrayType.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette\Schema\{Context, MergeMode, Schema};
use function is_array;

final class ArrayType extends Type
{
	public function __construct(
		string $type,

		private readonly bool $wrap = false,
	) {
		parent::__construct($type);
	}

public function doNormalize(mixed $value, Context $context): mixed
	{
		$value = parent::doNormalize($value, $context);
		return $this->wrap && !is_array($value) && $value !== null ? [$value] : $value;
	}

public function items(string|Schema $valueType = 'mixed', string|Schema|null $keyType = null): static
	{
		return parent::items($valueType, $keyType);
	}

public function mergeMode(MergeMode $mode): static
	{
		return parent::mergeMode($mode);
	}

public function pattern(?string $pattern): static
	{
		trigger_error(__METHOD__ . '() is deprecated, an array has no pattern.', E_USER_DEPRECATED);
		return parent::pattern($pattern);
	}

public function describe(): array
	{
		return parent::describe() + ['wrap' => $this->wrap];
	}
}
}

// nette/schema/src/Schema/Elements/EnumType.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\Schema\{Context, Kind};
use function is_int, is_string;

final class EnumType extends Type
{
	public function __construct(

		private string $enum,
	) {
		if (!is_subclass_of($enum, \BackedEnum::class)) {
			throw new Nette\InvalidArgumentException("'$enum' is not a backed enum.");
		}
		parent::__construct($enum);
	}

public function doNormalize(mixed $value, Context $context): mixed
	{
		$value = parent::doNormalize($value, $context);
		try {
			return is_int($value) || is_string($value) ? $this->enum::tryFrom($value) ?? $value : $value;
		} catch (\TypeError) {
			return $value;
		}
	}

public function describe(): array
	{
		return [
			'kind' => Kind::Enum,
			'values' => array_map(fn(\BackedEnum $case) => $case->value, ($this->enum)::cases()),
		] + parent::describe();
	}
}
}

// nette/schema/src/Schema/Elements/NumberType.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette\Schema\Schema;

final class NumberType extends Type
{

	public function pattern(?string $pattern): static
	{
		trigger_error(__METHOD__ . '() is deprecated, a number has no pattern.', E_USER_DEPRECATED);
		return parent::pattern($pattern);
	}

public function items(string|Schema $valueType = 'mixed', string|Schema|null $keyType = null): static
	{
		trigger_error(__METHOD__ . '() is deprecated, a number has no items.', E_USER_DEPRECATED);
		return parent::items($valueType, $keyType);
	}

public function mergeDefaults(bool $state = true): static
	{
		trigger_error(__METHOD__ . '() is deprecated, a number has no default merging.', E_USER_DEPRECATED);
		return parent::mergeDefaults($state);
	}
}
}

// nette/schema/src/Schema/Elements/StringType.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette\Schema\Schema;

final class StringType extends Type
{

	public function items(string|Schema $valueType = 'mixed', string|Schema|null $keyType = null): static
	{
		trigger_error(__METHOD__ . '() is deprecated, a string has no items.', E_USER_DEPRECATED);
		return parent::items($valueType, $keyType);
	}

public function mergeDefaults(bool $state = true): static
	{
		trigger_error(__METHOD__ . '() is deprecated, a string has no default merging.', E_USER_DEPRECATED);
		return parent::mergeDefaults($state);
	}
}
}

// nette/schema/src/Schema/Elements/Structure.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\Schema\{Context, Helpers, Kind, MergeMode, Schema};
use function array_key_exists, is_array, is_object;

final class Structure implements Schema
{
	use Base;

private array $items;

private ?Schema $otherItems = null;

private array $range = [null, null];
	private bool $skipDefaults = false;
	private ?MergeMode $mergeMode = null;

public function __construct(array $shape)
	{
		(function (Schema ...$items) {})(...array_values($shape));
		$this->items = $shape;
		$this->castTo('object');
		$this->required = true;
	}

public function default(mixed $value): self
	{
		throw new Nette\InvalidStateException('Structure cannot have default value.');
	}

public function min(?int $min): self
	{
		$this->range[0] = $min;
		return $this;
	}

public function max(?int $max): self
	{
		$this->range[1] = $max;
		return $this;
	}

public function otherItems(string|Schema $type = 'mixed'): self
	{
		$this->otherItems = $type instanceof Schema ? $type : Nette\Schema\Expect::type($type);
		return $this;
	}

public function skipDefaults(bool $state = true): self
	{
		$this->skipDefaults = $state;
		return $this;
	}

public function mergeMode(MergeMode $mode): self
	{
		$this->mergeMode = $mode;
		return $this;
	}

public function extend(array|self $shape): self
	{
		$shape = $shape instanceof self ? $shape->items : $shape;
		return new self([...$this->items, ...$shape]);
	}

public function getShape(): array
	{
		return $this->items;
	}

public function describe(): array
	{
		return [
			'kind' => Kind::Structure,
			'shape' => $this->items,
			'otherItems' => $this->otherItems,
			'min' => $this->range[0],
			'max' => $this->range[1],
		] + $this->describeBase();
	}

public function normalize(mixed $value, Context $context): mixed
	{
		if ($prevent = (is_array($value) && isset($value[Helpers::PreventMerging]))) {
			unset($value[Helpers::PreventMerging]);
		}

		$value = $this->doNormalize($value, $context);
		if (is_object($value)) {
			$value = (array) $value;
		}

		if (is_array($value)) {
			foreach ($value as $key => $val) {
				$itemSchema = $this->items[$key] ?? $this->otherItems;
				if ($itemSchema) {
					$context->path[] = $key;
					$value[$key] = $itemSchema->normalize($val, $context);
					array_pop($context->path);
				}
			}

			if ($prevent) {
				$value[Helpers::PreventMerging] = true;
			}
		}

		return $value;
	}

public function merge(mixed $value, mixed $base): mixed
	{
		if (is_array($value) && isset($value[Helpers::PreventMerging])) {
			unset($value[Helpers::PreventMerging]);
			return $value;
		}

		if ($this->mergeWith) {
			return ($this->mergeWith)($value, $base);
		}

		$mode = $this->mergeMode ?? ($this->otherItems === null ? MergeMode::OverwriteKeys : MergeMode::AppendKeys);
		if ($mode === MergeMode::Replace) {
			return $value;
		}

		if (is_array($value) && is_array($base)) {
			$index = $mode === MergeMode::OverwriteKeys ? null : 0;
			foreach ($value as $key => $val) {
				if ($key === $index) {
					$base[] = $val;
					$index++;
				} else {
					$base[$key] = array_key_exists($key, $base) && ($itemSchema = $this->items[$key] ?? $this->otherItems)
						? $itemSchema->merge($val, $base[$key])
						: $val;
				}
			}

			return $base;
		}

		return $value ?? $base;
	}

public function complete(mixed $value, Context $context): mixed
	{

if (is_array($value)) {
			unset($value[Helpers::PreventMerging]);
		}

		if ($value === null && $context->keepNullAt !== $context->path) {
			$value = [];
		}

		$this->doDeprecation($context);

		$isOk = $context->createChecker();
		Helpers::validateType($value, 'array', $context);
		$isOk() && Helpers::validateRange($value, $this->range, $context);
		$isOk() && $this->validateItems($value, $context);
		$isOk() && $value = $this->doTransform($value, $context);
		return $isOk() ? $value : null;
	}

private function validateItems(array &$value, Context $context): void
	{
		$items = $this->items;
		if ($extraKeys = array_keys(array_diff_key($value, $items))) {
			if ($this->otherItems) {
				$items += array_fill_keys($extraKeys, $this->otherItems);
			} else {
				$keys = array_map(strval(...), array_keys($items));
				foreach ($extraKeys as $key) {
					$hint = Nette\Utils\Helpers::getSuggestion($keys, (string) $key);
					$context->addError(
						'Unexpected item %path%' . ($hint ? ", did you mean '%hint%'?" : '.'),
						Nette\Schema\Message::UnexpectedItem,
						['hint' => $hint],
					)->path[] = $key;
				}
			}
		}

		foreach ($items as $itemKey => $itemVal) {
			$context->path[] = $itemKey;
			if (array_key_exists($itemKey, $value)) {
				$value[$itemKey] = $itemVal->complete($value[$itemKey], $context);
			} else {
				$default = $itemVal->completeDefault($context);
				if (!$context->skipDefaults && !$this->skipDefaults) {
					$value[$itemKey] = $default;
				}
			}

			array_pop($context->path);
		}
	}

public function completeDefault(Context $context): mixed
	{
		if (!$this->required) {
			return null;
		}

$deprecated = $this->deprecated;
		$this->deprecated = null;
		try {
			return $this->complete([], $context);
		} finally {
			$this->deprecated = $deprecated;
		}
	}
}
}

// nette/schema/src/Schema/Elements/TupleType.php


namespace CMS\Vendor\Nette\Schema\Elements {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\Schema\{Context, Kind, Message, Schema};
use function count;

final class TupleType extends Type
{

	private array $items;

private ?Schema $otherItems = null;

public function __construct(array $shape)
	{
		if (!array_is_list($shape)) {
			throw new Nette\InvalidArgumentException('Tuple shape must be indexed array.');
		}

		(function (Schema ...$items) {})(...$shape);
		parent::__construct('list');
		parent::default(null);
		$this->items = $shape;
	}

public function default(mixed $value): static
	{
		throw new Nette\InvalidStateException('Tuple cannot have default value.');
	}

public function otherItems(string|Schema $type = 'mixed'): self
	{
		$this->otherItems = $type instanceof Schema ? $type : Nette\Schema\Expect::type($type);
		return $this;
	}

public function extend(array|self $shape): self
	{
		$shape = $shape instanceof self ? $shape->items : $shape;
		return new self([...$this->items, ...$shape]);
	}

public function getShape(): array
	{
		return $this->items;
	}

public function describe(): array
	{
		return ['kind' => Kind::Tuple, 'shape' => $this->items, 'otherItems' => $this->otherItems] + parent::describe();
	}

protected function mergeValues(mixed $value, mixed $base): mixed
	{
		return $value;
	}

protected function normalizeItems(array $value, Context $context): array
	{
		foreach ($value as $key => $val) {
			if ($itemSchema = $this->items[$key] ?? $this->otherItems) {
				$context->path[] = $key;
				$value[$key] = $itemSchema->normalize($val, $context);
				array_pop($context->path);
			}
		}

		return $value;
	}

protected function validateItems(array &$value, Context $context): void
	{
		foreach ($value as $index => $val) {
			$context->path[] = $index;
			if ($itemSchema = $this->items[$index] ?? $this->otherItems) {
				$value[$index] = $itemSchema->complete($val, $context);
			} else {
				$context->addError('Unexpected item %path%.', Message::UnexpectedItem);
			}
			array_pop($context->path);
		}

		for ($index = count($value); $index < count($this->items); $index++) {
			$context->path[] = $index;
			$default = $this->items[$index]->completeDefault($context);
			if (!$context->skipDefaults) {
				$value[] = $default;
			}
			array_pop($context->path);
		}
	}
}
}

// nette/schema/src/Schema/Expect.php


namespace CMS\Vendor\Nette\Schema {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\Schema\Elements\{AnyOf, ArrayType, EnumType, NumberType, StringType, Structure, Type};
use function array_key_exists, count, is_object;

final class Expect
{

	public static function __callStatic(string $name, array $args): Type
	{
		$type = self::type($name);
		if ($args) {
			$type->default(array_key_exists('default', $args) ? $args['default'] : $args[0]);
		}

		return $type;
	}

public static function type(string $type): Type
	{
		$class = self::typeClass(TypeExpression::parse($type));
		return new $class($type);
	}

public static function typeClass(array $item): string
	{
		$variants = $item['kind'] === Kind::Union ? $item['variants'] : [$item];
		$classes = array_unique(array_map(fn(array $variant) => match ($variant['kind']) {
			Kind::String => StringType::class,
			Kind::Int, Kind::Float, Kind::Number => NumberType::class,
			Kind::Array, Kind::List, Kind::Iterable => ArrayType::class,
			default => Type::class,
		}, $variants));
		return count($classes) === 1 ? reset($classes) : Type::class;
	}

public static function enum(string $enum): EnumType
	{
		return new EnumType($enum);
	}

public static function anyOf(mixed ...$set): AnyOf
	{
		return new AnyOf(...$set);
	}

public static function structure(array $shape): Structure
	{
		return new Structure($shape);
	}

public static function from(object $object, array $items = []): Structure
	{
		$ro = new \ReflectionObject($object);
		$props = $ro->hasMethod('__construct')
			? $ro->getMethod('__construct')->getParameters()
			: array_filter($ro->getProperties(\ReflectionProperty::IS_PUBLIC), fn($prop) => !$prop->isStatic());

		foreach ($props as $prop) {
			$name = $prop->getName();
			if ($prop instanceof \ReflectionParameter && $prop->isVariadic()) {
				throw new Nette\InvalidArgumentException("Variadic parameter \$$name of {$ro->getName()}::__construct() is not supported by Expect::from().");
			} elseif (!isset($items[$name])) {
				$type = Helpers::getPropertyType($prop) ?? 'mixed';
				if (is_subclass_of($enum = ltrim($type, '?'), \BackedEnum::class)) {
					$item = self::enum($enum);
					$enum === $type || $item->nullable();
				} else {
					$item = self::type($type);
				}
				if ($prop instanceof \ReflectionProperty ? $prop->isInitialized($object) : $prop->isOptional()) {
					$def = ($prop instanceof \ReflectionProperty ? $prop->getValue($object) : $prop->getDefaultValue());
					if (is_object($def) && !$def instanceof \UnitEnum) {
						$item = static::from($def);
					} elseif ($def === null && !Nette\Utils\Validators::is(null, $type)) {
						$item->required();
					} else {
						$item->default($def);
					}
				} else {
					$item->required();
				}
				$items[$name] = $item;
			}
		}

		return (new Structure($items))->castTo($ro->getName());
	}

public static function array(?array $shape = []): Structure|ArrayType
	{
		$shape ??= [];
		return Nette\Utils\Arrays::first($shape) instanceof Schema
			? (new Structure($shape))->castTo('array')
			: (new ArrayType('array'))->default($shape);
	}

public static function arrayOf(string|Schema $valueType, string|Schema|null $keyType = null): ArrayType
	{
		return (new ArrayType('array'))->items($valueType, $keyType);
	}

public static function listOf(string|Schema $type, bool $wrap = false): ArrayType
	{
		return (new ArrayType('list', $wrap))->items($type);
	}

public static function tuple(array $shape): Elements\TupleType
	{
		return new Elements\TupleType($shape);
	}
}
}

// nette/utils/src/StaticClass.php


namespace CMS\Vendor\Nette {

trait StaticClass
{

	private function __construct()
	{
	}
}
}

// nette/schema/src/Schema/Helpers.php


namespace CMS\Vendor\Nette\Schema {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\Utils\Reflection;
use function count, is_array, is_float, is_int, is_object, is_scalar, is_string, strlen;

final class Helpers
{
	use Nette\StaticClass;

	public const PreventMerging = '_prevent_merging';

public static function merge(mixed $value, mixed $base): mixed
	{
		if (is_array($value) && isset($value[self::PreventMerging])) {
			unset($value[self::PreventMerging]);
			return $value;
		}

		if (is_array($value) && is_array($base)) {
			$index = 0;
			foreach ($value as $key => $val) {
				if ($key === $index) {
					$base[] = $val;
					$index++;
				} else {
					$base[$key] = static::merge($val, $base[$key] ?? null);
				}
			}

			return $base;

		} elseif ($value === null && is_array($base)) {
			return $base;

		} else {
			return $value;
		}
	}

public static function getPropertyType(\ReflectionProperty|\ReflectionParameter $prop): ?string
	{
		if ($type = Nette\Utils\Type::fromReflection($prop)) {
			return (string) $type;
		} elseif (
			($prop instanceof \ReflectionProperty)
			&& ($type = preg_replace('#\s.*#', '', (string) self::parseAnnotation($prop, 'var')))
		) {
			$class = Reflection::getPropertyDeclaringClass($prop);
			return preg_replace_callback('#[\w\\\]+#', fn($m) => Reflection::expandClassName($m[0], $class), $type);
		}

		return null;
	}

public static function parseAnnotation(\ReflectionClass|\ReflectionProperty $ref, string $name): ?string
	{
		if (!Reflection::areCommentsAvailable()) {
			throw new Nette\InvalidStateException('You have to enable phpDoc comments in opcode cache.');
		}

		$re = '#[\s*]@' . preg_quote($name, '#') . '(?=\s|$)(?:[ \t]+([^@\s]\S*))?#';
		if ($ref->getDocComment() && preg_match($re, trim($ref->getDocComment(), '/*'), $m)) {
			return $m[1] ?? '';
		}

		return null;
	}

public static function formatValue(mixed $value): string
	{
		if ($value instanceof DynamicParameter) {
			return 'dynamic';
		} elseif (is_object($value)) {
			return 'object ' . $value::class;
		} elseif (is_string($value)) {
			return "'" . Nette\Utils\Strings::truncate($value, 15, '...') . "'";
		} elseif (is_scalar($value)) {
			return var_export($value, return: true);
		} else {
			return get_debug_type($value);
		}
	}

public static function validateType(mixed $value, string $expected, Context $context): void
	{
		if (!Nette\Utils\Validators::is($value, $expected)) {
			$expected = str_replace(DynamicParameter::class . '|', '', $expected);
			$expected = str_replace(['|', ':'], [' or ', ' in range '], $expected);
			$context->addError(
				'The %label% %path% expects to be %expected%, %value% given.',
				Message::TypeMismatch,
				['value' => $value, 'expected' => $expected],
			);
		}
	}

public static function validateRange(mixed $value, array $range, Context $context, string $types = ''): void
	{
		if (is_array($value) || is_string($value)) {
			[$length, $label] = is_array($value)
				? [count($value), 'items']
				: (in_array('unicode', explode('|', $types), true)
					? [Nette\Utils\Strings::length($value), 'characters']
					: [strlen($value), 'bytes']);

			if (!self::isInRange($length, $range)) {
				$context->addError(
					"The length of %label% %path% expects to be in range %expected%, %length% $label given.",
					Message::LengthOutOfRange,
					['value' => $value, 'length' => $length, 'expected' => implode('..', $range)],
				);
			}
		} elseif ((is_int($value) || is_float($value)) && !self::isInRange($value, $range)) {
			$context->addError(
				'The %label% %path% expects to be in range %expected%, %value% given.',
				Message::ValueOutOfRange,
				['value' => $value, 'expected' => implode('..', $range)],
			);
		}
	}

public static function isInRange(mixed $value, array $range): bool
	{
		return ($range[0] === null || $value >= $range[0])
			&& ($range[1] === null || $value <= $range[1]);
	}

public static function validatePattern(string $value, string $pattern, Context $context): void
	{
		$res = @preg_match("\x01^(?:$pattern)$\x01Du", $value);
		if ($res === false && preg_last_error() === PREG_INTERNAL_ERROR) {
			throw new Nette\InvalidStateException("Invalid pattern '$pattern': " . Nette\Utils\Helpers::getLastError());
		} elseif (!$res) {
			$context->addError(
				"The %label% %path% expects to match pattern '%pattern%', %value% given.",
				Message::PatternMismatch,
				['value' => $value, 'pattern' => $pattern],
			);
		}
	}

public static function getCastStrategy(string $type): \Closure
	{
		if (str_starts_with($type, '?')) {
			$cast = self::getCastStrategy(substr($type, 1));
			return static fn($value, Context $context) => $value === null ? null : $cast($value, $context);

		} elseif (Nette\Utils\Validators::isBuiltinType($type)) {
			return static function ($value) use ($type) {
				settype($value, $type);
				return $value;
			};

		} elseif (is_subclass_of($type, \BackedEnum::class)) {
			return static function ($value, Context $context) use ($type) {
				if ($value === null) {
					return null;
				}

				try {
					return $type::from($value);
				} catch (\TypeError|\ValueError) {
					$context->addError(
						'The %label% %path% expects to be %expected%, %value% given.',
						Message::TypeMismatch,
						[
							'value' => $value,
							'expected' => implode('|', array_map(fn(\BackedEnum $case) => self::formatValue($case->value), $type::cases())),
						],
					);
					return null;
				}
			};

		} elseif (is_subclass_of($type, \UnitEnum::class)) {
			throw new Nette\InvalidStateException("Cannot cast value to pure enum $type.");
		}

		$factory = method_exists($type, '__construct')
			? static fn($value) => is_array($value) || $value instanceof \stdClass
				? new $type(...(array) $value)
				: new $type($value)
			: static fn($value) => Nette\Utils\Arrays::toObject((array) $value, new $type);

		return static function ($value) use ($factory, $type) {
			if ($value === null) {
				return null;
			}

			try {
				return $factory($value);
			} catch (\Error $e) {
				throw new Nette\InvalidStateException("Unable to cast value to $type: " . $e->getMessage(), 0, $e);
			}
		};
	}
}
}

// nette/schema/src/Schema/Message.php


namespace CMS\Vendor\Nette\Schema {

use function array_key_exists;

final class Message
{

	public const TypeMismatch = 'schema.typeMismatch';

public const ValueOutOfRange = 'schema.valueOutOfRange';

public const LengthOutOfRange = 'schema.lengthOutOfRange';

public const PatternMismatch = 'schema.patternMismatch';

public const FailedAssertion = 'schema.failedAssertion';

public const MissingItem = 'schema.missingItem';

public const UnexpectedItem = 'schema.unexpectedItem';

public const Deprecated = 'schema.deprecated';

public const TYPE_MISMATCH = self::TypeMismatch;

public const VALUE_OUT_OF_RANGE = self::ValueOutOfRange;

public const LENGTH_OUT_OF_RANGE = self::LengthOutOfRange;

public const PATTERN_MISMATCH = self::PatternMismatch;

public const FAILED_ASSERTION = self::FailedAssertion;

public const MISSING_ITEM = self::MissingItem;

public const UNEXPECTED_ITEM = self::UnexpectedItem;

public const DEPRECATED = self::Deprecated;

public function __construct(
		public string $message,
		public string $code,

		public array $path,

		public array $variables = [],
	) {
	}

public function toString(): string
	{
		$vars = $this->variables;
		$vars['label'] = empty($vars['isKey']) ? 'item' : 'key of item';
		$vars['path'] = $this->path
			? "'" . implode("\u{a0}›\u{a0}", $this->path) . "'"
			: null;
		$vars['value'] = Helpers::formatValue($vars['value'] ?? null);

		return preg_replace_callback('~( ?)%(\w+)%~', function ($m) use ($vars) {
			[, $space, $key] = $m;
			return array_key_exists($key, $vars)
				? ($vars[$key] === null ? '' : $space . $vars[$key])
				: $m[0];
		}, $this->message);
	}
}
}

// nette/schema/src/Schema/Processor.php


namespace CMS\Vendor\Nette\Schema {

use CMS\Vendor\Nette;

final class Processor
{

	public array $onNewContext = [];
	private Context $context;
	private bool $skipDefaults = false;

public function skipDefaults(bool $value = true): void
	{
		$this->skipDefaults = $value;
	}

public function process(Schema $schema, mixed $data): mixed
	{
		$context = $this->createContext();
		try {
			$data = $schema->normalize($data, $context);
			$this->throwErrors($context);
			$data = $schema->complete($data, $context);
			$this->throwErrors($context);
			return $data;
		} finally {
			$this->context = $context;
		}
	}

public function processMultiple(Schema $schema, array $dataset): mixed
	{
		$context = $this->createContext();
		try {
			$flatten = null;
			$first = true;
			foreach ($dataset as $data) {
				$data = $schema->normalize($data, $context);
				$this->throwErrors($context);
				$flatten = $first ? $data : $schema->merge($data, $flatten);
				$first = false;
			}

			$data = $schema->complete($flatten, $context);
			$this->throwErrors($context);
			return $data;
		} finally {
			$this->context = $context;
		}
	}

public function getWarnings(): array
	{
		$res = [];
		if (isset($this->context)) {
			foreach ($this->context->warnings as $message) {
				$res[] = $message->toString();
			}
		}

		return $res;
	}

private function throwErrors(Context $context): void
	{
		if ($context->errors) {
			throw new ValidationException(null, $context->errors);
		}
	}

private function createContext(): Context
	{
		$context = new Context;
		$context->skipDefaults = $this->skipDefaults;
		Nette\Utils\Arrays::invoke($this->onNewContext, $context);
		return $context;
	}
}
}

// nette/schema/src/Schema/TypeExpression.php


namespace CMS\Vendor\Nette\Schema {

use function count;

final class TypeExpression
{

	private const Names = [
		'mixed' => Kind::Any,
		'bool' => Kind::Bool, 'boolean' => Kind::Bool,
		'int' => Kind::Int, 'integer' => Kind::Int,
		'float' => Kind::Float, 'number' => Kind::Number,
		'string' => Kind::String,
		'unicode' => Kind::String, 'email' => Kind::String, 'uri' => Kind::String, 'url' => Kind::String,
		'identifier' => Kind::String, 'class' => Kind::String, 'interface' => Kind::String,
		'file' => Kind::String, 'directory' => Kind::String, 'alnum' => Kind::String, 'alpha' => Kind::String,
		'digit' => Kind::String, 'lower' => Kind::String, 'upper' => Kind::String, 'space' => Kind::String,
		'xdigit' => Kind::String, 'pattern' => Kind::String,
		'array' => Kind::Array, 'list' => Kind::List, 'iterable' => Kind::Iterable,
		'object' => Kind::Object, 'callable' => Kind::Callable,
		'resource' => Kind::Other, 'none' => Kind::Other, 'numeric' => Kind::Other, 'numericint' => Kind::Other,
	];

private static array $cache = [];

public static function parse(string $expression): array
	{
		return self::$cache[$expression] ??= self::doParse($expression);
	}

private static function doParse(string $expression): array
	{
		$variants = [];
		$nullable = $dynamic = false;
		foreach (explode('|', $expression) as $part) {
			if (str_starts_with($part, '?')) {
				$nullable = true;
				$part = substr($part, 1);
			}

			if ($part === 'null') {
				$nullable = true;
			} elseif ($part === DynamicParameter::class) {
				$dynamic = true;
			} else {
				array_push($variants, ...self::parseAlternative($part));
			}
		}

		$item = match (count($variants)) {
			0 => ['kind' => $nullable ? Kind::Null : Kind::Any],
			1 => $variants[0],
			default => ['kind' => Kind::Union, 'variants' => $variants],
		};
		return $item + ['nullable' => $nullable && count($variants) > 0, 'dynamic' => $dynamic];
	}

private static function parseAlternative(string $part): array
	{
		if (str_ends_with($part, '[]')) {
			$items = self::parseAlternative(substr($part, 0, -2));
			return [[
				'kind' => Kind::Iterable,
				'items' => count($items) === 1 ? $items[0] : ['kind' => Kind::Union, 'variants' => $items],
				'keys' => null,
				'min' => null,
				'max' => null,
			]];
		}

		if (str_contains($part, '&')) {
			return [['kind' => Kind::Other, 'type' => $part]];
		}

		$parts = explode(':', $part, 2);
		$name = $parts[0];
		$arg = $parts[1] ?? null;
		[$min, $max] = $arg === null || $name === 'pattern' ? [null, null] : self::parseRange($arg);
		$number = fn(Kind $kind) => ['kind' => $kind, 'min' => $min, 'max' => $max];
		$string = fn(?string $format = null, ?string $pattern = null) => [
			'kind' => Kind::String,
			'min' => $min,
			'max' => $max,
			'pattern' => $pattern,
			'format' => $format,
		];
		$collection = fn(Kind $kind) => ['kind' => $kind, 'items' => null, 'keys' => null, 'min' => $min, 'max' => $max];

		$kind = self::Names[$name] ?? Kind::Instance;
		return match (true) {
			$name === 'scalar' => [['kind' => Kind::Bool], $number(Kind::Number), $string()],
			$name === 'pattern' => [$string(pattern: $arg)],
			$kind === Kind::String => [$string(format: $name === 'string' ? null : $name)],
			$kind === Kind::Int, $kind === Kind::Float, $kind === Kind::Number => [$number($kind)],
			$kind === Kind::Array, $kind === Kind::List, $kind === Kind::Iterable => [$collection($kind)],
			$kind === Kind::Instance => [['kind' => $kind, 'type' => $name]],
			$kind === Kind::Other => [['kind' => $kind, 'type' => $part]],
			default => [['kind' => $kind]],
		};
	}

private static function parseRange(string $range): array
	{
		$bounds = explode('..', $range) + [1 => $range];
		return [
			$bounds[0] === '' ? null : self::parseBound($bounds[0]),
			$bounds[1] === '' ? null : self::parseBound($bounds[1]),
		];
	}

private static function parseBound(string $bound): int|float
	{
		return is_numeric($bound) ? $bound + 0 : (float) $bound;
	}
}
}

// nette/utils/src/exceptions.php


namespace CMS\Vendor\Nette {

class ArgumentOutOfRangeException extends \InvalidArgumentException
{
}

class InvalidStateException extends \RuntimeException
{
}

class NotImplementedException extends \LogicException
{
}

class NotSupportedException extends \LogicException
{
}

class DeprecatedException extends NotSupportedException
{
}

class MemberAccessException extends \Error
{
}

class IOException extends \RuntimeException
{
}

class FileNotFoundException extends IOException
{
}

class DirectoryNotFoundException extends IOException
{
}

class InvalidArgumentException extends \InvalidArgumentException
{
}

class OutOfRangeException extends \OutOfRangeException
{
}

class UnexpectedValueException extends \UnexpectedValueException
{
}

class ShouldNotHappenException extends \LogicException
{
}
}

// nette/schema/src/Schema/ValidationException.php


namespace CMS\Vendor\Nette\Schema {

use CMS\Vendor\Nette;

class ValidationException extends Nette\InvalidStateException
{
	public function __construct(
		?string $message,

		private readonly array $messages = [],
	) {
		parent::__construct($message ?? $messages[0]->toString());
	}

public function getMessages(): array
	{
		$res = [];
		foreach ($this->messages as $message) {
			$res[] = $message->toString();
		}

		return $res;
	}

public function getMessageObjects(): array
	{
		return $this->messages;
	}
}
}

// nette/schema/src/Schema/enums.php


namespace CMS\Vendor\Nette\Schema {

enum Kind
{
	case Any;
	case Null;
	case Bool;
	case Int;
	case Float;

case Number;
	case String;
	case Array;
	case List;
	case Iterable;
	case Structure;
	case Enum;
	case Tuple;
	case Union;
	case Instance;
	case Object;
	case Callable;
	case Other;
}

enum MergeMode
{
	case Replace;
	case OverwriteKeys;
	case AppendKeys;
}
}

// nette/utils/src/HtmlStringable.php


namespace CMS\Vendor\Nette {

interface HtmlStringable
{

	function __toString(): string;
}

interface_exists(Utils\IHtmlString::class);
}

// nette/utils/src/Translator.php


namespace CMS\Vendor\Nette\Localization {

interface Translator
{

	function translate(string|\Stringable $message, mixed ...$parameters): string|\Stringable;
}

interface_exists(ITranslator::class);
}

// nette/utils/src/Utils/Arrays.php


namespace CMS\Vendor\Nette\Utils {

use JetBrains\PhpStorm\Language;
use CMS\Vendor\Nette;
use function array_key_exists, count, func_num_args, is_array, is_int, is_object;
use const PREG_SPLIT_DELIM_CAPTURE, PREG_SPLIT_NO_EMPTY;

class Arrays
{
	use Nette\StaticClass;

public static function get(array $array, string|int|array $key, mixed $default = null): mixed
	{
		foreach (is_array($key) ? $key : [$key] as $k) {
			if (is_array($array) && array_key_exists($k, $array)) {
				$array = $array[$k];
			} else {
				if (func_num_args() < 3) {
					throw new Nette\InvalidArgumentException("Missing item '$k'.");
				}

				return $default;
			}
		}

		return $array;
	}

public static function &getRef(array &$array, string|int|array $key): mixed
	{
		foreach (is_array($key) ? $key : [$key] as $k) {
			if (is_array($array) || $array === null) {
				$array = &$array[$k];
			} else {
				throw new Nette\InvalidArgumentException('Traversed item is not an array.');
			}
		}

		return $array;
	}

public static function mergeTree(array $array1, array $array2): array
	{
		$res = $array1 + $array2;
		foreach (array_intersect_key($array1, $array2) as $k => $v) {
			if (is_array($v) && is_array($array2[$k])) {
				$res[$k] = self::mergeTree($v, $array2[$k]);
			}
		}

		return $res;
	}

public static function getKeyOffset(array $array, string|int $key): ?int
	{
		return Helpers::falseToNull(array_search(self::toKey($key), array_keys($array), strict: true));
	}

public static function searchKey(array $array, string|int $key): ?int
	{
		return self::getKeyOffset($array, $key);
	}

public static function contains(array $array, mixed $value): bool
	{
		return in_array($value, $array, true);
	}

public static function first(array $array, ?callable $predicate = null, ?callable $else = null): mixed
	{
		$key = self::firstKey($array, $predicate);
		return $key === null
			? ($else ? $else() : null)
			: $array[$key];
	}

public static function last(array $array, ?callable $predicate = null, ?callable $else = null): mixed
	{
		$key = self::lastKey($array, $predicate);
		return $key === null
			? ($else ? $else() : null)
			: $array[$key];
	}

public static function firstKey(array $array, ?callable $predicate = null): int|string|null
	{
		if (!$predicate) {
			return array_key_first($array);
		}
		foreach ($array as $k => $v) {
			if ($predicate($v, $k, $array)) {
				return $k;
			}
		}
		return null;
	}

public static function lastKey(array $array, ?callable $predicate = null): int|string|null
	{
		return $predicate
			? self::firstKey(array_reverse($array, preserve_keys: true), $predicate)
			: array_key_last($array);
	}

public static function insertBefore(array &$array, string|int|null $key, array $inserted): void
	{
		$offset = $key === null ? 0 : (int) self::getKeyOffset($array, $key);
		$array = array_slice($array, 0, $offset, preserve_keys: true)
			+ $inserted
			+ array_slice($array, $offset, count($array), preserve_keys: true);
	}

public static function insertAfter(array &$array, string|int|null $key, array $inserted): void
	{
		if ($key === null || ($offset = self::getKeyOffset($array, $key)) === null) {
			$offset = count($array) - 1;
		}

		$array = array_slice($array, 0, $offset + 1, preserve_keys: true)
			+ $inserted
			+ array_slice($array, $offset + 1, count($array), preserve_keys: true);
	}

public static function renameKey(array &$array, string|int $oldKey, string|int $newKey): bool
	{
		$offset = self::getKeyOffset($array, $oldKey);
		if ($offset === null) {
			return false;
		}

		$val = &$array[$oldKey];
		$keys = array_keys($array);
		$keys[$offset] = $newKey;
		$array = array_combine($keys, $array);
		$array[$newKey] = &$val;
		return true;
	}

public static function grep(
		array $array,
		#[Language('RegExp')]
		string $pattern,
		bool|int $invert = false,
	): array
	{
		$flags = $invert ? PREG_GREP_INVERT : 0;
		return Strings::pcre('preg_grep', [$pattern, $array, $flags]);
	}

public static function flatten(array $array, bool $preserveKeys = false): array
	{
		$res = [];
		$cb = $preserveKeys
			? function ($v, $k) use (&$res): void { $res[$k] = $v; }
			: function ($v) use (&$res): void { $res[] = $v; };
		array_walk_recursive($array, $cb);
		return $res;
	}

public static function isList(mixed $value): bool
	{
		return is_array($value) && array_is_list($value);
	}

public static function associate(array $array, string|array $path): array|\stdClass
	{
		$parts = is_array($path)
			? $path
			: preg_split('#(\[]|->|=|\|)#', $path, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

		if (!$parts || $parts === ['->'] || $parts[0] === '=' || $parts[0] === '|') {
			throw new Nette\InvalidArgumentException("Invalid path '" . (is_array($path) ? implode('', $path) : $path) . "'.");
		}

		$res = $parts[0] === '->' ? new \stdClass : [];

		foreach ($array as $rowOrig) {
			$row = (array) $rowOrig;
			$x = &$res;

			for ($i = 0; $i < count($parts); $i++) {
				$part = $parts[$i];
				if ($part === '[]') {
					$x = &$x[];

				} elseif ($part === '=') {
					if (isset($parts[++$i])) {
						$x = $row[$parts[$i]];
						$row = null;
					}
					break;

				} elseif ($part === '->') {
					if (isset($parts[++$i])) {
						if ($x === null) {
							$x = new \stdClass;
						}

						$x = &$x->{$row[$parts[$i]]};

					} else {
						$row = is_object($rowOrig) ? $rowOrig : (object) $row;
					}
				} elseif ($part !== '|') {
					$x = &$x[(string) $row[$part]];
				}
			}

			if ($x === null) {
				$x = $row;
			}
		}

		return $res;
	}

public static function normalize(array $array, mixed $filling = null): array
	{
		$res = [];
		foreach ($array as $k => $v) {
			$res[is_int($k) ? $v : $k] = is_int($k) ? $filling : $v;
		}

		return $res;
	}

public static function pick(array &$array, string|int $key, mixed $default = null): mixed
	{
		if (array_key_exists($key, $array)) {
			$value = $array[$key];
			unset($array[$key]);
			return $value;

		} elseif (func_num_args() < 3) {
			throw new Nette\InvalidArgumentException("Missing item '$key'.");

		} else {
			return $default;
		}
	}

public static function some(iterable $array, callable $predicate): bool
	{
		foreach ($array as $k => $v) {
			if ($predicate($v, $k, $array)) {
				return true;
			}
		}

		return false;
	}

public static function every(iterable $array, callable $predicate): bool
	{
		foreach ($array as $k => $v) {
			if (!$predicate($v, $k, $array)) {
				return false;
			}
		}

		return true;
	}

public static function filter(array $array, callable $predicate): array
	{
		$res = [];
		foreach ($array as $k => $v) {
			if ($predicate($v, $k, $array)) {
				$res[$k] = $v;
			}
		}
		return $res;
	}

public static function map(iterable $array, callable $transformer): array
	{
		$res = [];
		foreach ($array as $k => $v) {
			$res[$k] = $transformer($v, $k, $array);
		}

		return $res;
	}

public static function mapWithKeys(array $array, callable $transformer): array
	{
		$res = [];
		foreach ($array as $k => $v) {
			$pair = $transformer($v, $k, $array);
			if ($pair) {
				$res[$pair[0]] = $pair[1];
			}
		}

		return $res;
	}

public static function invoke(iterable $callbacks, mixed ...$args): array
	{
		$res = [];
		foreach ($callbacks as $k => $cb) {
			$res[$k] = $cb(...$args);
		}

		return $res;
	}

public static function invokeMethod(iterable $objects, string $method, mixed ...$args): array
	{
		$res = [];
		foreach ($objects as $k => $obj) {
			$res[$k] = $obj->$method(...$args);
		}

		return $res;
	}

public static function toObject(iterable $array, object $object): object
	{
		foreach ($array as $k => $v) {
			$object->$k = $v;
		}

		return $object;
	}

public static function toKey(mixed $value): int|string
	{
		return key(@[$value => null]);
	}

public static function wrap(array $array, string $prefix = '', string $suffix = ''): array
	{
		$res = [];
		foreach ($array as $k => $v) {
			$res[$k] = $prefix . $v . $suffix;
		}

		return $res;
	}
}
}

// nette/utils/src/Utils/Callback.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette;
use function func_get_args, ini_get, is_array, is_object, is_string, sprintf;

final class Callback
{
	use Nette\StaticClass;

public static function invokeSafe(string $function, array $args, callable $onError): mixed
	{
		$prev = set_error_handler(
			function (int $severity, string $message, string $file, int $line) use ($onError, &$prev, $function): bool {
				if ($file === __FILE__) {
					$msg = ini_get('html_errors')
						? Html::htmlToText($message)
						: $message;
					$msg = (string) preg_replace("#^$function\\(.*?\\): #", '', $msg);
					if ($onError($msg, $severity) !== false) {
						return true;
					}
				}

				return $prev ? $prev(...func_get_args()) !== false : false;
			},
		);

		try {
			return $function(...$args);
		} finally {
			restore_error_handler();
		}
	}

public static function check(mixed $callable, bool $syntax = false): mixed
	{
		if (!is_callable($callable, $syntax)) {
			throw new Nette\InvalidArgumentException(
				$syntax
					? 'Given value is not a callable type.'
					: sprintf("Callback '%s' is not callable.", self::toString($callable)),
			);
		}

		return $callable;
	}

public static function toString(mixed $callable): string
	{
		if ($callable instanceof \Closure) {
			$inner = self::unwrap($callable);
			return '{closure' . ($inner instanceof \Closure ? '}' : ' ' . self::toString($inner) . '}');
		} else {
			is_callable(is_object($callable) ? [$callable, '__invoke'] : $callable, true, $textual);
			return $textual;
		}
	}

public static function toReflection(mixed $callable): \ReflectionMethod|\ReflectionFunction
	{
		if ($callable instanceof \Closure) {
			$callable = self::unwrap($callable);
		}

		if (is_string($callable) && str_contains($callable, '::')) {
			return new ReflectionMethod(...explode('::', $callable, 2));
		} elseif (is_array($callable)) {
			return new ReflectionMethod($callable[0], $callable[1]);
		} elseif (is_object($callable) && !$callable instanceof \Closure) {
			return new ReflectionMethod($callable, '__invoke');
		} else {
			assert($callable instanceof \Closure || is_string($callable));
			return new \ReflectionFunction($callable);
		}
	}

public static function isStatic(callable $callable): bool
	{
		return is_string(is_array($callable) ? $callable[0] : $callable);
	}

public static function unwrap(\Closure $closure): callable|array|string
	{
		$r = new \ReflectionFunction($closure);
		$class = $r->getClosureScopeClass()?->name;
		if (str_ends_with($r->name, '}')) {
			return $closure;

		} elseif (($obj = $r->getClosureThis()) && $obj::class === $class) {
			return [$obj, $r->name];

		} elseif ($class) {
			return [$class, $r->name];

		} else {
			return $r->name;
		}
	}
}
}

// nette/utils/src/Utils/Helpers.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette;
use function ini_get, strlen;
use const PHP_OS_FAMILY;

class Helpers
{
	public const IsWindows = PHP_OS_FAMILY === 'Windows';

public static function capture(callable $func): string
	{
		ob_start(fn() => '');
		try {
			$func();
			return ob_get_clean();
		} catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		}
	}

public static function getLastError(): string
	{
		$message = error_get_last()['message'] ?? '';
		$message = ini_get('html_errors') ? Html::htmlToText($message) : $message;
		$message = preg_replace('#^\w+\(.*?\): #', '', $message);
		return $message;
	}

public static function falseToNull(mixed $value): mixed
	{
		return $value === false ? null : $value;
	}

public static function clamp(int|float $value, int|float $min, int|float $max): int|float
	{
		if ($min > $max) {
			throw new Nette\InvalidArgumentException("Minimum ($min) is not less than maximum ($max).");
		}

		return min(max($value, $min), $max);
	}

public static function getSuggestion(array $possibilities, string $value): ?string
	{
		$best = null;
		$min = (strlen($value) / 4 + 1) * 10 + .1;
		foreach (array_unique($possibilities) as $item) {
			if ($item !== $value && ($len = levenshtein($item, $value, 10, 11, 10)) < $min) {
				$min = $len;
				$best = $item;
			}
		}

		return $best;
	}

public static function compare(mixed $left, string $operator, mixed $right): bool
	{
		return match ($operator) {
			'>' => $left > $right,
			'>=' => $left >= $right,
			'<' => $left < $right,
			'<=' => $left <= $right,
			'=', '==' => $left == $right,
			'===' => $left === $right,
			'!=', '<>' => $left != $right,
			'!==' => $left !== $right,
			default => throw new Nette\InvalidArgumentException("Unknown operator '$operator'"),
		};
	}

public static function splitClassName(string $name): array
	{
		return ($pos = strrpos($name, '\\')) === false
			? ['', $name]
			: [substr($name, 0, $pos), substr($name, $pos + 1)];
	}
}
}

// nette/utils/src/Utils/Html.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette\HtmlStringable;
use function count, func_num_args, is_array, is_bool, is_float, is_object, is_string;
use const ENT_HTML5, ENT_QUOTES;

class Html implements \ArrayAccess, \Countable, \IteratorAggregate, HtmlStringable
{

	public array $attrs = [];

public static array $emptyElements = [
		'img' => 1, 'hr' => 1, 'br' => 1, 'input' => 1, 'meta' => 1, 'area' => 1, 'embed' => 1, 'keygen' => 1,
		'source' => 1, 'base' => 1, 'col' => 1, 'link' => 1, 'param' => 1, 'basefont' => 1, 'frame' => 1,
		'isindex' => 1, 'wbr' => 1, 'command' => 1, 'track' => 1,
	];

protected array $children = [];

private string $name = '';

	private bool $isEmpty = false;

public static function el(?string $name = null, array|string|null $attrs = null): static
	{
		$el = new static;
		$parts = explode(' ', (string) $name, 2);
		$el->setName($parts[0]);

		if (is_array($attrs)) {
			$el->attrs = $attrs;

		} elseif ($attrs !== null) {
			$el->setText($attrs);
		}

		if (isset($parts[1])) {
			foreach (Strings::matchAll($parts[1] . ' ', '#([a-z0-9:-]+)(?:=(["\'])?(.*?)(?(2)\2|\s))?#i') as $m) {
				$el->attrs[$m[1]] = $m[3] ?? true;
			}
		}

		return $el;
	}

public static function fragment(HtmlStringable|\Stringable|string|int|null ...$children): static
	{
		return (new static)->add(...$children);
	}

public static function fromHtml(string $html): static
	{
		return (new static)->setHtml($html);
	}

public static function fromText(string $text): static
	{
		return (new static)->setText($text);
	}

final public function toHtml(): string
	{
		return $this->render();
	}

final public function toText(): string
	{
		return $this->getText();
	}

public static function htmlToText(string $html): string
	{
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

final public function setName(string $name, ?bool $isEmpty = null): static
	{
		$this->name = $name;
		$this->isEmpty = $isEmpty ?? isset(static::$emptyElements[$name]);
		return $this;
	}

final public function getName(): string
	{
		return $this->name;
	}

final public function isEmpty(): bool
	{
		return $this->isEmpty;
	}

public function addAttributes(array $attrs): static
	{
		$this->attrs = [...$this->attrs, ...$attrs];
		return $this;
	}

public function appendAttribute(string $name, mixed $value, mixed $option = true): static
	{
		if (is_array($value)) {
			$prev = isset($this->attrs[$name]) ? (array) $this->attrs[$name] : [];
			$this->attrs[$name] = $value + $prev;

		} elseif ((string) $value === '') {
			$tmp = &$this->attrs[$name];

		} elseif (!isset($this->attrs[$name]) || is_array($this->attrs[$name])) {
			$this->attrs[$name][$value] = $option;

		} else {
			$this->attrs[$name] = [$this->attrs[$name] => true, $value => $option];
		}

		return $this;
	}

public function setAttribute(string $name, mixed $value): static
	{
		$this->attrs[$name] = $value;
		return $this;
	}

public function getAttribute(string $name): mixed
	{
		return $this->attrs[$name] ?? null;
	}

public function removeAttribute(string $name): static
	{
		unset($this->attrs[$name]);
		return $this;
	}

public function removeAttributes(array $attributes): static
	{
		foreach ($attributes as $name) {
			unset($this->attrs[$name]);
		}

		return $this;
	}

final public function __set(string $name, mixed $value): void
	{
		$this->attrs[$name] = $value;
	}

final public function &__get(string $name): mixed
	{
		return $this->attrs[$name];
	}

final public function __isset(string $name): bool
	{
		return isset($this->attrs[$name]);
	}

final public function __unset(string $name): void
	{
		unset($this->attrs[$name]);
	}

final public function __call(string $m, array $args): mixed
	{
		if ($m === 'text' || $m === 'html') {
			trigger_error(
				"Method \$el->$m() is deprecated, use set" . ucfirst($m)
				. "() for content or setAttribute() for the '$m' attribute; Html::$m() is a static factory.",
				E_USER_DEPRECATED,
			);
		}

		$p = substr($m, 0, 3);
		if ($p === 'get' || $p === 'set' || $p === 'add') {
			$m = substr($m, 3);
			$m[0] = $m[0] | "\x20";
			if ($p === 'get') {
				return $this->attrs[$m] ?? null;

			} elseif ($p === 'add') {
				$args[] = true;
			}
		}

		if (count($args) === 0) {

		} elseif (count($args) === 1) {
			$this->attrs[$m] = $args[0];

		} else {
			$this->appendAttribute($m, $args[0], $args[1]);
		}

		return $this;
	}

final public static function __callStatic(string $name, array $args): static
	{
		return match ($name) {
			'text' => (new static)->setText(...$args),
			'html' => (new static)->setHtml(...$args),
			default => ObjectHelpers::strictStaticCall(static::class, $name),
		};
	}

final public function href(string $path, array $query = []): static
	{
		if ($query) {
			$query = http_build_query($query, '', '&');
			if ($query !== '') {
				$path .= '?' . $query;
			}
		}

		$this->attrs['href'] = $path;
		return $this;
	}

public function data(string $name, mixed $value = null): static
	{
		if (func_num_args() === 1) {
			$this->attrs['data'] = $name;
		} else {
			$this->attrs["data-$name"] = is_bool($value)
				? json_encode($value)
				: $value;
		}

		return $this;
	}

final public function setHtml(mixed $html): static
	{
		$this->children = [(string) $html];
		return $this;
	}

final public function getHtml(): string
	{
		return implode('', $this->children);
	}

final public function setText(mixed $text): static
	{
		if (!$text instanceof HtmlStringable) {
			$text = htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8');
		}

		$this->children = [(string) $text];
		return $this;
	}

final public function getText(): string
	{
		return self::htmlToText($this->getHtml());
	}

public function add(HtmlStringable|\Stringable|string|int|null ...$children): static
	{
		foreach ($children as $child) {
			if ($child !== null) {
				$this->addText($child);
			}
		}

		return $this;
	}

final public function addHtml(HtmlStringable|string $child): static
	{
		return $this->insert(null, $child);
	}

public function addText(\Stringable|string|int|null $text): static
	{
		if (!$text instanceof HtmlStringable) {
			$text = htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8');
		}

		return $this->insert(null, $text);
	}

final public function create(string $name, array|string|null $attrs = null): static
	{
		$this->insert(null, $child = static::el($name, $attrs));
		return $child;
	}

public function insert(?int $index, HtmlStringable|string $child, bool $replace = false): static
	{
		$child = $child instanceof self ? $child : (string) $child;
		if ($index === null) {
			$this->children[] = $child;

		} else {
			array_splice($this->children, $index, $replace ? 1 : 0, [$child]);
		}

		return $this;
	}

final public function offsetSet($index, $child): void
	{
		$this->insert($index, $child, replace: true);
	}

final public function offsetGet($index): self|string
	{
		return $this->children[$index];
	}

final public function offsetExists($index): bool
	{
		return isset($this->children[$index]);
	}

public function offsetUnset($index): void
	{
		if (isset($this->children[$index])) {
			array_splice($this->children, $index, 1);
		}
	}

final public function count(): int
	{
		return count($this->children);
	}

public function removeChildren(): void
	{
		$this->children = [];
	}

final public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->children);
	}

final public function getChildren(): array
	{
		return $this->children;
	}

final public function render(?int $indent = null): string
	{
		$s = $this->startTag();

		if (!$this->isEmpty) {

			if ($indent !== null) {
				$indent++;
			}

			foreach ($this->children as $child) {
				if ($child instanceof self) {
					$s .= $child->render($indent);
				} else {
					$s .= $child;
				}
			}

$s .= $this->endTag();
		}

		if ($indent !== null) {
			return "\n" . str_repeat("\t", $indent - 1) . $s . "\n" . str_repeat("\t", max(0, $indent - 2));
		}

		return $s;
	}

final public function __toString(): string
	{
		return $this->render();
	}

final public function startTag(): string
	{
		return $this->name
			? '<' . $this->name . $this->attributes() . '>'
			: '';
	}

final public function endTag(): string
	{
		return $this->name && !$this->isEmpty ? '</' . $this->name . '>' : '';
	}

final public function attributes(): string
	{
		$s = '';
		$attrs = $this->attrs;
		foreach ($attrs as $key => $value) {
			if ($value === null || $value === false) {
				continue;

			} elseif ($value === true) {
				$s .= ' ' . $key;

				continue;

			} elseif (is_array($value)) {
				if (str_starts_with($key, 'data-')) {
					$value = Json::encode($value);

				} else {
					$tmp = null;
					foreach ($value as $k => $v) {
						if ($v != null) {

							$tmp[] = $v === true
								? $k
								: (is_string($k) ? $k . ':' . $v : $v);
						}
					}

					if ($tmp === null) {
						continue;
					}

					$value = implode($key === 'style' || !strncmp($key, 'on', 2) ? ';' : ' ', $tmp);
				}
			} elseif (is_float($value)) {
				$value = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

			} else {
				$value = (string) $value;
			}

			$q = str_contains($value, '"') ? "'" : '"';
			$s .= ' ' . $key . '=' . $q
				. str_replace(
					['&', $q],
					['&amp;', $q === '"' ? '&quot;' : '&#39;'],
					$value,
				)
				. (str_contains($value, '`') && strpbrk($value, ' <>"\'') === false ? ' ' : '')
				. $q;
		}

		$s = str_replace('@', '&#64;', $s);
		return $s;
	}

public function __clone()
	{
		foreach ($this->children as $key => $value) {
			if (is_object($value)) {
				$this->children[$key] = clone $value;
			}
		}
	}
}
}

// nette/utils/src/Utils/Json.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette;
use function is_int;
use const JSON_HEX_AMP, JSON_HEX_APOS, JSON_HEX_QUOT, JSON_HEX_TAG, JSON_PRESERVE_ZERO_FRACTION, JSON_UNESCAPED_SLASHES;

final class Json
{
	use Nette\StaticClass;

public const FORCE_ARRAY = JSON_OBJECT_AS_ARRAY;

public const PRETTY = JSON_PRETTY_PRINT;

public const ESCAPE_UNICODE = 1 << 19;

public static function encode(
		mixed $value,
		bool|int $pretty = false,
		bool $asciiSafe = false,
		bool $htmlSafe = false,
		bool $forceObjects = false,
	): string
	{
		$flags = is_int($pretty)
			? ($pretty & self::ESCAPE_UNICODE ? 0 : JSON_UNESCAPED_UNICODE) | ($pretty & ~self::ESCAPE_UNICODE)
			: ($asciiSafe ? 0 : JSON_UNESCAPED_UNICODE)
				| ($pretty ? JSON_PRETTY_PRINT : 0)
				| ($forceObjects ? JSON_FORCE_OBJECT : 0)
				| ($htmlSafe ? JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG : 0);

		$flags |= JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

		$json = json_encode($value, $flags);
		if ($error = json_last_error()) {
			throw new JsonException(json_last_error_msg(), $error);
		}

		return $json;
	}

public static function decode(string $json, bool|int $forceArrays = false): mixed
	{
		$flags = is_int($forceArrays)
			? $forceArrays
			: ($forceArrays ? JSON_OBJECT_AS_ARRAY : 0);
		$flags |= JSON_BIGINT_AS_STRING;

		$value = json_decode($json, flags: $flags);
		if ($error = json_last_error()) {
			throw new JsonException(json_last_error_msg(), $error);
		}

		return $value;
	}
}
}

// nette/utils/src/Utils/ObjectHelpers.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette;
use CMS\Vendor\Nette\MemberAccessException;
use function strlen;

final class ObjectHelpers
{
	use Nette\StaticClass;

public static function strictGet(string $class, string $name): void
	{
		$rc = new \ReflectionClass($class);
		$hint = self::getSuggestion([
			...array_filter($rc->getProperties(\ReflectionProperty::IS_PUBLIC), fn($p) => !$p->isStatic()),
			...self::parseFullDoc($rc, '~^[ \t*]*@property(?:-read)?[ \t]+(?:\S+[ \t]+)??\$(\w+)~m'),
		], $name);
		throw new MemberAccessException("Cannot read an undeclared property $class::\$$name" . ($hint ? ", did you mean \$$hint?" : '.'));
	}

public static function strictSet(string $class, string $name): void
	{
		$rc = new \ReflectionClass($class);
		$hint = self::getSuggestion([
			...array_filter($rc->getProperties(\ReflectionProperty::IS_PUBLIC), fn($p) => !$p->isStatic()),
			...self::parseFullDoc($rc, '~^[ \t*]*@property(?:-write)?[ \t]+(?:\S+[ \t]+)??\$(\w+)~m'),
		], $name);
		throw new MemberAccessException("Cannot write to an undeclared property $class::\$$name" . ($hint ? ", did you mean \$$hint?" : '.'));
	}

public static function strictCall(string $class, string $method, array $additionalMethods = []): void
	{
		$trace = debug_backtrace(0, 3);
		$context = ($trace[1]['function'] ?? null) === '__call'
			? ($trace[2]['class'] ?? null)
			: null;

		if ($context && is_a($class, $context, allow_string: true) && method_exists($context, $method)) {
			$class = get_parent_class($context);
		}

		if (method_exists($class, $method)) {
			$rm = new \ReflectionMethod($class, $method);
			$visibility = $rm->isPrivate()
				? 'private '
				: ($rm->isProtected() ? 'protected ' : '');
			throw new MemberAccessException("Call to {$visibility}method $class::$method() from " . ($context ? "scope $context." : 'global scope.'));

		} else {
			$hint = self::getSuggestion([
				...get_class_methods($class),
				...self::parseFullDoc(new \ReflectionClass($class), '~^[ \t*]*@method[ \t]+(?:static[ \t]+)?(?:\S+[ \t]+)??(\w+)\(~m'),
				...$additionalMethods,
			], $method);
			throw new MemberAccessException("Call to undefined method $class::$method()" . ($hint ? ", did you mean $hint()?" : '.'));
		}
	}

public static function strictStaticCall(string $class, string $method): void
	{
		$trace = debug_backtrace(0, 3);
		$context = ($trace[1]['function'] ?? null) === '__callStatic'
			? ($trace[2]['class'] ?? null)
			: null;

		if ($context && is_a($class, $context, allow_string: true) && method_exists($context, $method)) {
			$class = get_parent_class($context);
		}

		if (method_exists($class, $method)) {
			$rm = new \ReflectionMethod($class, $method);
			$visibility = $rm->isPrivate()
				? 'private '
				: ($rm->isProtected() ? 'protected ' : '');
			throw new MemberAccessException("Call to {$visibility}method $class::$method() from " . ($context ? "scope $context." : 'global scope.'));

		} else {
			$hint = self::getSuggestion(
				array_filter((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC), fn($m) => $m->isStatic()),
				$method,
			);
			throw new MemberAccessException("Call to undefined static method $class::$method()" . ($hint ? ", did you mean $hint()?" : '.'));
		}
	}

public static function getMagicProperties(string $class): array
	{
		static $cache;
		$props = &$cache[$class];
		if ($props !== null) {
			return $props;
		}

		$rc = new \ReflectionClass($class);
		preg_match_all(
			'~^  [ \t*]*  @property(|-read|-write|-deprecated)  [ \t]+  [^\s$]+  [ \t]+  \$  (\w+)  ()~mx',
			(string) $rc->getDocComment(),
			$matches,
			PREG_SET_ORDER,
		);

		$props = [];
		foreach ($matches as [, $type, $name]) {
			$uname = ucfirst($name);
			$write = $type !== '-read'
				&& $rc->hasMethod($nm = 'set' . $uname)
				&& ($rm = $rc->getMethod($nm))->name === $nm && !$rm->isPrivate() && !$rm->isStatic();
			$read = $type !== '-write'
				&& ($rc->hasMethod($nm = 'get' . $uname) || $rc->hasMethod($nm = 'is' . $uname))
				&& ($rm = $rc->getMethod($nm))->name === $nm && !$rm->isPrivate() && !$rm->isStatic();

			if ($read || $write) {
				$props[$name] = $read << 0
					| ($nm[0] === 'g') << 1
					| $rm->returnsReference() << 2
					| $write << 3
					| ($type === '-deprecated') << 4;
			}
		}

		foreach ($rc->getTraits() as $trait) {
			$props += self::getMagicProperties($trait->name);
		}

		if ($parent = get_parent_class($class)) {
			$props += self::getMagicProperties($parent);
		}

		return $props;
	}

public static function getSuggestion(array $possibilities, string $value): ?string
	{
		$norm = preg_replace($re = '#^(get|set|has|is|add)(?=[A-Z])#', '+', $value);
		$best = null;
		$min = (strlen($value) / 4 + 1) * 10 + .1;
		foreach (array_unique($possibilities, SORT_REGULAR) as $item) {
			$item = $item instanceof \Reflector ? $item->name : $item;
			if ($item !== $value && (
				($len = levenshtein($item, $value, 10, 11, 10)) < $min
				|| ($len = levenshtein(preg_replace($re, '*', $item), $norm, 10, 11, 10)) < $min
			)
			) {
				$min = $len;
				$best = $item;
			}
		}

		return $best;
	}

private static function parseFullDoc(\ReflectionClass $rc, string $pattern): array
	{
		do {
			$doc[] = $rc->getDocComment();
			$traits = $rc->getTraits();
			while ($trait = array_pop($traits)) {
				$doc[] = $trait->getDocComment();
				$traits += $trait->getTraits();
			}
		} while ($rc = $rc->getParentClass());

		return preg_match_all($pattern, implode('', $doc), $m) ? $m[1] : [];
	}

public static function hasProperty(string $class, string $name): bool|string
	{
		static $cache;
		$prop = &$cache[$class][$name];
		if ($prop === null) {
			$prop = false;
			try {
				$rp = new \ReflectionProperty($class, $name);
				if ($rp->isPublic() && !$rp->isStatic()) {
					$prop = $name >= 'onA' && $name < 'on_' ? 'event' : true;
				}
			} catch (\ReflectionException) {
			}
		}

		return $prop;
	}
}
}

// nette/utils/src/Utils/Reflection.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette;
use function ord;

final class Reflection
{
	use Nette\StaticClass;

public static function isBuiltinType(string $type): bool
	{
		return Validators::isBuiltinType($type);
	}

#[\Deprecated('use Nette\Utils\Validators::isClassKeyword()')]
	public static function isClassKeyword(string $name): bool
	{
		return Validators::isClassKeyword($name);
	}

public static function getParameterDefaultValue(\ReflectionParameter $param): mixed
	{
		if ($param->isDefaultValueConstant()) {
			$const = $orig = $param->getDefaultValueConstantName() ?? throw new Nette\ShouldNotHappenException;
			$pair = explode('::', $const);
			if (isset($pair[1])) {
				$pair[0] = Type::resolve($pair[0], $param);
				try {
					$rcc = new \ReflectionClassConstant($pair[0], $pair[1]);
				} catch (\ReflectionException $e) {
					$name = self::toString($param);
					throw new \ReflectionException("Unable to resolve constant $orig used as default value of $name.", 0, $e);
				}

				return $rcc->getValue();

			} elseif (!defined($const)) {
				$const = substr((string) strrchr($const, '\\'), 1);
				if (!defined($const)) {
					$name = self::toString($param);
					throw new \ReflectionException("Unable to resolve constant $orig used as default value of $name.");
				}
			}

			return constant($const);
		}

		return $param->getDefaultValue();
	}

public static function getPropertyDeclaringClass(\ReflectionProperty $prop): \ReflectionClass
	{
		foreach ($prop->getDeclaringClass()->getTraits() as $trait) {
			if ($trait->hasProperty($prop->name)

				&& $trait->getProperty($prop->name)->getDocComment() === $prop->getDocComment()
			) {
				return self::getPropertyDeclaringClass($trait->getProperty($prop->name));
			}
		}

		return $prop->getDeclaringClass();
	}

public static function getMethodDeclaringMethod(\ReflectionMethod $method): \ReflectionMethod
	{

		$decl = $method->getDeclaringClass();
		if ($decl->getFileName() === $method->getFileName()
			&& $decl->getStartLine() <= $method->getStartLine()
			&& $decl->getEndLine() >= $method->getEndLine()
		) {
			return $method;
		}

		$hash = [$method->getFileName(), $method->getStartLine(), $method->getEndLine()];
		if (($alias = $decl->getTraitAliases()[$method->name] ?? null)
			&& ($m = new \ReflectionMethod(...explode('::', $alias, 2)))
			&& $hash === [$m->getFileName(), $m->getStartLine(), $m->getEndLine()]
		) {
			return self::getMethodDeclaringMethod($m);
		}

		foreach ($decl->getTraits() as $trait) {
			if ($trait->hasMethod($method->name)
				&& ($m = $trait->getMethod($method->name))
				&& $hash === [$m->getFileName(), $m->getStartLine(), $m->getEndLine()]
			) {
				return self::getMethodDeclaringMethod($m);
			}
		}

		return $method;
	}

public static function areCommentsAvailable(): bool
	{
		static $res;
		return $res ?? $res = (bool) (new \ReflectionMethod(self::class, __FUNCTION__))->getDocComment();
	}

public static function toString(\Reflector $ref): string
	{
		if ($ref instanceof \ReflectionClass) {
			return $ref->name;
		} elseif ($ref instanceof \ReflectionMethod) {
			return $ref->getDeclaringClass()->name . '::' . $ref->name . '()';
		} elseif ($ref instanceof \ReflectionFunction) {
			return $ref->isAnonymous() ? '{closure}()' : $ref->name . '()';
		} elseif ($ref instanceof \ReflectionProperty) {
			return self::getPropertyDeclaringClass($ref)->name . '::$' . $ref->name;
		} elseif ($ref instanceof \ReflectionParameter) {
			return '$' . $ref->name . ' in ' . self::toString($ref->getDeclaringFunction());
		} else {
			throw new Nette\InvalidArgumentException;
		}
	}

public static function expandClassName(string $name, \ReflectionClass $context): string
	{
		$lower = strtolower($name);
		if (empty($name)) {
			throw new Nette\InvalidArgumentException('Class name must not be empty.');

		} elseif (Validators::isBuiltinType($lower)) {
			return $lower;

		} elseif ($lower === 'self' || $lower === 'static') {
			return $context->name;

		} elseif ($lower === 'parent') {
			return $context->getParentClass()
				? $context->getParentClass()->name
				: 'parent';

		} elseif ($name[0] === '\\') {
			return ltrim($name, '\\');
		}

		$uses = self::getUseStatements($context);
		$parts = explode('\\', $name, 2);
		if (isset($uses[$parts[0]])) {
			$parts[0] = $uses[$parts[0]];
			return implode('\\', $parts);

		} elseif ($context->inNamespace()) {
			return $context->getNamespaceName() . '\\' . $name;

		} else {
			return $name;
		}
	}

public static function getUseStatements(\ReflectionClass $class): array
	{
		if ($class->isAnonymous()) {
			throw new Nette\NotImplementedException('Anonymous classes are not supported.');
		}

		static $cache = [];
		if (!isset($cache[$name = $class->name])) {
			if ($class->isInternal()) {
				$cache[$name] = [];
			} else {
				$code = (string) file_get_contents((string) $class->getFileName());
				$cache = self::parseUseStatements($code, $name) + $cache;
			}
		}

		return $cache[$name];
	}

private static function parseUseStatements(string $code, ?string $forClass = null): array
	{
		try {
			$tokens = \PhpToken::tokenize($code, TOKEN_PARSE);
		} catch (\ParseError $e) {
			trigger_error($e->getMessage());
			$tokens = [];
		}

		$namespace = $class = null;
		$classLevel = $level = 0;
		$res = $uses = [];

		$nameTokens = [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

		while ($token = current($tokens)) {
			next($tokens);
			switch ($token->id) {
				case T_NAMESPACE:
					$namespace = ltrim(self::fetch($tokens, $nameTokens) . '\\', '\\');
					$uses = [];
					break;

				case T_CLASS:
				case T_INTERFACE:
				case T_TRAIT:
				case T_ENUM:
					if ($name = self::fetch($tokens, T_STRING)) {
						$class = $namespace . $name;
						$classLevel = $level + 1;
						$res[$class] = $uses;
						if ($class === $forClass) {
							return $res;
						}
					}

					break;

				case T_USE:
					while (!$class && ($name = self::fetch($tokens, $nameTokens))) {
						$name = ltrim($name, '\\');
						if (self::fetch($tokens, '{')) {
							while ($suffix = self::fetch($tokens, $nameTokens)) {
								if (self::fetch($tokens, T_AS) && ($alias = self::fetch($tokens, T_STRING))) {
									$uses[$alias] = $name . $suffix;
								} else {
									$tmp = explode('\\', $suffix);
									$uses[end($tmp)] = $name . $suffix;
								}

								if (!self::fetch($tokens, ',')) {
									break;
								}
							}
						} elseif (self::fetch($tokens, T_AS) && ($alias = self::fetch($tokens, T_STRING))) {
							$uses[$alias] = $name;

						} else {
							$tmp = explode('\\', $name);
							$uses[end($tmp)] = $name;
						}

						if (!self::fetch($tokens, ',')) {
							break;
						}
					}

					break;

				case T_CURLY_OPEN:
				case T_DOLLAR_OPEN_CURLY_BRACES:
				case ord('{'):
					$level++;
					break;

				case ord('}'):
					if ($level === $classLevel) {
						$class = $classLevel = 0;
					}

					$level--;
			}
		}

		return $res;
	}

private static function fetch(array &$tokens, string|int|array $take): ?string
	{
		$res = null;
		while ($token = current($tokens)) {
			if ($token->is($take)) {
				$res .= $token->text;
			} elseif (!$token->is([T_DOC_COMMENT, T_WHITESPACE, T_COMMENT])) {
				break;
			}

			next($tokens);
		}

		return $res;
	}
}
}

// nette/utils/src/Utils/ReflectionMethod.php


namespace CMS\Vendor\Nette\Utils {

use function is_string;

final class ReflectionMethod extends \ReflectionMethod
{

	private readonly \ReflectionClass $originalClass;

public function __construct(object|string $objectOrMethod, ?string $method = null)
	{
		if (is_string($objectOrMethod) && str_contains($objectOrMethod, '::')) {
			[$objectOrMethod, $method] = explode('::', $objectOrMethod, 2);
		}
		parent::__construct($objectOrMethod, $method);
		$this->originalClass = new \ReflectionClass($objectOrMethod);
	}

public function getOriginalClass(): \ReflectionClass
	{
		return $this->originalClass;
	}
}
}

// nette/utils/src/Utils/Strings.php


namespace CMS\Vendor\Nette\Utils {

use JetBrains\PhpStorm\Language;
use CMS\Vendor\Nette;
use function defined, extension_loaded, function_exists, in_array, is_array, is_int, is_object, is_string, strlen;
use const ENT_IGNORE, ENT_NOQUOTES;

class Strings
{
	use Nette\StaticClass;

	public const TrimCharacters = " \t\n\r\0\x0B\u{A0}\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}\u{200B}\u{2028}\u{3000}";

	#[\Deprecated('use Strings::TrimCharacters')]
	public const TRIM_CHARACTERS = self::TrimCharacters;

public static function checkEncoding(string $s): bool
	{
		return $s === self::fixEncoding($s);
	}

public static function fixEncoding(string $s): string
	{

		return htmlspecialchars_decode(htmlspecialchars($s, ENT_NOQUOTES | ENT_IGNORE, 'UTF-8'), ENT_NOQUOTES);
	}

public static function chr(int $code): string
	{
		if ($code < 0 || ($code >= 0xD800 && $code <= 0xDFFF) || $code > 0x10FFFF) {
			throw new Nette\InvalidArgumentException('Code point must be in range 0x0 to 0xD7FF or 0xE000 to 0x10FFFF.');
		} elseif (!extension_loaded('iconv')) {
			throw new Nette\NotSupportedException(__METHOD__ . '() requires ICONV extension that is not loaded.');
		}

		$res = iconv('UTF-32BE', 'UTF-8//IGNORE', pack('N', $code));
		return $res === false ? throw new Nette\ShouldNotHappenException : $res;
	}

public static function ord(string $c): int
	{
		if (!extension_loaded('iconv')) {
			throw new Nette\NotSupportedException(__METHOD__ . '() requires ICONV extension that is not loaded.');
		}

		$tmp = iconv('UTF-8', 'UTF-32BE//IGNORE', $c);
		if ($tmp === false || $tmp === '') {
			throw new Nette\InvalidArgumentException('Invalid UTF-8 character "' . ($c === '' ? '' : '\x' . strtoupper(bin2hex($c))) . '".');
		}

		return unpack('N', $tmp)[1] ?? throw new Nette\ShouldNotHappenException;
	}

public static function startsWith(string $haystack, string $needle): bool
	{
		return str_starts_with($haystack, $needle);
	}

public static function endsWith(string $haystack, string $needle): bool
	{
		return str_ends_with($haystack, $needle);
	}

public static function contains(string $haystack, string $needle): bool
	{
		return str_contains($haystack, $needle);
	}

public static function substring(string $s, int $start, ?int $length = null): string
	{
		if (function_exists('mb_substr')) {
			return mb_substr($s, $start, $length, 'UTF-8');
		} elseif (!extension_loaded('iconv')) {
			throw new Nette\NotSupportedException(__METHOD__ . '() requires extension ICONV or MBSTRING, neither is loaded.');
		} elseif ($length === null) {
			$length = self::length($s);
		} elseif ($start < 0 && $length < 0) {
			$start += self::length($s);
		}

		$res = iconv_substr($s, $start, $length, 'UTF-8');
		return $res === false ? throw new Nette\InvalidStateException('iconv_substr() failed.') : $res;
	}

public static function normalize(string $s): string
	{

		if (class_exists(\Normalizer::class, false) && ($n = \Normalizer::normalize($s, \Normalizer::FORM_C)) !== false) {
			$s = $n;
		}

		$s = self::unixNewLines($s);

$s = self::pcre('preg_replace', ['#[\x00-\x08\x0B-\x1F\x7F-\x9F]+#u', '', $s]);

$s = self::pcre('preg_replace', ['#[\t ]+$#m', '', $s]);

$s = trim($s, "\n");

		return $s;
	}

public static function normalizeNewLines(string $s): string
	{
		return self::unixNewLines($s);
	}

public static function unixNewLines(string $s): string
	{
		return preg_replace("~\r\n?|\u{2028}|\u{2029}~", "\n", $s);
	}

public static function platformNewLines(string $s): string
	{
		return preg_replace("~\r\n?|\n|\u{2028}|\u{2029}~", PHP_EOL, $s);
	}

public static function toAscii(string $s): string
	{
		if (!extension_loaded('intl')) {
			throw new Nette\NotSupportedException(__METHOD__ . '() requires INTL extension that is not loaded.');
		}

		$iconv = defined('ICONV_IMPL') ? trim(ICONV_IMPL, '"\'') : null;

$s = self::pcre('preg_replace', ['#[^\x09\x0A\x0D\x20-\x7E\xA0-\x{2FF}\x{370}-\x{10FFFF}]#u', '', $s]);

$s = strtr($s, [
			"\u{201E}" => '"', "\u{201C}" => '"', "\u{201D}" => '"', "\u{201A}" => "'", "\u{2018}" => "'", "\u{2019}" => "'",
			"\u{B0}" => '^', "\u{42F}" => 'Ya', "\u{44F}" => 'ya', "\u{42E}" => 'Yu', "\u{44E}" => 'yu', "\u{c4}" => 'Ae', "\u{d6}" => 'Oe',
			"\u{dc}" => 'Ue', "\u{1e9e}" => 'Ss', "\u{e4}" => 'ae', "\u{f6}" => 'oe', "\u{fc}" => 'ue', "\u{df}" => 'ss',
		]);
		if ($iconv !== 'libiconv') {
			$s = strtr($s, [
				"\u{AE}" => '(R)', "\u{A9}" => '(c)', "\u{2026}" => '...', "\u{AB}" => '<<', "\u{BB}" => '>>', "\u{A3}" => 'lb',
				"\u{A5}" => 'yen', "\u{B2}" => '^2', "\u{B3}" => '^3', "\u{B5}" => 'u', "\u{B9}" => '^1', "\u{BA}" => 'o', "\u{BF}" => '?',
				"\u{2CA}" => "'", "\u{2CD}" => '_', "\u{2DD}" => '"', "\u{1FEF}" => '', "\u{20AC}" => 'EUR', "\u{2122}" => 'TM',
				"\u{212E}" => 'e', "\u{2190}" => '<-', "\u{2191}" => '^', "\u{2192}" => '->', "\u{2193}" => 'V', "\u{2194}" => '<->',
			]);
		}

		$s = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($s)
			?? throw new Nette\InvalidStateException('Transliterator::transliterate() failed.');

if ($iconv === 'glibc') {
			$s = strtr($s, '?', "\x01");
			$s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if ($s === false) {
				throw new Nette\InvalidStateException('iconv() failed.');
			}

			$s = str_replace(['?', "\x01"], ['', '?'], $s);

		} elseif ($iconv === 'libiconv') {
			$s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if ($s === false) {
				throw new Nette\InvalidStateException('iconv() failed.');
			}
		} else {
			$s = self::pcre('preg_replace', ['#[^\x00-\x7F]++#', '', $s]);
		}

		return $s;
	}

public static function webalize(string $s, ?string $charlist = null, bool $lower = true): string
	{
		$s = self::toAscii($s);
		if ($lower) {
			$s = strtolower($s);
		}

		$s = self::pcre('preg_replace', ['#[^a-z0-9' . ($charlist !== null ? preg_quote($charlist, '#') : '') . ']+#i', '-', $s]);
		$s = trim($s, '-');
		return $s;
	}

public static function truncate(string $s, int $maxLen, string $append = "\u{2026}"): string
	{
		if (self::length($s) > $maxLen) {
			$maxLen -= self::length($append);
			if ($maxLen < 1) {
				return $append;

			} elseif ($matches = self::match($s, '#^.{1,' . $maxLen . '}(?=[\s\x00-/:-@\[-`{-~])#us')) {
				return $matches[0] . $append;

			} else {
				return self::substring($s, 0, $maxLen) . $append;
			}
		}

		return $s;
	}

public static function indent(string $s, int $level = 1, string $chars = "\t"): string
	{
		if ($level > 0) {
			$s = self::replace($s, '#(?:^|[\r\n]+)(?=[^\r\n])#', '$0' . str_repeat($chars, $level));
		}

		return $s;
	}

public static function lower(string $s): string
	{
		return mb_strtolower($s, 'UTF-8');
	}

public static function firstLower(string $s): string
	{
		return self::lower(self::substring($s, 0, 1)) . self::substring($s, 1);
	}

public static function upper(string $s): string
	{
		return mb_strtoupper($s, 'UTF-8');
	}

public static function firstUpper(string $s): string
	{
		return self::upper(self::substring($s, 0, 1)) . self::substring($s, 1);
	}

public static function capitalize(string $s): string
	{
		return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
	}

public static function compare(string $left, string $right, ?int $length = null): bool
	{
		if (class_exists(\Normalizer::class, false)) {
			$left = \Normalizer::normalize($left, \Normalizer::FORM_D) ?: $left;
			$right = \Normalizer::normalize($right, \Normalizer::FORM_D) ?: $right;
		}

		if ($length < 0) {
			$left = self::substring($left, $length, -$length);
			$right = self::substring($right, $length, -$length);
		} elseif ($length !== null) {
			$left = self::substring($left, 0, $length);
			$right = self::substring($right, 0, $length);
		}

		return self::lower($left) === self::lower($right);
	}

public static function findPrefix(array $strings): string
	{
		$first = array_shift($strings);
		if ($first === null) {
			return '';
		}

		for ($i = 0; $i < strlen($first); $i++) {
			foreach ($strings as $s) {
				if (!isset($s[$i]) || $first[$i] !== $s[$i]) {
					while ($i && $first[$i - 1] >= "\x80" && $first[$i] >= "\x80" && $first[$i] < "\xC0") {
						$i--;
					}

					return substr($first, 0, $i);
				}
			}
		}

		return $first;
	}

public static function length(string $s): int
	{
		return match (true) {
			extension_loaded('mbstring') => (int) mb_strlen($s, 'UTF-8'),
			extension_loaded('iconv') => (int) iconv_strlen($s, 'UTF-8'),
			default => strlen((string) preg_replace('#[\x80-\xBF]#', '', $s)),
		};
	}

public static function trim(string $s, string $charlist = self::TrimCharacters): string
	{
		$charlist = preg_quote($charlist, '#');
		return self::replace($s, '#^[' . $charlist . ']+|[' . $charlist . ']+$#Du');
	}

public static function padLeft(string $s, int $length, string $pad = ' '): string
	{
		$length = max(0, $length - self::length($s));
		$padLen = self::length($pad);
		return str_repeat($pad, (int) ($length / $padLen)) . self::substring($pad, 0, $length % $padLen) . $s;
	}

public static function padRight(string $s, int $length, string $pad = ' '): string
	{
		$length = max(0, $length - self::length($s));
		$padLen = self::length($pad);
		return $s . str_repeat($pad, (int) ($length / $padLen)) . self::substring($pad, 0, $length % $padLen);
	}

public static function reverse(string $s): string
	{
		if (!extension_loaded('iconv')) {
			throw new Nette\NotSupportedException(__METHOD__ . '() requires ICONV extension that is not loaded.');
		}

		$tmp = iconv('UTF-8', 'UTF-32BE', $s);
		return $tmp === false
			? throw new Nette\InvalidStateException('iconv() failed.')
			: (string) iconv('UTF-32LE', 'UTF-8', strrev($tmp));
	}

public static function before(string $haystack, string $needle, int $nth = 1): ?string
	{
		$pos = self::pos($haystack, $needle, $nth);
		return $pos === null
			? null
			: substr($haystack, 0, $pos);
	}

public static function after(string $haystack, string $needle, int $nth = 1): ?string
	{
		$pos = self::pos($haystack, $needle, $nth);
		return $pos === null
			? null
			: substr($haystack, $pos + strlen($needle));
	}

public static function indexOf(string $haystack, string $needle, int $nth = 1): ?int
	{
		$pos = self::pos($haystack, $needle, $nth);
		return $pos === null
			? null
			: self::length(substr($haystack, 0, $pos));
	}

private static function pos(string $haystack, string $needle, int $nth = 1): ?int
	{
		if (!$nth) {
			return null;
		} elseif ($nth > 0) {
			if ($needle === '') {
				return 0;
			}

			$pos = 0;
			while (($pos = strpos($haystack, $needle, $pos)) !== false && --$nth) {
				$pos++;
			}
		} else {
			$len = strlen($haystack);
			if ($needle === '') {
				return $len;
			} elseif ($len === 0) {
				return null;
			}

			$pos = $len - 1;
			while (($pos = strrpos($haystack, $needle, $pos - $len)) !== false && ++$nth) {
				$pos--;
			}
		}

		return Helpers::falseToNull($pos);
	}

public static function split(
		string $subject,
		#[Language('RegExp')]
		string $pattern,
		bool|int $captureOffset = false,
		bool $skipEmpty = false,
		int $limit = -1,
		bool $utf8 = false,
	): array
	{
		$flags = is_int($captureOffset)
			? $captureOffset
			: ($captureOffset ? PREG_SPLIT_OFFSET_CAPTURE : 0) | ($skipEmpty ? PREG_SPLIT_NO_EMPTY : 0);

		$pattern .= $utf8 ? 'u' : '';
		$m = self::pcre('preg_split', [$pattern, $subject, $limit, $flags | PREG_SPLIT_DELIM_CAPTURE]);
		return $utf8 && $captureOffset
			? self::bytesToChars($subject, [$m])[0]
			: $m;
	}

public static function match(
		string $subject,
		#[Language('RegExp')]
		string $pattern,
		bool|int $captureOffset = false,
		int $offset = 0,
		bool $unmatchedAsNull = false,
		bool $utf8 = false,
	): ?array
	{
		$flags = is_int($captureOffset)
			? $captureOffset
			: ($captureOffset ? PREG_OFFSET_CAPTURE : 0) | ($unmatchedAsNull ? PREG_UNMATCHED_AS_NULL : 0);

		if ($utf8) {
			$offset = strlen(self::substring($subject, 0, $offset));
			$pattern .= 'u';
		}

		$m = [];
		if ($offset > strlen($subject)) {
			return null;
		} elseif (!self::pcre('preg_match', [$pattern, $subject, &$m, $flags, $offset])) {
			return null;
		} elseif ($utf8 && $captureOffset) {
			return self::bytesToChars($subject, [$m])[0];
		} else {
			return $m;
		}
	}

public static function matchAll(
		string $subject,
		#[Language('RegExp')]
		string $pattern,
		bool|int $captureOffset = false,
		int $offset = 0,
		bool $unmatchedAsNull = false,
		bool $patternOrder = false,
		bool $utf8 = false,
		bool $lazy = false,
	): array|\Generator
	{
		if ($utf8) {
			$offset = strlen(self::substring($subject, 0, $offset));
			$pattern .= 'u';
		}

		if ($lazy) {
			$flags = PREG_OFFSET_CAPTURE | ($unmatchedAsNull ? PREG_UNMATCHED_AS_NULL : 0);
			return (function () use ($utf8, $captureOffset, $flags, $subject, $pattern, $offset) {
				$counter = 0;
				$m = [];
				while (
					$offset <= strlen($subject) - ($counter ? 1 : 0)
					&& self::pcre('preg_match', [$pattern, $subject, &$m, $flags, $offset])
				) {

					$offset = $m[0][1] + max(1, strlen($m[0][0]));
					if (!$captureOffset) {
						$m = array_map(fn($item) => $item[0], $m);
					} elseif ($utf8) {
						$m = self::bytesToChars($subject, [$m])[0];
					}
					yield $counter++ => $m;
				}
			})();
		}

		if ($offset > strlen($subject)) {
			return [];
		}

		$flags = is_int($captureOffset)
			? $captureOffset
			: ($captureOffset ? PREG_OFFSET_CAPTURE : 0)
				| ($unmatchedAsNull ? PREG_UNMATCHED_AS_NULL : 0)
				| ($patternOrder ? PREG_PATTERN_ORDER : 0);

		$m = [];
		self::pcre('preg_match_all', [
			$pattern, $subject, &$m,
			($flags & PREG_PATTERN_ORDER) ? $flags : ($flags | PREG_SET_ORDER),
			$offset,
		]);
		return $utf8 && $captureOffset
			? self::bytesToChars($subject, $m)
			: $m;
	}

public static function replace(
		string $subject,
		#[Language('RegExp')]
		string|array $pattern,
		string|callable $replacement = '',
		int $limit = -1,
		bool $captureOffset = false,
		bool $unmatchedAsNull = false,
		bool $utf8 = false,
	): string
	{
		if (is_object($replacement) || is_array($replacement)) {
			if (!is_callable($replacement, false, $textual)) {
				throw new Nette\InvalidStateException("Callback '$textual' is not callable.");
			}

			$flags = ($captureOffset ? PREG_OFFSET_CAPTURE : 0) | ($unmatchedAsNull ? PREG_UNMATCHED_AS_NULL : 0);
			if ($utf8) {
				$pattern = is_array($pattern) ? array_map(fn($item) => $item . 'u', $pattern) : $pattern . 'u';
				if ($captureOffset) {
					$replacement = fn($m) => $replacement(self::bytesToChars($subject, [$m])[0]);
				}
			}

			return self::pcre('preg_replace_callback', [$pattern, $replacement, $subject, $limit, 0, $flags]);

		} elseif (is_array($pattern) && is_string(key($pattern))) {
			$replacement = array_values($pattern);
			$pattern = array_keys($pattern);
		}

		if ($utf8) {
			$pattern = array_map(fn($item) => $item . 'u', (array) $pattern);
		}

		return self::pcre('preg_replace', [$pattern, $replacement, $subject, $limit]);
	}

private static function bytesToChars(string $s, array $groups): array
	{
		$lastBytes = $lastChars = 0;
		foreach ($groups as &$matches) {
			foreach ($matches as &$match) {
				if ($match[1] > $lastBytes) {
					$lastChars += self::length(substr($s, $lastBytes, $match[1] - $lastBytes));
				} elseif ($match[1] < $lastBytes) {
					$lastChars -= self::length(substr($s, $match[1], $lastBytes - $match[1]));
				}

				$lastBytes = $match[1];
				$match[1] = $lastChars;
			}
		}

		return $groups;
	}

public static function pcre(string $func, array $args): mixed
	{
		$res = Callback::invokeSafe($func, $args, function (string $message) use ($args): void {

			throw new RegexpException($message . ' in pattern: ' . implode(' or ', (array) $args[0]));
		});

		if (($code = preg_last_error())
			&& ($res === null || !in_array($func, ['preg_filter', 'preg_replace_callback', 'preg_replace'], true))
		) {
			throw new RegexpException(preg_last_error_msg()
				. ' (pattern: ' . implode(' or ', (array) $args[0]) . ')', $code);
		}

		return $res;
	}
}
}

// nette/utils/src/Utils/Type.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette;
use function count, is_resource, is_string;

final readonly class Type
{

	private array $types;
	private ?string $singleName;
	private string $kind;

public static function fromReflection(
		\ReflectionFunctionAbstract|\ReflectionParameter|\ReflectionProperty $reflection,
	): ?self
	{
		$type = $reflection instanceof \ReflectionFunctionAbstract
			? $reflection->getReturnType() ?? ($reflection instanceof \ReflectionMethod ? $reflection->getTentativeReturnType() : null)
			: $reflection->getType();

		return $type ? self::fromReflectionType($type, $reflection, asObject: true) : null;
	}

private static function fromReflectionType(
		\ReflectionType $type,
		\ReflectionFunctionAbstract|\ReflectionParameter|\ReflectionProperty $of,
		bool $asObject,
	): self|string
	{
		if ($type instanceof \ReflectionNamedType) {
			$name = self::resolve($type->getName(), $of);
			return $asObject
				? new self($type->allowsNull() && $name !== 'mixed' ? [$name, 'null'] : [$name])
				: $name;

		} elseif ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
			return new self(
				array_map(fn($t) => self::fromReflectionType($t, $of, asObject: false), $type->getTypes()),
				$type instanceof \ReflectionUnionType ? '|' : '&',
			);

		} else {
			throw new Nette\InvalidStateException('Unexpected type of ' . Reflection::toString($of));
		}
	}

public static function fromString(string $type): self
	{
		if (!Validators::isTypeDeclaration($type)) {
			throw new Nette\InvalidArgumentException("Invalid type '$type'.");
		}

		if ($type[0] === '?') {
			return new self([substr($type, 1), 'null']);
		}

		$unions = [];
		foreach (explode('|', $type) as $part) {
			$part = explode('&', trim($part, '()'));
			$unions[] = count($part) === 1 ? $part[0] : new self($part, '&');
		}

		return count($unions) === 1 && $unions[0] instanceof self
			? $unions[0]
			: new self($unions);
	}

public static function fromValue(mixed $value): self
	{
		$type = get_debug_type($value);
		if (is_resource($value)) {
			$type = 'mixed';
		} elseif (str_ends_with($type, '@anonymous')) {
			$parent = substr($type, 0, -10);
			$type = $parent === 'class' ? 'object' : $parent;
		}

		return new self([$type]);
	}

public static function resolve(
		string $type,
		\ReflectionFunction|\ReflectionMethod|\ReflectionParameter|\ReflectionProperty $of,
	): string
	{
		$lower = strtolower($type);
		if ($of instanceof \ReflectionFunction) {
			return $type;
		}

		$class = $of->getDeclaringClass();
		if ($class === null) {
			return $type;
		} elseif ($lower === 'self') {
			return $class->name;
		} elseif ($lower === 'static') {
			return ($of instanceof ReflectionMethod ? $of->getOriginalClass() : $class)->name;
		} elseif ($lower === 'parent' && $class->getParentClass()) {
			return $class->getParentClass()->name;
		} else {
			return $type;
		}
	}

private function __construct(array $types, string $kind = '|')
	{
		$o = array_search('null', $types, strict: true);
		if ($o !== false) {
			array_splice($types, (int) $o, 1);
			$types[] = 'null';
		}

		$this->types = array_values($types);
		$this->singleName = is_string($types[0]) && ($types[1] ?? 'null') === 'null' ? $types[0] : null;
		$this->kind = count($types) > 1 ? $kind : '';
	}

public function __toString(): string
	{
		$multi = count($this->types) > 1;
		if ($this->singleName !== null) {
			return ($multi ? '?' : '') . $this->singleName;
		}

		$res = [];
		foreach ($this->types as $type) {
			$res[] = $type instanceof self && $multi ? "($type)" : $type;
		}
		return implode($this->kind, $res);
	}

public function with(string|self $type): self
	{
		$type = is_string($type) ? self::fromString($type) : $type;
		return match (true) {
			$this->allows($type) => $this,
			$type->allows($this) => $type,
			default => new self(array_unique(
				[...$this->isIntersection() ? [$this] : $this->types, ...$type->isIntersection() ? [$type] : $type->types],
				SORT_REGULAR,
			)),
		};
	}

public function getNames(): array
	{
		return array_map(fn($t) => $t instanceof self ? $t->getNames() : $t, $this->types);
	}

public function getTypes(): array
	{
		return array_map(fn($t) => $t instanceof self ? $t : new self([$t]), $this->types);
	}

public function getSingleName(): ?string
	{
		return $this->singleName;
	}

public function isUnion(): bool
	{
		return $this->kind === '|';
	}

public function isIntersection(): bool
	{
		return $this->kind === '&';
	}

public function isSimple(): bool
	{
		return $this->singleName !== null;
	}

#[\Deprecated('use isSimple()')]
	public function isSingle(): bool
	{
		return $this->singleName !== null;
	}

public function isBuiltin(): bool
	{
		return $this->singleName !== null && Validators::isBuiltinType($this->singleName);
	}

public function isClass(): bool
	{
		return $this->singleName !== null && !Validators::isBuiltinType($this->singleName);
	}

public function isClassKeyword(): bool
	{
		return $this->singleName !== null && Validators::isClassKeyword($this->singleName);
	}

public function allows(string|self $type): bool
	{
		if ($this->types === ['mixed']) {
			return true;
		}

		$type = is_string($type) ? self::fromString($type) : $type;
		return $type->isUnion()
			? Arrays::every($type->types, fn($t) => $this->allowsAny($t instanceof self ? $t->types : [$t]))
			: $this->allowsAny($type->types);
	}

private function allowsAny(array $givenTypes): bool
	{
		return $this->isUnion()
			? Arrays::some($this->types, fn($t) => $this->allowsAll($t instanceof self ? $t->types : [$t], $givenTypes))
			: $this->allowsAll($this->types, $givenTypes);
	}

private function allowsAll(array $ourTypes, array $givenTypes): bool
	{
		return Arrays::every(
			$ourTypes,
			fn(string $ourType) => Arrays::some(
				$givenTypes,
				fn(string $givenType) => Validators::isBuiltinType($ourType)
					? strcasecmp($ourType, $givenType) === 0
					: is_a($givenType, $ourType, allow_string: true),
			),
		);
	}
}
}

// nette/utils/src/Utils/Validators.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette;
use function array_key_exists, gettype, is_float, is_int, is_object, is_string, strlen;

class Validators
{
	use Nette\StaticClass;

	private const BuiltinTypes = [
		'string' => 1, 'int' => 1, 'float' => 1, 'bool' => 1, 'array' => 1, 'object' => 1,
		'callable' => 1, 'iterable' => 1, 'void' => 1, 'null' => 1, 'mixed' => 1, 'false' => 1,
		'never' => 1, 'true' => 1,
	];

protected static $validators = [

		'array' => 'is_array',
		'bool' => 'is_bool',
		'boolean' => 'is_bool',
		'float' => 'is_float',
		'int' => 'is_int',
		'integer' => 'is_int',
		'null' => 'is_null',
		'object' => 'is_object',
		'resource' => 'is_resource',
		'scalar' => 'is_scalar',
		'string' => 'is_string',

'callable' => [self::class, 'isCallable'],
		'iterable' => 'is_iterable',
		'list' => [Arrays::class, 'isList'],
		'mixed' => [self::class, 'isMixed'],
		'none' => [self::class, 'isNone'],
		'number' => [self::class, 'isNumber'],
		'numeric' => [self::class, 'isNumeric'],
		'numericint' => [self::class, 'isNumericInt'],

'alnum' => 'ctype_alnum',
		'alpha' => 'ctype_alpha',
		'digit' => 'ctype_digit',
		'lower' => 'ctype_lower',
		'pattern' => null,
		'space' => 'ctype_space',
		'unicode' => [self::class, 'isUnicode'],
		'upper' => 'ctype_upper',
		'xdigit' => 'ctype_xdigit',

'email' => [self::class, 'isEmail'],
		'identifier' => [self::class, 'isPhpIdentifier'],
		'uri' => [self::class, 'isUri'],
		'url' => [self::class, 'isUrl'],

'class' => 'class_exists',
		'interface' => 'interface_exists',
		'directory' => 'is_dir',
		'file' => 'is_file',
		'type' => [self::class, 'isType'],
	];

protected static $counters = [
		'string' => 'strlen',
		'unicode' => [Strings::class, 'length'],
		'array' => 'count',
		'list' => 'count',
		'alnum' => 'strlen',
		'alpha' => 'strlen',
		'digit' => 'strlen',
		'lower' => 'strlen',
		'space' => 'strlen',
		'upper' => 'strlen',
		'xdigit' => 'strlen',
	];

public static function assert(mixed $value, string $expected, string $label = 'variable'): void
	{
		if (!static::is($value, $expected)) {
			$expected = str_replace(['|', ':'], [' or ', ' in range '], $expected);
			$translate = ['boolean' => 'bool', 'integer' => 'int', 'double' => 'float', 'NULL' => 'null'];
			$type = $translate[gettype($value)] ?? gettype($value);
			if (is_int($value) || is_float($value) || (is_string($value) && strlen($value) < 40)) {
				$type .= ' ' . var_export($value, return: true);
			} elseif (is_object($value)) {
				$type .= ' ' . $value::class;
			}

			throw new AssertionException("The $label expects to be $expected, $type given.");
		}
	}

public static function assertField(
		array $array,
		int|string $key,
		?string $expected = null,
		string $label = "item '%' in array",
	): void
	{
		if (!array_key_exists($key, $array)) {
			throw new AssertionException('Missing ' . str_replace('%', (string) $key, $label) . '.');

		} elseif ($expected) {
			static::assert($array[$key], $expected, str_replace('%', (string) $key, $label));
		}
	}

public static function is(mixed $value, string $expected): bool
	{
		foreach (explode('|', $expected) as $item) {
			if (str_ends_with($item, '[]')) {
				if (is_iterable($value) && self::everyIs($value, substr($item, 0, -2))) {
					return true;
				}

				continue;

			} elseif (str_starts_with($item, '?')) {
				$item = substr($item, 1);
				if ($value === null) {
					return true;
				}
			}

			[$type] = $item = explode(':', $item, 2);
			if (isset(static::$validators[$type])) {
				try {
					if (!static::$validators[$type]($value)) {
						continue;
					}
				} catch (\TypeError) {
					continue;
				}
			} elseif ($type === 'pattern') {
				if (Strings::match($value, '|^' . ($item[1] ?? '') . '$|D')) {
					return true;
				}

				continue;

			} elseif (!$value instanceof $type) {
				continue;
			}

			if (isset($item[1])) {
				$length = $value;
				if (isset(static::$counters[$type])) {
					$length = static::$counters[$type]($value);
				}

				$range = explode('..', $item[1]);
				if (!isset($range[1])) {
					$range[1] = $range[0];
				}

				if (($range[0] !== '' && $length < $range[0]) || ($range[1] !== '' && $length > $range[1])) {
					continue;
				}
			}

			return true;
		}

		return false;
	}

public static function everyIs(iterable $values, string $expected): bool
	{
		foreach ($values as $value) {
			if (!static::is($value, $expected)) {
				return false;
			}
		}

		return true;
	}

public static function isNumber(mixed $value): bool
	{
		return is_int($value) || is_float($value);
	}

public static function isNumericInt(mixed $value): bool
	{
		return is_int($value) || (is_string($value) && preg_match('#^[+-]?[0-9]+$#D', $value));
	}

public static function isNumeric(mixed $value): bool
	{
		return is_float($value) || is_int($value) || (is_string($value) && preg_match('#^[+-]?([0-9]++\.?[0-9]*|\.[0-9]+)$#D', $value));
	}

public static function isCallable(mixed $value): bool
	{
		return $value && is_callable($value, syntax_only: true);
	}

public static function isUnicode(mixed $value): bool
	{
		return is_string($value) && preg_match('##u', $value);
	}

public static function isNone(mixed $value): bool
	{
		return $value == null;
	}

public static function isMixed(): bool
	{
		return true;
	}

public static function isList(mixed $value): bool
	{
		return Arrays::isList($value);
	}

public static function isInRange(mixed $value, array $range): bool
	{
		if ($value === null || !(isset($range[0]) || isset($range[1]))) {
			return false;
		}

		$limit = $range[0] ?? $range[1];
		if (is_string($limit)) {
			$value = (string) $value;
		} elseif ($limit instanceof \DateTimeInterface) {
			if (!$value instanceof \DateTimeInterface) {
				return false;
			}
		} elseif (is_numeric($value)) {
			$value *= 1;
		} else {
			return false;
		}

		return (!isset($range[0]) || ($value >= $range[0])) && (!isset($range[1]) || ($value <= $range[1]));
	}

public static function isEmail(string $value): bool
	{
		$atom = "[-a-z0-9!#$%&'*+/=?^_`{|}~]";
		$alpha = "a-z\x80-\xFF";
		return (bool) preg_match(<<<XX
			(^(?n)
				("([ !#-[\\]-~]*|\\\\[ -~])+"|$atom+(\\.$atom+)*)  # quoted or unquoted
				@
				([0-9$alpha]([-0-9$alpha]{0,61}[0-9$alpha])?\\.)+  # domain - RFC 1034
				[$alpha]([-0-9$alpha]{0,17}[$alpha])?              # top domain
			$)Dix
			XX, $value);
	}

public static function isUrl(string $value): bool
	{
		$alpha = "a-z\x80-\xFF";
		$octet = '(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])';
		return (bool) preg_match(<<<XX
			(^(?n)
				https?://(
					(([-_0-9$alpha]+\\.)*                       # subdomain
						[0-9$alpha]([-0-9$alpha]{0,61}[0-9$alpha])?\\.)?  # domain
						[$alpha]([-0-9$alpha]{0,17}[$alpha])?   # top domain
					|$octet(\\.$octet){3}                       # IPv4
					|\\[[0-9a-f:]{3,39}]                        # IPv6
				)(:\\d{1,5})?                                   # port
				(/\\S*)?                                        # path
				(\\?\\S*)?                                      # query
				(\\#\\S*)?                                      # fragment
			$)Dix
			XX, $value);
	}

public static function isUri(string $value): bool
	{
		return (bool) preg_match('#^[a-z\d+.-]+:\S+$#Di', $value);
	}

public static function isType(string $type): bool
	{
		return class_exists($type) || interface_exists($type) || trait_exists($type);
	}

public static function isPhpIdentifier(string $value): bool
	{
		return preg_match('#^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#D', $value) === 1;
	}

public static function isBuiltinType(string $type): bool
	{
		return isset(self::BuiltinTypes[strtolower($type)]);
	}

public static function isClassKeyword(string $name): bool
	{
		return (bool) preg_match('#^(self|parent|static)$#Di', $name);
	}

public static function isTypeDeclaration(string $type): bool
	{
		return (bool) preg_match(<<<'XX'
			~((?n)
				\?? (?<type> \\? (?<name> [a-zA-Z_\x7f-\xff][\w\x7f-\xff]*) (\\ (?&name))* ) |
				(?<intersection> (?&type) (& (?&type))+ ) |
				(?<upart> (?&type) | \( (?&intersection) \) )  (\| (?&upart))+
			)$~xAD
			XX, $type);
	}
}
}

// nette/utils/src/Utils/exceptions.php


namespace CMS\Vendor\Nette\Utils {

class ImageException extends \Exception
{
}

class UnknownImageFileException extends ImageException
{
}

class JsonException extends \JsonException
{
}

class RegexpException extends \Exception
{
}

class AssertionException extends \Exception
{
}

class ProcessFailedException extends \RuntimeException
{
}

class ProcessTimeoutException extends \RuntimeException
{
}
}

// nette/utils/src/compatibility.php


namespace CMS\Vendor\Nette\Utils {

use CMS\Vendor\Nette;

if (false) {

	interface IHtmlString extends Nette\HtmlStringable
	{
	}
} elseif (!interface_exists(IHtmlString::class)) {
	class_alias(Nette\HtmlStringable::class, IHtmlString::class);
}
}
namespace CMS\Vendor\Nette\Localization {

if (false) {

	interface ITranslator extends Translator
	{
	}
} elseif (!interface_exists(ITranslator::class)) {
	class_alias(Translator::class, ITranslator::class);
}
}
