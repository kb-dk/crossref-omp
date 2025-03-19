<?php

/**
 * @file plugins/generic/crossref/CrossrefPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class Crossref
 *
 * @brief Plugin to let managers deposit DOIs and metadata to Crossref
 *
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\monograph\Chapter;
use APP\monograph\ChapterDAO;
use APP\plugins\generic\crossref\classes\CrossrefSettings;
use APP\plugins\IDoiRegistrationAgency;
use APP\publicationFormat\PublicationFormat;
use APP\publicationFormat\PublicationFormatDAO;
use APP\submission\Submission;
use Illuminate\Support\Collection;
use PKP\context\Context;
use PKP\doi\RegistrationAgencySettings;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\services\PKPSchemaService;

class CrossrefPlugin extends GenericPlugin implements IDoiRegistrationAgency
{
    private CrossrefSettings $_settingsObject;
    private ?CrossrefExportPlugin $_exportPlugin = null;


    public function getDisplayName(): string
    {
        return __('plugins.generic.crossref.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.crossref.description');
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            // If the system isn't installed, or is performing an upgrade, don't
            // register hooks. This will prevent DB access attempts before the
            // schema is installed.
            if (Application::isUnderMaintenance()) {
                return true;
            }

            if ($this->getEnabled($mainContextId)) {
                $this->_pluginInitialization();
            }
        }

        return $success;
    }

    /**
     * Remove plugin as configured registration agency if set at the time plugin is disabled.
     *
     * @copydoc LazyLoadPlugin::setEnabled()
     */
    public function setEnabled($enabled): void
    {
        parent::setEnabled($enabled);
        if (!$enabled) {
            $contextId = $this->getCurrentContextId();
            /** @var \PKP\context\ContextDAO $contextDao */
            $contextDao = Application::getContextDAO();
            $context = $contextDao->getById($contextId);
            if ($context->getData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY) === $this->getName()) {
                $context->setData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY, Context::SETTING_NO_REGISTRATION_AGENCY);
                $contextDao->updateObject($context);
            }
        }
    }

    /**
     * @param Submission[] $submissions
     *
     */
    public function exportSubmissions(array $submissions, Context $context): array
    {
        $exportPlugin = $this->_getExportPlugin();
        $filterName = $exportPlugin->getSubmissionFilter();
        $xmlErrors = [];

        $temporaryFileId = $exportPlugin->exportAsDownload($context, $submissions, $filterName, 'books', null, $xmlErrors);
        return ['temporaryFileId' => $temporaryFileId, 'xmlErrors' => $xmlErrors];
    }

    /**
     * @param Submission[] $submissions
     */
    public function depositSubmissions(array $submissions, Context $context): array
    {
        $exportPlugin = $this->_getExportPlugin();
        $filterName = $exportPlugin->getSubmissionFilter();
        $responseMessage = '';
        $status = $exportPlugin->exportAndDeposit($context, $submissions, $filterName, $responseMessage);

        return [
            'hasErrors' => !$status,
            'responseMessage' => $responseMessage
        ];
    }


    /**
     * Includes plugin in list of configurable registration agencies for DOI depositing functionality
     *
     * @param string $hookName DoiSettingsForm::setEnabledRegistrationAgencies
     * @param array $args [
     *
     * @option $enabledRegistrationAgencies array
     */
    public function addAsRegistrationAgencyOption(string $hookName, array $args): void
    {
        /** @var Collection<int,IDoiRegistrationAgency> $enabledRegistrationAgencies */
        $enabledRegistrationAgencies = &$args[0];
        $enabledRegistrationAgencies->add($this);
    }

    /**
     * Checks if plugin meets registration agency-specific requirements for being active and handling deposits
     *
     */
    public function isPluginConfigured(Context $context): bool
    {
        $settingsObject = $this->getSettingsObject();

        /** @var PKPSchemaService $schemaService */
        $schemaService = Services::get('schema');
        $requiredProps = $schemaService->getRequiredProps($settingsObject::class);

        foreach ($requiredProps as $requiredProp) {
            $settingValue = $this->getSetting($context->getId(), $requiredProp);
            if (empty($settingValue)) {
                return false;
            }
        }

        $doiPrefix = $context->getData(Context::SETTING_DOI_PREFIX);
        if (empty($doiPrefix)) {
            return false;
        }

        if (!$context->getData('publisher')) {
            return false;
        }

        return true;
    }

    /**
     * Get configured registration agency display name for use in DOI management pages
     *
     */
    public function getRegistrationAgencyName(): string
    {
        return __('plugins.generic.crossref.registrationAgency.name');
    }

    /**
     * Get key for retrieving error message if one exists on DOI object
     *
     */
    public function getErrorMessageKey(): ?string
    {
        return $this->_getFailedMsgSettingName();
    }

    /**
     * Get key for retrieving registered message if one exists on DOI object
     *
     */
    public function getRegisteredMessageKey(): ?string
    {
        return $this->_getSuccessMsgSettingName();
    }

    /**
     * Get request failed message setting name.
     * NB: Change from 3.3.x to camelCase (over crossref::failedMsg)
     *
     */
    private function _getFailedMsgSettingName(): string
    {
        return $this->getName() . '_failedMsg';
    }

    /**
     * Get deposit batch ID setting name.
     * NB: Change from 3.3.x to camelCase (over crossref::batchId)
     *
     */
    private function _getDepositBatchIdSettingName(): string
    {
        return $this->getName() . '_batchId';
    }

    private function _getSuccessMsgSettingName(): string
    {
        return $this->getName() . '_successMsg';
    }

    /**
     * @return CrossrefExportPlugin
     */
    private function _getExportPlugin(): CrossrefExportPlugin
    {
        if (!$this->_exportPlugin instanceof CrossrefExportPlugin) {
            $pluginCategory = 'importexport';
            $pluginPathName = 'CrossrefExportPlugin';
            $this->_exportPlugin = PluginRegistry::getPlugin($pluginCategory, $pluginPathName);
            // If being run from CLI, there is no context, so plugin initialization would not have been fired
            if ($this->_exportPlugin === null /**&& !isset($_SERVER['SERVER_NAME'])*/) {
                $this->_pluginInitialization();
                $this->_exportPlugin = PluginRegistry::getPlugin($pluginCategory, $pluginPathName);
            }
        }
        return $this->_exportPlugin;
    }

    /**
     * Helper to register hooks that are used in normal plugin setup and in CLI tool usage.
     */
    private function _pluginInitialization(): void
    {
        PluginRegistry::register('importexport', new CrossrefExportPlugin($this), $this->getPluginPath());

        Hook::add('DoiSettingsForm::setEnabledRegistrationAgencies', $this-> addAsRegistrationAgencyOption(...));
        Hook::add('DoiSetupSettingsForm::getObjectTypes', $this->addAllowedObjectTypes(...));
        Hook::add('DoiListPanel::setConfig', $this->addRegistrationAgencyName(...));
    }

    /**
     * Includes human-readable name of registration agency for display in conjunction with how/with whom the
     * DOI was registered.
     *
     * @param string $hookName DoiListPanel::setConfig
     * @param array $args [
     *
     *      @option $config array
     * ]
     */
    public function addRegistrationAgencyName(string $hookName, array $args): bool
    {
        $config = &$args[0];
        $config['registrationAgencyNames'][$this->_getExportPlugin()->getName()] = $this->getRegistrationAgencyName();

        return HOOK::CONTINUE;
    }

    /**
     * Adds self to "allowed" list of pub object types that can be assigned DOIs for this registration agency.
     *
     * @param string $hookName DoiSetupSettingsForm::getObjectTypes
     * @param array $args [
     *
     *      @option array &$objectTypeOptions
     * ]
     */
    public function addAllowedObjectTypes(string $hookName, array $args): bool
    {
        $objectTypeOptions = &$args[0];
        $allowedTypes = $this->getAllowedDoiTypes();

        $objectTypeOptions = array_map(function ($option) use ($allowedTypes) {
            if (in_array($option['value'], $allowedTypes)) {
                $option['allowedBy'][] = $this->getName();
            }
            return $option;
        }, $objectTypeOptions);

        return Hook::CONTINUE;
    }

    /**
     * @inheritDoc
     */
    public function getSettingsObject(): RegistrationAgencySettings
    {
        if (!isset($this->_settingsObject)) {
            $this->_settingsObject = new CrossrefSettings($this);
        }

        return $this->_settingsObject;
    }

    /**
     * @inheritDoc
     */
    public function getAllowedDoiTypes(): array
    {
        return [
            Repo::doi()::TYPE_PUBLICATION, //book
            Repo::doi()::TYPE_CHAPTER  //chapter
        ];
    }
}
