<?php
declare(strict_types=1);

namespace Etechflow\MetaTemplates\Service;

use Magento\Framework\Registry;
use Magento\Framework\App\Request\Http;
use Magento\Store\Model\StoreManagerInterface;
use Etechflow\MetaTemplates\Model\ResourceModel\Template\CollectionFactory;
use Etechflow\MetaTemplates\Model\Config;
use Magento\Cms\Model\Page as CmsPage;

/**
 * Resolves the matching template's meta for the current request. Memoized per
 * request — but only caches once a definitive answer is known, so early reads
 * (before the entity is in the registry) don't poison the cache.
 */
class MetaResolver
{
    private bool $resolved = false;
    private ?array $cache = null;

    public function __construct(
        private Registry $registry,
        private Http $request,
        private StoreManagerInterface $storeManager,
        private CollectionFactory $collectionFactory,
        private VariableProcessor $processor,
        private Config $config,
        private CmsPage $cmsPage
    ) {
    }

    /**
     * @return array{title:?string,description:?string,keywords:?string}|null
     */
    public function resolve(): ?array
    {
        if ($this->resolved) {
            return $this->cache;
        }
        if (!$this->config->isEnabled()) {
            $this->resolved = true;
            return $this->cache = null;
        }
        $type = $this->pageType();
        if (!$type) {
            $this->resolved = true;
            return $this->cache = null;
        }
        $context = $this->buildContext($type);
        if ($context === null) {
            return null; // entity not ready yet — retry on a later read, don't cache
        }

        $this->resolved = true;
        $rule = $this->matchRule($type, $context);
        if (!$rule) {
            return $this->cache = null;
        }

        $override = $this->config->isOverride();
        $entity   = $context['product'] ?? $context['category'] ?? null;

        $title = $this->maybe($rule->getData('meta_title'), $context, $override, $entity, 'meta_title');
        $desc  = $this->maybe($rule->getData('meta_description'), $context, $override, $entity, 'meta_description');
        $keys  = $this->maybe($rule->getData('meta_keywords'), $context, $override, $entity, 'meta_keyword');

        if ($title === null && $desc === null && $keys === null) {
            return $this->cache = null;
        }
        return $this->cache = ['title' => $title, 'description' => $desc, 'keywords' => $keys];
    }

    private function maybe($template, array $context, bool $override, $entity, string $entityField): ?string
    {
        if (!$template) {
            return null;
        }
        if (!$override && $entity && trim((string)$entity->getData($entityField)) !== '') {
            return null;
        }
        $val = trim($this->processor->process((string)$template, $context));
        return $val !== '' ? $val : null;
    }

    private function pageType(): ?string
    {
        return match ($this->request->getFullActionName()) {
            'catalog_product_view'             => 'product',
            'catalog_category_view'            => 'category',
            'cms_page_view', 'cms_index_index' => 'cms_page',
            default                            => null,
        };
    }

    private function buildContext(string $type): ?array
    {
        $ctx = ['store' => $this->storeManager->getStore()];
        if ($type === 'product') {
            $p = $this->registry->registry('current_product');
            if (!$p || !$p->getId()) {
                return null;
            }
            $ctx['product'] = $p;
        } elseif ($type === 'category') {
            $c = $this->registry->registry('current_category');
            if (!$c || !$c->getId()) {
                return null;
            }
            $ctx['category'] = $c;
        } elseif ($type === 'cms_page') {
            // The storefront never registers 'cms_page' in the registry (only the
            // adminhtml page-edit controller does), so read the current page from the
            // shared Cms\Model\Page that Helper\Page loads during the controller.
            $pg = $this->registry->registry('cms_page');
            if (!$pg || !$pg->getId()) {
                $pg = $this->cmsPage;
            }
            if (!$pg->getId()) {
                return null; // page not loaded yet — retry on a later read, don't cache
            }
            $ctx['cms'] = $pg;
        }
        return $ctx;
    }

    private function matchRule(string $type, array $context)
    {
        $storeId = (int)$context['store']->getId();
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('applies_to', $type)
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('store_id', ['in' => [0, $storeId]])
            ->setOrder('priority', 'DESC')
            ->setOrder('template_id', 'DESC');

        foreach ($collection as $rule) {
            $catId = (int)$rule->getData('category_id');
            if ($type === 'product' && $catId) {
                $ids = array_map('intval', (array)$context['product']->getCategoryIds());
                if (!in_array($catId, $ids, true)) {
                    continue;
                }
            }
            return $rule;
        }
        return null;
    }
}
