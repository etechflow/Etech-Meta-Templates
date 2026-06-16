<?php

declare(strict_types=1);

namespace Etechflow\MetaTemplates\Controller\Adminhtml\License;

use Etechflow\MetaTemplates\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\View\Result\PageFactory;

/**
 * Landing page after payment. The buyer returns here from the webstore Paddle
 * checkout (module.etechflow.com) carrying the broker session id; we ask the
 * broker for the issued SP-XXXX key (it only returns one once Paddle has
 * confirmed payment), save it to config, and show the success page. Same shape
 * as the prior Stripe success -> portal activate flow; only the rail changed.
 */
class Activated extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_MetaTemplates::config';

    private const BROKER_URL = 'https://module.etechflow.com/api/license/result';
    private const LICENSE_TOKEN = 'lcsk_8f3b9d2a7c14e605b9af2e7c1d8043f6';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly Curl $curl,
        private readonly WriterInterface $configWriter,
        private readonly CacheInterface $cache,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $sessionId = trim((string) $this->getRequest()->getParam('session_id', ''));
        $plan      = trim((string) $this->getRequest()->getParam('plan', ''));

        if (!$sessionId) {
            $this->messageManager->addErrorMessage(__('Invalid payment callback.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('metatemplates/license/gate');
        }

        $payload = json_encode(['session_id' => $sessionId]);

        $licenseKey = '';
        $planName   = '';
        $error      = '';

        try {
            $this->curl->setTimeout(20);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('X-ETF-License-Token', self::LICENSE_TOKEN);
            $this->curl->post(self::BROKER_URL, $payload);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
            $data   = json_decode($body, true);

            if ($status === 200 && !empty($data['license_key'])) {
                $licenseKey = $data['license_key'];
                $planName   = $data['plan'] ?? $plan;
            } else {
                $error = $data['error'] ?? ('Payment not confirmed yet (status ' . $status . ').');
            }
        } catch (\Throwable $e) {
            $error = 'Could not reach the licensing portal: ' . $e->getMessage();
        }

        if ($licenseKey) {
            $this->configWriter->save(LicenseValidator::XML_PATH_LICENSE_KEY, $licenseKey);
            $this->cache->clean([ConfigCacheType::CACHE_TAG]);
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Subscription Activated'));

        $block = $page->getLayout()->getBlock('etechflow.metatemplates.license.activated');
        if ($block) {
            $block->setData('license_key', $licenseKey)
                  ->setData('plan', $planName)
                  ->setData('error', $error)
                  ->setData('settings_url', $this->getUrl('adminhtml/system_config/edit/section/etechflow_metatemplates'))
                  ->setData('management_url', $this->getUrl('metatemplates/template/index'));
        }

        return $page;
    }
}
