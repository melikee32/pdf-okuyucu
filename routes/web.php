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

    $text = $pdf->getText();

    $chunks = [];
    //$chunklar = str_split($metin, 1000);
    // dd($chunklar); test amaçlı idi.
    $chunkSize = 1000;
    $overlapSize = 200;

    $startPosition = 0;
    $textLength = strlen($text);

    while ($startPosition < $textLength) {

        $chunk = substr($text, $startPosition, $chunkSize);
        
        // Prevent splitting words in the middle //* kelimenin ortasından bölmek
        if ($startPosition + $chunkSize < $textLength) {

            $lastSpacePosition = strrpos($chunk, ' ');

            if ($lastSpacePosition !== false) {
                $chunk = substr($chunk, 0, $lastSpacePosition);
            }
        }

        $chunks[] = trim($chunk);

        $startPosition += ($chunkSize - $overlapSize);
    }


    session([

        'pdf_adi' => $dosya->getClientOriginalName(),
        'pdf_metni' => $text,
        'pdf_chunklar' => $chunks

    ]);


    return redirect(('/'));
});



//* SOR butonuna basınca Laravel bunu alacak:
//* Kullanıcının sorusu >Laravel>session'daki PDF metni>Daha sonra LLM
Route::post('/soru', function (Illuminate\Http\Request $request) {

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
