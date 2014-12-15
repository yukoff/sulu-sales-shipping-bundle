<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\Sales\OrderBundle\Order;

use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\NoResultException;
use Sulu\Bundle\ContactBundle\Entity\Account;
use Sulu\Bundle\ContactBundle\Entity\Address;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\ContactBundle\Entity\ContactRepository;
use Sulu\Bundle\ContactBundle\Entity\TermsOfDelivery;
use Sulu\Bundle\Sales\CoreBundle\Item\ItemManager;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderActivityLog;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderAddress;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderRepository;
use Sulu\Bundle\Sales\OrderBundle\Entity\Order as OrderEntity;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderStatus as OrderStatusEntity;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderStatus;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\MissingOrderAttributeException;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderDependencyNotFoundException;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderException;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderNotFoundException;
use Sulu\Component\Rest\Exception\EntityNotFoundException;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineConcatenationFieldDescriptor;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineFieldDescriptor;
use Sulu\Bundle\Sales\OrderBundle\Api\Order;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineJoinDescriptor;
use Sulu\Component\Rest\RestHelperInterface;
use Sulu\Component\Security\UserRepositoryInterface;
use DateTime;
use Sulu\Component\Persistence\RelationTrait;

class OrderManager
{
    use RelationTrait;

    protected static $orderEntityName = 'SuluSalesOrderBundle:Order';
    protected static $contactEntityName = 'SuluContactBundle:Contact';
    protected static $addressEntityName = 'SuluContactBundle:Address';
    protected static $accountEntityName = 'SuluContactBundle:Account';
    protected static $orderStatusEntityName = 'SuluSalesOrderBundle:OrderStatus';
    protected static $orderAddressEntityName = 'SuluSalesOrderBundle:OrderAddress';
    protected static $orderStatusTranslationEntityName = 'SuluSalesOrderBundle:OrderStatusTranslation';
    protected static $itemEntityName = 'SuluSalesCoreBundle:Item';
    protected static $termsOfDeliveryEntityName = 'SuluContactBundle:TermsOfDelivery';
    protected static $termsOfPaymentEntityName = 'SuluContactBundle:TermsOfPayment';

    private $currentLocale;

    /**
     * @var ObjectManager
     */
    private $em;

    /**
     * @var ItemManager
     */
    private $itemManager;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var UserRepositoryInterface
     */
    private $userRepository;

    /**
     * @var RestHelperInterface
     */
    private $restHelper;

    /**
     * @var Array
     */
    private $scheduledIds;

    /**
     * Describes the fields, which are handled by this controller
     * @var DoctrineFieldDescriptor[]
     */
    private $fieldDescriptors = array();

    public function __construct(
        ObjectManager $em,
        OrderRepository $orderRepository,
        UserRepositoryInterface $userRepository,
        ItemManager $itemManager
    ) {
        $this->orderRepository = $orderRepository;
        $this->userRepository = $userRepository;
        $this->em = $em;
        $this->itemManager = $itemManager;
        $this->scheduledIds = [];
    }

