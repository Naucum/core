<?php
/**
 * Copyright (c) 2015 Thomas Müller <deepdiver@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\App;

use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

class CodeChecker {

	const CLASS_EXTENDS_NOT_ALLOWED = 1000;
	const CLASS_IMPLEMENTS_NOT_ALLOWED = 1001;
	const STATIC_CALL_NOT_ALLOWED = 1002;
	const CLASS_CONST_FETCH_NOT_ALLOWED = 1003;
	const CLASS_NEW_FETCH_NOT_ALLOWED =  1004;

	public function __construct() {
		$this->parser = new Parser(new Lexer);
		$this->blackListedClassNames = [
			// classes replaced by the public api
			'OC_API',
			'OC_App',
			'OC_AppConfig',
			'OC_Avatar',
			'OC_BackgroundJob',
			'OC_Config',
			'OC_DB',
			'OC_Files',
			'OC_Helper',
			'OC_Hook',
			'OC_Image',
			'OC_JSON',
			'OC_L10N',
			'OC_Log',
			'OC_Mail',
			'OC_Preferences',
			'OC_Request',
			'OC_Response',
			'OC_Template',
			'OC_User',
			'OC_Util',
		];
	}

	public function analyse($appId) {
		$appPath = \OC_App::getAppPath($appId);

		$iterator = new RegexIterator( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($appPath)), '/^.+\.php$/i',
			RecursiveRegexIterator::GET_MATCH);
		foreach ($iterator as $fileInfo) {
			$this->analyseFile($fileInfo[0]);
			$x = 0;
		}

	}

	/**
	 * @param string $file
	 */
	public function analyseFile($file) {
		$code = file_get_contents($file);
		$statements = $this->parser->parse($code);

		$visitor = new CodeCheckVisitor($this->blackListedClassNames);
		$traverser = new NodeTraverser;
		$traverser->addVisitor($visitor);

		$traverser->traverse($statements);

		return $visitor->errors;
	}
}


class CodeCheckVisitor extends NodeVisitorAbstract {

	public function __construct($blackListedClassNames) {
		$this->blackListedClassNames = array_map('strtolower', $blackListedClassNames);
	}

	public $errors = [];

	public function enterNode(Node $node) {
		if ($node instanceof Node\Stmt\Class_) {
			if (!is_null($node->extends)) {
				$this->checkBlackList($node->extends->toString(), CodeChecker::CLASS_EXTENDS_NOT_ALLOWED);
			}
			foreach ($node->implements as $implements) {
				$this->checkBlackList($implements->toString(), CodeChecker::CLASS_IMPLEMENTS_NOT_ALLOWED);
			}
		}
		if ($node instanceof Node\Expr\StaticCall) {
			if (!is_null($node->class)) {
				$this->checkBlackList($node->class->toString(), CodeChecker::STATIC_CALL_NOT_ALLOWED);
			}
		}
		if ($node instanceof Node\Expr\ClassConstFetch) {
			if (!is_null($node->class)) {
				if ($node->class instanceof Name) {
					$this->checkBlackList($node->class->toString(), CodeChecker::CLASS_CONST_FETCH_NOT_ALLOWED);
				}
				if ($node->class instanceof Node\Expr\Variable) {
					/**
					 * TODO: find a way to detect something like this:
					 *       $c = "OC_API";
					 *       $n = $i::ADMIN_AUTH;
					 */
				}
			}
		}
		if ($node instanceof Node\Expr\New_) {
			if (!is_null($node->class)) {
				if ($node->class instanceof Name) {
					$this->checkBlackList($node->class->toString(), CodeChecker::CLASS_NEW_FETCH_NOT_ALLOWED);
				}
				if ($node->class instanceof Node\Expr\Variable) {
					/**
					 * TODO: find a way to detect something like this:
					 *       $c = "OC_API";
					 *       $n = new $i;
					 */
				}
			}
		}
	}

	private function checkBlackList($name, $errorCode) {
		if (in_array(strtolower($name), $this->blackListedClassNames)) {
			$this->errors[]= [
				'disallowedToken' => $name,
				'errorCode' => $errorCode
			];
		}
	}
}
