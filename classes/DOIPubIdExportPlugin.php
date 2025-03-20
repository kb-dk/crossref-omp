<?php

/**
 * @file plugins/generic/crossref/classes/DOIPubIdExportPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DOIPubIdExportPlugin
 *
 * @ingroup plugins
 *
 * @brief Basis class for DOI XML metadata export plugins
 */

namespace APP\plugins\generic\crossref\classes;

use APP\facades\Repo;
use APP\monograph\Chapter;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\core\PKPString;

abstract class DOIPubIdExportPlugin extends PubObjectsExportPlugin
{
    // Configuration errors.
    public const DOI_EXPORT_CONFIG_ERROR_DOIPREFIX = 0x01;

    // The name of the setting used to save the registered DOI.
    public const DOI_EXPORT_REGISTERED_DOI = 'registeredDoi';

    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request): void
    {
        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
            default:
                parent::display($args, $request);
        }
    }

    /**
     * Get pub ID type
     *
     * @return string
     */
    public function getPubIdType(): string
    {
        return 'doi';
    }

    /**
     * Get pub ID display type
     *
     * @return string
     */
    public function getPubIdDisplayType(): string
    {
        return 'DOI';
    }


    /**
     * Mark selected publication as registered.
     *
     * @param Journal $context
     * @param array $objects Array of published submissions or chapters
     */
    public function markRegistered($context, $objects)
    {
        foreach ($objects as $object) {
            $doiId = $object->getData('doiId');

            if ($doiId != null) {
                Repo::doi()->markRegistered($doiId);
            }
        }
    }

    /**
     * Saving object's DOI to the object's
     * "registeredDoi" setting.
     * We prefix the setting with the plugin's
     * id so that we do not get name clashes
     * when several DOI registration plug-ins
     * are active at the same time.
     *
     * @param Context $context
     * @param Submission|Chapter $object
     * @param string $testPrefix
     */
    public function saveRegisteredDoi($context, $object, $testPrefix = '10.1234')
    {
        $registeredDoi = $object->getStoredPubId('doi');
        assert(!empty($registeredDoi));
        if ($this->isTestMode($context)) {
            $registeredDoi = PKPString::regexp_replace('#^[^/]+/#', $testPrefix . '/', $registeredDoi);
        }
        $object->setData($this->getPluginSettingsPrefix() . '::' . self::DOI_EXPORT_REGISTERED_DOI, $registeredDoi);
        $this->updateObject($object);
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     *
     * @return array
     */
    protected function _getObjectAdditionalSettings(): array
    {
        return array_merge(parent::_getObjectAdditionalSettings(), [
            $this->getPluginSettingsPrefix() . '::' . self::DOI_EXPORT_REGISTERED_DOI
        ]);
    }

    /**
     * Get published submissions with a DOI assigned from submission IDs.
     *
     * @param array $submissionIds
     * @param Context $context
     *
     * @return array
     */
    public function getPublishedSubmissions($submissionIds, $context)
    {
        $allSubmissionIds = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED])
            ->getIds()
            ->toArray();
        $validSubmissionIds = array_intersect($allSubmissionIds, $submissionIds);
        $submissions = array_map(function ($submissionId) {
            return Repo::submission()->get($submissionId);
        }, $validSubmissionIds);
        return array_filter($submissions, function ($submission) {
            return $submission->getCurrentPublication()->getDoi() !== null;
        });
    }

    /**
     * Get published chapters with a DOI assigned from chapter IDs.
     *
     * @param array $chapterIds
     * @param Context $context
     *
     * @return array
     */
    public function getPublishedChapters(array $chapterIds, Context $context): array
    {
        $validPublishedChapters = parent::getPublishedChapters($chapterIds, $context);
        $validPublishedChaptersWithDoi = [];
        /** @var Chapter $chapter */
        foreach ($validPublishedChapters as $chapter) {
            if ($chapter->getDoi() !== null) {
                $validPublishedChaptersWithDoi[] = $chapter;
            }
        }

        return $validPublishedChaptersWithDoi;
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args): void
    {
    }

    /**
     * @copydoc ImportExportPlugin::supportsCLI()
     */
    public function supportsCLI(): bool
    {
        return false;
    }

}