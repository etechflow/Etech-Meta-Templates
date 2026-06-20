<?php
declare(strict_types=1);

namespace Etechflow\MetaTemplates\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Adds an admin bell-icon (AdminNotification inbox) entry when a newer version
 * of Meta Templates is published to the eTechFlow private Composer repo. Fires when
 * the admin opens the module's admin area. Re-added if dismissed; never duplicated
 * while an active entry for the same version exists. Fail-safe.
 */
class AddUpdateNotification implements ObserverInterface
{
    private const PACKAGE     = 'etechflow/module-meta-templates';
    private const LATEST_URL  = 'https://license-service.etechflow.com/composer/latest/etechflow/module-meta-templates.json';
    private const CACHE_KEY   = 'etechflow_mt_latest_version';
    private const CACHE_TTL   = 21600;
    private const LABEL       = 'Meta Templates';

    /** @var NotifierInterface */
    private $notifier;
    /** @var CurlFactory */
    private $curlFactory;
    /** @var CacheInterface */
    private $cache;
    /** @var ResourceConnection */
    private $resource;

    public function __construct(
        NotifierInterface $notifier,
        CurlFactory $curlFactory,
        CacheInterface $cache,
        ResourceConnection $resource
    ) {
        $this->notifier = $notifier;
        $this->curlFactory = $curlFactory;
        $this->cache = $cache;
        $this->resource = $resource;
    }

    public function execute(Observer $observer)
    {
        try {
            $latest = $this->latest();
            if (empty($latest['version'])) {
                return;
            }
            $installed = $this->installedVersion();
            if ($installed === '' || version_compare($installed, $latest['version'], '>=')) {
                return;
            }
            $title = (string) __('eTechFlow %1 %2 is available', self::LABEL, $latest['version']);

            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName('adminnotification_inbox');
            $exists = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM {$table} WHERE title = ? AND is_remove = 0",
                [$title]
            );
            if ($exists > 0) {
                return;
            }

            $desc = $latest['notes'] !== ''
                ? $latest['notes']
                : (string) __(
                    'A new version (%1) is available — you have %2. Update with: composer update %3',
                    $latest['version'],
                    $installed,
                    self::PACKAGE
                );
            $this->notifier->addNotice($title, $desc);
        } catch (\Throwable $e) {
            // never interrupt the admin page
        }
    }

    private function latest(): array
    {
        $raw = $this->cache->load(self::CACHE_KEY);
        if ($raw === false || $raw === null || $raw === '') {
            $raw = '{}';
            try {
                $curl = $this->curlFactory->create();
                $curl->setTimeout(5);
                $curl->get(self::LATEST_URL);
                if ((int) $curl->getStatus() === 200) {
                    $raw = (string) $curl->getBody();
                }
            } catch (\Throwable $e) {
                $raw = '{}';
            }
            $this->cache->save($raw, self::CACHE_KEY, [], self::CACHE_TTL);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['latest_version'])) {
            return ['version' => '', 'notes' => ''];
        }
        return [
            'version' => (string) $data['latest_version'],
            'notes'   => (string) ($data['release_notes'] ?? ''),
        ];
    }

    private function installedVersion(): string
    {
        if (class_exists('\Composer\InstalledVersions')) {
            try {
                $v = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE);
                if ($v) {
                    return ltrim((string) $v, 'v');
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return '';
    }
}
