<?php

/*
 * This file is part of the OpenMiamMiam project.
 *
 * (c) Isics <contact@isics.fr>
 *
 * This source file is subject to the AGPL v3 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Isics\Bundle\OpenMiamMiamBundle\Manager;

use Doctrine\ORM\EntityManager;
use Isics\Bundle\OpenMiamMiamBundle\Entity\Association;
use Isics\Bundle\OpenMiamMiamBundle\Entity\BranchOccurrence;
use Isics\Bundle\OpenMiamMiamBundle\Entity\Repository\BranchRepository;
use Isics\Bundle\OpenMiamMiamBundle\Model\Association\AssociationWithOwner;
use Isics\Bundle\OpenMiamMiamBundle\Model\Document\ProducersDepositWithdrawal;
use Isics\Bundle\OpenMiamMiamBundle\Model\Document\ProducersTransfer;
use Isics\Bundle\OpenMiamMiamUserBundle\Entity\User;
use Isics\Bundle\OpenMiamMiamUserBundle\Manager\UserManager;

class AssociationManager
{
    /**
     * @var EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var String $artificialProductRef
     */
    protected $artificialProductRef;

    /**
     * @var UserManager $userManager
     */
    protected $userManager;

    /**
     * @var ActivityManager $activityManager
     */
    protected $activityManager;

    /**
     * Constructs object
     *
     * @param EntityManager $entityManager
     * @param String        $artificialProductRef
     */
    public function __construct(EntityManager $entityManager, $artificialProductRef, UserManager $userManager, ActivityManager $activityManager)
    {
        $this->entityManager        = $entityManager;
        $this->artificialProductRef = $artificialProductRef;
        $this->userManager          = $userManager;
        $this->activityManager      = $activityManager;
    }

    /**
     * Creates an association
     *
     * @return Association
     */
    public function create()
    {
        $association = new Association();

        return $association;
    }

    /**
     * Saves a association
     *
     * @param Association $association
     * @param User     $user
     */
    public function save(Association $association, User $user = null)
    {
        $activityTransKey = null;
        if (null === $association->getId()) {
            $activityTransKey = 'activity_stream.association.created';
        } else {
            $unitOfWork = $this->entityManager->getUnitOfWork();
            $unitOfWork->computeChangeSets();

            $changeSet = $unitOfWork->getEntityChangeSet($association);
            if (!empty($changeSet)) {
                $activityTransKey = 'activity_stream.association.updated';
            }
        }

        // Save object
        $this->entityManager->persist($association);
        $this->entityManager->flush();

        // Process image file
        // $this->processProfileImageFile($association);
        // $this->processPresentationImageFile($association);

        // Activity
        if (null !== $activityTransKey) {
            $activity = $this->activityManager->createFromEntities(
                $activityTransKey,
                array('%name%' => $association->getName()),
                $association,
                null,
                $user
            );
            $this->entityManager->persist($activity);
            $this->entityManager->flush();
        }
    }

    /**
     * Returns a AssociationWithOwner (DTO)
     *
     * @return AssociationWithOwner
     */
    public function getAssociationWithOwner(Association $association = null)
    {
        $associationWithOwner = new AssociationWithOwner();

        if (null === $association) {
            $associationWithOwner->setAssociation($this->create());
        } else {
            $associationWithOwner->setAssociation($association);

            if (null !== $owner = $this->userManager->getOwner($association)) {
                $associationWithOwner->setOwner($owner);
            }
        }

        return $associationWithOwner;
    }

    /**
     * Saves AssociationWithOwner
     *
     * @param AssociationWithOwner $associationWithOwner
     * @param User              $user
     */
    public function saveAssociationWithOwner(AssociationWithOwner $associationWithOwner, User $user = null)
    {
        $association = $associationWithOwner->getAssociation();

        $this->save($association, $user);

        // Set owner
        $this->userManager->setOwner($association, $associationWithOwner->getOwner());
    }

    /**
     * Removes an association
     *
     * @param Association $association
     */
    public function delete(Association $association)
    {
        $this->entityManager->remove($association);
        $this->entityManager->flush();
    }

    /**
     * Get producers transfer object for association and month
     *
     * @param Association $association
     * @param \DateTime   $fromDate
     *
     * @return ProducersTransfer
     */
    public function getProducerTransferForMonth(Association $association, \DateTime $fromDate)
    {
        $toDate = clone $fromDate;
        $toDate->modify('first day of next month midnight - 1 second');

        // Retrieve branch occurrence to consider
        $branchOccurrences = $this->entityManager
            ->getRepository('IsicsOpenMiamMiamBundle:BranchOccurrence')
            ->findForAssociationByDateRange($association, $fromDate, $toDate);

        // Get ids from records
        $branchOccurrenceIds = array();

        foreach ($branchOccurrences as $branchOccurrence) {
            $branchOccurrenceIds[] = $branchOccurrence->getId();
        }

        if (count($branchOccurrenceIds) == 0) {
            $producersData = array();
            $producers     = array();
        } else {
            $producersDataQueryBuilder       = $this->entityManager->createQueryBuilder();
            $producersWithSalesOrderRowsData = $producersDataQueryBuilder
                ->select('p.id AS producer_id')
                ->addSelect('p.name AS producer_name')
                ->addSelect('bo.id AS branch_occurrence_id')
                ->addSelect('SUM(sor.total * (1 - COALESCE(sor.commission, a.defaultCommission)/100)) AS amount')
                ->from('IsicsOpenMiamMiamBundle:Producer', 'p')
                ->leftJoin('p.salesOrderRows', 'sor')
                ->leftJoin('sor.salesOrder', 'so')
                ->leftJoin('so.branchOccurrence', 'bo')
                ->leftJoin('bo.branch', 'b')
                ->leftJoin('b.association', 'a')
                ->where($producersDataQueryBuilder->expr()->in('bo.id', $branchOccurrenceIds))
                ->addGroupBy('bo.id')
                ->addGroupBy('p.id')
                ->getQuery()
                ->getResult();

            $producersIdsWithSalesOrderRows = array();

            foreach ($producersWithSalesOrderRowsData as $producersDatum) {
                $producersIdsWithSalesOrderRows[] = $producersDatum['producer_id'];
            }

            $producersIdsWithSalesOrderRows = array_unique($producersIdsWithSalesOrderRows);

            $producersDataQueryBuilder = $this->entityManager->createQueryBuilder();
            $producersAttendeesData    = $producersDataQueryBuilder
                ->select('p.id AS producer_id')
                ->addSelect('p.name AS producer_name')
                ->addSelect('bo.id AS branch_occurrence_id')
                ->from('IsicsOpenMiamMiamBundle:Producer', 'p')
                ->leftJoin('p.producerAttendances', 'pa')
                ->leftJoin('pa.branchOccurrence', 'bo')
                ->where($producersDataQueryBuilder->expr()->andX(
                    $producersDataQueryBuilder->expr()->in('bo.id', $branchOccurrenceIds),
                    $producersDataQueryBuilder->expr()->eq('pa.isAttendee', $producersDataQueryBuilder->expr()->literal(true))
                ))
                ->addGroupBy('bo.id')
                ->addGroupBy('p.id');

            if (count($producersIdsWithSalesOrderRows) > 0) {
                $producersAttendeesData->andWhere($producersAttendeesData->expr()->notIn('p.id', $producersIdsWithSalesOrderRows));
            }

            $producersAttendeesData = $producersAttendeesData->getQuery()->getResult();

            $producersData = array_merge($producersWithSalesOrderRowsData, $producersAttendeesData);

            usort($producersData, function ($producerData1, $producerData2) {
                if ($producerData1['producer_name'] < $producerData2['producer_name']) {
                    return -1;
                } elseif ($producerData1['producer_name'] > $producerData2['producer_name']) {
                    return 1;
                } else {
                    return 0;
                }
            });

            $producerIds = array();

            foreach ($producersData as $producersDatum) {
                $producerIds[] = $producersDatum['producer_id'];
            }

            $producerIds = array_unique($producerIds);

            if (count($producerIds) == 0) {
                $producers = array();
            } else {
                $producersQueryBuilder = $this->entityManager->getRepository('IsicsOpenMiamMiamBundle:Producer')
                    ->createQueryBuilder('p');
                $producers             = $producersQueryBuilder
                    ->where($producersQueryBuilder->expr()->in('p.id', $producerIds))
                    ->orderBy('p.name')
                    ->getQuery()
                    ->getResult();
            }
        }

        return new ProducersTransfer($branchOccurrences, $producers, $producersData, $fromDate);
    }

    /**
     * Get producers transfer object for branch occurence
     *
     * @param BranchOccurrence $branchOccurrence
     *
     * @return ProducersDepositWithdrawal
     */
    public function getProducerTransferForBranchOccurrence(BranchOccurrence $branchOccurrence)
    {
        $producersDataQueryBuilder = $this->entityManager->createQueryBuilder();
        $producersData             = $producersDataQueryBuilder
            ->select('p.id AS producer_id')
            ->addSelect('p.name AS producer_name')
            ->addSelect('pr.name AS product_name')
            ->addSelect('sor.ref AS product_ref')
            ->addSelect('sor.unitPrice AS product_unit_price')
            ->addSelect('sor.quantity AS product_quantity')
            ->addSelect('sor.total AS product_total')
            ->addSelect('sor.commission AS product_commission')
            ->from('IsicsOpenMiamMiamBundle:Producer', 'p')
            ->leftJoin('p.salesOrderRows', 'sor')
            ->leftJoin('sor.salesOrder', 'so')
            ->leftJoin('sor.product', 'pr')
            ->leftJoin('so.branchOccurrence', 'bo')
            ->leftJoin('bo.branch', 'b')
            ->leftJoin('b.association', 'a')
            ->where($producersDataQueryBuilder->expr()->in('bo.id', $branchOccurrence->getId()))
            ->getQuery()
            ->getResult();

        return new ProducersDepositWithdrawal(
            $branchOccurrence,
            $producersData,
            $this->artificialProductRef,
            $this->entityManager->getRepository('IsicsOpenMiamMiamBundle:SalesOrder')
        );
    }

    /**
     * Returns activities of an association
     *
     * @param Association $association
     *
     * @return array
     */
    public function getActivities(Association $association)
    {
        return $this->entityManager->getRepository('IsicsOpenMiamMiamBundle:Activity')->findByEntities($association);
    }
}