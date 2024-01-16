<?php

declare(strict_types=1);

namespace Rector\Symfony\CodeQuality\Rector\Closure;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Expression;
use Rector\Exception\NotImplementedYetException;
use Rector\Naming\Naming\PropertyNaming;
use Rector\PhpParser\Node\Value\ValueResolver;
use Rector\Rector\AbstractRector;
use Rector\Symfony\CodeQuality\NodeFactory\SymfonyClosureFactory;
use Rector\Symfony\Configs\ConfigArrayHandler\NestedConfigCallsFactory;
use Rector\Symfony\Configs\ConfigArrayHandler\SecurityAccessDecisionManagerConfigArrayHandler;
use Rector\Symfony\Configs\Enum\SecurityConfigKey;
use Rector\Symfony\NodeAnalyzer\SymfonyClosureExtensionMatcher;
use Rector\Symfony\NodeAnalyzer\SymfonyPhpClosureDetector;
use Rector\Symfony\Utils\StringUtils;
use Rector\Symfony\ValueObject\ExtensionKeyAndConfiguration;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @changelog https://symfony.com/blog/new-in-symfony-5-3-config-builder-classes
 *
 * @see \Rector\Symfony\Tests\CodeQuality\Rector\Closure\StringExtensionToConfigBuilderRector\StringExtensionToConfigBuilderRectorTest
 */
final class StringExtensionToConfigBuilderRector extends AbstractRector
{
    /**
     * @var array<string, string>
     */
    private const EXTENSION_KEY_TO_CLASS_MAP = [
        'security' => 'Symfony\Config\SecurityConfig',
        'framework' => 'Symfony\Config\FrameworkConfig',
        'monolog' => 'Symfony\Config\MonologConfig',
        'twig' => 'Symfony\Config\TwigConfig',
        'doctrine' => 'Symfony\Config\DoctrineConfig',
    ];

    public function __construct(
        private readonly SymfonyPhpClosureDetector $symfonyPhpClosureDetector,
        private readonly SymfonyClosureExtensionMatcher $symfonyClosureExtensionMatcher,
        private readonly PropertyNaming $propertyNaming,
        private readonly ValueResolver $valueResolver,
        private readonly NestedConfigCallsFactory $nestedConfigCallsFactory,
        private readonly SecurityAccessDecisionManagerConfigArrayHandler $securityAccessDecisionManagerConfigArrayHandler,
        private readonly SymfonyClosureFactory $symfonyClosureFactory
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Add config builder classes', [
            new CodeSample(
                <<<'CODE_SAMPLE'
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('security', [
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js)/',
                'security' => false,
            ],
        ],
    ]);
};
CODE_SAMPLE

                ,
                <<<'CODE_SAMPLE'
use Symfony\Config\SecurityConfig;

return static function (SecurityConfig $securityConfig): void {
    $securityConfig->firewall('dev')
        ->pattern('^/(_(profiler|wdt)|css|images|js)/')
        ->security(false);
};
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Closure::class];
    }

    /**
     * @param Closure $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->symfonyPhpClosureDetector->detect($node)) {
            return null;
        }

        $extensionKeyAndConfiguration = $this->symfonyClosureExtensionMatcher->match($node);
        if (! $extensionKeyAndConfiguration instanceof ExtensionKeyAndConfiguration) {
            return null;
        }

        $configClass = self::EXTENSION_KEY_TO_CLASS_MAP[$extensionKeyAndConfiguration->getKey()] ?? null;
        if ($configClass === null) {
            throw new NotImplementedYetException($extensionKeyAndConfiguration->getKey());
        }

        $configVariable = $this->createConfigVariable($configClass);

        $stmts = $this->createMethodCallStmts($extensionKeyAndConfiguration->getArray(), $configVariable);
        return $this->symfonyClosureFactory->create($configClass, $node, $stmts);
    }

    /**
     * @return array<Expression<MethodCall>>
     */
    private function createMethodCallStmts(Array_ $configurationArray, Variable $configVariable): array
    {
        $methodCallStmts = [];

        $configurationValues = $this->valueResolver->getValue($configurationArray);

        foreach ($configurationValues as $key => $value) {
            $splitMany = false;
            if ($key === 'providers') {
                $methodCallName = 'provider';
                $splitMany = true;
            } elseif ($key === 'firewalls') {
                $methodCallName = 'firewall';
                $splitMany = true;
            } elseif ($key === SecurityConfigKey::ACCESS_CONTROL) {
                $splitMany = true;
                $methodCallName = 'accessControl';
            } else {
                $methodCallName = StringUtils::underscoreToCamelCase($key);
            }

            if (in_array($key, [SecurityConfigKey::ACCESS_DECISION_MANAGER, SecurityConfigKey::ENTITY])) {
                $mainMethodName = StringUtils::underscoreToCamelCase($key);

                $accessDecisionManagerMethodCalls = $this->securityAccessDecisionManagerConfigArrayHandler->handle(
                    $configurationArray,
                    $configVariable,
                    $mainMethodName
                );

                if ($accessDecisionManagerMethodCalls !== []) {
                    $methodCallStmts = array_merge($methodCallStmts, $accessDecisionManagerMethodCalls);
                    continue;
                }
            }

            if ($splitMany) {
                foreach ($value as $itemName => $itemConfiguration) {
                    $nextMethodCallExpressions = $this->nestedConfigCallsFactory->create(
                        [$itemName, $itemConfiguration],
                        $configVariable,
                        $methodCallName
                    );

                    $methodCallStmts = array_merge($methodCallStmts, $nextMethodCallExpressions);
                }
            } else {
                // skip empty values
                if ($value === null) {
                    continue;
                }

                $simpleMethodName = StringUtils::underscoreToCamelCase($key);

                $args = $this->nodeFactory->createArgs([$value]);
                $methodCall = new MethodCall($configVariable, $simpleMethodName, $args);
                $methodCallStmts[] = new Expression($methodCall);
            }
        }

        return $methodCallStmts;
    }

    private function createConfigVariable(string $configClass): Variable
    {
        $variableName = $this->propertyNaming->fqnToVariableName($configClass);
        return new Variable($variableName);
    }
}
