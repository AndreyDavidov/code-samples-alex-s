<?php

namespace eFloat\OffersBundle\Model;

use Application\Sonata\UserBundle\Entity\User;
use Doctrine\ORM\EntityManager;
use eFloat\OffersBundle\Entity\AllocationReserve;
use eFloat\OffersBundle\Entity\AllocationReserveRepository;
use eFloat\OffersBundle\Entity\Offer;
use eFloat\OffersBundle\Services\BidScopeHelper;
use eFloat\OffersBundle\Services\ReserveLimiterService;
use FOS\RestBundle\Util\Codes;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use eFloat\NotificationBundle\Services\NotificationService;
use Symfony\Cmf\Component\Routing\ChainRouter;

/**
 * Class AllocationReserveManager
 * @package eFloat\OffersBundle\Model
 */
class AllocationReserveApiManager
{
    /**
     * @var ReserveLimiterService
     */
    protected $reserveLimiter;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var BidScopeHelper
     */
    protected $bidScopeHelper;

    /**
     * AllocationReserveApiManager constructor.
     * @param ContainerInterface $container
     * @param EntityManager $entityManager
     * @param ReserveLimiterService $reserveLimiterService
     * @param BidScopeHelper $bidScopeHelper
     */
    public function __construct(
        ContainerInterface $container,
        EntityManager $entityManager,
        ReserveLimiterService $reserveLimiterService,
        BidScopeHelper $bidScopeHelper
    ) {
        $this->container = $container;
        $this->em = $entityManager;
        $this->reserveLimiter = $reserveLimiterService;
        $this->bidScopeHelper = $bidScopeHelper;
    }

    /**
     * @return ContainerInterface
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * @return EntityManager
     */
    public function getManager()
    {
        return $this->em;
    }

    /**
     * @return ReserveLimiterService
     */
    public function getReserveLimiter()
    {
        return $this->reserveLimiter;
    }

    /**
     * @return BidScopeHelper
     */
    public function getBidScopeHelper()
    {
        return $this->bidScopeHelper;
    }

    /**
     * @return NotificationService
     */
    public function getUserNotification()
    {
        return $this->getContainer()->get('user_notification');
    }

    /**
     * @return AllocationReserveRepository
     */
    public function getRepository()
    {
        return $this->getManager()->getRepository('eFloatOffersBundle:AllocationReserve');
    }

    /**
     * @return ChainRouter
     */
    public function getRouter()
    {
        return $this->getContainer()->get('router');
    }