    /**
     * Creates a new Order Entity
     *
     * @param array $data The data array, which will be used for setting the orders data
     * @param string $locale Locale
     * @param int $userId Id of the User, which is is saved as creator / changer
     * @param int|null $id If defined, the Order with the given ID will be updated
     * @param int|null $statusId if defined, the status will be set to the given value
     * @param bool $flush Defines if a flush should be performed
     * @throws Exception\OrderNotFoundException
     * @throws Exception\OrderException
     * @return null|Order|\Sulu\Bundle\Sales\OrderBundle\Entity\Order
     */
    public function save(
        array $data,
        $locale,
        $userId = null,
        $id = null,
        $statusId = null,
        $flush = true
    ) {
        if ($id) {
            $order = $this->findByIdAndLocale($id, $locale);

            if (!$order) {
                throw new OrderNotFoundException($id);
            }
        } else {
            $order = new Order(new OrderEntity(), $locale);
        }

        // check for data
        $this->checkRequiredData($data, $id === null);

        $user = $userId ? $this->userRepository->findUserById($userId) : null;

        $order->setOrderNumber($this->getProperty($data, 'orderNumber', $order->getOrderNumber()));
        $order->setCurrency($this->getProperty($data, 'currency', $order->getCurrency()));
        $order->setCostCentre($this->getProperty($data, 'costCentre', $order->getCostCentre()));
        $order->setCommission($this->getProperty($data, 'commission', $order->getCommission()));
        $order->setTaxfree($this->getProperty($data, 'taxfree', $order->getTaxfree()));

        $this->setDate(
            $data,
            'desiredDeliveryDate',
            $order->getDesiredDeliveryDate(),
            array($order, 'setDesiredDeliveryDate')
        );
        $this->setDate($data, 'orderDate', $order->getOrderDate(), array($order, 'setOrderDate'));

        $this->setTermsOfDelivery($data, $order);
        $this->setTermsOfPayment($data, $order);

        $account = $this->setAccount($data, $order);

        // TODO: check sessionID
//        $order->setSessionId($this->getProperty($data, 'number', $order->getNumber()));

        // add contact
        $contact = $this->addContactRelation(
            $data,
            'contact',
            function ($contact) use ($order) {
                $order->setContact($contact);
            }
        );

        // add contact
        $this->addContactRelation(
            $data,
            'responsibleContact',
            function ($contact) use ($order) {
                $order->setResponsibleContact($contact);
            }
        );

        // create order (POST)
        if ($order->getId() == null) {
            $order->setCreated(new DateTime());
            $order->setCreator($user);
            $this->em->persist($order->getEntity());

            // set status to created if not defined
            if ($statusId === null) {
                $statusId = OrderStatus::STATUS_CREATED;
            }

            // create OrderAddress
            $deliveryAddress = new OrderAddress();
            $invoiceAddress = new OrderAddress();
            // persist entities
            $this->em->persist($deliveryAddress);
            $this->em->persist($invoiceAddress);
            // assign to order
            $order->setDeliveryAddress($deliveryAddress);
            $order->setInvoiceAddress($invoiceAddress);
        }

        // set order status
        if ($statusId !== null) {
            $this->convertStatus($order, $statusId);
        }

        // set customer name to account if set, otherwise to contact
        $contactFullName = $this->getContactData($data['invoiceAddress'], $contact)['fullName'];
        $customerName = $account !== null ? $account->getName() : $contactFullName;
        $order->setCustomerName($customerName);

        // set OrderAddress data
        $this->setOrderAddress($order->getInvoiceAddress(), $data['invoiceAddress'], $contact, $account);
        $this->setOrderAddress($order->getDeliveryAddress(), $data['deliveryAddress'], $contact, $account);

        // handle items
        if (!$this->processItems($data, $order, $locale, $userId)) {
            throw new OrderException('Error while processing items');
        }

        $order->setChanged(new DateTime());
        $order->setChanger($user);

        if ($flush) {
            $this->em->flush();
        }

        return $order;
    }

    /**
     * returns contact data as an array. either by provided address or contact
     */
    public function getContactData($addressData, $contact)
    {
        $result = array();
        // if account is set, take account's name
        if (isset($addressData['firstName']) && isset($addressData['lastName'])) {
            $result['firstName'] = $addressData['firstName'];
            $result['lastName'] = $addressData['lastName'];
            $result['fullName'] = $result['firstName'] . ' ' . $result['lastName'];
            if (isset($addressData['title'])) {
                $result['title'] = $addressData['title'];
            }
            if (isset($addressData['salutation'])) {
                $result['salutation'] = $addressData['salutation'];
            }
        } else {
            if ($contact) {
                $result['firstName'] = $contact->getFirstName();
                $result['lastName'] = $contact->getLastName();
                $result['fullName'] = $contact->getFullName();
                $result['salutation'] = $contact->getFormOfAddress();
                if ($contact->getTitle() !== null) {
                    $result['title'] = $contact->getTitle()->getTitle();
                }
            } else {
                throw new MissingOrderAttributeException('firstName, lastName or contact');
            }
        }

        return $result;
    }

    /**
     * deletes an order
     * @param $id
     * @throws Exception\OrderNotFoundException
     */
    public function delete($id)
    {
        // TODO: move order to an archive instead of remove it from database
        $order = $this->orderRepository->findById($id);

        if (!$order) {
            throw new OrderNotFoundException($id);
        }

        $this->em->remove($order);
        $this->em->flush();
    }

