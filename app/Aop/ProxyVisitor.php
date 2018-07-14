<?php
namespace App\Aop;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Use_;
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

    /**
     * @return string
     */
    public function getProxyClassName(): string
    {
        return \basename(str_replace('\\', '/', $this->className)) . '_' . $this->proxyId;
    }

    public function getClassName()
    {
        return '\\' . $this->className . '_' . $this->proxyId;
    }

    /**
     * Called when leaving a node.
     * Return value semantics:
     *  * null
     *        => $node stays as-is
     *  * NodeTraverser::REMOVE_NODE
     *        => $node is removed from the parent array
     *  * NodeTraverser::STOP_TRAVERSAL
     *        => Traversal is aborted. $node stays as-is
     *  * array (of Nodes)
     *        => The return value is merged into the parent array (at the position of the $node)
     *  * otherwise
     *        => $node is set to the return value
     *
     * @param Node $node Node
     * @return null|int|Node|Node[] Replacement node (or special return value)
     */
    public function leaveNode(Node $node)
    {
        // Clear all comment
        $node->getDocComment() && $node->setDocComment(new Doc(''));
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
            if ($methodName === 'getOriginalClassName') {
                return;
            }
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
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Name && $node->class->toString() === 'parent') {
            $parentClass = $this->getParentClassFullName();
            if ($parentClass) {
                return new Node\Expr\StaticCall(new Name($parentClass), $node->name, $node->args, $node->getAttributes());
            }
        }
    }
}