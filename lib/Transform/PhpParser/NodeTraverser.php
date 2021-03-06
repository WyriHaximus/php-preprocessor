<?php
declare(strict_types=1);
namespace PhpPreprocessor\Transform\PhpParser;

use PhpParser\Node;
use RuntimeException;

class NodeTraverser
{
    protected $rules = [];
    protected $context;

    public function addTranspileRule(TranspileRule $rule)
    {
        foreach ($rule->getNodeTypes() as $type) {
            if (empty($this->rules[$type])) {
                $this->rules[$type] = [];
            }
            $this->rules[$type][] = $rule;
        }
    }

    public function traverse(array $nodes): array
    {
        if (empty($this->rules)) {
            return $nodes;
        }
        $this->context = new Context;
        $nodes = $this->traverseArray($nodes);
        $this->context = null;
        return $nodes;
    }

    protected function traverseArray(array $nodes): array
    {
        do {
            $changed = false;
            $doNodes = [];
            foreach ($nodes as $i => $node) {
                if (is_array($node)) {
                    $result = $this->traverseArray($node);
                    if ($nodes[$i] !== $result) {
                        $nodes[$i] = $result;
                        $changed = true;
                    }
                } elseif ($node instanceof Node) {
                    $return = $node;
                    foreach ($this->rules as $class => $ruleset) {
                        if ($return instanceof $class) {
                            foreach ($ruleset as $rule) {
                                $return = $rule->transpile($return, $this->context);
                                if ($return === null) {
                                    $return = $node;
                                } elseif ($return !== $node) {
                                    $changed = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    if ($return !== $node && $return instanceof Node) {
                        $doNodes[] = [$i, $this->traverseArray([$return])];
                    } elseif (is_array($return)) {
                        $doNodes[] = [$i, $this->traverseArray($return)];
                    } elseif ($return === false) {
                        $doNodes[] = [$i, []];
                    }
                }
            }

            if (!empty($doNodes)) {
                $changed = true;
                while (list($i, $replace) = array_pop($doNodes)) {
                    array_splice($nodes, $i, 1, $replace);
                }
            }

            foreach ($nodes as $node) {
                if ($node instanceof Node) {
                    $this->context->push($node);
                    foreach ($node->getSubNodeNames() as $name) {
                        $subNode = $node->$name;
                        if (is_array($subNode)) {
                            $node->$name = $this->traverseArray($subNode);
                            if ($node->$name !== $subNode) {
                                $changed = true;
                            }
                        } elseif ($subNode instanceof Node) {
                            $return = $this->traverseArray([$subNode]);
                            if (is_array($return)) {
                                if (count($return) !== 1) {
                                    throw new RuntimeException("May only return " . count($return) . " nodes if parent structure is array");
                                }
                                if ($subNode !== $return[0]) {
                                    $node->$name = $return[0];
                                    $changed = true;
                                }
                            }
                        }
                    }
                    $this->context->pop($node);
                }
            }
        } while ($changed);
        return $nodes;
    }

}
