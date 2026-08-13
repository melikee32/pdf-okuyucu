<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('pdf');
});

Route::post('/pdf-yukle', function (Illuminate\Http\Request $request) {

    $request->validate([
        'pdf' => 'required|mimes:pdf|max:10140',
    ]);

    $dosya = $request->file('pdf');

    $yol = $dosya->store('pdfler');            // PDF'yi kaydediyoruz.

    $parser = new \Smalot\PdfParser\Parser();  // PDF okuyucuyu oluşturuyoruz.

    $pdf = $parser->parseFile(
        storage_path('app/private/' . $yol)
    );                                         // Kaydettiğimiz PDF'yi okuyoruz.
     
    $metin = $pdf->getText();

    session([
        'pdf_adi' => $dosya->getClientOriginalName(),
        'pdf_metni' => $metin
    ]);

    return redirect(('/'));

});



//* SOR butonuna basınca Laravel bunu alacak:
//* Kullanıcının sorusu >Laravel>session'daki PDF metni>Daha sonra LLM
Route::post('/soru', function(Illuminate\Http\Request $request) {
    
    $request->validate([
        'soru' => 'required',
    ]);

    $soru = $request->input('soru');

    $pdfMetni = session('pdf_metni');

    $cevap = "Sorunuz: " . $soru;

    return redirect('/')->with('cevap', $cevap);

});

Route::get('/soru', function () {
    return redirect('/');
});



Route::get('/yeni-pdf', function () {

    session()->forget([
        'pdf_adi',
        'pdf_metni',
        'cevap'
    ]);

    return redirect('/');

});