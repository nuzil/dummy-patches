<?php

/**
 * PAYONE Magento 2 Connector is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PAYONE Magento 2 Connector is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with PAYONE Magento 2 Connector. If not, see <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 *
 * @category  Payone
 * @package   Payone_Magento2_Plugin
 * @author    FATCHIP GmbH <support@fatchip.de>
 * @copyright 2003 - 2016 Payone GmbH
 * @license   <http://www.gnu.org/licenses/> GNU Lesser General Public License
 * @link      http://www.payone.de
 */

namespace Payone\Core\Helper;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Store\Model\Store;
use Payone\Core\Model\Methods\PayoneMethod;
use Magento\Framework\Exception\LocalizedException;

/**
 * Toolkit class for methods that dont fit in a certain drawer
 */
class Toolkit extends \Payone\Core\Helper\Base
{
    /**
     * PAYONE payment helper
     *
     * @var \Payone\Core\Helper\Payment
     */
    protected $paymentHelper;

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Helper\Context      $context
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Payone\Core\Helper\Payment                $paymentHelper
     * @param \Payone\Core\Helper\Shop                   $shopHelper
     * @param \Magento\Framework\App\State               $state
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Payone\Core\Helper\Payment $paymentHelper,
        \Payone\Core\Helper\Shop $shopHelper,
        \Magento\Framework\App\State $state
    ) {
        parent::__construct($context, $storeManager, $shopHelper, $state);
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Get security keys for all payment types for the given store code
     *
     * @param  string $sStoreCode
     * @return array
     */
    protected function getAllPayoneSecurityKeysByStoreCode($sStoreCode)
    {
        $aKeys = [];
        foreach ($this->paymentHelper->getAvailablePaymentTypes() as $sPaymentCode) {
            $iUseGlobal = $this->getConfigParam('use_global', $sPaymentCode, 'payone_payment', $sStoreCode);
            if ($iUseGlobal == '0') {
                $aKeys[] = $this->getConfigParam('key', $sPaymentCode, 'payone_payment', $sStoreCode);
            }
        }
        return $aKeys;
    }

    /**
     * Get the configured security keys for all available stores
     * and payment types - since every payment-type can have its own
     *
     * @return array
     */
    public function getAllPayoneSecurityKeys()
    {
        $aKeys = $this->getConfigParamAllStores('key');
        $aShopIds = $this->storeManager->getStores(false, true);
        foreach ($aShopIds as $sStoreCode => $oStore) {
            $aKeys = array_merge($aKeys, $this->getAllPayoneSecurityKeysByStoreCode($sStoreCode));
        }
        return array_unique($aKeys);
    }

    /**
     * Check wheither the given key is configured in the shop and thus valid
     *
     * @param  string $sKey
     * @return bool
     */
    public function isKeyValid($sKey)
    {
        $aKeyValues = $this->getAllPayoneSecurityKeys();
        foreach ($aKeyValues as $sConfigKey) {
            if ($this->hashString($sConfigKey ?? '') == $sKey) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replace substitutes in a given text with the given replacements
     *
     * @param  string   $sText
     * @param  array    $aSubstitutionArray
     * @param  int|bool $iMaxLength
     * @return string
     */
    public function handleSubstituteReplacement($sText, $aSubstitutionArray, $iMaxLength = false)
    {
        if (!empty($sText)) {
            $sText = str_replace(['{{', '}}'], ['{', '}'], $sText); // backwards compatibility for changes in MAG2-248
            $sText = str_replace(array_keys($aSubstitutionArray), array_values($aSubstitutionArray), $sText);
            if ($iMaxLength !== false && strlen($sText) > $iMaxLength) {
                $sText = substr($sText, 0, $iMaxLength); // shorten text if too long
            }
            return $sText;
        }
        return '';
    }

    /**
     * Get substituted invoice appendix text
     *
     * @param  SalesOrder $oOrder
     * @return string
     */
    public function getInvoiceAppendix(SalesOrder $oOrder)
    {
        $sText = $this->getConfigParam('invoice_appendix', 'invoicing'); // get invoice appendix from config
        $aSubstitutionArray = [
            '{order_increment_id}' => $oOrder->getIncrementId(),
            '{customer_id}' => $oOrder->getCustomerId(),
        ];
        $sInvoiceAppendix = $this->handleSubstituteReplacement($sText, $aSubstitutionArray, 255);
        return $sInvoiceAppendix;
    }

    /**
     * Returns narrative text for authorization request
     *
     * @param  SalesOrder   $oOrder
     * @param  PayoneMethod $oPayment
     * @return string
     */
    public function getNarrativeText(SalesOrder $oOrder, PayoneMethod $oPayment)
    {
        $sText = $this->getConfigParam('narrative_text', $oPayment->getCode(), 'payone_payment'); // get narrative text for payment from config
        $aSubstitutionArray = [
            '{order_increment_id}' => $oOrder->getIncrementId(),
        ];
        $sNarrativeText = $this->handleSubstituteReplacement($sText, $aSubstitutionArray, $oPayment->getNarrativeTextMaxLength());
        return $sNarrativeText;
    }

    /**
     * Format a price to the XX.YY format
     *
     * @param  double $dPrice    price of any sort
     * @param  int    $iDecimals number of digits behind the decimal point
     * @return string
     */
    public function formatNumber($dPrice, $iDecimals = 2)
    {
        return number_format($dPrice, $iDecimals, '.', '');
    }

    /**
     * Masks IBAN
     *
     * @param string $sUnmasked
     * @return string
     */
    public function maskIban($sUnmasked)
    {
        $sMasked = '';
        for ($i = 0; $i < strlen($sUnmasked); $i++) {
            if ($i == 2 || ((strlen($sUnmasked) - 2) % 4 == 0 && (($i - 2) % 4 == 0))) {
                $sMasked .= ' ';
            }

            if ($i < 4 || $i >= (strlen($sUnmasked) - 4)) {
                $sMasked .= $sUnmasked[$i];
            } else {
                $sMasked .= 'x';
            }
        }
        return $sMasked;
    }

    /**
     * Checks if given string is utf8 encoded
     *
     * @param  string $sString
     * @return bool
     */
    public function isUTF8($sString)
    {
        return $sString === mb_convert_encoding(mb_convert_encoding($sString ?? '', "UTF-32", "UTF-8"), "UTF-8", "UTF-32");
    }

    /**
     * Return data from data-object
     * Needed because of different ways to read from it for different magento versions
     *
     * @param  DataObject $oData
     * @param  string     $sKey
     * @return string|null
     */
    public function getAdditionalDataEntry(DataObject $oData, $sKey)
    {
        // The way to read the form-parameters changed with version 2.0.6
        if (version_compare($this->shopHelper->getMagentoVersion(), '2.0.6', '>=')) { // Magento 2.0.6 and above
            $aAdditionalData = $oData->getAdditionalData();
            if (isset($aAdditionalData[$sKey])) {
                return $aAdditionalData[$sKey];
            }
            return null;
        }
        // everything below 2.0.6
        return $oData->getData($sKey);
    }

    /**
     * Generates a Universally Unique Identifier (UUID)
     *
     * @return string
     * @throws \Exception
     */
    public function generateUUIDv4()
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * In the Payone universe different hash mechanisms are needed
     * Returns a hashed string and defines a default through the sAlgorithm parameter
     *
     * @param  string $sString
     * @return string
     */
    public function hashString($sString, $sAlgorithm = 'md5', $sKey = false)
    {
        if ($sAlgorithm == "sha384" && is_string($sKey)) {
            return hash_hmac($sAlgorithm, $sString, $sKey);
        }
        return hash($sAlgorithm, $sString);
    }
}
