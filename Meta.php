<?php

namespace Amasty\ProductAttachment\Model\Product\DataProvider\Modifiers;

use Amasty\ProductAttachment\Model\ConfigProvider;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;

class Meta
{
    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @var Configurable
     */
    private $configurableProduct;

    /**
     * @var LocatorInterface
     */
    private $locator;

    public function __construct(
        ConfigProvider $configProvider,
        Configurable $configurableProduct,
        LocatorInterface $locator
    ) {
        $this->configProvider = $configProvider;
        $this->configurableProduct = $configurableProduct;
        $this->locator = $locator;
    }

    /**
     * @param array $meta
     *
     * @return array
     */
    public function execute($meta)
    {
        $product = $this->locator->getProduct();

        if ($product->getId()) {
            if ($isPartOfConfigurable = (bool)$this->configurableProduct->getParentIdsByChild($product->getId())) {
                $isPartOfConfigurable = $product->getVisibility() == Visibility::VISIBILITY_NOT_VISIBLE;
            }

            if ($isPartOfConfigurable) {
                $meta['attachments']['arguments']['data']['config']['visible'] = false;
                $meta['attachments']['arguments']['data']['config']['disabled'] = true;
            }

            if ($isPartOfConfigurable || !$this->configProvider->addCategoriesFilesToProducts()) {
                $meta['categories_attachments']['arguments']['data']['config']['visible'] = false;
                $meta['categories_attachments']['arguments']['data']['config']['disabled'] = true;
            }
        }

        return $meta;
    }
}
