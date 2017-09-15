<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Shiphawk\Shipping\Model;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    /**
     * Carrier's code
     *
     * @var string
     */
    protected $_code = 'shiphawk';

    /**
     * @var ResultFactory
     */
    protected $rateResultFactory;
    /**
     * @var MethodFactory
     */
    protected $rateMethodFactory;
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }
    /**
     * Generates list of allowed carrier`s shipping methods
     * Displays on cart price rules page
     *
     * @return array
     * @api
     */
    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('title'))];
    }
    /**
     * Collect and get rates for storefront
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param RateRequest $request
     * @return DataObject|bool|null
     * @api
     */
    public function collectRates(RateRequest $request)
    {
        /**
         * Make sure that Shipping method is enabled
         */
        if (!$this->isActive()) {
            return false;
        }

        $result = $this->rateResultFactory->create();

        $items = $this->getItems($request);
        $origin_zip = Config::XML_PATH_ORIGIN_POSTCODE;

        $rateRequest = array(
            'items' => $items,
            'origin_address'=> array(
                'zip'=> $this->scopeConfig->getValue($origin_zip)
            ),
            'destination_address'=> array(
                'zip'               =>  $to_zip = $request->getDestPostcode(),
                'is_residential'    =>  'true'
            ),
            'apply_rules'=>'true'
        );

        $rateResponse = $this->getRates($rateRequest);

        if(property_exists($rateResponse, 'error')) {
            $this->logger->addError(var_export($rateResponse->error, true));
        }else{
            if($rateResponse && isset($rateResponse->rates)) {

                foreach($rateResponse->rates as $rateRow)
                {
                    $method = $this->_buildRate($rateRow);
                    $result->append($method);
                }
            }
        }

        return $result;
    }
    /**
     * Build Rate
     *
     * @param array $rateRow
     * @return Method
     */
    protected function _buildRate($rateRow)
    {
        $rateResultMethod = $this->rateMethodFactory->create();
        /**
         * Set carrier's method data
         */
        $rateResultMethod->setData('carrier', $this->getCarrierCode());
        $rateResultMethod->setData('carrier_title', $rateRow->carrier);
        /**
         * Displayed as shipping method
         */
        $methodTitle = $rateRow->service_name;;

        $rateResultMethod->setData('method_title', $methodTitle);
        $rateResultMethod->setData('method', $methodTitle);
        $rateResultMethod->setPrice($rateRow->price);
        $rateResultMethod->setData('cost', $rateRow->price);

        return $rateResultMethod;
    }

    public function getRates($rateRequest)
    {
        $jsonRateRequest = json_encode($rateRequest);

        try {
            $response = $this->_get($jsonRateRequest);

            return $response;
        } catch (Exception $e) {
            $this->logger->critical($e);
        }
    }

    protected function _get($jsonRateRequest) {

        $params = http_build_query(['api_key' => $this->getConfigData('api_key')]);
        $ch_url = $this->getConfigData('gateway_url') . 'rates' . '?' . $params;

        $this->logger->debug(var_export(json_decode($jsonRateRequest), true));

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $ch_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRateRequest);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonRateRequest)
            )
        );

        $resp = curl_exec($ch);
        $arr_res = json_decode($resp);

        $this->logger->debug(var_export($arr_res, true));

        curl_close($ch);
        return $arr_res;
    }

    public function getItems($request)
    {
        $items = array();

        foreach ($request->getAllItems() as $item) {

            if ($item->getProductType() != 'simple') {

                if ($option = $item->getOptionByCode('simple_product')->getProduct()) {

                    $item_weight = $item->getWeight();
                    $items[] = array(
                        'product_sku' => $item->getSku(),
                        'quantity' => $item->getQty(),
                        'value' => $option->getPrice(),
                        'length' => floatval($option->getResource()->getAttributeRawValue($option->getId(),'shiphawk_length', null)),
                        'width' => floatval($option->getResource()->getAttributeRawValue($option->getId(),'shiphawk_width', null)),
                        'height' => floatval($option->getResource()->getAttributeRawValue($option->getId(),'shiphawk_height', null)),
                        'weight' => $item_weight <= 70 ? $item_weight * 16 : $item_weight,
                        'item_type' => $item_weight <= 70 ? 'parcel' : 'handling_unit',
                        'handling_unit_type' => $item_weight <= 70 ? '' : 'box'
                    );
                }

            } else if ($item->getProductType() != 'configurable' && !$item->getParentItemId()) {

                    $item_weight = $item->getWeight();
                    $items[] = array(
                        'product_sku' => $item->getSku(),
                        'quantity' => $item->getQty(),
                        'value' => $item->getPrice(),
                        'length' => floatval($item->getProduct()->getResource()->getAttributeRawValue($item->getProduct()->getId(),'shiphawk_length', null)),
                        'width' => floatval($item->getProduct()->getResource()->getAttributeRawValue($item->getProduct()->getId(),'shiphawk_width', null)),
                        'height' => floatval($item->getProduct()->getResource()->getAttributeRawValue($item->getProduct()->getId(),'shiphawk_height', null)),
                        'weight' => $item_weight <= 70 ? $item_weight * 16 : $item_weight,
                        'item_type' => $item_weight <= 70 ? 'parcel' : 'handling_unit',
                        'handling_unit_type' => $item_weight <= 70 ? '' : 'box'
                    );
            }
        }

        return $items;
    }
}