    /**
     * Converts the status of an order
     * @param Order $order
     * @param $statusId
     * @param bool $flush
     * @throws \Sulu\Component\Rest\Exception\EntityNotFoundException
     */
    public function convertStatus(Order $order, $statusId, $flush = false)
    {
        // get current status
        $currentStatus = null;
        if ($order->getStatus()) {
            $currentStatus = $order->getStatus()->getEntity();

            // if status has not changed, skip
            if ($currentStatus->getId() === $statusId) {
                return;
            }
        }

        // get desired status
        $statusEntity = $this->em
            ->getRepository(self::$orderStatusEntityName)
            ->find($statusId);
        if (!$statusEntity) {
            throw new EntityNotFoundException($statusEntity, $statusEntity);
        }

        // ACTIVITY LOG
        $orderActivity = new OrderActivityLog();
        $orderActivity->setOrder($order->getEntity());
        if ($currentStatus) {
            $orderActivity->setStatusFrom($currentStatus);
        }
        $orderActivity->setStatusTo($statusEntity);
        $orderActivity->setCreated(new \DateTime());
        $this->em->persist($orderActivity);

        // BITMASK
        $currentBitmaskStatus = $order->getBitmaskStatus();
        // if desired status already is in bitmask, remove current state
        // since this is a step back
        if ($currentBitmaskStatus && $currentBitmaskStatus & $statusEntity->getId()) {
            $order->setBitmaskStatus($currentBitmaskStatus & ~$currentStatus->getId());
        } else {
            // else increment bitmask status
            $order->setBitmaskStatus($currentBitmaskStatus | $statusEntity->getId());
        }

        // check if status has changed
        if ($statusId === OrderStatusEntity::STATUS_CREATED) {
            // TODO: re-edit - do some business logic
        }
        $order->setStatus($statusEntity);

        if ($flush === true) {
            $this->em->flush();
        }
    }

    /**
     * finds a status by id
     * @param $statusId
     * @return object
     * @throws \Sulu\Component\Rest\Exception\EntityNotFoundException
     */
    public function findOrderStatusById($statusId)
    {
        try {
            return $this->em
                ->getRepository(self::$orderStatusEntityName)
                ->find($statusId);
        } catch (NoResultException $nre) {
            throw new EntityNotFoundException(self::$orderStatusEntityName, $statusId);
        }
    }

    /**
     * find order entity by id
     * @param $id
     * @throws \Sulu\Component\Rest\Exception\EntityNotFoundException
     * @internal param $statusId
     * @return OrderEntity
     */
    public function findOrderEntityById($id)
    {
        try {
            return $this->em
                ->getRepository(self::$orderEntityName)
                ->find($id);
        } catch (NoResultException $nre) {
            throw new EntityNotFoundException(self::$orderEntityName, $id);
        }
    }

    /**
     * find order for item with id
     * @param $id
     * @throws \Sulu\Component\Rest\Exception\EntityNotFoundException
     * @internal param $statusId
     * @return OrderEntity
     */
    public function findOrderEntityForItemWithId($id)
    {
        try {
            return $this->em
                ->getRepository(self::$orderEntityName)
                ->findOrderForItemWithId($id);
        } catch (NoResultException $nre) {
            throw new EntityNotFoundException(self::$itemEntity, $id);
        }
    }

    /**
     * @param $locale
     * @return \Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineFieldDescriptor[]
     */
    public function getFieldDescriptors($locale)
    {
        if ($locale !== $this->currentLocale) {
            $this->initializeFieldDescriptors($locale);
        }
        return $this->fieldDescriptors;
    }

    /**
     * returns a specific field descriptor by key
     * @param $key
     * @return DoctrineFieldDescriptor
     */
    public function getFieldDescriptor($key)
    {
        return $this->fieldDescriptors[$key];
    }

    /**
     * Finds an order by id and locale
     * @param $id
     * @param $locale
     * @return null|Order
     */
    public function findByIdAndLocale($id, $locale)
    {
        $order = $this->orderRepository->findByIdAndLocale($id, $locale);

        if ($order) {
            return new Order($order, $locale);
        } else {
            return null;
        }
    }

    /**
     * @param $locale
     * @param array $filter
     * @return mixed
     */
    public function findAllByLocale($locale, $filter = array())
    {
        if (empty($filter)) {
            $order = $this->orderRepository->findAllByLocale($locale);
        } else {
            $order = $this->orderRepository->findByLocaleAndFilter($locale, $filter);
        }

        if ($order) {
            array_walk(
                $order,
                function (&$order) use ($locale) {
                    $order = new Order($order, $locale);
                }
            );
        }

        return $order;
    }

