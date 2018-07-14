<?php
require_once __DIR__ . '/../bootstrap.php';

use PhpParser\ParserFactory;
use PhpParser\NodeDumper;

$file = APP_PATH . '/Test.php';
$code = file_get_contents($file);

$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
$ast = $parser->parse($code);

$dumper = new NodeDumper();
echo $dumper->dump($ast) . "\n";