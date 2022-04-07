<?php

namespace Enrich\Checkout\Model;

use Magento\Framework\Webapi\Rest\Request;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Enrich\Checkout\Helper\Data as checkouthelper;


class QuoteTotals implements \Enrich\Checkout\Api\QuoteTotalsInterface
{
    const CODELIST = ['shipping'];
    const TYPE = ['Wallet', 'Giftcard'];
    const SHIPPING_INFO = 'enrich_override/pdp/shipping_info';
    const FREE_SHIPPING_AMOUNT = 'enrich_override/pdp/free_shipping_amount';
    /**
     * Construct function.
     */
    public function __construct(
        Request $request,
        CartTotalRepositoryInterface $cartTotalRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $cartRepository,
        \Enrich\Pos\Model\Pos\Quote $posQuote,
        \Magento\Framework\App\ResourceConnection $connection,
        checkouthelper $checkouthelper
    ) {
        $this->request = $request;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->cartRepository = $cartRepository;
        $this->posQuote = $posQuote;
        $this->connection = $connection;
        $this->checkouthelper = $checkouthelper;
    }

    /**
     * This function gives the total segments of quote.
     *
     * @return array
     */
    public function quoteTotals()
    {        
        try {
            $response = ['status' => false, 'message' => 'Fail', 'data' => []];
            $request = $this->request->getRequestData();
            $quoteId = isset($request['quote_id']) ? $request['quote_id'] : 0;
            $isGuestQuote = isset($request['is_guest_quote']) ? $request['is_guest_quote'] : 'false';
            $isCollectTotal = isset($request['is_collect_total']) ? $request['is_collect_total'] : 'false';
            if (empty($quoteId)) {
                return ['status' => false, 'message' => 'Please Provide Valid Cart Id'];
            }
            if ($isGuestQuote == 'true') {
                //use of sql for performance
                $connection = $this->connection->getConnection();
                $sql = "select quote_id from quote_id_mask where masked_id="."'$quoteId'";
                $quoteId = $connection->fetchOne($sql);
            }
            $quote = $this->cartRepository->getActive($quoteId);
            $isVirtual = $collectTotals = 0;
            if ($quote->isVirtual()) {
                $isVirtual = 1;
            }
            // @TODO We wil require in case of totals mismatch

            // $items = $quote->getAllVisibleItems();
            // foreach ($items as $item) {
            //     $type = $item->getProductCategoryType();
            //     if (in_array($type, self::TYPE)) {
            //         $collectTotals = 1;
            //         break;
            //     }
            // }
            // if ($collectTotals) {
            //     $quote->setTotalsCollectedFlag(false)->collectTotals();
            // }
            if ($isCollectTotal) {
                $quote->setTotalsCollectedFlag(false)->collectTotals();
            }
            $totals = $quote->getTotals($quoteId);
            $totalSagement = [];
            foreach ($totals as $totalSegment) {
                $totalSegmentArray = $totalSegment->toArray();
                if ($isVirtual && in_array($totalSegmentArray['code'], self::CODELIST)) {
                    continue;
                }
                $totalSagement[] = [
                    'title' => $totalSegmentArray['title'],
                    'code' => $totalSegmentArray['code'],
                    'value' => round($totalSegmentArray['value'], 2),
                    'area' => null,
                ];                
            }
            if ($shippingInfo = $this->checkouthelper->getConfigValues(self::SHIPPING_INFO)) {
                $freeSHippingAmount = $this->checkouthelper->getConfigValues(self::FREE_SHIPPING_AMOUNT);
                $shippingInfo = str_replace('{{free_shipping_value}}', $freeSHippingAmount, $shippingInfo);

            }
            $totalSagement[] = [
                'title' => $shippingInfo,
                'code' => 'shipping_info',
                'value' => 0,
            ];
            $response = ['status' => true, 'data' => ['total_segments' => $totalSagement], 'message' => 'success'];
        } catch (\Exception $e) {
            $response = ['status' => false, 'message' => $e->getMessage(), 'data' => ['total_segments' => null]];
        }

        return $response;
    }

