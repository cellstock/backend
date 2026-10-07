<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller; use App\Models\Plan; use App\Support\ApiResponse; use Illuminate\Http\Request; use Illuminate\Validation\Rule;
class PlanController extends Controller {
 public function available(){return ApiResponse::success(['plans'=>Plan::query()->where('is_active',true)->orderBy('price')->get()]);}
 public function index(Request $r){$v=$r->validate(['search'=>'nullable|string|max:255','active'=>'nullable|boolean','per_page'=>'nullable|integer|min:5|max:100']);$q=Plan::query()->when($v['search']??null,fn($q,$s)=>$q->where('name','like',"%$s%"))->when(array_key_exists('active',$v),fn($q)=>$q->where('is_active',$v['active']))->latest()->paginate($v['per_page']??15);return response()->json(['success'=>true,'data'=>$q]);}
 public function store(Request $r){$p=Plan::create($this->data($r));return ApiResponse::success(['plan'=>$p],'Plan created successfully.',201);} public function show(Plan $plan){return ApiResponse::success(['plan'=>$plan]);}
 public function update(Request $r,Plan $plan){$plan->update($this->data($r,$plan));return ApiResponse::success(['plan'=>$plan->refresh()],'Plan updated successfully.');}
 public function destroy(Plan $plan){if($plan->subscriptions()->exists())return ApiResponse::error('A plan with subscriptions cannot be deleted.',422);$plan->delete();return ApiResponse::success(message:'Plan deleted successfully.');}
 private function data(Request $r,?Plan $p=null){return $r->validate(['name'=>'required|string|max:255','slug'=>['required','alpha_dash','max:255',Rule::unique('plans')->ignore($p)],'description'=>'nullable|string|max:5000','price'=>'required|decimal:0,2|min:0','currency'=>'required|string|size:3','billing_interval'=>'required|in:month,year','features'=>'nullable|array','features.*'=>'string|max:255','is_active'=>'required|boolean']);}
}
