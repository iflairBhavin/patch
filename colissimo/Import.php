<?php
/**
 * Copyright © 2017 Magentix. All rights reserved.
 *
 * NOTICE OF LICENSE
 * This source file is subject to commercial licence, do not copy or distribute without authorization
 */
namespace Magentix\Expeditor\Model;

use Magentix\Expeditor\Helper\Upload as HelperUpload;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader;
use Magento\Sales\Model\Order as OrderModel;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;
use Exception;

class Import extends \Magento\Framework\DataObject
{

    /**
     * @var string
     */
    protected $_file;

    /**
     * @var int
     */
    protected $_line;

    /**
     * @var \Magentix\Expeditor\Helper\Upload
     */
    protected $_helperUpload;

    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $_fileSystem;

    /**
     * @var \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader
     */
    protected $_shipmentLoader;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\ShipmentSender
     */
    protected $_shipmentSender;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $_orderModel;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var \Magento\Backend\Helper\Data
     */
    protected $_adminhtmlData;

    /**
     * Constructor
     *
     * @param \Magentix\Expeditor\Helper\Upload $helperUpload
     * @param \Magento\Framework\Filesystem $fileSystem
     * @param \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader $shipmentLoader
     * @param \Magento\Sales\Model\Order\Email\Sender\ShipmentSender $shipmentSender
     * @param \Magento\Sales\Model\Order $orderModel
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Backend\Helper\Data $adminhtmlData
     * @param array $data
     */
    public function __construct(
        HelperUpload $helperUpload,
        Filesystem $fileSystem,
        ShipmentLoader $shipmentLoader,
        ShipmentSender $shipmentSender,
        OrderModel $orderModel,
        ObjectManagerInterface $objectManager,
        \Magento\Backend\Helper\Data $adminhtmlData,
        array $data = []
    )
    {
        parent::__construct($data);
        $this->_helperUpload = $helperUpload;
        $this->_fileSystem = $fileSystem;
        $this->_shipmentLoader = $shipmentLoader;
        $this->_shipmentSender = $shipmentSender;
        $this->_orderModel = $orderModel;
        $this->_objectManager = $objectManager;
        $this->_adminhtmlData = $adminhtmlData;
    }

    /**
     * Set file name
     *
     * @param string $file
     * @return $this
     */
    public function setFile($file)
    {
        $this->_file = $file;

        return $this;
    }

    /**
     * Retrieve file
     *
     * @return string
     */
    public function getFile()
    {
        return $this->_file;
    }

    /**
     * Set line number
     *
     * @param int $line
     * @return $this
     */
    public function setLine($line)
    {
        $this->_line = $line;

        return $this;
    }

    /**
     * Retrieve line number
     *
     * @return int
     */
    public function getLine()
    {
        return $this->_line;
    }

    /**
     * Set order number
     *
     * @param string $orderNumber
     * @return $this
     */
    public function setIncrementId($orderNumber)
    {
        $this->setData('increment_id', $orderNumber);

        return $this;
    }

    /**
     * Retrieve order number
     *
     * @return string
     */
    public function getIncrementId()
    {
        return $this->getData('increment_id');
    }

    /**
     * Retrieve message
     *
     * @return string
     */
    public function getMessage()
    {
        return $this->getData('message');
    }

    /**
     * Set message
     *
     * @param string $message
     * @return $this
     */
    public function setMessage($message)
    {
        $this->setData('message', $message);

        return $this;
    }

    /**
     * Retrieve order link
     *
     * @return string
     */
    public function getOrderUrl()
    {
        return $this->getData('order_url');
    }

    /**
     * Set order link
     *
     * @param string $link
     * @return $this
     */
    public function setOrderUrl($link)
    {
        $this->setData('order_url', $link);

        return $this;
    }

    /**
     * Set tracking number
     *
     * @param string $tracking
     * @return $this
     */
    public function setTracking($tracking)
    {
        $this->setData('tracking', $tracking);

        return $this;
    }

    /**
     * Retrieve tracking number
     *
     * @return string
     */
    public function getTracking()
    {
        return $this->getData('tracking');
    }

    /**
     * Set status
     *
     * @param int $status
     * @return $this
     */
    public function setStatus($status)
    {
        $this->setData('status', $status);

        return $this;
    }

    /**
     * Retrieve status
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->getData('status');
    }

    /**
     * Set continue
     *
     * @param int $continue
     * @return $this
     */
    public function setContinue($continue)
    {
        $this->setData('continue', $continue);

        return $this;
    }

    /**
     * Retrieve continue
     *
     * @return int
     */
    public function getContinue()
    {
        return $this->getData('continue');
    }

    /**
     * Extract order from CSV file
     *
     * @return $this;
     */
    public function extract()
    {
        $read = $this->_fileSystem->getDirectoryRead(DirectoryList::VAR_DIR);

        $file = $this->_helperUpload->getPath() . '/' . $this->_file;

        if ($read->isFile($file)) {
            $file = $read->openFile($file);

            $iterator = 0;

            while (($row = $file->readCsv(0, ';')) !== false) {
                if ($iterator++ == $this->_line) {
                    $this->setIncrementId($row[1]);
                    $this->setTracking($row[0]);
                    $this->setStatus(0);
                    $this->setContinue(1);
                    break;
                }
                $this->setContinue(0);
            }
        }

        return $this;
    }

    /**
     * Run import
     *
     * @return $this
     */
    public function run()
    {
        $this->extract();

        if (!$this->getContinue()) {
            return $this->setMessage(__('Nothing to import'));
        }

        if (!$this->getIncrementId() || !$this->getTracking()) {
            return $this->setMessage(__('Wrong data'));
        }

        $order = $this->_orderModel->loadByIncrementId($this->getIncrementId());

        if (!$order->getId()) {
            return $this->setMessage(__('Order not found'));
        }

        $this->setOrderUrl(
            $this->_adminhtmlData->getUrl('sales/order/view', ['order_id' => $order->getId()])
        );

        if (!$order->canShip()) {
            return $this->setMessage(__('Order cannot be shipped'));
        }

        try {
            $carrier = $order->getShippingMethod(true);

            $tracking = array(
                array(
                    'carrier_code' => $carrier->getCarrierCode(),
                    'title'        => $order->getShippingDescription(),
                    'number'       => $this->getTracking()
                )
            );

            $this->_shipmentLoader->setOrderId($order->getId());
            $this->_shipmentLoader->setTracking($tracking);

            $shipment = $this->_shipmentLoader->load();

            $shipment->register();

            $shipment->getOrder()->setCustomerNoteNotify(true)->setIsInProcess(true);

            $transaction = $this->_objectManager->create('Magento\Framework\DB\Transaction');
            $transaction->addObject($shipment)->addObject($shipment->getOrder())->save();

            $this->_shipmentSender->send($shipment);

            $this->setStatus(1);
            $this->setMessage(__('Success'));

        } catch (Exception $e) {
            $this->setMessage(__('Error'));
        }

        return $this;
    }
}