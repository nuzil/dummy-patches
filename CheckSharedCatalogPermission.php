<?php
/**
 * ADOBE CONFIDENTIAL
 *
 *  Copyright 2024 Adobe
 *  All Rights Reserved.
 *
 *  NOTICE: All information contained herein is, and remains
 *  the property of Adobe and its suppliers, if any. The intellectual
 *  and technical concepts contained herein are proprietary to Adobe
 *  and its suppliers and are protected by all applicable intellectual
 *  property laws, including trade secret and copyright laws.
 *  Dissemination of this information or reproduction of this material
 *  is strictly forbidden unless prior written permission is obtained
 *  from Adobe.
 */
declare(strict_types=1);

namespace Magento\SharedCatalog\Plugin\Checkout\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Company\Api\CompanyRepositoryInterface;
use Magento\Company\Model\CompanyContextInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SharedCatalog\Api\Data\ProductItemInterface;
use Magento\SharedCatalog\Api\StatusInfoInterface;
use Magento\Checkout\Model\Cart;
use Magento\SharedCatalog\Model\SharedCatalogResolver;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\SharedCatalog\Model\ResourceModel\ProductItem\CollectionFactory;

/**
 * Plugin to check if product is available under existing shared catalog mapping.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CheckSharedCatalogPermission
{
    /**
     * @param StatusInfoInterface $config
     * @param StoreManagerInterface $storeManager
     * @param SharedCatalogResolver $sharedCatalogResolver
     * @param CompanyRepositoryInterface $companyRepository
     * @param CompanyContextInterface $companyContext
     * @param CollectionFactory $sharedCatalogProductCollectionFactory
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        private readonly StatusInfoInterface        $config,
        private readonly StoreManagerInterface      $storeManager,
        private readonly SharedCatalogResolver      $sharedCatalogResolver,
        private readonly CompanyRepositoryInterface $companyRepository,
        private readonly CompanyContextInterface    $companyContext,
        private readonly CollectionFactory          $sharedCatalogProductCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    /**
     * Check if product is available to current group.
     *
     * @param string $sku
     * @param mixed $customerGroupId
     * @return bool
     */
    private function isProductAvailableToGroup(string $sku, int|string $customerGroupId): bool
    {
        $collection = $this->sharedCatalogProductCollectionFactory->create();
        $collection
            ->addFieldToSelect(ProductItemInterface::SKU)
            ->addFieldToFilter(ProductItemInterface::CUSTOMER_GROUP_ID, $customerGroupId)
            ->setPageSize(1);
        $collection->getSelect()->where(\sprintf('%s = ?', ProductItemInterface::SKU), $sku);

        return $collection->getSize() > 0;
    }

    /**
     * Check if product available in shared catalog.
     *
     * @param Cart $subject
     * @param mixed $productInfo
     * @param mixed|null $requestInfo
     * @return array
     * @throws LocalizedException
     */
    public function beforeAddProduct(
        Cart $subject,
        mixed $productInfo,
        mixed $requestInfo = null
    ): array {
        $isCompanyConfigEnabled = $this->config->isActive(
            ScopeInterface::SCOPE_WEBSITE,
            $this->storeManager->getWebsite()->getId()
        );
        $companyId = $this->companyContext->getCompanyId();
        if (!$isCompanyConfigEnabled || $companyId === null || $companyId === 0) {
            return [$productInfo, $requestInfo];
        }

        $groupId = (int)$this->companyRepository->get($companyId)->getCustomerGroupId();
        $isPrimaryCatalogAvailable = $this->sharedCatalogResolver->isPrimaryCatalogAvailable($groupId);
        if ($isPrimaryCatalogAvailable) {
            return [$productInfo, $requestInfo];
        }

        $product = $this->getProduct($productInfo);

        if (!$this->isProductAvailableToGroup($product->getSku(), $groupId)) {
            throw new LocalizedException(
                __("The product that was requested can't be added to the cart. "
                    . "Verify access rights to the product and try again.")
            );
        }

        return [$productInfo, $requestInfo];
    }

    /**
     * Get product object.
     *
     * @param mixed $productInfo
     * @return Product
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getProduct(mixed $productInfo): ?Product
    {
        $product = null;
        if ($productInfo instanceof Product) {
            $product = $productInfo;
        } elseif (is_int($productInfo) || is_string($productInfo)) {
            try {
                $product = $this->productRepository->getById($productInfo);
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(
                    __("The product wasn't found. Verify the product and try again."),
                    $e
                );
            }
        } else {
            throw new LocalizedException(
                __("The product wasn't found. Verify the product and try again.")
            );
        }

        return $product;
    }
}
