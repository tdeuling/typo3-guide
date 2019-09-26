<?php
namespace Tx\Guide\Utility;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2014 TYPO3 CMS Team
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Backend\Domain\Repository\Module\BackendModuleRepository;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Extbase\Service\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Tx\Guide\Service\SanitizationService;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Form\Mvc\Configuration\YamlSource;

/**
 * GuideUtility
 *
 * @author Thomas Deuling <typo3@coding.ms>
 * @package TYPO3
 * @subpackage tx_guide
 */
class GuideUtility
{
    /**
     * @var \TYPO3\CMS\Form\Mvc\Configuration\YamlSource
     * @inject
     */
    protected $yamlSource;

    /**
     * @var \TYPO3\CMS\Extbase\Service\TypoScriptService
     * @inject
     */
    protected $typoScriptService;

    /**
     * @var \TYPO3\CMS\Backend\Domain\Repository\Module\BackendModuleRepository
     * @inject
     */
    protected $backendModuleRepository;

    /**
     * Get a list with available tours
     * @return array
     */
    public function getRegisteredGuideTours()
    {
        // Be sure the TypoScript service is available
        if (!($this->typoScriptService instanceof TypoScriptService)) {
            $this->typoScriptService = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Service\\TypoScriptService');
        }
        //
        // 1. Get configured tours registered by GuideUtility::addTour
        $tours = array();
        $backendUser = $this->getBackendUserAuthentication();
        if(isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['guide']['tours']) && count($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['guide']['tours'])>0) {
            $tours = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['guide']['tours'];
        }
        //
        // 2. Get tours defined by Page-TypoScript/tsconfig
        $toursPage = $this->getBackendUserAuthentication()->getTSConfig(
            'mod.guide.tours', BackendUtility::getPagesTSconfig(0)
        );
        if(is_array($toursPage['properties']) && count($toursPage['properties'])>0) {
            $toursPage = $this->typoScriptService->convertTypoScriptArrayToPlainArray($toursPage['properties']);
            ArrayUtility::mergeRecursiveWithOverrule($tours, $toursPage);
        }
        //
        // 3. Get tours defined by User-TypoScript
        $toursUser = $this->getBackendUserAuthentication()->getTSConfig(
            'mod.guide.tours'
        );
        if(is_array($toursUser['properties']) && count($toursUser['properties'])>0) {
            $toursUser = $this->typoScriptService->convertTypoScriptArrayToPlainArray($toursUser['properties']);
            ArrayUtility::mergeRecursiveWithOverrule($tours, $toursUser);
        }
        if(count($tours) >0) {
            foreach ($tours as $tourKey => $tour) {
                $tour['name'] = $tourKey;
                // Merge user configuration
                if (isset($backendUser->uc['moduleData']['guide'][$tour['name']])) {
                    // Keep original values → no mergeRecursiveWithOverrule() here
                    $tours[$tour['name']] = array_merge(
                        $tour,
                        $backendUser->uc['moduleData']['guide'][$tour['name']]
                    );
                } else {
                    $tours[$tour['name']] = $tour;
                }
                // Be sure disabled is available
                if (!isset($tours[$tourKey]['disabled'])) {
                    $tours[$tourKey]['disabled'] = false;
                }
                // Title and description
                $tours[$tourKey]['title'] = $this->translate($tours[$tourKey]['title']);
                $tours[$tourKey]['description'] = $this->translate($tours[$tourKey]['description']);
                // Generate an id
                $tours[$tourKey]['id'] = GeneralUtility::camelCaseToLowerCaseUnderscored($tour['name']);
                $tours[$tourKey]['id'] = 'guide-tour-' . str_replace('_', '-', $tours[$tourKey]['id']);
                // Remove steps
                if (!isset($tours[$tourKey]['currentStepNo'])) {
                    $tours[$tourKey]['currentStepNo'] = 0;
                }
                $tours[$tourKey]['stepsCount'] = count($tours[$tourKey]['steps']);
                unset($tours[$tourKey]['steps']);
                // Tour/Module is enabled for current user
                if (!$this->moduleEnabled($tour['moduleName'])) {
                    // ..if not, remove that tour!
                    unset($tours[$tourKey]);
                }
            }
        }
        return $tours;
    }

    /**
     * Passed module is enabled for current backend user?
     * @param $moduleName
     * @return bool
     */
    public function moduleEnabled($moduleName)
    {
        $enabled = false;
        $backendUser = $this->getBackendUserAuthentication();
        if ($backendUser->isAdmin()) {
            $enabled = true;
        } else {
            if ($moduleName === 'core') {
                $enabled = true;
            } else {
                if (!($this->backendModuleRepository instanceof BackendModuleRepository)) {
                    $this->backendModuleRepository = GeneralUtility::makeInstance('TYPO3\\CMS\\Backend\\Domain\\Repository\\Module\\BackendModuleRepository');
                }
                $modules = $this->backendModuleRepository->loadAllowedModules();
                /** @var \TYPO3\CMS\Backend\Domain\Model\Module\BackendModule $module */
                foreach ($modules as $module) {
                    $children = $module->getChildren();
                    if (!empty($children)) {
                        /** @var \TYPO3\CMS\Backend\Domain\Model\Module\BackendModule $child */
                        foreach ($children as $child) {
                            if ($moduleName === $child->getName()) {
                                $enabled = true;
                                break(2);
                            }
                        }
                    }
                }
            }
        }
        return $enabled;
    }

    /**
     * Get a tour by name
     * @param $tour
     * @return array
     */
    public function getRegisteredGuideTour($tourName, $prepareTourSteps=true)
    {
        $tour = array();
        // Get all tours
        $tours = $this->getRegisteredGuideTours();
        if(isset($tours[$tourName])) {
            $tour = $tours[$tourName];
            $tour['steps'] = array();
            // Be sure the TypoScript service is available
            if (!($this->typoScriptService instanceof TypoScriptService)) {
                $this->typoScriptService = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Service\\TypoScriptService');
            }
            //
            // Get steps
            // 1. Try to find a StepConfiguration.yaml file
            if(isset($tour['stepsConfiguration'])) {
                // Be sure the TypoScript service is available
                if (!($this->yamlSource instanceof YamlSource)) {
                    $this->yamlSource = GeneralUtility::makeInstance('TYPO3\\CMS\\Form\\Mvc\\Configuration\\YamlSource');
                }
                $stepsConfigurationFile = GeneralUtility::getFileAbsFileName($tour['stepsConfiguration']);
                if(file_exists($stepsConfigurationFile)) {
                    $tour['steps'] = $this->yamlSource->load(array($stepsConfigurationFile));
                    unset($tour['stepsConfiguration']);
                }
            }
            //
            // 2. Get tour steps by Page-TypoScript/tsconfig
            $stepsPage = $this->getBackendUserAuthentication()->getTSConfig(
                'mod.guide.tours.' . $tourName . '.steps', BackendUtility::getPagesTSconfig(0)
            );
            if(is_array($stepsPage['properties']) && count($stepsPage['properties'])>0) {
                $stepsPage = $this->typoScriptService->convertTypoScriptArrayToPlainArray($stepsPage['properties']);
                ArrayUtility::mergeRecursiveWithOverrule($tour['steps'], $stepsPage);
            }
            //
            // 3. Get tour steps by User-TypoScript
            $stepsUser = $this->getBackendUserAuthentication()->getTSConfig(
                'mod.guide.tours.' . $tourName . '.steps'
            );
            if(is_array($stepsUser['properties']) && count($stepsUser['properties'])>0) {
                $stepsUser = $this->typoScriptService->convertTypoScriptArrayToPlainArray($stepsUser['properties']);
                ArrayUtility::mergeRecursiveWithOverrule($tour['steps'], $stepsUser);
            }
            // Prepare tour steps
            if($prepareTourSteps) {
                foreach ($tour['steps'] as $stepKey => $step) {
                    $tour['steps'][$stepKey]['title'] = $this->translate($tour['steps'][$stepKey]['title']);
                    $tour['steps'][$stepKey]['content'] = $this->translate($tour['steps'][$stepKey]['content']);
                    $tour['steps'][$stepKey]['title'] = SanitizationService::sanitizeHtml($tour['steps'][$stepKey]['title']);
                    // Strip disallowed tags
                    $tour['steps'][$stepKey]['title'] = strip_tags($tour['steps'][$stepKey]['title']);
                    // Prepare content
                    // Strip all tags an attributes and insert icons by identifier
                    $content = $tour['steps'][$stepKey]['content'];
                    $icons = array(
                        '<statusdialogwarning>' => 'status-dialog-warning'
                    );
                    $allowedTags = array('<p>', '<i>', '<u>', '<b>', '<br>', '<img>', '<action>');
                    if(preg_match_all("/<img\s(.+?)\/>/is", $tour['steps'][$stepKey]['content'], $replacements)) {
                        foreach($replacements[0] as $icon) {
                            $iconTag = '';
                            $imgTag = new \SimpleXMLElement($icon);
                            if($imgTag instanceof \SimpleXMLElement && isset($imgTag['data-icon-identifier'])) {
                                $iconTag = '<' . (string)$imgTag['data-icon-identifier'] . '>';
                                $iconTag = str_replace(array('-', '_'), '', $iconTag);
                            }
                            $icons[$iconTag] = (string)$imgTag['data-icon-identifier'];
                            $allowedTags[] = $iconTag;
                            $content = str_replace($icon, $iconTag, $content);
                        }
                    }
                    // Cleanup HTML
                    $allowedTags = implode('', $allowedTags);
                    $content = SanitizationService::sanitizeHtml($content, $allowedTags);
                    // Parse user actions
                    $content = str_replace('<action>', '<br /><div class="text-warning"><statusdialogwarning> ', $content);
                    $content = str_replace('</action>', '</div>', $content);
                    // Insert icons by IconFactory
                    if(count($icons)>0) {
                        /** @var \TYPO3\CMS\Core\Imaging\IconFactory $iconFactory */
                        $iconFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Imaging\IconFactory::class);
                        foreach($icons as $replacement=>$iconIdentifier) {
                            $icon = $iconFactory->getIcon($iconIdentifier, \TYPO3\CMS\Core\Imaging\Icon::SIZE_SMALL);
                            $iconMarkup = $icon->render();
                            $content = str_replace($replacement, $iconMarkup, $content);
                        }
                    }
                    $tour['steps'][$stepKey]['content'] = $content;
                    // Reset backdrop
                    if (!isset($tour['steps'][$stepKey]['backdrop'])) {
                        $tour['steps'][$stepKey]['backdrop'] = false;
                    }
                    else {
                        $tour['steps'][$stepKey]['backdrop'] = ($tour['steps'][$stepKey]['backdrop']=='true');
                    }
                    if (!isset($tour['steps'][$stepKey]['backdropPadding'])) {
                        $tour['steps'][$stepKey]['backdropPadding'] = 0;
                    }
                    else {
                        $tour['steps'][$stepKey]['backdropPadding'] = (int)$tour['steps'][$stepKey]['backdropPadding'];
                    }
                    if (!isset($tour['steps'][$stepKey]['showArrow'])) {
                        $tour['steps'][$stepKey]['showArrow'] = true;
                    }
                    else {
                        $tour['steps'][$stepKey]['showArrow'] = ($tour['steps'][$stepKey]['showArrow']=='true');
                    }
                    # Resolve next step index/key
                    if(isset($tour['steps'][$stepKey]['next']) && isset($tour['steps'][$stepKey]['next']['stepByKey'])) {
                        $stepByKey = trim($tour['steps'][$stepKey]['next']['stepByKey']);
                        $stepTour = trim($tour['steps'][$stepKey]['next']['tour']);
                        $tour['steps'][$stepKey]['next']['step'] = $this->resolveStepNoByStepKey($stepTour, $stepByKey);
                    }
                    // Click event
                    if (isset($tour['steps'][$stepKey]['click'])) {
                        if(isset($tour['steps'][$stepKey]['click']['selector'])) {
                            $tour['steps'][$stepKey]['click'] = $tour['steps'][$stepKey]['click']['selector'];
                        }
                        else {
                            $tour['steps'][$stepKey]['click'] = true;
                        }
                    }
                    else {
                        $tour['steps'][$stepKey]['click'] = false;
                    }
                    // Current step no
                    $tour['steps'][$stepKey]['currentStepNo'] = $this->getTourStepNo($tourName);
                }
            }
        }
        return $tour;
    }

    protected function resolveStepNoByStepKey($tourName, $stepKeyName) {
        $stepReturn = 0;
        $stepNumber = 0;
        $tour = $this->getRegisteredGuideTour($tourName, false);
        foreach ($tour['steps'] as $stepKey => $step) {
            if($stepKey == $stepKeyName) {
                $stepReturn = $stepNumber;
                break;
            }
            $stepNumber++;
        }
        return $stepReturn;
    }

    /**
     * @todo: don't include anything, in case of the user confirmed that he won't restart the guide
     */
    public function isGuidedTourActivated()
    {
        return true;
    }

    /**
     * Set a tour as disabled
     * @param string $tourName Name of the guided tour
     * @param bool $disabled Disabled true/false
     * @return array
     */
    public function setTourDisabled($tourName, $disabled = true)
    {
        $backendUser = $this->getBackendUserAuthentication();
        if (!isset($backendUser->uc['moduleData']['guide'][$tourName])) {
            $backendUser->uc['moduleData']['guide'][$tourName] = array();
        }
        $backendUser->uc['moduleData']['guide'][$tourName]['disabled'] = $disabled;
        // Write back into user configuration
        $backendUser->writeUC($backendUser->uc);
        return $backendUser->uc['moduleData']['guide'][$tourName];
    }

    /**
     * Write current step no of a tour
     * @param string $tourName Name of the guided tour
     * @param int $stepNo Number of the current step
     * @return array
     */
    public function setTourStepNo($tourName, $stepNo)
    {
        $backendUser = $this->getBackendUserAuthentication();
        if (!isset($backendUser->uc['moduleData']['guide'][$tourName])) {
            $backendUser->uc['moduleData']['guide'][$tourName] = array();
        }
        $backendUser->uc['moduleData']['guide'][$tourName]['currentStepNo'] = $stepNo;
        // Set already viewed
        $backendUser->uc['moduleData']['guide'][$tourName]['alreadyViewed'] = false;
        $tour = $this->getRegisteredGuideTour($tourName);
        // Set step count
        if ($tour['stepsCount'] == ($stepNo + 1)) {
            $backendUser->uc['moduleData']['guide'][$tourName]['alreadyViewed'] = true;
        }
        // Write back into user configuration
        $backendUser->writeUC($backendUser->uc);
        return $backendUser->uc['moduleData']['guide'][$tourName];
    }

    /**
     * @param $tourName
     * @return int
     */
    protected function getTourStepNo($tourName) {
        $stepNo = 0;
        $backendUser = $this->getBackendUserAuthentication();
        if (isset($backendUser->uc['moduleData']['guide'][$tourName])) {
            $stepNo = $backendUser->uc['moduleData']['guide'][$tourName]['currentStepNo'];
        }
        return $stepNo;
    }

    /**
     * Check if a tour is registered
     * @param string $tour Name of the guided tour
     * @return bool
     */
    public function tourExists($tour)
    {
        $tours = $this->getRegisteredGuideTours();
        return isset($tours[$tour]);
    }

    /**
     * Get user configuration for guides
     * @return array
     */
    public function getUserConfiguration()
    {
        $backendUser = $this->getBackendUserAuthentication();
        return $backendUser->uc['moduleData']['guide'];
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @param $translationKey
     * @return NULL|string
     */
    protected function translate($translationKey)
    {
        if (substr($translationKey, 0, 4) == 'LLL:') {
            $translationKey = LocalizationUtility::translate($translationKey, 'Guide');
        }
        return $translationKey;
    }

    /**
     * Register new tours
     * @param $identifier
     * @param $title
     * @param $description
     * @param $moduleIdentifier
     * @param $iconIdentifier
     * @param $stepsConfigurationPath
     */
    static public function addTour($identifier, $title, $description, $moduleIdentifier, $iconIdentifier, $stepsConfigurationPath) {
        $majorVersion = (int)(VersionNumberUtility::convertVersionNumberToInteger(TYPO3_version)/1000000);
        $versionPath = 'TYPO3-' . (string)$majorVersion;
        $stepsConfiguration = $stepsConfigurationPath . $versionPath . '/' . $identifier . '.yaml';
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['guide']['tours'][$identifier] = [
            'title' => $title,
            'description' => $description,
            'moduleName' => $moduleIdentifier,
            'iconIdentifier' => $iconIdentifier,
            'stepsConfiguration' => $stepsConfiguration
        ];
    }
}