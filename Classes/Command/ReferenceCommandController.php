<?php
namespace Documentation\Command;

/*                                                                        *
 * This script belongs to the FLOW3 package "Documentation".              *
 *                                                                        *
 *                                                                        *
 */

use TYPO3\FLOW3\Annotations as FLOW3;

/**
 * Reference command controller for the Documentation package
 * Used to create reference documentations for special classes (e.g. Fluid ViewHelpers, FLOW3 Validators, ...)
 *
 * @FLOW3\Scope("singleton")
 */
class ReferenceCommandController extends \TYPO3\FLOW3\MVC\Controller\CommandController {

	/**
	 * @var \TYPO3\FLOW3\Reflection\ReflectionService
	 * @FLOW3\Inject
	 */
	protected $reflectionService;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * @param array $settings
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * @param string $reference to render. If not specified all configured references will be rendered
	 * @return void
	 */
	public function renderCommand($reference = NULL) {
		$references = $reference !== NULL ? array($reference) : array_keys($this->settings['references']);
		foreach ($references as $reference) {
			$this->outputLine('Rendering Reference "%s"', array($reference));
			$this->renderReference($reference);
		}
	}

	/**
	 * @param $reference
	 */
	protected function renderReference($reference) {
		if (!isset($this->settings['references'][$reference])) {
			$this->outputLine('Reference "%s" is not configured', array($reference));
			$this->quit(1);
		}
		$referenceConfiguration = $this->settings['references'][$reference];
		$affectedClassNames = $this->getAffectedClassNames($referenceConfiguration['affectedClasses']);
		$parserClassName = $referenceConfiguration['parser']['implementationClassName'];
		$parserOptions = isset($referenceConfiguration['parser']['options']) ? $referenceConfiguration['parser']['options'] : array();
		/** @var $classParser \Documentation\Domain\Service\AbstractClassParser */
		$classParser = new $parserClassName($parserOptions);
		$classReferences = array();
		foreach ($affectedClassNames as $className) {
			$classReferences[$className] = $classParser->parse($className);
		}
		$standaloneView = new \TYPO3\Fluid\View\StandaloneView();
		$templatePathAndFilename = isset($referenceConfiguration['templatePathAndFilename']) ? $referenceConfiguration['templatePathAndFilename'] : 'resource://Documentation/Private/Templates/ClassReferenceTemplate.txt';
		$standaloneView->setTemplatePathAndFilename($templatePathAndFilename);
		$standaloneView->assign('title', isset($referenceConfiguration['title']) ? $referenceConfiguration['title'] : $reference);
		$standaloneView->assign('classReferences', $classReferences);
		file_put_contents($referenceConfiguration['savePathAndFilename'], $standaloneView->render());
		$this->outputLine('DONE.');
	}

	/**
	 * @param array $classesSelector
	 * @return array
	 */
	protected function getAffectedClassNames(array $classesSelector) {
		if (isset($classesSelector['parentClassName'])) {
			$affectedClassNames = $this->reflectionService->getAllSubClassNamesForClass($classesSelector['parentClassName']);
		} elseif (isset($classesSelector['interface'])) {
			$affectedClassNames = $this->reflectionService->getAllImplementationClassNamesForInterface($classesSelector['interface']);
		} else {
			$affectedClassNames = $this->reflectionService->getAllClassNames();
		}

		foreach ($affectedClassNames as $index => $className) {
			if ($this->reflectionService->isClassAbstract($className)) {
				unset($affectedClassNames[$index]);
			} elseif (isset($classesSelector['classNamePattern']) && preg_match($classesSelector['classNamePattern'], $className) === 0) {
				unset($affectedClassNames[$index]);
			}
		}
		return $affectedClassNames;
	}
}

?>