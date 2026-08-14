<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'user_id',
        'pdf_id',
        'soru',
        'cevap',
    ];

    /*
    Soru bir PDF'e aittir
    */
    public function pdf()
    {
        return $this->belongsTo(Pdf::class);
    }

    /*
    Soru bir kullanıcıya aittir
    */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}