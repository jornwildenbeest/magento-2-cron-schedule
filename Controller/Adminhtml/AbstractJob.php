<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_CronSchedule
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\CronSchedule\Controller\Adminhtml;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Mageplaza\CronSchedule\Helper\Data;
use Mageplaza\CronSchedule\Model\Job;
use Mageplaza\CronSchedule\Model\JobFactory;
use Psr\Log\LoggerInterface;

/**
 * Class AbstractJob
 * @package Mageplaza\CronSchedule\Controller\Adminhtml
 */
abstract class AbstractJob extends Action
{
    /**
     * Authorization level of a basic admin session
     */
    const ADMIN_RESOURCE = 'Mageplaza_CronSchedule::job';

    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var JsonFactory
     */
    protected $jsonFactory;

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var JobFactory
     */
    protected $jobFactory;

    /**
     * @var ScheduleFactory
     */
    protected $scheduleFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var TypeListInterface
     */
    protected $cacheTypeList;

    /**
     * AbstractLog constructor.
     *
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param JsonFactory $jsonFactory
     * @param Registry $registry
     * @param Data $helper
     * @param JobFactory $jobFactory
     * @param ScheduleFactory $scheduleFactory
     * @param LoggerInterface $logger
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        JsonFactory $jsonFactory,
        Registry $registry,
        Data $helper,
        JobFactory $jobFactory,
        ScheduleFactory $scheduleFactory,
        LoggerInterface $logger,
        TypeListInterface $cacheTypeList
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->jsonFactory       = $jsonFactory;
        $this->registry          = $registry;
        $this->helper            = $helper;
        $this->jobFactory        = $jobFactory;
        $this->scheduleFactory   = $scheduleFactory;
        $this->logger            = $logger;
        $this->cacheTypeList     = $cacheTypeList;

        parent::__construct($context);
    }

    /**
     * Load layout, set breadcrumbs
     *
     * @return Page
     */
    protected function _initAction()
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu(self::ADMIN_RESOURCE);

        return $resultPage;
    }

    /**
     * @param string $idFieldName
     *
     * @return Job
     */
    protected function _initJob($idFieldName = 'name')
    {
        $job = $this->jobFactory->create();

        if ($name = $this->getRequest()->getParam($idFieldName)) {
            $job->setData($this->helper->getJobs($name));
        }

        return $job;
    }

    /**
     * @param array $data
     *
     * @return array
     */
    protected function getSelectedRecords($data)
    {
        if (isset($data['selected'])) {
            return $data['selected'];
        }

        $allJobs = $this->helper->getJobs();

        if (isset($data['excluded']) && $data['excluded'] !== 'false') {
            $excluded = $data['excluded'];

            return array_filter(array_keys($allJobs), function ($item) use ($excluded) {
                return !in_array($item, $excluded, true);
            });
        }

        $jobs    = array_values($allJobs);
        $filters = (array) $data['filters'];
        unset($filters['placeholder']);
        foreach ($filters as $column => $value) {
            $jobs = array_filter($jobs, function ($item) use ($column, $value) {
                return stripos($item[$column], $value) !== false;
            });
        }

        return array_column($jobs, 'name');
    }

    /**
     * @param array $jobs
     *
     * @return array
     */
    protected function executeJobs($jobs)
    {
        $success = 0;
        $failure = 0;

        foreach ($jobs as $name) {
            if ($this->helper->isJobDisabled($name)) {
                continue;
            }

            $data = [
                'job_code'     => $name,
                'status'       => Schedule::STATUS_SUCCESS,
                'scheduled_at' => floor(time() / 60) * 60
            ];

            $schedule = $this->scheduleFactory->create()->setData($data);

            try {
                $this->jobFactory->create()->setData($this->helper->getJobs($name))->executeJob($schedule);
                $success++;
            } catch (Exception $e) {
                $schedule->addData([
                    'status'      => Schedule::STATUS_ERROR,
                    'messages'    => $e->getMessage(),
                    'executed_at' => null,
                ]);
                $failure++;
            }

            try {
                $schedule->save();
            } catch (Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        return [$success, $failure];
    }

    /**
     * @param array $jobs
     * @param string $status
     *
     * @return int
     */
    protected function scheduleJobs($jobs, $status = Schedule::STATUS_PENDING)
    {
        $count = 0;

        foreach ($jobs as $name) {
            if ($this->helper->isJobDisabled($name)) {
                continue;
            }

            $data = [
                'job_code'     => $name,
                'status'       => $status,
                'scheduled_at' => floor(time() / 60) * 60
            ];

            try {
                $this->scheduleFactory->create()->setData($data)->save();

                $count++;
            } catch (Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        return $count;
    }
}