    /**
     * sets a date if it's set in data
     * @param $data
     * @param $key
     * @param $currentDate
     * @param callable $setCallback
     */
    private function setDate($data, $key, $currentDate, callable $setCallback)
    {
        if (($date = $this->getProperty($data, $key, $currentDate)) !== null) {
            if (is_string($date)) {
                $date = new DateTime($data[$key]);
            }
            call_user_func($setCallback, $date);
        }
    }

    /**
     * initializes field descriptors
     */
    private function initializeFieldDescriptors($locale)
    {
        $this->fieldDescriptors['id'] = new DoctrineFieldDescriptor(
            'id',
            'id',
            self::$orderEntityName,
            'public.id',
            array(),
            true
        );
        $this->fieldDescriptors['number'] = new DoctrineFieldDescriptor(
            'number',
            'number',
            self::$orderEntityName,
            'salesorder.orders.number',
            array(),
            false,
            true
        );

        // TODO: get customer from order-address

        $contactJoin = array(
            self::$orderAddressEntityName => new DoctrineJoinDescriptor(
                self::$orderAddressEntityName,
                self::$orderEntityName . '.invoiceAddress'
            )
        );

        $this->fieldDescriptors['account'] = new DoctrineConcatenationFieldDescriptor(
            array(
                new DoctrineFieldDescriptor(
                    'accountName',
                    'account',
                    self::$orderAddressEntityName,
                    'contact.contacts.contact',
                    $contactJoin
                )
            ),
            'account',
            'salesorder.orders.account',
            ' ',
            false,
            false,
            '',
            '',
            '160px'
        );

        $this->fieldDescriptors['contact'] = new DoctrineConcatenationFieldDescriptor(
            array(
                new DoctrineFieldDescriptor(
                    'firstName',
                    'contact',
                    self::$orderAddressEntityName,
                    'contact.contacts.contact',
                    $contactJoin
                ),
                new DoctrineFieldDescriptor(
                    'lastName',
                    'contact',
                    self::$orderAddressEntityName,
                    'contact.contacts.contact',
                    $contactJoin
                )
            ),
            'contact',
            'salesorder.orders.contact',
            ' ',
            false,
            false,
            '',
            '',
            '160px'
        );

        $this->fieldDescriptors['status'] = new DoctrineFieldDescriptor(
            'name',
            'status',
            self::$orderStatusTranslationEntityName,
            'salesorder.orders.status',
            array(
                self::$orderStatusEntityName => new DoctrineJoinDescriptor(
                    self::$orderStatusEntityName,
                    self::$orderEntityName . '.status'
                ),
                self::$orderStatusTranslationEntityName => new DoctrineJoinDescriptor(
                    self::$orderStatusTranslationEntityName,
                    self::$orderStatusEntityName . '.translations',
                    self::$orderStatusTranslationEntityName . ".locale = '" . $locale . "'"
                )
            )
        );
    }

    /**
     * check if necessary data is set
     * @param $data
     * @param $isNew
     */
    private function checkRequiredData($data, $isNew)
    {
        $this->checkDataSet($data, 'deliveryAddress', $isNew);
        $this->checkDataSet($data, 'invoiceAddress', $isNew);
    }

    /**
     * checks data for attributes
     * @param array $data
     * @param $key
     * @param $isNew
     * @return bool
     * @throws Exception\MissingOrderAttributeException
     */
    private function checkDataSet(array $data, $key, $isNew)
    {
        $keyExists = array_key_exists($key, $data);

        if (($isNew && !($keyExists && $data[$key] !== null)) || (!$keyExists || $data[$key] === null)) {
            throw new MissingOrderAttributeException($key);
        }

        return $keyExists;
    }

    /**
     * checks if data is set
     * @param $key
     * @param $data
     * @return bool
     */
    private function checkIfSet($key, $data)
    {
        $keyExists = array_key_exists($key, $data);

        return $keyExists && $data[$key] !== null && $data[$key] !== '';
    }

