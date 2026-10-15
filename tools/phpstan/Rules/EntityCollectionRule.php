<?php

declare(strict_types=1);

namespace Tools\PHPStan\Rules;

use App\Shared\Domain\Collection\AbstractCollection;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<InClassNode> */
final class EntityCollectionRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $reflection = $node->getClassReflection();
        $name = $reflection->getName();
        if (!$node->getOriginalNode() instanceof Class_
            || AbstractCollection::class === $name
            || str_ends_with($name, 'PaginatedCollection')
            || 1 !== preg_match('~^App\\\\[^\\\\]+\\\\Domain\\\\Collection\\\\.*Collection$~', $name)
            || $reflection->isSubclassOf(AbstractCollection::class)
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Entity collections must extend App\Shared\Domain\Collection\AbstractCollection.')
                ->identifier('architecture.collectionBase')
                ->build(),
        ];
    }
}
