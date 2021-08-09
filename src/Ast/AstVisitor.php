<?php

declare(strict_types=1);

namespace App\Ast;

use App\AnnotationChecker\AnnotationCheckerInterface;
use App\Dto\DtoList;
use App\Dto\DtoProperty;
use App\Dto\DtoType;
use App\Dto\SingleType;
use App\Dto\UnionType;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class AstVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private DtoList $dtoList,
        /** @var AnnotationCheckerInterface[] $annotationCheckers */
        private array $annotationCheckers,
    ) {
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            foreach ($this->annotationCheckers as $dtoAnnotationChecker) {
                if ($dtoAnnotationChecker->hasDtoAttribute($node)) {
                    $this->createDtoType($node);
                    break;
                }
            }
        }
    }

    private function createDtoType(Node\Stmt\Class_ $node)
    {
        $name = $node->name->name;

        $properties = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                $type = match (get_class($stmt->type)) {
                    Node\UnionType::class => new UnionType(array_map(fn ($type) => new SingleType($type->name), $stmt->type->types)),
                    Node\NullableType::class => UnionType::nullable(new SingleType($stmt->type->type->name)),
                    default => new SingleType($stmt->type->name),
                };

                $properties[] = new DtoProperty(
                    type: $type,
                    name: $stmt->props[0]->name->name,
                );
            }
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                foreach ($stmt->params as $param) {
                    $type = match (get_class($param)) {
                        Node\UnionType::class => new UnionType(array_map(fn ($type) => new SingleType($type->name), $param->types)),
                        Node\NullableType::class => UnionType::nullable(new SingleType($param->type->type->name)),
                        default => new SingleType($param->type->name),
                    };

                    $properties[] = new DtoProperty(
                        type: $type,
                        name: $param->var->name,
                    );
                }
            }
        }

        $this->dtoList->addDto(new DtoType(title: $name, properties: $properties));
    }
}