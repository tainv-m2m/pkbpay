<?php

namespace Pkbpay\Billings\Models\Traits\Method;

use App\Models\BillingDetails;
use App\Models\RecruitJobSeekerApplyMgts;
use App\Models\PaymentRewardsModel;
use Carbon;
use Illuminate\Support\Facades\Log;

trait IncPaymentReward
{
    public $_debugFlg = true;
    public $_prVersion = 1;

    public function calculatePaymentRewards() {
        $this->is_recruit = $this->job_seeker_apply_mgt_type == PaymentRewardsModel::TYPE_RECRUIT_APPLY_MGT;
        $mgt_type = $this->is_recruit ? BillingDetails::TYPE_RECRUIT_APPLY_MGT : BillingDetails::TYPE_OUTSOURCE_APPLY_MGT;
        $jobSeekerApplyMgts = $this->is_recruit ? $this->recruitJobSeekerApplyMgts : $this->outsourceJobSeekerApplyMgts;
        $offerInfo = $jobSeekerApplyMgts->offerInfo ?? null;
        $company = $jobSeekerApplyMgts->companyUser->company;
        $issueDate = date("Y-m-d");
        if ($this->is_recruit) {
            $this->application_date = $jobSeekerApplyMgts && $jobSeekerApplyMgts->application_date ? date("Y-m-d", strtotime($jobSeekerApplyMgts->application_date)) : "---";
            $issueDate = $jobSeekerApplyMgts && $jobSeekerApplyMgts->application_date ? date("Y-m-d", strtotime($jobSeekerApplyMgts->application_date)) : $issueDate;
        } else {
            $this->application_date = $jobSeekerApplyMgts && $jobSeekerApplyMgts->proposal_date ? date("Y-m-d", strtotime($jobSeekerApplyMgts->proposal_date)) : "---";
            $issueDate = $jobSeekerApplyMgts && $jobSeekerApplyMgts->proposal_date ? date("Y-m-d", strtotime($jobSeekerApplyMgts->proposal_date)) : $issueDate;
        }
        $isBussiness = $this->checkApplyBusinessFlg($company, $issueDate);
        $this->subtotal = 0;
        if ($this->billingDetail && $this->billingDetail->job_seeker_apply_mgt_type == $mgt_type) {
            $this->logDebug('BillD-subtotal: '. $this->billingDetail->subtotal);
            $this->subtotal = $this->billingDetail->subtotal * $this->billingDetail->quantity;
            $this->logDebug('PR-billingDetail-id: '. $this->billingDetail->id);
            $this->logDebug('PR-billingDetail-subtotal: '. $this->billingDetail->subtotal);
            $this->logDebug('PR-billingDetail-quantity: '. $this->billingDetail->quantity);
            $this->logDebug('PR-subtotal39: '. $this->subtotal);
        }
        $this->logDebug('PR-subtotal44: '. $this->subtotal);

        $alliance_type = $jobSeekerApplyMgts->recruitOfferInfo->alliance_type ?? $jobSeekerApplyMgts->outSourceOfferInfo->alliance_type ?? null;
        if ($this->is_recruit) {
            $this->offer_job_title = ($alliance_type == 2 ? "【提携求人】" : "") . ($offerInfo->job_title ?? "---");
            $this->offer_route = route('admin.job.recruit', ['J', $offerInfo->id ?? 0]);
            $this->job_seeker_route = route('admin.recruit.jobseeker', ['id' => $jobSeekerApplyMgts->jobSeeker->id ?? 0]);
            $this->apply_route = route('admin.apply_recruit.detail', [$jobSeekerApplyMgts->id ?? 0]);

            $this->admin_job_show_route = route('admin.job.recruit', ['J', $offerInfo->id ?? 0]);
            $this->client_job_show_route = route('recruit.job_show', ['J', $offerInfo->id ?? 0]);
            $this->client_apply_route = route('recruit.apply.detail', [$jobSeekerApplyMgts->id ?? 0]);
            $this->joining_confirmation_date = $jobSeekerApplyMgts && $jobSeekerApplyMgts->joining_confirmation_date ? date("Y-m-d", strtotime($jobSeekerApplyMgts->joining_confirmation_date)) : "---";
            $this->recruitment_date = $jobSeekerApplyMgts && $jobSeekerApplyMgts->recruitment_date && $jobSeekerApplyMgts->recruitment == RecruitJobSeekerApplyMgts::RECRUITMENT_SCHEDULED ? date("Y-m-d", strtotime($jobSeekerApplyMgts->recruitment_date)) : "---";

            $this->recruit_subtotal = $this->subtotal ?? 0;
            $this->recruit_individual_reward_amoun_change = $this->individual_reward_amoun_change ?? 0;
            $this->before_success_fee_plan_usage_fee = $this->success_fee_plan_usage_fee;
            if ($this->is_used_plan) {
                $this->success_fee_plan_usage_fee = (is_null($this->success_fee_plan_usage_fee) || $this->intro_successful_reward != $this->used_plan_intro_successful_reward)
                    ? ($this->subtotal + $this->individual_reward_amoun_change) * $this->used_plan_intro_successful_reward/100
                    : $this->success_fee_plan_usage_fee;
            }
            $this->success_fee_plan_usage_fee_tax = (float) $this->success_fee_plan_usage_fee * $this->tax_included;
            $this->success_fee_plan_usage_fee_tax_format = curency_format($this->success_fee_plan_usage_fee_tax);
            $this->username_create = $jobSeekerApplyMgts->recruitCompanyUser ? $jobSeekerApplyMgts->recruitCompanyUser->name : "---";
        } else {
            $this->offer_job_title = ($alliance_type == 2 ? "【提携案件】" : "") . ($offerInfo->job_title ?? "---");
            $this->apply_route = route('admin.apply_outsource.detail', [$jobSeekerApplyMgts->id ?? 0]);
            $this->offer_route = route('admin.job.outsource', ['G', $offerInfo->id ?? 0]);
            $this->admin_job_show_route = route('admin.job.outsource', ['G', $offerInfo->id ?? 0]);
            $this->client_job_show_route = route('outsource.job_show', ['G', $offerInfo->id ?? 0]);
            $this->client_apply_route = route('outsource.apply.detail', [$jobSeekerApplyMgts->id ?? 0]);
            $this->job_seeker_route = route('admin.outsource.jobseeker', ['id' => $jobSeekerApplyMgts->jobSeeker->id ?? 0]);

            $this->joining_confirmation_date = $jobSeekerApplyMgts && $jobSeekerApplyMgts->joining_confirmation_start_date ? date("Y-m-d", strtotime($jobSeekerApplyMgts->joining_confirmation_start_date)) : "---";
            $this->recruitment_date = $jobSeekerApplyMgts && $jobSeekerApplyMgts->suggestion_date ? date("Y-m-d", strtotime($jobSeekerApplyMgts->suggestion_date)) : "---";
            $this->username_create = $jobSeekerApplyMgts->companyUser ? $jobSeekerApplyMgts->companyUser->name : "---";
        }
        $jobSeeker = $jobSeekerApplyMgts->jobSeeker ?? null;
        $this->job_seeker_name = ($jobSeeker->last_name ?? '') . " " . ($jobSeeker->first_name ?? '');
        $this->job_seeker_age = ($jobSeeker->age ?? "---") . " 歳／" . ($jobSeeker ? ($jobSeeker::mapSex()[$jobSeeker->sex]['text'] ?? '---') : '---');
        $this->company_name = $offerInfo->company->name ?? "---";
        $this->company_route = $offerInfo && $offerInfo->company ? route('admin.show_company', [$offerInfo->company->id ?? 0]) : "#";
        $this->offer_occupation_category_2 = $offerInfo->occupation_category_2 ?? "---";
        $this->offer_occupation_category_1 = $offerInfo->occupation_category_1 ?? "---";

        $this->logDebug('date: '. $this->application_date);
        $this->logDebug('PR-subtotal: '. $this->subtotal);
        $this->logDebug('tax_included: '.$this->tax_included);
        $individualRewardAmounCost = $this->individual_reward_amoun_change;
        $fee = $this->subtotal + $individualRewardAmounCost;
        if (!$isBussiness) {
            $this->logDebug("business: false");
            $fee = roundNumber(($this->subtotal + $individualRewardAmounCost) * $this->tax_included);
        } else {
            $this->logDebug('business: true');
        }

        $this->logDebug('individualRewardAmounCost: '.$individualRewardAmounCost);
        $this->logDebug('PR-fee: '. $fee);
        $this->fee = curency_format($fee);
        $this->platform_clearing_fee_tax = $this->tax_included * $this->platform_clearing_fee;
        $this->outsourcing_fee_tax = $this->tax_included  * $this->outsourcing_fee;
        $this->make_status = $this->makeStatus();
        $this->list_status = $this::mapStatus();
        $this->total = curency_format($this->is_recruit ? $this->billingDetail->totalAndTax() : $this->tax_included * $this->subtotal);
        $this->total_format = curency_format($this->tax_included * $this->subtotal);
        $this->subtotal_format = curency_format($this->subtotal);
        $this->individual_reward_amoun_change_format = curency_format($this->individual_reward_amoun_change);
        $this->platform_clearing_fee_format = curency_format($this->platform_clearing_fee);
        $this->platform_clearing_fee_tax_format = curency_format($this->platform_clearing_fee_tax);
        $this->outsourcing_fee_format = curency_format($this->outsourcing_fee);

        $this->outsourcing_fee_tax_format = curency_format($this->outsourcing_fee_tax);
        $this->fee_format = curency_format($fee);

        $this->billing_detail_status = $this->billingDetail->status ?? '';

        return $this;
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
