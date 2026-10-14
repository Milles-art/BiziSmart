<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformOperation extends Model {
    public $timestamps=false;protected $guarded=['id'];protected function casts():array{return ['input'=>'encrypted:array','result'=>'encrypted:array'];}
    protected static function booted():void{static::updating(fn()=>throw new \LogicException('Platform operations are immutable.'));static::deleting(fn()=>throw new \LogicException('Platform operations cannot be deleted.'));}
}
