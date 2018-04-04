<?php

/*
 * This file is part of the OpenMiamMiam project.
 *
 * (c) Isics <contact@isics.fr>
 *
 * This source file is subject to the AGPL v3 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Isics\Bundle\OpenMiamMiamBundle\Model\SalesOrder;

use Isics\Bundle\OpenMiamMiamBundle\Document\OpenMiamMiamPDF;
use Isics\Bundle\OpenMiamMiamBundle\Entity\Repository\SalesOrderRepository;
use Isics\Bundle\OpenMiamMiamBundle\Entity\Repository\SubscriptionRepository;
use Isics\Bundle\OpenMiamMiamBundle\Entity\SalesOrder;
use Isics\Bundle\OpenMiamMiamBundle\Entity\Subscription;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

class SalesOrdersPdf
{
    /**
     * @var TCPDF $pdf
     */
    protected $pdf;

    /**
     * @var EngineInterface
     */
    protected $engine;

    /**
     * @var array
     */
    protected $salesOrders;

	/**
	 * @var $salesOrderRepository
	 */
	protected $subscriptionRepository;

    /**
     * Constructs object
     *
     * @param TCPDF $pdf
     * @param EngineInterface $engine
	 * @param SalesOrderRepository
     */
    public function __construct(\TCPDF $pdf, SubscriptionRepository $subscriptionRepository, EngineInterface $engine)
    {
        $this->pdf = $pdf;
        $this->engine = $engine;
        $this->subscriptionRepository = $subscriptionRepository;
    }

    /**
     * Sets sales orders
     *
     * @param array $salesOrders
     */
    public function setSalesOrders(array $salesOrders)
    {
        $this->salesOrders = $salesOrders;
    }

    /**
     * Builds pdf
     */
    public function build()
    {

        foreach ($this->salesOrders as $salesOrder) {
			$user = $salesOrder->getUser();
			$association = $salesOrder->getBranchOccurrence()->getBranch()->getAssociation();
			$balance = $this->subscriptionRepository->getBalanceUserAndAssociation($user,$association);
            $this->pdf->AddPage();
            $this->pdf->writeHTML(
                $this->engine->render('IsicsOpenMiamMiamBundle:Pdf:salesOrder.html.twig', array('order' => $salesOrder, 'balance' => $balance))
            );
        }

    }

    /**
     * Returns html
     *
     * @param $filename
     *
     * @return string
     */
    public function render($filename = null)
    {
        $this->build();

        return $this->pdf->Output($filename, 'I');
    }
}
