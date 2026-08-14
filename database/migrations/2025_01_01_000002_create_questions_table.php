<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {

            $table->id();

            /*
            Hangi kullanıcının sorusu
            */
            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');

            /*
            Hangi PDF'e ait
            */
            $table->foreignId('pdf_id')
                  ->constrained()
                  ->onDelete('cascade');  /*PDF silinince soruları da silinsin*/

            $table->text('soru');         /*kullanıcının sorusu*/
            $table->longText('cevap');    /*AI'ın cevabı*/

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};