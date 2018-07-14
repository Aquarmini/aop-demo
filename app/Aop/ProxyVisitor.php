<?php
namespace App\Aop;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;

class ProxyVisitor extends NodeVisitorAbstract
{
    protected $className;

    protected $proxyId;

    public function __construct($className, $proxyId)
    {
        $this->className = $className;
        $this->proxyId = $proxyId;
    }

    public function getProxyClassName(): string
    {
        return \basename(str_replace('\\', '/', $this->className)) . '_' . $this->proxyId;
    }

    public function getClassName()
    {
        return '\\' . $this->className . '_' . $this->proxyId;
    }

    /**
     * @return \PhpParser\Node\Stmt\TraitUse
     */
    private function getAopTraitUseNode(): TraitUse
    {
        // Use AopTrait trait use node
        return new TraitUse([new Name('\App\Aop\AopTrait')]);
    }

    public function leaveNode(Node $node)
    {
        // Proxy Class
        if ($node instanceof Class_) {
            // Create proxy class base on parent class
            return new Class_($this->getProxyClassName(), [
                'flags' => $node->flags,
                'stmts' => $node->stmts,
                'extends' => new Name('\\' . $this->className),
            ]);
        }
        // Rewrite public and protected methods, without static methods
        if ($node instanceof ClassMethod && !$node->isStatic() && ($node->isPublic() || $node->isProtected())) {
            $methodName = $node->name->toString();
            // Rebuild closure uses, only variable
            $uses = [];
            foreach ($node->params as $key => $param) {
                if ($param instanceof Param) {
                    $uses[$key] = new Param($param->var, null, null, true);
                }
            }
            $params = [
                // Add method to an closure
                new Closure([
                    'static' => $node->isStatic(),
                    'uses' => $uses,
                    'stmts' => $node->stmts,
                ]),
                new String_($methodName),
                new FuncCall(new Name('func_get_args')),
            ];
            $stmts = [
                new Return_(new MethodCall(new Variable('this'), '__proxyCall', $params))
            ];
            $returnType = $node->getReturnType();
            if ($returnType instanceof Name && $returnType->toString() === 'self') {
                $returnType = new Name('\\' . $this->className);
            }
            return new ClassMethod($methodName, [
                'flags' => $node->flags,
                'byRef' => $node->byRef,
                'params' => $node->params,
                'returnType' => $returnType,
                'stmts' => $stmts,
            ]);
        }
    }

    public function afterTraverse(array $nodes)
    {
        $addEnhancementMethods = true;
        $nodeFinder = new NodeFinder();
        $nodeFinder->find($nodes, function (Node $node) use (
            &$addEnhancementMethods
        ) {
            if ($node instanceof TraitUse) {
                foreach ($node->traits as $trait) {
                    // Did AopTrait trait use ?
                    if ($trait instanceof Name && $trait->toString() === '\App\Aop\AopTrait') {
                        $addEnhancementMethods = false;
                        break;
                    }
                }
            }
        });
        // Find Class Node and then Add Aop Enhancement Methods nodes and getOriginalClassName() method
        $classNode = $nodeFinder->findFirstInstanceOf($nodes, Class_::class);
        $addEnhancementMethods && array_unshift($classNode->stmts, $this->getAopTraitUseNode());
        return $nodes;
    }
}