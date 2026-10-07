<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Support\Facades\DB;
return new class extends Migration{public function up():void{DB::table('roles')->whereIn('slug',['manager','staff'])->whereNotExists(fn($q)=>$q->selectRaw('1')->from('users')->whereColumn('users.role_id','roles.id'))->delete();}public function down():void{}};
