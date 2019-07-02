<?php
/**
 * Taxjar_SalesTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Taxjar
 * @package    Taxjar_SalesTax
 * @copyright  Copyright (c) 2017 TaxJar. TaxJar is a trademark of TPS Unlimited, Inc. (http://www.taxjar.com)
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxjar\SalesTax\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

use Taxjar\SalesTax\Model\Configuration as TaxjarConfig;

class Customer implements ObserverInterface
{
    /**
     * @var \Taxjar\SalesTax\Model\Client
     */
    protected $client;

    /**
     * @var \Magento\Customer\Model\Customer
     */
    protected $customer;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $date;

    /**
     * @var \Taxjar\SalesTax\Model\Logger
     */
    protected $logger;

    /**
     * @var \Magento\Directory\Model\Region
     */
    protected $region;

    /**
     * @param \Taxjar\SalesTax\Model\ClientFactory $clientFactory
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param \\Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     *
     */
    public function __construct(
        \Taxjar\SalesTax\Model\ClientFactory $clientFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Taxjar\SalesTax\Model\Logger $logger,
        \Magento\Directory\Model\RegionFactory $regionFactory
    ) {
        $this->client = $clientFactory->create();
        $this->client->showResponseErrors(true);
        $this->customer = $customerFactory->create();
        $this->date = $date;
        $this->logger = $logger->setFilename(TaxjarConfig::TAXJAR_CUSTOMER_LOG);
        $this->region = $regionFactory->create();
    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Framework\Event $event */
        $event = $observer->getEvent();

        if ($observer->getCustomerDataObject()) {
            $customerId = $observer->getCustomerDataObject()->getId();
        } else {
            $customerId = $observer->getCustomer()->getId();
        }

        /** @var \Magento\Customer\Model\Customer $customer */
        $customer = $this->customer->load($customerId);

        /** @var \Magento\Customer\Model\Address $customerAddress */
        $customerAddress = $customer->getDefaultShippingAddress();

        if (!$customerAddress) {
            $customerAddress = $customer->getAddresses();
            $customerAddress = reset($customerAddress);
        }

        $data = [
            'customer_id' => $customer->getId(),
            'exemption_type' => $customer->getTjExemptionType(),
            'name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
            'street' => '',
            'city' => '',
            'state' => '',
            'zip' => ''
        ];

        $regions = $customer->getTjRegions();

        if (!empty($regions)) {
            $customerRegions = [];
            foreach (explode(',', $regions) as $region) {
                $customerRegions[] = ['country' => 'US', 'state' => $this->region->load($region)->getCode()];
            }
            $data['exempt_regions'] = $customerRegions;
        }

        if ($customerAddress) {
            $data += [
                'country' => $customerAddress->getCountry(),
                'state' => $customerAddress->getRegionCode(),
                'zip' => $customerAddress->getPostcode(),
                'city' => $customerAddress->getCity(),
                'street' => $customerAddress->getStreetFull()
            ];
        }

        if ($event->getName() == 'customer_save_after_data_object' && empty($customer->getTjLastSync())) {
            try {
                $response = $this->client->postResource('customers', $data);  //create a new customer
            } catch (LocalizedException $e) {
                $message = json_decode($e->getMessage());

                if (isset($message->status) && $message->status == 422) {  //unprocessable
                    try {
                        $this->logger->log('Could not update customer #' . $customer->getId() . ', attempting to create instead',
                            'fallback');
                        $response = $this->client->putResource('customers', $customer->getId(), $data);
                    } catch (LocalizedException $e) {
                        $this->logger->log('Could not update customer #' . $customer->getId() . ": " . $e->getMessage(),
                            'error');
                    }
                } else {
                    $this->logger->log('Could not create customer #' . $customer->getId() . ': ' . $e->getMessage(),
                        'error');
                }
            }
        } elseif ($event->getName() == 'customer_save_after_data_object') {
            try {
                $response = $this->client->putResource('customers', $customer->getId(), $data);
            } catch (LocalizedException $e) {
                $message = json_decode($e->getMessage());

                if (isset($message->status) && $message->status == 404) {  //unprocessable
                    try {
                        $this->logger->log('Could not create customer #' . $customer->getId() . ', attempting to update instead',
                            'fallback');
                        $response = $this->client->postResource('customers', $data);
                    } catch (LocalizedException $e) {
                        $this->logger->log('Could not create customer #' . $customer->getId() . ": " . $e->getMessage(),
                            'error');
                    }
                } else {
                    $this->logger->log('Could not update customer #' . $customer->getId() . ': ' . $e->getMessage(),
                        'error');
                }
            }
        }

        if ($event->getName() == 'customer_delete_before') {
            try {
                $response = $this->client->deleteResource('customers', $customer->getId());  //delete customer
            } catch (LocalizedException $e) {
                $this->logger->log('Could not delete customer #' . $customer->getId() . ": " . $e->getMessage(),
                    'error');
            }
        }

        if (isset($response) && isset($response['customer']) && !is_null($response['customer'])) {
            $this->logger->log('Successful API response: ' . json_encode($response), 'success');
            $customer->setData('tj_last_sync', $this->date->timestamp());
            $customer->save();
        }
    }
}