    /**
     * @param User $user
     * @param $offerId
     * @return array|JsonResponse
     */
    public function checkPossibilityToReserve(User $user, $offerId)
    {
        $response = $this->getBidScopeHelper()->checkOfferScope($offerId);

        if (isset($response['error'])) {
            $code = $response['code'];
            unset($response['code']);

            return $this->jsonResponse($response, $code);
        }

        /** @var Offer $offer */
        $offer = $response['offer'];

        $reserveLimits = $this->getReserveLimiter()->calculateLimits($offer, $user);

        $reserveParams = [
            'offer_id' => $offer->getId(),
            'min_parcel' => $offer->getMinimumParcelShares(),
            'max_parcel' => $offer->getMaximumParcelShares(),
            'price_per_share' => $offer->getSharePrice(),
            'offer_limit' => $reserveLimits
        ];

        if (($reserveParams['offer_limit'] !== false && $reserveParams['offer_limit'] < $reserveParams['min_parcel'])
            && !$offer->getAllowReservationWhenSharesOnOfferIsExceeded()
        ) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'exceeded',
                'message' => 'Offer limit exceeded'
            ], Codes::HTTP_BAD_REQUEST);
        } else {
            return $this->jsonResponse([
                'success' => true,
                'instance_name' => $this->container->getParameter('project_name'),
                'reserve_params' => $reserveParams
            ], Codes::HTTP_OK);
        }
    }

    /**
     * @param User $user
     * @param Request $request
     * @param $offerId
     * @return JsonResponse
     */
    public function postAllocationReserve(User $user, Request $request, $offerId)
    {
        $response = $this->getBidScopeHelper()->checkOfferScope($offerId);

        if (isset($response['error'])) {
            $code = $response['code'];
            unset($response['code']);

            return $this->jsonResponse($response, $code);
        }

        /** @var Offer $offer */
        $offer = $response['offer'];

        $amount = $request->request->get('amount', false);

        if ($amount == false) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'invalid_amount',
                'message' => 'Required parameter amount not sent']);
        }

        $amount = floatval($amount);

        if ($amount <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'bad_request',
                'message' => 'Amount cannot be negative number'
            ], Codes::HTTP_BAD_REQUEST);
        }

        $shares = floor(bcdiv($amount, $offer->getSharePrice()));
        $reserveLimits = $this->getReserveLimiter()->calculateLimits($offer, $user);

        if ((($reserveLimits >= 0 && $shares <= $reserveLimits) && ($shares >= $offer->getMinimumParcelShares())) ||
            $offer->getAllowReservationWhenSharesOnOfferIsExceeded()
        ) {
            $message = sprintf(
                '<p>Thank you for your interest in subscribing to invest in %s. We will contact you via email once your application has been reviewed and approved.</p><p>You will then be required to complete an online application form and transfer the funds.</p>',
                $offer->getCompany()->getName()
            );

            /** @var AllocationReserve $reserve */
            $reserve = $this->createAllocationReserve($offer, $user, $amount, $shares);


            //send notification about approved AR
            if ($reserve->getStatus() === AllocationReserve::getStatusApproved()) {
                if (count($user->getInvestmentProfiles()) > 0) {
                    $url = [
                        'path' => $this->getRouter()->generate(
                            'e_float_member_application_create',
                            [
                                "allocationId" => $reserve->getId()
                            ],
                            true
                        ),
                        'title' => 'Allocation Reserve'
                    ];
                } else {
                    $url = [
                        'path' => $this->getRouter()->generate('investment_profile_index', [], true),
                        'title' => 'Create Investment Profile'
                    ];
                }

                $params = [
                    'allocationReserve' => $reserve,
                    'user' => $user,
                    'offer' => $reserve->getOffer(),
                    'application' => $offer->getApplication(),
                    'url' => $url
                ];

                $this->getUserNotification()
                    ->sendViaEnabledMethodsByListenerViaRabbitMQ('member_reserve_allocation_approved', $user, $params);
            }

            //recalculate reserve limits
            $newReserveLimits = $this->getReserveLimiter()->calculateLimits($offer, $user);

            return $this->jsonResponse([
                'success' => true,
                'message' => $message,
                'data' => [
                    'allocation_reserve_id' => $reserve->getId(),
                    'offer_id' => $offer->getId(),
                    'reserved' => $reserveLimits,
                    'reserve_limits' => $newReserveLimits
                ]
            ]);

        } elseif ($reserveLimits && $shares > $reserveLimits) {
            $message = sprintf('The Maximum Investment Amount is $ %d', $reserveLimits * $offer->getSharePrice());
        } elseif ($shares > $offer->getMaximumParcelShares()) {
            $message = sprintf(
                'The Maximum Investment Amount is $ %d',
                $offer->getMaximumParcelShares() * $offer->getSharePrice()
            );
        } elseif ($shares < $offer->getMinimumParcelShares()) {
            $message = sprintf(
                'The Minimum Investment Amount is $ %d',
                $offer->getMinimumParcelShares() * $offer->getSharePrice()
            );
        } else {
            $message = 'You cannot add another reservation as you have already subscribed to the maximum allowed for 
            this Offer. Please manage your investments / applications by navigating to the My Investments page.';
        }

        return $this->jsonResponse([
            'success' => false,
            'error' => 'calculation_error',
            'message' => $message
        ], Codes::HTTP_BAD_REQUEST);
    }

    /**
     * @param User $user
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function updateAllocationReserve(User $user, Request $request, $id)
    {
        $amount = $request->request->get('amount', false);

        if ($amount == false) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'invalid_amount',
                'message' => 'Required parameter "amount" not sent'
            ]);
        }

        if (floatval($amount) <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'bad_request',
                'message' => 'Amount cannot be negative number'
            ], Codes::HTTP_BAD_REQUEST);
        }

        $allocationReserve = $this->getRepository()->findOneBy(['id' => $id, 'member' => $user]);

        if ($allocationReserve instanceof AllocationReserve) {
            $response = $this->getBidScopeHelper()->checkOfferScope($allocationReserve->getOffer()->getId());

            if (isset($response['error'])) {
                $code = $response['code'];
                unset($response['code']);

                return $this->jsonResponse($response, $code);
            }

            /** @var Offer $offer */
            $offer = $response['offer'];

            $shares = floor(bcdiv($amount, $offer->getSharePrice()));
            $reserveLimits = $this->getReserveLimiter()->calculateLimits($offer, $user);

            $alreadyReserved = floor(bcdiv($allocationReserve->getAmount(), $offer->getSharePrice()));

            if (($reserveLimits >= 0 && $shares <= $reserveLimits) &&
                ($shares >= $offer->getMinimumParcelShares()) && ($shares <= $alreadyReserved)
            ) {
                $allocationReserve->setAmount($amount);
                $this->getManager()->flush();

                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Allocation Reserve successfully updated',
                    'allocation_reserve_id' => $allocationReserve->getId()
                ]);

            } elseif ($reserveLimits && $shares > $reserveLimits) {
                $message = sprintf('The Maximum Investment Amount is $ %d', $reserveLimits * $offer->getSharePrice());
            } elseif ($shares > $offer->getMaximumParcelShares()) {
                $message = sprintf(
                    'The Maximum Investment Amount is $ %d',
                    $offer->getMaximumParcelShares() * $offer->getSharePrice()
                );
            } elseif ($shares < $offer->getMinimumParcelShares()) {
                $message = sprintf(
                    'The Minimum Investment Amount is $ %d',
                    $offer->getMinimumParcelShares() * $offer->getSharePrice()
                );
            } elseif ($shares > $alreadyReserved) {
                $message = sprintf('Number of shares must be less or equals to %d', $alreadyReserved);
            } else {
                $message = 'Allocation Reserve limit reached';
            }

            return $this->jsonResponse([
                'success' => false,
                'error' => 'amount_error',
                'message' => $message
            ], Codes::HTTP_BAD_REQUEST);
        }

        return $this->jsonResponse([
            'success' => false,
            'error' => 'not_found',
            'message' => sprintf('Allocation Reserve with id %d not found', $id)
        ], Codes::HTTP_NOT_FOUND);
    }

    /**
     * @param User $user
     * @param $id
     * @return JsonResponse
     */
    public function getAllocationReserve(User $user, $id)
    {
        /** @var  $reserve AllocationReserve */
        $reserve = $this->getRepository()->findOneBy(['id' => $id, 'member' => $user]);

        if ($reserve instanceof AllocationReserve) {

            /** @var  $offer \eFloat\OffersBundle\Entity\Offer */
            $offer = $reserve->getOffer();

            $isApplicationPossible = $this->getRepository()
                ->getIsReserveNotExceeded(
                    $reserve->getId(),
                    $reserve->getOfferId(),
                    floor($reserve->getAmount() / $offer->getSharePrice()),
                    $user
                );

            $params = [
                'offerId' => $offer->getId(),
                'sharePrice' => $offer->getSharePrice(),
                'minParcel' => $offer->getMinimumParcelShares(),
                'maxParcel' => $reserve->getAmount() / $offer->getSharePrice(),
                'reserveLimits' => $reserve->getAmount() / $offer->getSharePrice(),
                'isApplicationPossible' => $isApplicationPossible
            ];

            return $this->jsonResponse([
                'success' => true,
                'allocation_reserve' => $reserve->getId(),
                'data' => $params
            ], Codes::HTTP_NOT_FOUND);
        }

        return $this->jsonResponse([
            'success' => false,
            'error' => 'not_found',
            'message' => sprintf('Allocation Reserve with id %d not found', $id)
        ], Codes::HTTP_NOT_FOUND);
    }

    /**
     * @param User $user
     * @param $id
     * @return JsonResponse
     */
    public function deleteAllocationReserve(User $user, $id)
    {
        $allocationReserve = $this->getRepository()->findOneBy(['id' => $id, 'member' => $user]);

        if ($allocationReserve instanceof AllocationReserve) {
            if ($allocationReserve->getStatus() == AllocationReserve::APPROVED) {
                $allocationReserve->setStatus(AllocationReserve::FAILED_TO_COMPLETE);
                $this->getManager()->flush();

                return $this->jsonResponse([
                    'success' => true,
                    'isRemoved' => false,
                    'message' => 'Allocation Reserve status was changed to Failed To Complete'
                ]);
            } else {
                $this->getManager()->remove($allocationReserve);
                $this->getManager()->flush();

                return $this->jsonResponse([
                    'success' => true,
                    'isRemoved' => true,
                    'message' => 'Allocation Reserve was successfully deleted'
                ]);
            }
        }

        return $this->jsonResponse([
            'success' => false,
            'error' => 'not_found',
            'message' => sprintf('Allocation Reserve with id %d not found', $id)
        ], Codes::HTTP_NOT_FOUND);
    }

    /**
     * @param Offer $offer
     * @param User $user
     * @param $amount
     * @param $shares
     * @param bool $createByMobApp
     * @return AllocationReserve
     */
    public function createAllocationReserve(Offer $offer, User $user, $amount, $shares, $createByMobApp = true)
    {
        /** @var $reserve AllocationReserve */
        $reserve = new AllocationReserve();
        $currentDate = new \DateTime();

        // cron task run automatically on the opening day and
        // approved allocation reserves which has Application with option auto approved
        if ($offer->getApplication()->getAutoApprove() &&
            $currentDate >= $offer->getOpenDate() && $currentDate <= $offer->getCloseDate()
        ) {
            $reserve->setStatus(AllocationReserve::getStatusApproved());
        } else {
            $reserve->setStatus(AllocationReserve::getStatusPending());
        }

        $reserve->setAmount($amount);
        $reserve->setMember($user);
        $reserve->setOffer($offer);
        $reserve->setMaximumParcelShares($shares);

        if ($createByMobApp) {
            $reserve->setCreatedByMobileApplication(true);
        }

        $this->getManager()->persist($reserve);
        $this->getManager()->flush($reserve);

        return $reserve;
    }

    /**
     * @param array $data
     * @param int $status
     * @param array $headers
     * @return JsonResponse
     */
    public function jsonResponse(array $data, $status = Codes::HTTP_OK, array $headers = [])
    {
        return new JsonResponse($data, $status, $headers);
    }
}
