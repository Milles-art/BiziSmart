<?php
namespace App\Http\Requests\Finance;
class DepartmentTillReportRequest extends ReportRequest {
    public function rules(): array {return [...parent::rules(),'department_id'=>['sometimes','string','regex:/^dep_[0-9A-HJKMNP-TV-Z]{26}$/D']];}
}
