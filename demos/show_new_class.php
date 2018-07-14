<?php
require_once __DIR__ . '/../bootstrap.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use App\Aop\ProxyVisitor;
use PhpParser\PrettyPrinter\Standard;

$file = APP_PATH . '/Test.php';
$code = file_get_contents($file);

$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
$ast = $parser->parse($code);

$traverser = new NodeTraverser();
$className = 'App\\Test';
$proxyId = uniqid();
$visitor = new ProxyVisitor($className, $proxyId);
$traverser->addVisitor($visitor);
$proxyAst = $traverser->traverse($ast);
if (!$proxyAst) {
    throw new \Exception(sprintf('Class %s AST optimize failure', $className));
}
$printer = new Standard();
$proxyCode = $printer->prettyPrint($proxyAst);

echo $proxyCode . PHP_EOL;

eval($proxyCode);

$class = $visitor->getClassName();
$bean = new $class();

echo $bean->show();