<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SyncQueue extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['payload' => 'array', 'synced_at' => 'datetime'];
}
