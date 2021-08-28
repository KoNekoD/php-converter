<?php

declare(strict_types=1);

namespace Riverwaysoft\DtoConverter\Language\TypeScript;

use Riverwaysoft\DtoConverter\Dto\DtoList;
use Riverwaysoft\DtoConverter\Dto\DtoType;
use Riverwaysoft\DtoConverter\Dto\SingleType;
use Riverwaysoft\DtoConverter\Language\UnknownTypeResolverInterface;

class DateTimeTypeResolver implements UnknownTypeResolverInterface
{
    public function supports(SingleType $type, DtoType $dto, DtoList $dtoList): bool
    {
        return $type->getName() === 'DateTime' || $type->getName() === 'DateTimeImmutable';
    }

    public function resolve(SingleType $type, DtoType $dto, DtoList $dtoList): string
    {
        return 'string';
    }
}
