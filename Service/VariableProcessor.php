<?php
declare(strict_types=1);

namespace Etechflow\MetaTemplates\Service;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Replaces {{object.attr}} placeholders (with optional {{var|fallback}}) using
 * the current entity context. Supported objects: product, category, cms, store.
 * Any product/category attribute code is available, e.g. {{product.color}}.
 */
class VariableProcessor
{
    public function __construct(
        private StoreManagerInterface $storeManager,
        private PriceCurrencyInterface $priceCurrency
    ) {
    }

    public function process(?string $template, array $context): string
    {
        $template = (string)$template;
        if ($template === '') {
            return '';
        }
        return (string)preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*(?:\|\s*([^}]*?))?\s*\}\}/i',
            function (array $m) use ($context): string {
                $value = $this->resolve(strtolower(trim($m[1])), $context);
                if ($value === '' && isset($m[2])) {
                    return trim($m[2]);
                }
                return $value;
            },
            $template
        );
    }

    private function resolve(string $var, array $context): string
    {
        [$obj, $attr] = array_pad(explode('.', $var, 2), 2, '');
        try {
            $store = $context['store'] ?? $this->storeManager->getStore();
            switch ($obj) {
                case 'store':
                    if ($attr === 'name') {
                        return (string)$store->getFrontendName();
                    }
                    if ($attr === 'url') {
                        return (string)$store->getBaseUrl();
                    }
                    return '';
                case 'product':
                    return isset($context['product']) ? $this->productVar($context['product'], $attr, $store) : '';
                case 'category':
                    return isset($context['category']) ? $this->categoryVar($context['category'], $attr) : '';
                case 'cms':
                    return isset($context['cms']) ? $this->cmsVar($context['cms'], $attr) : '';
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    private function productVar($product, string $attr, $store): string
    {
        switch ($attr) {
            case 'name':
                return (string)$product->getName();
            case 'sku':
                return (string)$product->getSku();
            case 'price':
            case 'final_price':
                return (string)$this->priceCurrency->format((float)$product->getFinalPrice(), false, 2, $store->getId());
            case 'category':
                return $this->deepestCategory($product);
        }
        $text = $product->getAttributeText($attr);
        if ($text) {
            return is_array($text) ? implode(', ', $text) : (string)$text;
        }
        $data = $product->getData($attr);
        return ($data !== null && $data !== '' && !is_array($data)) ? (string)$data : '';
    }

    private function categoryVar($category, string $attr): string
    {
        switch ($attr) {
            case 'name':
                return (string)$category->getName();
            case 'description':
                return trim(strip_tags((string)$category->getData('description')));
        }
        $data = $category->getData($attr);
        return ($data !== null && $data !== '' && !is_array($data)) ? (string)$data : '';
    }

    private function cmsVar($page, string $attr): string
    {
        switch ($attr) {
            case 'title':
                return (string)$page->getTitle();
            case 'content_heading':
                return (string)$page->getContentHeading();
        }
        $data = $page->getData($attr);
        return ($data !== null && $data !== '' && !is_array($data)) ? (string)$data : '';
    }

    private function deepestCategory($product): string
    {
        try {
            $col = $product->getCategoryCollection()->addAttributeToSelect('name')
                ->addAttributeToSort('level', 'desc')->setPageSize(1);
            $cat = $col->getFirstItem();
            return ($cat && $cat->getId()) ? (string)$cat->getName() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
