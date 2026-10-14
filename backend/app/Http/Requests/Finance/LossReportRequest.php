<?php
namespace App\Http\Requests\Finance;
class LossReportRequest extends DepartmentTillReportRequest {public function rules():array{return [...parent::rules(),'category'=>['sometimes','in:damage,spoilage,wastage']];}}