    /**
     * searches for contact in specified data and calls callback function
     * @param array $data
     * @param $dataKey
     * @param $addCallback
     * @throws Exception\MissingOrderAttributeException
     * @throws Exception\OrderDependencyNotFoundException
     * @return Contact|null
     */
    private function addContactRelation(array $data, $dataKey, $addCallback)
    {
        $contact = null;
        if (array_key_exists($dataKey, $data) && is_array($data[$dataKey]) && array_key_exists('id', $data[$dataKey])) {
            /** @var Contact $contact */
            $contactId = $data[$dataKey]['id'];
            $contact = $this->em->getRepository(self::$contactEntityName)->find($contactId);
            if (!$contact) {
                throw new OrderDependencyNotFoundException(self::$contactEntityName, $contactId);
            }
            $addCallback($contact);
        }
        return $contact;
    }

    /**
     * @param OrderAddress $orderAddress
     * @param $addressData
     * @param Contact $contact
     * @param Account|null $account
     * @throws OrderDependencyNotFoundException
     */
    private function setOrderAddress(OrderAddress $orderAddress, $addressData, $contact = null, $account = null)
    {
        // check if address with id can be found

        $contactData = $this->getContactData($addressData, $contact);
        // add contact data
        $orderAddress->setFirstName($contactData['firstName']);
        $orderAddress->setLastName($contactData['lastName']);
        if (isset($contactData['title'])) {
            $orderAddress->setTitle($contactData['title']);
        }
        if (isset($contactData['salutation'])) {
            $orderAddress->setSalutation($contactData['salutation']);
        }

        // add account data
        if ($account) {
            $orderAddress->setAccountName($account->getName());
            $orderAddress->setUid($account->getUid());
        } else {
            $orderAddress->setAccountName(null);
            $orderAddress->setUid(null);
        }

        // TODO: add phone

        $this->setAddressDataForOrder($orderAddress, $addressData);
    }

    /**
     * copies address data to order address
     * @param OrderAddress $orderAddress
     * @param $addressData
     */
    private function setAddressDataForOrder(OrderAddress &$orderAddress, $addressData)
    {
        $orderAddress->setStreet($this->getProperty($addressData, 'street', ''));
        $orderAddress->setNumber($this->getProperty($addressData, 'number', ''));
        $orderAddress->setAddition($this->getProperty($addressData, 'addition', ''));
        $orderAddress->setCity($this->getProperty($addressData, 'city', ''));
        $orderAddress->setZip($this->getProperty($addressData, 'zip', ''));
        $orderAddress->setState($this->getProperty($addressData, 'state', ''));
        $orderAddress->setCountry($this->getProperty($addressData, 'country', ''));
        $orderAddress->setEmail($this->getProperty($addressData, 'email', ''));
        $orderAddress->setPhone($this->getProperty($addressData, 'phone', ''));

        $orderAddress->setPostboxCity($this->getProperty($addressData, 'postboxCity', ''));
        $orderAddress->setPostboxPostcode($this->getProperty($addressData, 'postboxPostcode', ''));
        $orderAddress->setPostboxNumber($this->getProperty($addressData, 'postboxNumber', ''));
    }

