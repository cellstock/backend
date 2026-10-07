<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Plan extends Model { protected $fillable = ['name','slug','description','price','currency','billing_interval','features','is_active']; protected function casts(): array { return ['features'=>'array','is_active'=>'boolean','price'=>'decimal:2']; } public function subscriptions(){return $this->hasMany(Subscription::class);} }
