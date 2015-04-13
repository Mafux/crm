<?php

namespace OroCRM\Bundle\MagentoBundle\Entity\Repository;

use DateTime;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;

use Oro\Bundle\DashboardBundle\Helper\DateHelper;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\EntityBundle\Exception\InvalidEntityException;

use OroCRM\Bundle\MagentoBundle\Entity\Cart;
use OroCRM\Bundle\MagentoBundle\Entity\Customer;
use OroCRM\Bundle\MagentoBundle\Entity\Order;
use OroCRM\Bundle\MagentoBundle\Utils\DatePeriodUtils;

class OrderRepository extends EntityRepository
{
    /**
     * @param Cart|Customer $item
     * @param string        $field
     *
     * @return Cart|Customer|null $item
     * @throws InvalidEntityException
     */
    public function getLastPlacedOrderBy($item, $field)
    {
        if (!($item instanceof Cart) && !($item instanceof Customer)) {
            throw new InvalidEntityException();
        }
        $qb = $this->createQueryBuilder('o');
        $qb->where('o.' . $field . ' = :item');
        $qb->setParameter('item', $item);
        $qb->orderBy('o.updatedAt', 'DESC');
        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Get customer orders subtotal amount
     *
     * @param Customer $customer
     * @return float
     */
    public function getCustomerOrdersSubtotalAmount(Customer $customer)
    {
        $qb = $this->createQueryBuilder('o');

        $qb
            ->select('sum(o.subtotalAmount) as subtotal')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('o.customer', ':customer'),
                    $qb->expr()->neq($qb->expr()->lower('o.status'), ':status')
                )
            )
            ->setParameter('customer', $customer)
            ->setParameter('status', Order::STATUS_CANCELED);

        return (float)$qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param AclHelper  $aclHelper
     * @param \DateTime  $dateFrom
     * @param \DateTime  $dateTo
     * @param DateHelper $dateHelper
     * @return array
     */
    public function getAverageOrderAmount(
        AclHelper $aclHelper,
        \DateTime $dateFrom,
        \DateTime $dateTo,
        DateHelper $dateHelper
    ) {
        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();
        $channels      = $entityManager->getRepository('OroCRMChannelBundle:Channel')
            ->getAvailableChannelNames($aclHelper, 'magento');

        // execute data query
        $queryBuilder = $this->createQueryBuilder('o');
        $selectClause = '
            IDENTITY(o.dataChannel) AS dataChannelId,
            AVG(
                CASE WHEN o.subtotalAmount IS NOT NULL THEN o.subtotalAmount ELSE 0 END -
                CASE WHEN o.discountAmount IS NOT NULL THEN o.discountAmount ELSE 0 END
            ) as averageOrderAmount';

        $dates = $dateHelper->getDatePeriod($dateFrom, $dateTo);

        $queryBuilder->select($selectClause)
            ->andWhere($queryBuilder->expr()->between('o.createdAt', ':dateStart', ':dateEnd'))
            ->setParameter('dateStart', $dateFrom)
            ->setParameter('dateEnd', $dateTo)
            ->groupBy('dataChannelId');
        $dateHelper->addDatePartsSelect($dateFrom, $dateTo, $queryBuilder, 'o.createdAt');
        $amountStatistics = $aclHelper->apply($queryBuilder)->getArrayResult();

        $items             = [];
        foreach ($amountStatistics as $row) {
            $key         = $dateHelper->getKey($dateFrom, $dateTo, $row);
            $channelId   = (int)$row['dataChannelId'];
            $channelName = $channels[$channelId]['name'];

            if (!isset($items[$channelName])) {
                $items[$channelName] = $dates;
            }
            $items[$channelName][$key]['amount'] = (float)$row['averageOrderAmount'];
        }

        // restore default keys
        foreach ($items as $channelName => $item) {
            $items[$channelName] = array_values($item);
        }

        return $items;
    }

    /**
     * @param AclHelper $aclHelper
     * @param DateHelper $dateHelper
     * @param DateTime $from
     * @param DateTime|null $to
     *
     * @return array
     */
    public function getRevenueOverTime(
        AclHelper $aclHelper,
        DateHelper $dateHelper,
        DateTime $from,
        DateTime $to = null
    ) {
        $from = clone $from;
        $to = clone $to;

        $qb = $this->createQueryBuilder('o')
            ->select('SUM(
                    CASE WHEN o.subtotalAmount IS NOT NULL THEN o.subtotalAmount ELSE 0 END -
                    CASE WHEN o.discountAmount IS NOT NULL THEN o.discountAmount ELSE 0 END
                ) AS amount')
        ;

        $dateHelper->addDatePartsSelect($from, $to, $qb, 'o.createdAt');

        if ($to) {
            $qb->andWhere($qb->expr()->between('o.createdAt', ':from', ':to'))
                ->setParameter('to', $to);
        } else {
            $qb->andWhere('o.createdAt > :from');
        }
        $qb->setParameter('from', $from);

        return $aclHelper->apply($qb)->getResult();
    }

    /**
     * @return array
     */
    protected function getOrderSliceDateAndTemplates()
    {
        // calculate slice date
        $currentYear  = (int)date('Y');
        $currentMonth = (int)date('m');

        $sliceYear  = $currentMonth === 12 ? $currentYear : $currentYear - 1;
        $sliceMonth = $currentMonth === 12 ? 1 : $currentMonth + 1;
        $sliceDate  = new \DateTime(sprintf('%s-%s-01', $sliceYear, $sliceMonth), new \DateTimeZone('UTC'));

        // calculate match for month and default channel template
        $monthMatch = [];
        $channelTemplate = [];
        if ($sliceYear !== $currentYear) {
            for ($i = $sliceMonth; $i <= 12; $i++) {
                $monthMatch[$i] = ['year' => $sliceYear, 'month' => $i];
                $channelTemplate[$sliceYear][$i] = 0;
            }
        }
        for ($i = 1; $i <= $currentMonth; $i++) {
            $monthMatch[$i] = ['year' => $currentYear, 'month' => $i];
            $channelTemplate[$currentYear][$i] = 0;
        }

        return [$sliceDate, $monthMatch, $channelTemplate];
    }
}
