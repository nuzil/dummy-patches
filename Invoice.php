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
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as OrderColFactory;

/**
 * Class Invoice using to sync to Invoices table
 *
 * @package Magenest\ZohocrmIntegration\Model\Sync
 */
class Invoice extends Sync
{
    /**
     * @var OrderColFactory
     */
    protected $orderColFactory;

    /**
     * @var ProductLinkCollection
     */
    protected $productLinkCollectionFactory;

    /**
     * Invoice constructor.
     * @param ResourceConnection $resourceConnection
     * @param CollectionFactory $queueFactory
     * @param MapFactory $mapFactory
     * @param Connector $connector
     * @param \Magenest\ZohocrmIntegration\Helper\Data $dataHelper
     * @param Rule $rule
     * @param Data $data
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductRepository $productRepository
     * @param CountryFactory $countryFactory
     * @param Helper $helper
     * @param \Magenest\ZohocrmIntegration\Model\ResourceModel\ProductLink $productLinkResource
     * @param OrderColFactory $orderColFactory
     * @param ProductLinkCollection $productLinkCollectionFactory
     */
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
        OrderColFactory $orderColFactory,
        ProductLinkCollection $productLinkCollectionFactory
    ) {
        $this->productLinkCollectionFactory = $productLinkCollectionFactory;
        $this->orderColFactory = $orderColFactory;
        parent::__construct($resourceConnection, $queueFactory, $mapFactory, $connector, $dataHelper, $rule, $data, $scopeConfig, $productRepository, $countryFactory, $helper, $productLinkResource);
    }

    /**
     * @return string
     */
    public function getType()
    {
        return self::INVOICES;
    }

    /**
     * @param int $website_id
     * @return \Magento\Sales\Model\ResourceModel\Order\Invoice\Collection
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
     * Get All Invoice
     *
     * @param $collections
     * @return string
     */
    public function getCollectionDataV2($collections, $website_id = 0)
    {
        $data = [];
        $number = 0;

        $productIds = [];
        $customerIds = [];
        $orderIds = [];
        foreach ($collections as $collection) {
            $billingAddress = $collection->getBillingAddress();
            $shippingAddress = $collection->getShippingAddress();
            $order = $collection->getOrder();
            if ($order) {
                $collection->setData("customer_email", $order->getData('customer_email'));
                if (!$collection->getOrder()->getData('customer_is_guest')) {
                    $collection->setData("customer_id", $order->getData('customer_id'));
                    $collection->setData("customer_firstname", $order->getData('customer_firstname'));
                    $collection->setData("customer_lastname", $order->getData('customer_lastname'));
                }
                $collection->setData("order_date", $collection->getOrder()->getData('created_at'));
                if (!in_array($order->getId(), $orderIds)) {
                    $orderIds[] = $order->getId();
                }
                if ($collection->getData('customer_email')) {
                    if (!in_array($collection->getData('customer_email'), $customerIds)) {
                        $customerIds[] = $collection->getData('customer_email');
                    }
                }
                $collection->setData('account_name', $collection->getData("customer_firstname") . ' ' . $collection->getData("customer_lastname"));

            }
            $this->setDataBillingAndShippingAddress($collection, $billingAddress, $shippingAddress);
            foreach ($collection->getOrder()->getAllItems() as $it) {
                if (!in_array($it->getProductId(), $productIds)) {
                    $productIds[] = $it->getProductId();
                }
            }
        }
        //sync product lost
        $productLinkArr = $this->syncProductLostWithInvoicesAndSalesOrder($collections, 'Invoices', $website_id, $productIds);

        //sync contact lost
        $contactLinkArr = $this->syncContactLostWithInvoicesAndSalesOrder($collections, $website_id, $customerIds);

        //sync SalesOrder lost
        $enableSyncOrder = $this->scopeConfig->getValue('zohocrm/zohocrm_sync/order');
        if (count($orderIds) > 0 && $enableSyncOrder) {
            $this->syncSalesOrderLost($orderIds, $collections, $website_id);
        }
        $linkCol = $this->productLinkCollectionFactory->create();
        $linkCol->addFieldToFilter("entity_id", ['in' => $orderIds])->addFieldToFilter("type", Queue::TYPE_ORDER);
        $orderLinkData = $linkCol->getData();
        $orderLinkArr = [];
        if ((count($orderLinkData) > 0) && (is_array($orderLinkData))) {
            foreach ($orderLinkData as $value) {
                $orderLinkArr[$value['entity_id']] = $value['zoho_entity_id'];
            }
        }

        foreach ($collections as $collection) {
            $data = $this->getDatMapping($collection->getData(), $number, $data, null, $website_id);
            if (isset($contactLinkArr[$collection->getCustomerEmail()])) {
                $data[$number]['Contact_Name']['id'] = $contactLinkArr[$collection->getData('customer_email')];
            }
            if (isset($orderLinkArr[$collection->getOrder()->getId()])) {
                $data[$number]['Sales_Order'] = $orderLinkArr[$collection->getOrder()->getId()];
            }
            $data[$number]['Account_Name'] = $collection->getData('account_name');
            $data[$number]['Status'] = $collection->getStateName();
            if (count($collection->getAllItems()) > 0) {
                $productVal = [];
                $countProd = 0;
                foreach ($collection->getAllItems() as $keyItem => $item) {
                    $orderItem = $item->getOrderItem();
                    if (!$orderItem->getHasChildren()) {
                        $productId = $item->getProductId();
                        if (isset($productLinkArr[$productId])) {
                            $price = $orderItem->getParentItem() ? $orderItem->getParentItem()->getPrice() : $orderItem->getPrice();
                            $unitPrice = $orderItem->getParentItem() ? $orderItem->getParentItem()->getOriginalPrice() : $orderItem->getOriginalPrice();
                            $total = $item->getData('row_total');
                            $discount = $item->getData('discount_amount');
                            $tax = $item->getData('tax_amount');
                            $productVal[$countProd]['product']['id'] = $productLinkArr[$productId];
                            $productVal[$countProd]['product']['Product_Code'] = $item->getData('sku');
                            $productVal[$countProd]['product']['Product_Name'] = $item->getData('name');
                            $productVal[$countProd]['quantity'] = (int) $item->getData('qty');
                            $productVal[$countProd]['list_price'] = floatval($price);
                            $productVal[$countProd]['unit_price'] = floatval($unitPrice);
                            $productVal[$countProd]['total'] = floatval($total);
                            $total_after_discount = ($total - $discount);
                            $net_total = ($total_after_discount + floatval($tax));
                            $countProd++;
                        }
                    }
                }
                if ($countProd > 0) {
                    $data[$number]['Product_Details'] = $productVal;
                }
            }
            $data[$number]['Adjustment'] = floatval($collection->getData('shipping_amount'));
            $data[$number]['Discount'] = -floatval($collection->getData('discount_amount'));
            $subDis = floatval($collection->getSubtotal() + $collection->getDiscountAmount());
            if ($subDis) {
                $data[$number]['$line_tax'][0]['percentage'] = floatval($collection->getData('tax_amount') / $subDis * 100);
            } else {
                $data[$number]['$line_tax'][0]['percentage'] = 0;
            }
            $data[$number]['$line_tax'][0]['name'] = 'Vat';
            $number++;
        }

        $params['data'] = $data;

        return $params;
    }

    /**
     * Sync Order lost
     *
     * @param $orderIds , $collections
     * @param $collections
     * @param int $website_id
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Zend_Http_Client_Exception
     */
    public function syncSalesOrderLost($orderIds, $collections, $website_id = 0)
    {
        if ((count($orderIds) > 0) && (is_array($orderIds))) {
            foreach ($orderIds as $value) {
                $orderNonLinkArr[]['order_id'] = $value;
            }
            $allOrderId = [];
            $productIds = [];
            $customerIds = [];
            $orderLinkArr = [];
            foreach ($collections as $collection) {
                if ((count($orderNonLinkArr) > 0) && (is_array($orderNonLinkArr))) {
                    foreach ($orderNonLinkArr as $key => $value) {
                        if ($collection->getOrder()->getId() == $value['order_id']) {
                            $orderLinkArr[$key]["order_id"] = $collection->getOrder()->getId();
                            $this->billingAndShippingAddress($key, $collection, $orderLinkArr);
                            $orderLinkArr[$key]['so_number'] = $collection->getOrder()->getData('entity_id');
                            $orderLinkArr[$key]['increment_id'] = $collection->getOrder()->getData('increment_id');
                            $orderLinkArr[$key]['customer_id'] = $collection->getOrder()->getCustomerId();
                            $orderLinkArr[$key]['account_name'] = $collection->getData('account_name');
                            $orderLinkArr[$key]['customer_email'] = $collection->getOrder()->getCustomerEmail();
                            $orderLinkArr[$key]['status'] = $collection->getOrder()->getStatus();
                            $orderLinkArr[$key]['tax_amount'] = $collection->getOrder()->getData('tax_amount');
                            $orderLinkArr[$key]['grand_total'] = $collection->getOrder()->getData('grand_total');
                            $orderLinkArr[$key]['sub_total'] = $collection->getOrder()->getData('subtotal');
                            $orderLinkArr[$key]['discount_amount'] = $collection->getOrder()->getData('discount_amount');
                            $orderLinkArr[$key]['created_at'] = $collection->getOrder()->getData('created_at');
                            $orderLinkArr[$key]['product_details'] = [];
                            $k = 0;
                            if (count($collection->getOrder()->getAllItems()) > 0) {
                                foreach ($collection->getOrder()->getAllItems() as $item) {
                                    if (!$item->getHasChildren()) {
                                        $productId = $item->getProductId();
                                        $orderLinkArr[$key]['product_details'][$k]['product_id'] = $productId;
                                        $orderLinkArr[$key]['product_details'][$k]['price'] = $item->getParentItem() ? $item->getParentItem()->getData('price') : $item->getData('price');
                                        $orderLinkArr[$key]['product_details'][$k]['unit_price'] = $item->getParentItem() ? $item->getParentItem()->getOriginalPrice() : $item->getOriginalPrice();
                                        $orderLinkArr[$key]['product_details'][$k]['row_total'] = $item->getParentItem() ? $item->getParentItem()->getData('row_total') : $item->getData('row_total');
                                        $orderLinkArr[$key]['product_details'][$k]['discount_amount'] = $item->getData('discount_amount');
                                        $orderLinkArr[$key]['product_details'][$k]['tax_amount']      = $item->getData('tax_amount');
                                        $orderLinkArr[$key]['product_details'][$k]['qty_ordered']     = $item->getData('qty_ordered');
                                        $orderLinkArr[$key]['product_details'][$k]['tax_percent']     = $item->getData('tax_percent');
                                        if (!in_array($productId, $productIds)) {
                                            $productIds[] = $productId;
                                        }
                                        $k++;
                                    }
                                }
                            }
                            $orderLinkArr[$key]['shipping_amount'] = $collection->getOrder()->getData('shipping_amount');

                            if ($collection->getData('customer_email')) {
                                $customerIds[] = $collection->getData('customer_email');
                            }
                            if (!in_array($value['order_id'], $allOrderId)) {
                                $allOrderId[] = $value['order_id'];
                            }
                        }
                    }
                }
            }
            while (count($orderLinkArr) > 0) {
                $records = array_slice($orderLinkArr, 0, Connector::MAX_RECORD_PER_CONNECT);
                array_splice($orderLinkArr, 0, Connector::MAX_RECORD_PER_CONNECT);
                $syncSaleOrders = $this->allOder($records, $productIds, $customerIds, $website_id);
                $response = $this->connector->insertRecordsV2(Queue::TYPE_ORDER, $syncSaleOrders, $website_id);
                //parse response data
                $this->dataHelper->processInsertEntityResponse($response, $allOrderId, Queue::TYPE_ORDER, true, $website_id);
            }
        }
    }

    /**
     * Get all record Order
     *
     * @param $orderNonLinkArr
     * @param $productIds
     * @param $customerIds
     * @param int $website_id
     * @return array
     */
    public function allOder($orderNonLinkArr, $productIds, $customerIds, $website_id = 0)
    {
        $data = [];
        $number = 0;
        $linkCol = $this->productLinkCollectionFactory->create()->addFieldToFilter("entity_id", ['in' => $productIds])->addFieldToFilter("type", "Products");

        $productLinkData = $linkCol->getData();
        $productLinkArr = [];
        if ((count($productLinkData) > 0) && (is_array($productLinkData))) {
            foreach ($productLinkData as $value) {
                $productLinkArr[$value['entity_id']] = $value['zoho_entity_id'];
            }
        }
        $linkCol = $this->productLinkCollectionFactory->create();
        $linkCol->addFieldToFilter("entity_id", ['in' => $customerIds])->addFieldToFilter("type", "Contacts");
        $contactLinkData = $linkCol->getData();
        $contactLinkArr = [];

        if ((count($contactLinkData) > 0) && (is_array($contactLinkData))) {
            foreach ($contactLinkData as $value) {
                $contactLinkArr[$value['entity_id']] = $value['zoho_entity_id'];
            }
        }
        foreach ($orderNonLinkArr as $value) {
            $data = $this->getDatMapping($value, $number, $data, Queue::TYPE_ORDER, $website_id);
            if (isset($contactLinkArr[$value['customer_email']])) {
                $data[$number]['Contact_Name']['id'] = $contactLinkArr[$value['customer_email']];
            }
            if (isset($value['account_name'])) {
                $data[$number]['Account_Name'] = $value['account_name'];
            }
            if (isset($value['status'])) {
                $data[$number]['Status'] = $value['status'];
            }
            if (isset($value['discount_amount'])) {
                $data[$number]['Discount'] = -floatval($value['discount_amount']);
            }

            if (count($value['product_details']) > 0) {
                $productVal = [];
                $countProd = 0;
                foreach ($value['product_details'] as $product) {
                    if (isset($productLinkArr[$product['product_id']])) {
                        $price = $product['price'] ?? null;
                        $unitPrice = $product['unit_price'] ?? null;
                        $total = $product['row_total'] ?? null;
                        $discount = $product['discount_amount'] ?? null;
                        $tax = $product['tax_amount'] ?? null;
                        $productVal[$countProd]['product']['id'] = $productLinkArr[$product['product_id']] ?? null;
                        $productVal[$countProd]['quantity'] = isset($product['qty_ordered']) ? (int) $product['qty_ordered'] : 0;
                        $productVal[$countProd]['list_price'] = floatval($price);
                        $productVal[$countProd]['unit_price'] = floatval($price);
                        $total_after_discount = ($total - $discount);
                        $net_total = ($total_after_discount + $tax);
                        $countProd++;
                    }
                }
                if ($countProd > 0) {
                    $data[$number]['Product_Details'] = $productVal;
                }
            }
            $data[$number]['Adjustment'] = isset($value['shipping_amount']) ? floatval($value['shipping_amount']) : 0;
            $subDis = floatval($value['sub_total'] + $value['discount_amount']);
            if ($subDis) {
                $data[$number]['$line_tax'][0]['percentage'] = floatval($value['tax_amount'] / $subDis * 100);
            } else {
                $data[$number]['$line_tax'][0]['percentage'] = 0;
            }
            $data[$number]['$line_tax'][0]['name'] = 'Vat';
            $number++;
        }
        $params['data'] = $data;

        return $params;
    }
}
