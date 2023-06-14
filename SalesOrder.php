<?php
/**
 * Copyright © 2015 Magenest. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Magenest_ZohocrmIntegration extension
 * NOTICE OF LICENSE
 *
 * @category Magenest
 * @package  Magenest_ZohocrmIntegration
 * @author   ThaoPV
 */

namespace Magenest\ZohocrmIntegration\Model\Sync;

use Magenest\ZohocrmIntegration\Helper\Helper;
use Magenest\ZohocrmIntegration\Model\Connector;
use Magenest\ZohocrmIntegration\Model\Data;
use Magenest\ZohocrmIntegration\Model\MapFactory;
use Magenest\ZohocrmIntegration\Model\Queue;
use Magenest\ZohocrmIntegration\Model\ResourceModel\ProductLink\CollectionFactory as ProductLinkCollection;
use Magenest\ZohocrmIntegration\Model\ResourceModel\Queue\CollectionFactory;
use Magento\Catalog\Model\ProductRepository;
use Magento\CatalogRule\Model\Rule;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Class SalesOrder using to sync SalesOrder
 *
 * @package Magenest\ZohocrmIntegration\Model\Sync
 */
class SalesOrder extends Sync
{
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $orderColFactory;

    /**
     * @var ProductLinkCollection
     */
    protected $productLinkCollectionFactory;

    /**
     * @var \Magento\Tax\Api\OrderTaxManagementInterface
     */
    protected $orderTax;

    /**
     * @var Tax
     */
    protected $taxMapping;

    public function __construct(
        ResourceConnection $resourceConnection,
        CollectionFactory $queueFactory,
        MapFactory $mapFactory,
        Connector $connector,
        \Magenest\ZohocrmIntegration\Helper\Data $dataHelper,
        Rule $rule,
        Data $data,
        ScopeConfigInterface $scopeConfig,
        ProductRepository $productRepository,
        CountryFactory $countryFactory,
        Helper $helper,
        \Magenest\ZohocrmIntegration\Model\ResourceModel\ProductLink $productLinkResource,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderColFactory,
        ProductLinkCollection $productLinkCollectionFactory,
        \Magento\Tax\Api\OrderTaxManagementInterface $orderTaxManagement,
        Tax $taxMapping
    ) {
        $this->productLinkCollectionFactory = $productLinkCollectionFactory;
        $this->orderColFactory = $orderColFactory;
        $this->orderTax = $orderTaxManagement;
        $this->taxMapping = $taxMapping;
        parent::__construct($resourceConnection, $queueFactory, $mapFactory, $connector, $dataHelper, $rule, $data, $scopeConfig, $productRepository, $countryFactory, $helper, $productLinkResource);
    }

    /**
     * @return string
     */
    public function getType()
    {
        return Queue::TYPE_ORDER;
    }

    /**
     * @param int $website_id
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    public function getCollection($website_id = 0)
    {
        $collections = $this->orderColFactory->create()->addAttributeToSelect('*');
        $storeArray = $this->connector->getAllStoreByWebsiteId($website_id);
        if (!empty($storeArray)) {
            $collections->addFieldToFilter('store_id', ['in' => $storeArray]);
        }

        return $collections;
    }

    /**
     * Get all sales order
     *
     * @param \Magento\Sales\Model\ResourceModel\Order\Collection $collections
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCollectionDataV2($collections, $website_id = 0)
    {
        $data = [];
        $number = 0;
        //get list product need to resync
        $productIds = [];
        $customerIds = [];
        foreach ($collections as $collection) {
            $billingAddress = $collection->getBillingAddress();
            $shippingAddress = $collection->getShippingAddress();
            $this->setDataBillingAndShippingAddress($collection, $billingAddress, $shippingAddress);
            foreach ($collection->getAllItems() as $it) {
                if (!in_array($it->getProductId(), $productIds)) {
                    $productIds[] = $it->getProductId();
                }
            }

            if ($collection->getData("bill_company")) {
                $collection->setData('account_name', $collection->getData("bill_company"));
            } else {
                $collection->setData('account_name', $collection->getData("bill_firstname") . ' ' . $collection->getData("bill_lastname"));
            }
            if ($collection->getData('customer_email')) {
                if (!in_array($collection->getData('customer_email'), $customerIds)) {
                    $customerIds[] = $collection->getData('customer_email');
                }
            }
        }
        //sync product lost
        $productLinkArr = $this->syncProductLostWithInvoicesAndSalesOrder($collections, 'SalesOrder', $website_id, $productIds);

        //sync contact lost
        $contactLinkArr = $this->syncContactLostWithInvoicesAndSalesOrder($collections, $website_id, $customerIds);

        foreach ($collections as $collection) {
            $data = $this->getDatMapping($collection->getData(), $number, $data, null, $website_id);
            if (isset($contactLinkArr[$collection->getCustomerEmail()])) {
                $data[$number]['Contact_Name']['id'] = $contactLinkArr[$collection->getData('customer_email')];
            }
            $data[$number]['Account_Name'] = $collection->getData('account_name');
            $data[$number]['Status'] = $collection->getData('status');
            $data[$number]['Tax'] = $collection->getData('tax_amount');
            $data[$number]['Grand_Total'] = $collection->getData('grand_total');
            $data[$number]['Sub_Total'] = $collection->getData('subtotal');
            $data[$number]['Discount'] = -floatval($collection->getData('discount_amount'));
            $data[$number]['Subject'] = $collection->getData('increment_id');
            $taxDetails = $this->orderTax->getOrderTaxDetails($collection->getId());
            $taxOrder = [];
            foreach ($taxDetails->getAppliedTaxes() as $tax) {
                $taxCode = $tax->getCode();
                $taxOrder[] = [
                    'name' => $this->taxMapping->getZohoTaxCode($taxCode) ?? $taxCode,
                    'percentage' => floatval($tax->getPercent()),
                    'value' => $tax->getAmount()
                ];
            }

            if (count($collection->getAllItems()) > 0) {
                $productVal = [];
                $countProd = 0;
                foreach ($collection->getAllItems() as $item) {
                    if (!$item->getHasChildren()) {
                        $productId = $item->getProductId();
                        if (isset($productLinkArr[$productId])) {
                            $price = $item->getParentItem() ? $item->getParentItem()->getPrice() : $item->getPrice();
                            $total = $item->getData('row_total');
                            $discount = $item->getData('discount_amount');
                            $tax = $item->getData('tax_amount');
                            $productVal[$countProd]['product']['id'] = $productLinkArr[$productId];
                            $productVal[$countProd]['product']['Product_Code'] = $item->getData('sku');
                            $productVal[$countProd]['product']['Product_Name'] = $item->getData('name');
                            $productVal[$countProd]['quantity'] = (int) $item->getData('qty_ordered');
                            $productVal[$countProd]['list_price'] = floatval($price);
                            $productVal[$countProd]['unit_price'] = floatval($price);
                            $productVal[$countProd]['total'] = floatval($total);
                            $total_after_discount = ($total - $discount);
                            $productVal[$countProd]['total_after_discount'] = $total_after_discount;
                            $countProd++;
                        }
                    }
                }
                if ($countProd > 0) {
                    $data[$number]['Product_Details'] = $productVal;
                }
            }
            $data[$number]['Adjustment'] = floatval($collection->getData('shipping_amount'));
            $data[$number]['$line_tax'] = $taxOrder;
            $number++;
        }
        $params['data'] = $data;

        return $params;
    }
}
