<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdfs', function (Blueprint $table) {

            $table->id();

            /*
            Hangi kullanıcının yüklediği
            */
            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');   /*kullanıcı silinince PDF'leri de silinsin*/

            $table->string('dosya_adi');   /*orijinal dosya adı*/
            $table->string('yol');         /*storage'daki yolu*/

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdfs');
    }
};