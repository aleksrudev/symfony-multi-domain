<?php

declare(strict_types=1);

namespace Tools\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<InClassNode> */
final class InvokableControllerRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $reflection = $node->getClassReflection();
        if (!$node->getOriginalNode() instanceof Class_ || $reflection->isAbstract()) {
            return [];
        }

        $name = $reflection->getName();
        if (1 !== preg_match('~^App\\\\[^\\\\]+\\\\Presentation\\\\Http\\\\Controller\\\\Api\\\\~', $name, $matches)) {
            return [];
        }

        $errors = [];
        $relativeName = substr($name, strlen($matches[0]));
        if (1 !== preg_match('~^[A-Z][A-Za-z0-9]*\\\\~', $relativeName)) {
            $errors[] = RuleErrorBuilder::message('Controllers must be placed in Api/<Actor>/.')
                ->identifier('architecture.controllerActor')
                ->build();
        }

        $hasInvoke = false;
        $extraMethods = [];
        foreach ($reflection->getNativeReflection()->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $name) {
                continue;
            }

            $methodName = strtolower($method->getName());
            if ('__invoke' === $methodName) {
                $hasInvoke = $method->isPublic() && !$method->isStatic();
            } elseif ('__construct' !== $methodName && $method->isPublic()) {
                $extraMethods[] = $method->getName();
            }
        }

        if (!$hasInvoke) {
            $errors[] = RuleErrorBuilder::message('Controllers must declare a public non-static __invoke() method.')
                ->identifier('architecture.controllerInvoke')
                ->build();
        }
        if ([] !== $extraMethods) {
            $errors[] = RuleErrorBuilder::message(sprintf('Controllers may declare no public methods other than __construct() and __invoke(): %s.', implode(', ', $extraMethods)))
                ->identifier('architecture.controllerPublicMethod')
                ->build();
        }

        return $errors;
    }
}
