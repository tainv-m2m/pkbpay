<?php

namespace Pkbpay\Billings\Models\Traits\Method;

use App\Models\Billings;
use App\Models\BillingDetails;
use App\Models\ChargeUnitCostMgt;
use App\Models\SalesGuaranteeApply;
use App\Models\UsedPlan;
use App\Services\Admin\TaxService;
use Illuminate\Support\Facades\Log;

trait IncBilling
{
    public $_debugFlg = true;

    public function additionBilling()
    {
        $arr = [
            1 => [5 => 'inCulエージェント月額利用料'],
            2 => [9 => '転職者の送客'],
            4 => [15 => '参画候補者（フリーランス）の送客'],
            9 => [49 => 'その他の請求',],
            27 => [50 => 'その他値引き',],
            13 => [51 => '売上保証適用',],
            23 => [56 => '月額プラン月額費用',],
        ];

        $this->logDebug('IncBilling: '. $this->id);

        return $arr;
    }

    public function caculateBilling() {
        $this->addIsClient();
        $sum_no_tax = 0;
        $count_sum_no_tax = 0;
        $sum_minus = 0;
        $count_sum_minus = 0;
        $sum_no_tax_success_fee_plan_usage_fee = 0;
        $gSubTotal = 0;
        $gSubTotalTax = 0;
        $gTotal = 0;
        $gOtherFee = 0;
        $roundType = 1; // 1: billing, 2: payment
        ['company_user' => $company_user, 'company' => $company] = $this->clientCompany();
        $usedPlan = UsedPlan::where('start_date', 'like', $this->billing_date->copy()->format('Y-m') . "%")
            ->where('company_id', $this->company_id)
            ->where('type', UsedPlan::RECRUIT)
            ->first();
        $unitCostMgt = false;
        if ($usedPlan) {
            $unitCostMgt = ChargeUnitCostMgt::activePlanByMtPlanId($usedPlan->charge_unit_cost_mgt_id, $this->billing_date);
        }

        $is_recruit_free_plan = false;
        if ($unitCostMgt && $unitCostMgt->price_plan == ChargeUnitCostMgt::SUCCESS_FEE_PLAN) {
            $is_recruit_free_plan = true;
        }

        $billing_details = $this->billingDetails->filter(function ($billing_detail) {
            if ($billing_detail->job_seeker_apply_mgt_type && $this->is_recruit) {
                return $billing_detail->job_seeker_apply_mgt_type == BillingDetails::TYPE_RECRUIT_APPLY_MGT;
            }
            if ($billing_detail->job_seeker_apply_mgt_type && $this->is_outsource) {
                return $billing_detail->job_seeker_apply_mgt_type == BillingDetails::TYPE_OUTSOURCE_APPLY_MGT;
            }

            return $billing_detail;
        });

        $tax_rate_percent = TaxService::getRatePercent($this->created_at, $this->updated_at);
        $tax_included = TaxService::calculateTax($tax_rate_percent);
        $sum_with_billing_type = [];
        $sale_guarantee = [];
        $this->is_edit = false;
        $additionSettings = $this->additionBilling();
        $specialTypeIds = [50]; // その他値引き
        $saleGuaranteeId = 51; // 売上保証適用

        foreach ($billing_details as $key => $billing_detail) {
            $additionItemFlg = ($billing_detail->contents_operation) ? true : false;
            $billing_detail->tax_rate_percent = $tax_rate_percent;
            $billing_detail->tax_included = $tax_included;
            $itemCostNoTax = $billing_detail->subtotal;
            $itemCostTotalNoTax = $billing_detail->subtotal * $billing_detail->quantity;
            if ($is_recruit_free_plan) {
                $individual_reward_amoun_change = $billing_detail->paymentReward ? $billing_detail->paymentReward->individual_reward_amoun_change : 0;
                $subtotal = $billing_detail->subtotal * $billing_detail->quantity;
                $sum_no_tax_success_fee_plan_usage_fee += ($subtotal + $individual_reward_amoun_change) * $unitCostMgt->intro_successful_reward/100;
            }

            $billing_detail->makeDataDetails($company->campaign ?? null, $this->billing_date ?? null, $company_user);
            if ($billing_detail->is_edit == BillingDetails::EDITTING) {
                $this->is_edit = true;
            }
            $price_type = $billing_detail->chargeUnitCostMgt->price_type ?? null;
            $this->sending_custommer = false;
            if (in_array($price_type, [
                ChargeUnitCostMgt::PRICE_TYPE_TEMPORARY_STAFF_JOB_CHANGE_CUSTOMER,
                ChargeUnitCostMgt::PRICE_TYPE_SES_FREELANCE_CUSTOMER
            ])) {
                $this->sending_custommer = true;
            }
            $costWithTax = $itemCostTotalNoTax * $tax_included;

            $showItemTotalFlg = 1;
            $this->sale_guarantee = false;
            if ($additionItemFlg) {
                if (in_array($billing_detail->billing_type, $specialTypeIds)) {
                    $showItemTotalFlg = 0;
                    $gOtherFee += $costWithTax;
                } else {
                    if ($billing_detail->billing_type == $saleGuaranteeId) {
                        $this->sale_guarantee = true;
                        $sale_guarantee = [
                            'name' => '売上保証適用',
                            'sum' => $billing_detail->total_with_tax,
                            'sum_format' => curency_format($billing_detail->total_with_tax)
                        ];
                    }

                    // the same with !$additionItemFlg
                    //$billing_detail->subtotal = roundNumber($billing_detail->subtotal / $tax_included);
                    $this->logDebug("addition->subtotal: {$billing_detail->subtotal}");
                }
            } else {
                // 単価
                //$billing_detail->subtotal = roundNumber($billing_detail->subtotal / $tax_included);
                $this->logDebug("detail->subtotal: {$billing_detail->subtotal}");
            }

            if ($showItemTotalFlg) {
                // 小計 = SUM(②金額（税別）)
                $gSubTotal += $itemCostTotalNoTax;

                // SUM(税込額)
                $gSubTotalTax += $costWithTax;
            }
            $billing_detail->showItemTotalFlg = $showItemTotalFlg;

            $this->logDebug("billingDetail priceType: ". $price_type);
            if ($price_type && $billing_detail->status == BillingDetails::NORMAL) {
                $this->logDebug("billingDetail Normal: ". $billing_detail->id);
                if (!in_array($price_type, [
                    ChargeUnitCostMgt::PRICE_TYPE_CAMPAIGN,
                    ChargeUnitCostMgt::PRICE_TYPE_SALES_GUARANTEE,
                    ChargeUnitCostMgt::PRICE_TYPE_OTHER_DISCOUNT
                ])) {
                    $sum_no_tax += $billing_detail->paymentReward ? ($billing_detail->paymentReward->exclusion_status == 1 ? $billing_detail->total_no_tax : 0) : $billing_detail->total_no_tax;
                    $count_sum_no_tax++;
                    if (!isset($sum_with_billing_type[$price_type])) {
                        $sum_with_billing_type[$price_type] = ChargeUnitCostMgt::mapPriceType()[$price_type] ?? ['name' => 'underfine billing_type ' . $price_type];
                        $sum_with_billing_type[$price_type]['sum'] = $billing_detail->total_with_tax;
                    } else {
                        $sum_with_billing_type[$price_type]['sum'] += $billing_detail->total_with_tax;
                    }
                    $this->logDebug("billingDetail ID: ". $billing_detail->id);
                }

                if (in_array($price_type, [
                    ChargeUnitCostMgt::PRICE_TYPE_CAMPAIGN,
                    ChargeUnitCostMgt::PRICE_TYPE_SALES_GUARANTEE,
                    ChargeUnitCostMgt::PRICE_TYPE_OTHER_DISCOUNT
                ])) {
                    // 値引き（キャンペーン・売上保証等）
                    $sum_minus += $billing_detail->total_no_tax;
                    $count_sum_minus++;

                    if (!isset($sum_with_billing_type[$price_type])) {
                        $sum_with_billing_type[$price_type] = BillingDetails::mapBillingType()[$price_type] ?? ['name' => 'underfine billing_type ' . $price_type];
                        $sum_with_billing_type[$price_type]['sum'] = $billing_detail->total_with_tax;
                    } else {
                        $sum_with_billing_type[$price_type]['sum'] += $billing_detail->total_with_tax;
                    }
                    $this->logDebug("billingDetail discountID: ". $billing_detail->id);
                }
            }
        }
        foreach ($sum_with_billing_type as $key => $sum_type) {
            $sum_with_billing_type[$key]['sum_format'] = curency_format($sum_type['sum']);
        }
        $tax = roundNumber($sum_no_tax * $tax_rate_percent / 100);
        $sum_total = $sum_no_tax * $tax_included + $sum_minus * $tax_included;
        $count_total = $count_sum_minus + $count_sum_no_tax;

        if ($sale_guarantee) {
            $sum_with_billing_type[] = $sale_guarantee;
        }
        if ($tax) {
            $sum_with_billing_type[] = [
                'name' => '消費税',
                'sum' => $tax,
                'sum_format' => curency_format($tax)
            ];
        }
        $sale_guarantee_apply = null;
        $sale_guarantee_apply = SalesGuaranteeApply::where('recruit_company_id', $company->id ?? null)
            ->get();
        // 成功報酬プラン利用料（〇％）
         if ($is_recruit_free_plan) {
             $sum_with_billing_type[] = [
                 'name' => '成功報酬プラン利用料（' . $unitCostMgt->intro_successful_reward .'％）',
                 'sum' => $unitCostMgt->monthly_price,
                 'sum_format' => curency_format($unitCostMgt->monthly_price)
             ];
         }

        $gSubTotal = roundNumber($gSubTotal, $roundType);
        // 消費税 = SUM(②「税込額」) ー SUM(「金額（税別）」- ⑧小計) 23600 - 21455
        $gCostTax = $gSubTotalTax - $gSubTotal;

        // SUM(小計 + 消費税 + 値引き（キャンペーン・売上保証等）)
        $gTotal = $gSubTotal + $gCostTax + $gOtherFee;

        //（税別金額）= 合計金額 - 消費税 => for pdf
        $gNoTaxAmount = $gTotal - $gCostTax;

        $aaa = $sum_minus * $tax_included;
        $this->logDebug("4.1_小計: {$gSubTotal}
            消費税::tax_format: {$gCostTax}
            値引き（キャンペーン・売上保証等）:{$gOtherFee}
            合計金額::gTotal: {$gTotal}
            小計:sum_no_tax: {$sum_no_tax}
            値引き（キャンペーン・売上保証等）:{$aaa}
            消費税::tax: {$tax}
            合計金額::sum_total: {$sum_total}
            （税別金額）: {$gNoTaxAmount}");

        $this->recruit_sales_guarantee_applied = BillingDetails::SALES_GUARANTEE_APPLIED;
        $this->sale_guarantee_apply = $sale_guarantee_apply;
        $this->client_billing_details = $billing_details;
        $this->sum_with_billing_type = $sum_with_billing_type;
        $this->sum_no_tax = $gSubTotal;
        $this->sum_no_tax_with_minus = $gNoTaxAmount;
        $this->sum_no_tax_with_minus_format = curency_format($this->sum_no_tax_with_minus);
        $this->sum_no_tax_format = curency_format($gSubTotal);
        $this->count_sum_no_tax = $count_sum_no_tax;
        $this->sum_minus = $sum_minus;
        $this->sum_minus_format = curency_format($sum_minus);
        $this->sum_minus_tax_format = curency_format($gOtherFee);
        $this->count_sum_minus = $count_sum_minus;
        $this->tax = $gCostTax;
        $this->tax_format = curency_format($gCostTax);
        $this->sum_total = $gTotal;
        $this->sum_total_format = curency_format($gTotal);
        $this->count_total = $count_total;
        $this->client_company = $company;
        $this->tax_rate_percent = $tax_rate_percent;
        $this->tax_included = $tax_included;
        $this->is_recruit_free_plan = $is_recruit_free_plan;
        $this->sum_no_tax_success_fee_plan_usage_fee = $sum_no_tax_success_fee_plan_usage_fee;
        $this->sum_no_tax_success_fee_plan_usage_fee_format = curency_format($sum_no_tax_success_fee_plan_usage_fee);
        $this->addRoute($company_user);

        return $this;
    }

    public function addIsClient()
    {
        $this->is_company = $this->billing_to == 1;
        $this->is_recruit = $this->billing_to == 2;
        $this->is_outsource = $this->billing_to == 3;
        $this->is_invoicing_auto = $this->invoicing_auto == 1;
        $this->is_invoicing_sended = $this->invoicing == 2;
        $this->is_invoicing_readed = $this->invoicing_read == 2;

        return $this;
    }

    private function addRoute($company_user, $id_todo_admin = null)
    {
        $this->route_pdf = route('admin.pdf.billing', [$this->id]);
        $type = 'company';
        $company_user_type = 'company_id';
        $tab = 'jobs-company-information-status-tab';
        if ($this->billing_to == Billings::IS_RECRUIT_TO) {
            $type = 'recruit';
            $company_user_type = 'recruit_company_id';
            $tab = 'jobs-company-information-status-tab';
        }
        if ($this->billing_to == Billings::IS_OUTSOURCE_TO) {
            $type = 'outsource';
            $company_user_type = 'outsource_company_id';
            $tab = 'outsourcing-ses-company-info-tab';
        }
        $this->route_billing_detail = route('admin.show_' . $type, [$company_user->$company_user_type ?? '', 'tab' => 'billing-management-tab', 'billing_id' => $this->id, 'id_todo_admin' => $id_todo_admin, 'is_redirect' => true]);
        $this->route_company_edit = route('admin.show_' . $type, [$company_user->$company_user_type ?? '', 'tab' => $tab]);
        $this->route_billing_detail_memo = route('admin.show_' . $type, [$company_user->$company_user_type ?? '', 'tab' => 'billing-management-tab', 'billing_id' => $this->id, 'go' => 'memo', 'is_redirect' => true]);
        $this->route_billing_detail_status = route('admin.show_' . $type, [$company_user->$company_user_type ?? '', 'tab' => 'billing-management-tab', 'billing_id' => $this->id, 'go' => 'status', 'is_redirect' => true]);
        $this->route_list_billing = route('admin.list_billing', ['billing_id' => $this->id, 'id_todo_admin' => $id_todo_admin]);

        return $this;
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