    public function autoAppliedOffers()
    {
        try {
            $connection = $this->connection->getConnection();
            $response = [
                'status' => true,
                'data' => [
                    'totals' => ['total_segments' => null, 'items' => null],
                    'extension_attributes' => ['applied_offer_ids' => null, 'discard_offer_ids' => null],
                ],
            ];
            $request = $this->request->getRequestData();
            $cartId = isset($request['quote_id']) ? $request['quote_id'] : null;
            $payNowFromBooking = isset($request['pay_now_from_booking']) ? $request['pay_now_from_booking'] : false;
            if (is_null($cartId)) {
                return $response;
            }
            $isGuest = isset($request['is_guest_quote']) ? $request['is_guest_quote'] : false;
            if ($isGuest == 'true') {
                $sql = "select quote_id from quote_id_mask where masked_id="."'$cartId'";
                $cartId = $connection->fetchOne($sql);
            }
            $ruleIdsArr = isset($request['rule_id']) ? $request['rule_id'] : [];
            $appliedCouponRuleId = null;
            $appliedRuleIdsArray = $discardRuleIdsArray = [];
            $quote = $this->cartRepository->get($cartId);
            $couponCode = $quote->getCouponCode();
            $discardRuleIds = $quote->getDiscardRuleIds();
            $appliedRuleIds = $quote->getAppliedRuleIds();
            if (!is_null($appliedRuleIds)) {
                $appliedRuleIdsArray = explode(',', $appliedRuleIds);
            }
            foreach ($appliedRuleIdsArray as $key => $value) {
                $sql = 'select restrict_to_remove_offer from salesrule where rule_id='.$value;
                $ruleId = $connection->fetchOne($sql);
                if ($ruleId) {
                    array_push($ruleIdsArr, $value);
                }
            }
            if (!is_null($discardRuleIds)) {
                $discardRuleIdsArray = explode(',', $discardRuleIds);
            }
            if (!is_null($couponCode)) {
                $sql = "select rule_id from salesrule_coupon where code='".$couponCode."'";
                $appliedCouponRuleId = $connection->fetchOne($sql);
            }
            $cartRules = array_merge($appliedRuleIdsArray, $discardRuleIdsArray);
            $rulesNeedToApply = array_intersect($cartRules, $ruleIdsArr);
            $discardRules = array_diff($cartRules, $ruleIdsArr);
            if (!is_null($appliedCouponRuleId)) {
                if (($key = array_search($appliedCouponRuleId, $discardRules)) !== false) {
                    unset($discardRules[$key]);
                }
                array_push($rulesNeedToApply, $appliedCouponRuleId);
            }
            $discardRules = implode(',', $discardRules);
            $rulesNeedToApply = implode(',', $rulesNeedToApply);
            $quote->setDiscardRuleIds($discardRules);
            $quote->setAppliedRuleIds($rulesNeedToApply);
            $quote->setTotalsCollectedFlag(false)->collectTotals();

            $quote->save();
            $sql = 'select item_id,final_row_total from quote_item where quote_id='.$cartId;
            $sqlResult = $connection->fetchAll($sql);
            if (!empty($sqlResult)) {
                foreach ($sqlResult as $item) {
                    $response['data']['totals']['items'][] = [
                        'item_id' => (int) $item['item_id'],
                        'extension_attributes' => ['final_row_total' => (float) $item['final_row_total']],
                    ];
                }
            }
            if (!$payNowFromBooking) {
                $isVirtual = 0;
                if ($quote->isVirtual()) {
                    $isVirtual = 1;
                }
                $totals = $quote->getTotals($cartId);
                $totalSagement = [];
                foreach ($totals as $totalSegment) {
                    $totalSegmentArray = $totalSegment->toArray();
                    if ($isVirtual && in_array($totalSegmentArray['code'], self::CODELIST)) {
                        continue;
                    }
                    $totalSagement[] = [
                        'title' => $totalSegmentArray['title'],
                        'code' => $totalSegmentArray['code'],
                        'value' => round($totalSegmentArray['value'], 2),
                        'area' => null,
                    ];
                }
                $response['data']['totals']['total_segments'] = $totalSagement;
            }
            $appliedOffers = $this->posQuote->getAppliedOffers($quote);
            $discardOffers = $this->posQuote->getDiscardedOffers($quote);
            if (!empty($appliedOffers['data'])) {
                $response['data']['extension_attributes']['applied_offer_ids'] = $appliedOffers['data'];
            }
            if (!empty($discardOffers['data'])) {
                $response['data']['extension_attributes']['discard_offer_ids'] = $discardOffers['data'];
            }
        } catch (\Exception $e) {
            $response['status'] = false;
            $response['message'] = $e->getMessage();
        }

        return $response;
    }
}
