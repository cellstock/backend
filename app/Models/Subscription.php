<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Subscription extends Model { protected $fillable=['user_id','plan_id','status','starts_at','ends_at','cancel_at_period_end']; protected function casts(): array { return ['starts_at'=>'datetime','ends_at'=>'datetime','cancel_at_period_end'=>'boolean']; } public function user(){return $this->belongsTo(User::class);} public function plan(){return $this->belongsTo(Plan::class);} }
