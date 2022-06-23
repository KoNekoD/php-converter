<?php

declare(strict_types=1);

namespace Riverwaysoft\DtoConverter\Converter;

use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Riverwaysoft\DtoConverter\ClassFilter\ClassFilterInterface;
use Riverwaysoft\DtoConverter\Dto\DtoList;

/**
 * It converts PHP code string into a normalized DTO list suitable for converting into other languages
 */
class Converter
{
    private Parser $parser;
    private PhpDocTypeParser $phpDocTypeParser;

    public function __construct(
        private ?ClassFilterInterface $classFilter = null,
    )
    {
        $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $this->phpDocTypeParser = new PhpDocTypeParser();
    }

    /** @param string[]|iterable $listings */
    public function convert(iterable $listings): DtoList
    {
        $dtoList = new DtoList();

        foreach ($listings as $listing) {
            $dtoList->merge($this->normalize($listing));
        }

        return $dtoList;
    }

    private function normalize(string $code): DtoList
    {
        $ast = $this->parser->parse($code);
        $dtoList = new DtoList();
        $visitor = new AstVisitor($dtoList, $this->phpDocTypeParser, $this->classFilter);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $dtoList;
    }
}