    /**
     * Returns the entry from the data with the given key, or the given default value, if the key does not exist
     * @param array $data
     * @param string $key
     * @param string $default
     * @return mixed
     */
    private function getProperty(array $data, $key, $default = null)
    {
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * @param $data
     * @param Order $order
     * @return null|object
     * @throws Exception\MissingOrderAttributeException
     * @throws Exception\OrderDependencyNotFoundException
     */
    private function setTermsOfDelivery($data, Order $order)
    {
        $terms = null;
        // terms of delivery
        $termsOfDeliveryData = $this->getProperty($data, 'termsOfDelivery');
        $termsOfDeliveryContentData = $this->getProperty($data, 'termsOfDeliveryContent');
        if ($termsOfDeliveryData) {
            if (!array_key_exists('id', $termsOfDeliveryData)) {
                throw new MissingOrderAttributeException('termsOfDelivery.id');
            }
            // TODO: inject repository class
            $terms = $this->em->getRepository(self::$termsOfDeliveryEntityName)->find($termsOfDeliveryData['id']);
            if (!$terms) {
                throw new OrderDependencyNotFoundException(
                    self::$termsOfDeliveryEntityName,
                    $termsOfDeliveryData['id']
                );
            }
            $order->setTermsOfDelivery($terms);
            $order->setTermsOfDeliveryContent($terms->getTerms());
        } else {
            $order->setTermsOfDelivery(null);
            $order->setTermsOfDeliveryContent(null);
        }
        // set content data
        if ($termsOfDeliveryContentData) {
            $order->setTermsOfDeliveryContent($termsOfDeliveryContentData);
        }

        return $terms;
    }

    /**
     * @param $data
     * @param Order $order
     * @return null|object
     * @throws Exception\MissingOrderAttributeException
     * @throws Exception\OrderDependencyNotFoundException
     */
    private function setTermsOfPayment($data, Order $order)
    {
        $terms = null;
        // terms of delivery
        $termsOfPaymentData = $this->getProperty($data, 'termsOfPayment');
        $termsOfPaymentContentData = $this->getProperty($data, 'termsOfPaymentContent');
        if ($termsOfPaymentData) {
            if (!array_key_exists('id', $termsOfPaymentData)) {
                throw new MissingOrderAttributeException('termsOfPayment.id');
            }
            // TODO: inject repository class
            $terms = $this->em->getRepository(self::$termsOfPaymentEntityName)->find($termsOfPaymentData['id']);
            if (!$terms) {
                throw new OrderDependencyNotFoundException(self::$termsOfPaymentEntityName, $termsOfPaymentData['id']);
            }
            $order->setTermsOfPayment($terms);
            $order->setTermsOfPaymentContent($terms->getTerms());

        } else {
            $order->setTermsOfPayment(null);
            $order->setTermsOfPaymentContent(null);
        }
        // set content data
        if ($termsOfPaymentContentData) {
            $order->setTermsOfPaymentContent($termsOfPaymentContentData);
        }
        return $terms;
    }

    /**
     * @param $data
     * @param Order $order
     * @return null|object
     * @throws Exception\MissingOrderAttributeException
     * @throws Exception\OrderDependencyNotFoundException
     */
    private function setAccount($data, Order $order)
    {
        $accountData = $this->getProperty($data, 'account');
        if ($accountData) {
            if (!array_key_exists('id', $accountData)) {
                throw new MissingOrderAttributeException('account.id');
            }
            // TODO: inject repository class
            $account = $this->em->getRepository(self::$accountEntityName)->find($accountData['id']);
            if (!$account) {
                throw new OrderDependencyNotFoundException(self::$accountEntityName, $accountData['id']);
            }
            $order->setAccount($account);
            return $account;
        } else {
            $order->setAccount(null);
        }
        return null;
    }

    /**
     * processes items defined in an order and creates item entities
     * @param $data
     * @param Order $order
     * @param $locale
     * @param $userId
     * @return bool
     * @throws Exception\OrderException
     */
    private function processItems($data, Order $order, $locale, $userId = null)
    {
        $result = true;
        try {
            if ($this->checkIfSet('items', $data)) {
                // items has to be an array
                if (!is_array($data['items'])) {
                    throw new MissingOrderAttributeException('items array');
                }

                $items = $data['items'];

                $get = function ($item) {
                    return $item->getId();
                };

                $delete = function ($item) use ($order) {
                    $entity = $item->getEntity();
                    // remove from order
                    $order->removeItem($entity);
                    // delete item
                    $this->em->remove($entity);
                };

                $update = function ($item, $matchedEntry) use ($locale, $userId, $order) {
                    $itemEntity = $this->itemManager->save($matchedEntry, $locale, $userId, $item);
                    return $itemEntity ? true : false;
                };

                $add = function ($itemData) use ($locale, $userId, $order) {
                    $item = $this->itemManager->save($itemData, $locale, $userId);
                    return $order->addItem($item->getEntity());
                };

                $result = $this->restHelper->processSubEntities(
                    $order->getItems(),
                    $items,
                    $get,
                    $add,
                    $update,
                    $delete
                );
            }
        } catch (\Exception $e) {
            throw new OrderException('Error while creating items: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * Offers depending to an id are going to be updated
     * if processIds() is called.
     *
     * @param string $id
     */
    public function scheduleForUpdate($id)
    {
        if ($id) {
            $this->scheduledIds[] = $id;
        }
    }

    /**
     * Process (update total net price) all offers
     * for the scheduled Items.
     */
    public function processIds()
    {
        $orders = [];
        foreach ($this->scheduledIds as $id) {
            $order = $this->findOrderEntityForItemWithId($id);
            if (!in_array($order, $orders)) {
                $orders[] = $order;
                $order->updateTotalNetPrice();
            }
        }
        unset($this->scheduledIds);
        $this->scheduledIds = [];
        // $this->em->flush();
    }
}
