<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pdf extends Model
{
    protected $fillable = [
        'user_id',
        'dosya_adi',
        'yol',
    ];

    /*
    Bir PDF birçok soruya sahip olabilir
    */
    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    /*
    Bir PDF bir kullanıcıya aittir
    */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}