<?php

namespace Pkbpay\Billings\Models\Traits\Method;

use App\Models\BillingDetails;
use App\Models\ChargeUnitCostMgt;
use App\Models\OutsourceCompany;
use App\Models\RecruitCompany;
use App\Models\PaymentItemModel;
use App\Models\PaymentMgtModel;
use App\Models\PaymentRewardsModel;
use App\Services\Admin\TaxService;
use Illuminate\Support\Facades\Log;

trait IncPayment
{
    public $_debugFlg = true;

    public function caculatePayment() {
        $roundType = 2; // 1: billing, 2: payment
        $gSubTotal = 0;
        $gTotal = 0;
        $subtotal = 0;
        $outsourcing_fee = 0;
        $sumOutsourcingPlanFeeTax = 0;
        $platform_clearing_fee = 0;
        $sumPlatformClearingFeeTax = 0;
        $sumSubtotalQuantity = 0;
        $platform_clearing_fee_tax_quantity = 0;
        $outsourcing_fee_tax_quantity = 0;
        $sumFeeOther = 0;
        $sumFeeOtherQuantity = 0;
        $transfer_fee = 0;
        $price_type_transfer_fee = ChargeUnitCostMgt::where('price_type', ChargeUnitCostMgt::PRICE_TYPE_SES_TRANSFER_FEE)->first();
        $is_recruit = $this->payment_to == PaymentMgtModel::PAYMENT_TO_AGENT;
        list(, $companyCode,) = explode('-', $this->payment_id_onscreen);
        $company = $is_recruit ? RecruitCompany::where('recruit_company_code', $companyCode)->first() : OutsourceCompany::where('outsource_company_code', $companyCode)->first();
        $isBussiness = $this->checkApplyBusinessFlg($company, $this->date);

        $tax_rate_percent = TaxService::getRatePercent($this->created_at, $this->updated_at);
        $tax_included = TaxService::calculateTax($tax_rate_percent);

        foreach ($this->paymentRewards as $key => $reward) {
            $billing_detail = $reward->billingDetail;
            $billing = $billing_detail->billing;
            $tax_percent = TaxService::getRatePercent($billing->created_at, $billing->updated_at);
            $tax = TaxService::calculateTax($tax_percent);
            $reward->tax_rate_percent = $tax_percent;
            $reward->tax_included = $tax;
            if ($reward->exclusion_status != PaymentRewardsModel::STATUS_SETTING) {
                $billing_detail->tax_rate_percent = $tax_rate_percent;
                $billing_detail->tax_included = $tax_included;
                $reward->is_bussiness = $isBussiness;
                $mgt_type = $is_recruit ? BillingDetails::TYPE_RECRUIT_APPLY_MGT : BillingDetails::TYPE_OUTSOURCE_APPLY_MGT;
                if (!$isBussiness) {
                    // 個別報酬額（税別）
                    $billing_detail->subtotal = roundNumber($billing_detail->subtotal / $tax_included);
                }

                $billing_price_type = $billing_detail->chargeUnitCostMgt->price_type ?? null;
                $check_billing_detail = $is_recruit ? ($billing_price_type == ChargeUnitCostMgt::PRICE_TYPE_REWARD_FOR_SUCCESS_INTRODUCTION_AGENT
                ) : ($billing_price_type == ChargeUnitCostMgt::PRICE_TYPE_BUSINESS_CONSIGNMENT_FEE
                );
                $current_month_operating_hours_number = $billing_detail->current_month_operating_hours_number;
                if ($billing_detail && $check_billing_detail && $billing_detail->job_seeker_apply_mgt_type == $mgt_type) {
                    if (!$isBussiness) {
                        $subtotal += (($billing_detail->subtotal ?? 0) * ($billing_detail->quantity ?? 0) + ((int) $reward->individual_reward_amoun_change)) * ($tax_included);
                    } else {
                        $subtotal += ($billing_detail->subtotal ?? 0) * ($billing_detail->quantity ?? 0) + ((int) ($reward->individual_reward_amoun_change));
                    }
                    // 小計
                    $gSubTotal = $billing_detail->subtotal + (int) $reward->individual_reward_amoun_change;
                    $sumSubtotalQuantity += 1;
                }

                $outsourcing_fee -= $reward->outsourcing_fee;
                $sumOutsourcingPlanFeeTax -= $reward->outsourcing_fee * $tax_included; // 成功報酬プラン利用料（税込）
                $outsourcing_fee_tax_quantity += $reward->outsourcing_fee ? 1 : 0;

                $platform_clearing_fee -= $reward->platform_clearing_fee;
                $sumPlatformClearingFeeTax -= $reward->platform_clearing_fee * $tax_included; // プラットフォーム決済手数料（税込）
                $platform_clearing_fee_tax_quantity += $reward->platform_clearing_fee ? 1 : 0;
            } else {
                $this->logDebug("支払いから除外: Bill-D-id: {$billing_detail->id}");
                $billing_detail->subtotal = roundNumber($billing_detail->subtotal * 10 / ($tax * 10));
                $billing_detail->tax_included = $tax_included;
                $billing_detail->tax_rate_percent = $tax_rate_percent;
            }
        }

        foreach ($this->paymentItems as $key => $item) {
            if ($item->exclusion_status != PaymentItemModel::STATUS_SETTING) {
                // 手数料・キャンペーン等
                $sumFeeOther += floatval($item->unit_price) * intval($item->quantity) + floatval($item->tax);
                $sumFeeOtherQuantity += $item->quantity;
            }

            if ($price_type_transfer_fee !== null && $item->payment_type == $price_type_transfer_fee->id) {
                $transfer_fee = $item->quantity * $item->unit_price;
            }
        }

        // 消費税 = ⑤支払い紹介成功報酬（税込）- ⑧小計
        //$sumTax = $subtotal * $tax_rate_percent/100;
        $sumTax = $subtotal - $gSubTotal;
        $sumTax = ($sumTax < 0) ? 0 : $sumTax;

        // 3.1_業務委託報酬
        $gReferralFee = ($subtotal + $sumOutsourcingPlanFeeTax + $sumPlatformClearingFeeTax);
        if ($isBussiness) {
            $gReferralFee += $sumTax;
        }

        // 合計金額
        $gTotal = roundNumber($gSubTotal + $sumTax + $sumOutsourcingPlanFeeTax + $sumPlatformClearingFeeTax + $sumFeeOther, $roundType);
        $sumTotalQuantity = $sumSubtotalQuantity + $platform_clearing_fee_tax_quantity + $outsourcing_fee_tax_quantity + $sumFeeOtherQuantity;

        // 小計
        $sumSubtotal = roundNumber($isBussiness ? $subtotal : $subtotal * (1 - $tax_included), $roundType);
        $this->logDebug("小計::subtotal_format: {$sumSubtotal}
            消費税::tax_format: {$sumTax}
            業務委託手数料（税込）:{$sumOutsourcingPlanFeeTax}
            プラットフォーム決済手数料（税込）: {$sumPlatformClearingFeeTax}
            手数料・キャンペーン等	:{$sumFeeOther}
            合計金額::gTotal: {$gTotal}
            8.1_小計: {$gSubTotal}
            3.1_業務委託報酬: {$gReferralFee}
            支払い業務委託報酬（税込）: {$subtotal}");

        $res = [
            'subtotal' => $isBussiness ? $subtotal : $subtotal * (1 - $tax_included),
            'subtotal_tax' => $subtotal * $tax_included,
            'subtotal_format' => curency_format($gSubTotal),
            'subtotal_tax_format' => curency_format($subtotal * $tax_included),
            'subtotal_quantity' => $sumSubtotalQuantity,
            'outsourcing_fee' => $outsourcing_fee,
            'outsourcing_fee_format' => curency_format($outsourcing_fee),
            'outsourcing_fee_tax' => $sumOutsourcingPlanFeeTax,
            'outsourcing_fee_tax_format' => curency_format($sumOutsourcingPlanFeeTax),
            'outsourcing_fee_tax_quantity' => $outsourcing_fee_tax_quantity,
            'platform_clearing_fee' => $platform_clearing_fee,
            'platform_clearing_fee_format' => curency_format($platform_clearing_fee),
            'platform_clearing_fee_tax' => $sumPlatformClearingFeeTax,
            'platform_clearing_fee_tax_format' => curency_format($sumPlatformClearingFeeTax),
            'platform_clearing_fee_tax_quantity' => $platform_clearing_fee_tax_quantity,
            'tax' => $sumTax,
            'tax_format' => curency_format($sumTax),
            'item_unit_price_total' => $sumFeeOther,
            'item_unit_price_total_format' => curency_format($sumFeeOther),
            'item_unit_price_total_quantity' => $sumFeeOtherQuantity,
            'total' => $gTotal,
            'total_format' => curency_format($gTotal),
            'total_format_yen' => yen_format($gTotal),
            'total_quantity' => $sumTotalQuantity,
            'referral_success_fee' => $gReferralFee,
            'referral_success_fee_format' => curency_format($gReferralFee),
            'transfer_fee' => $transfer_fee,
            'current_month_operating_hours_number' => $current_month_operating_hours_number ?? '',
            'tax_rate_percent' => $tax_rate_percent,
            'tax_included' => $tax_included
        ];

        return $res;
    }

    /**
     * is active-business ???
     *
     * @param @company object
     * @param @billingDate date
     */
    public function checkApplyBusinessFlg($company, $billingDate, $businessType = 1)
    {
        if ($company && $company->business_type == $businessType) {
            $billingDate = Carbon::parse($billingDate);

            if (!empty($company->apply_start_year) && !empty($company->apply_start_month)) {
                $date = $company->apply_start_year.'-'.$company->apply_start_month. '-01 00:00:00';
                $businessDate = Carbon::parse($date);
                if ($billingDate >= $businessDate) {
                    return true;
                }
            } else {
                return true;
            }
        }

        return false;
    }

    public function logDebug($data, $isError = false)
    {
        if (!$this->_debugFlg) return true;
        if ($isError) {
            Log::error($data);
        } else {
            Log::info($data);
        }
    }
}
