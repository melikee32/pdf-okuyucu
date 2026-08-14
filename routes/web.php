<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;


/*
|--------------------------------------------------------------------------
| ANA SAYFA
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('pdf');
});


/*
|--------------------------------------------------------------------------
| PDF YÜKLEME
|--------------------------------------------------------------------------
*/

Route::post('/pdf-yukle', function (Illuminate\Http\Request $request) {

    $request->validate([
        'pdf' => 'required|mimes:pdf|max:10140',
    ]);


    $dosya = $request->file('pdf');

    $yol = $dosya->store('pdfler');

    $pdfPath = storage_path('app/private/' . $yol);


    /*
    |--------------------------------------------------------------------------
    | 1. PDF'yi Smalot ile oku
    |--------------------------------------------------------------------------
    */

    $parser = new \Smalot\PdfParser\Parser();

    $pdf = $parser->parseFile($pdfPath);

    $text = $pdf->getText();


    /*
    |--------------------------------------------------------------------------
    | 2. PDF'den metin çıkmadıysa OCR kullan
    |--------------------------------------------------------------------------
    */

    if (trim($text) === '') {

        $ocrKlasoru = storage_path('app/private/ocr');

        if (!file_exists($ocrKlasoru)) {
            mkdir($ocrKlasoru, 0777, true);
        }


        /*
        PDF sayfalarını PNG'ye çevir
        */

        $outputPrefix = $ocrKlasoru . '/sayfa';


        $command = 'wsl pdftoppm -png -r 200 '
            . escapeshellarg($pdfPath)
            . ' '
            . escapeshellarg($outputPrefix);


        exec($command, $output, $returnCode);


        /*
        OCR
        */

        $text = '';


        $sayfalar = glob($ocrKlasoru . '/sayfa-*.png');

        natsort($sayfalar);


        foreach ($sayfalar as $sayfa) {

            /*
            Windows yolu:
            C:\...
            
            WSL yolu:
            /mnt/c/...
            */

            $windowsPath = realpath($sayfa);

            $windowsPath = str_replace('\\', '/', $windowsPath);


            if (preg_match('/^([A-Za-z]):(.*)$/', $windowsPath, $matches)) {

                $drive = strtolower($matches[1]);

                $path = $matches[2];

                $wslSayfa = '/mnt/' . $drive . $path;

            } else {

                $wslSayfa = $windowsPath;
            }


            /*
            Tesseract
            */

            $command = 'wsl tesseract '
                . escapeshellarg($wslSayfa)
                . ' stdout -l tur';


            $sayfaMetni = shell_exec($command);


            if ($sayfaMetni) {

                $text .= "\n" . $sayfaMetni;
            }
        }


        /*
        Geçici PNG'leri sil
        */

        foreach ($sayfalar as $sayfa) {

            if (file_exists($sayfa)) {

                unlink($sayfa);
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 3. UTF-8 temizliği
    |--------------------------------------------------------------------------
    */

    $text = iconv(
        'UTF-8',
        'UTF-8//IGNORE',
        $text
    );


    if ($text === false) {

        $text = '';
    }


    $text = trim($text);


    /*
    |--------------------------------------------------------------------------
    | PDF tamamen boşsa
    |--------------------------------------------------------------------------
    */

    if ($text === '') {

        return redirect('/')->with(
            'cevap',
            'PDF içerisinden okunabilir bir metin çıkarılamadı.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 4. Chunk oluştur
    |--------------------------------------------------------------------------
    */

    $chunks = [];


    $chunkSize = 1000;

    $overlapSize = 200;


    $startPosition = 0;

    $textLength = strlen($text);


    while ($startPosition < $textLength) {

        $chunk = substr(
            $text,
            $startPosition,
            $chunkSize
        );


        /*
        Kelimenin ortasından bölmemeye çalış
        */

        if ($startPosition + $chunkSize < $textLength) {

            $lastSpacePosition = strrpos(
                $chunk,
                ' '
            );


            if ($lastSpacePosition !== false) {

                $chunk = substr(
                    $chunk,
                    0,
                    $lastSpacePosition
                );
            }
        }


        $chunk = trim($chunk);


        /*
        UTF-8 temizliği
        */

        $chunk = iconv(
            'UTF-8',
            'UTF-8//IGNORE',
            $chunk
        );


        if ($chunk !== false) {

            $chunk = trim($chunk);
        }


        /*
        Boş chunk kaydetme
        */

        if ($chunk !== '') {

            $chunks[] = $chunk;
        }


        /*
        Overlap
        */

        $startPosition += (
            $chunkSize - $overlapSize
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 5. Weaviate'a embedding + chunk kaydet
    |--------------------------------------------------------------------------
    */

    foreach ($chunks as $index => $chunk) {


        /*
        Embedding oluştur
        */

        $embeddingResponse = Http::timeout(120)->post(
            'http://localhost:11434/api/embed',
            [
                'model' => 'nomic-embed-text:latest',

                'input' => $chunk,
            ]
        );


        /*
        Ollama hata kontrolü
        */

        if ($embeddingResponse->failed()) {

            return redirect('/')->with(
                'cevap',
                'Embedding oluşturulurken hata oluştu.'
            );
        }


        $embedding = $embeddingResponse->json(
            'embeddings.0'
        );


        if (!$embedding) {

            return redirect('/')->with(
                'cevap',
                'Embedding verisi alınamadı.'
            );
        }


        /*
        Weaviate'a gönder
        */

        $weaviateResponse = Http::timeout(120)->post(
            'http://localhost:8080/v1/objects',
            [

                'class' => 'PdfChunk',

                'properties' => [

                    'pdf_adi' =>
                        $dosya->getClientOriginalName(),

                    'chunk' =>
                        $chunk,

                    'chunk_index' =>
                        $index,

                ],

                'vector' =>
                    $embedding,
            ]
        );


        /*
        Weaviate hata kontrolü
        */

        if ($weaviateResponse->failed()) {

            return redirect('/')->with(
                'cevap',
                'Chunk Weaviate içine kaydedilirken hata oluştu.'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 6. Session
    |--------------------------------------------------------------------------
    */

    session([

        'pdf_adi' =>
            $dosya->getClientOriginalName(),

        'pdf_metni' =>
            $text,

        'pdf_chunklar' =>
            $chunks,

    ]);


    return redirect('/');
});


/*
|--------------------------------------------------------------------------
| SORU SOR
|--------------------------------------------------------------------------
*/

Route::post('/soru', function (Illuminate\Http\Request $request) {


    /*
    |--------------------------------------------------------------------------
    | 1. Soruyu al
    |--------------------------------------------------------------------------
    */

    $request->validate([
        'soru' => 'required',
    ]);

    $question = $request->input('soru');


    /*
    UTF-8 temizliği
    */

    $question = iconv(
        'UTF-8',
        'UTF-8//IGNORE',
        $question
    );


    if ($question === false) {

        return redirect('/')->with(
            'cevap',
            'Soru okunamadı.'
        );
    }


    $question = trim($question);


    /*
    |--------------------------------------------------------------------------
    | 2. Aktif PDF kontrolü
    |--------------------------------------------------------------------------
    */

    $pdfAdi = session('pdf_adi');


    if (!$pdfAdi) {

        return redirect('/')->with(
            'cevap',
            'Önce bir PDF yüklemeniz gerekiyor.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 3. Soruyu embedding'e çevir
    |--------------------------------------------------------------------------
    */

    $embeddingResponse = Http::timeout(120)->post(
        'http://localhost:11434/api/embed',
        [

            'model' =>
                'nomic-embed-text:latest',

            'input' =>
                $question,

        ]
    );


    if ($embeddingResponse->failed()) {

        return redirect('/')->with(
            'cevap',
            'Soru embedding oluşturulurken hata oluştu.'
        );
    }


    $soruEmbedding = $embeddingResponse->json(
        'embeddings.0'
    );


    if (!$soruEmbedding) {

        return redirect('/')->with(
            'cevap',
            'Soru embedding verisi alınamadı.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 4. Weaviate'te arama
    |--------------------------------------------------------------------------
    |
    | SADECE şu anda yüklenmiş olan PDF'de ara.
    |
    */

    $vector = implode(
        ',',
        $soruEmbedding
    );


    /*
    PDF adını GraphQL içerisinde güvenli şekilde kullan
    */

    $pdfAdiGraphQL = addslashes(
        $pdfAdi
    );


    $graphql = <<<GRAPHQL
{
    Get {
        PdfChunk(
            nearVector: {
                vector: [$vector]
            }

            where: {
                path: ["pdf_adi"]
                operator: Equal
                valueText: "$pdfAdiGraphQL"
            }

            limit: 5
        ) {

            pdf_adi

            chunk

            chunk_index

            _additional {
                distance
            }
        }
    }
}
GRAPHQL;


    /*
    |--------------------------------------------------------------------------
    | 5. Weaviate sorgusu
    |--------------------------------------------------------------------------
    */

    $weaviateResponse = Http::timeout(120)->post(
        'http://localhost:8080/v1/graphql',
        [
            'query' =>
                $graphql,
        ]
    );


    if ($weaviateResponse->failed()) {

        return redirect('/')->with(
            'cevap',
            'Weaviate araması sırasında hata oluştu.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 6. Sonuçları al
    |--------------------------------------------------------------------------
    */

    $sonuclar = $weaviateResponse->json(
        'data.Get.PdfChunk'
    );


    /*
    |--------------------------------------------------------------------------
    | Weaviate hata döndürdüyse
    |--------------------------------------------------------------------------
    */

    if (!$sonuclar) {

        return redirect('/')->with(
            'cevap',
            'PDF içerisinde soruyla ilgili bilgi bulunamadı.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 7. İlgili chunk'ları birleştir
    |--------------------------------------------------------------------------
    */

    $ilgiliMetin = '';


    foreach ($sonuclar as $sonuc) {

        if (!isset($sonuc['chunk'])) {

            continue;
        }


        $ilgiliMetin .= "\n\n";

        $ilgiliMetin .=
            $sonuc['chunk'];
    }


    /*
    |--------------------------------------------------------------------------
    | 8. Llama3 Prompt
    |--------------------------------------------------------------------------
    */

    $prompt = <<<PROMPT
Sen PDF içeriğine göre çalışan Türkçe bir soru-cevap asistanısın.

ÇOK ÖNEMLİ KURALLAR:

1. Kullanıcının sorduğu soru numarasını dikkatlice belirle.
2. Örneğin kullanıcı "5. soru" diyorsa SADECE PDF'deki "Soru 5" başlığının altında bulunan içeriği kullan.
3. Başka bir sorunun içeriğini Soru 5 ile kesinlikle karıştırma.
4. Soru numarasını tahmin etme veya değiştirme.
5. Kullanıcı bir programlama sorusunun çözümünü isterse, ilgili sorunun TÜM maddelerini dikkate al.
6. PDF'de bulunmayan şartları ekleme.
7. PDF'deki şartlardan hiçbirini atlama.
8. Her zaman Türkçe cevap ver.
9. Gereksiz selamlama veya "sorunuzu tekrar sorun" gibi ifadeler kullanma.
10. Kodları Markdown kod bloğunda ve düzgün girintilerle göster.

KULLANICI SORUSU:
{question}

PDF İÇERİĞİ:
{pdf_text}


PDF içeriği:
{pdf_text}

Kullanıcının sorusu:
{question}

Kullanıcının sorusunu SADECE aşağıda verilen PDF bölümlerine dayanarak cevapla.

Kurallar:

- Sadece verilen PDF bölümlerini kullan.
- PDF dışında bilgi kullanma.
- Cevabı kendi bilginden uydurma.
- Verilen bölümlerde sorunun cevabı yoksa:
  "Bu bilgi PDF içerisinde bulunmuyor."
  yaz.
- Soruyu doğrudan cevapla.
- Gereksiz açıklama yapma.
- PDF'deki farklı soruları birbirine karıştırma.
- Soru numaralarını dikkatli takip et.
- Kod varsa kodu ve açıklamayı birbirine karıştırma.
- Kullanıcı belirli bir soru numarası soruyorsa öncelikle o soru numarasına ait bilgiyi kullan.

PDF'DEN GETİRİLEN İLGİLİ BÖLÜMLER:

$ilgiliMetin


KULLANICININ SORUSU:

$question


CEVAP:
PROMPT;


    /*
    |--------------------------------------------------------------------------
    | 9. Llama3
    |--------------------------------------------------------------------------
    */

    $response = Http::timeout(120)->post(
        'http://localhost:11434/api/generate',
        [

            'model' =>
                'llama3:latest',

            'prompt' =>
                $prompt,

            'stream' =>
                false,

        ]
    );


    /*
    |--------------------------------------------------------------------------
    | Llama hata kontrolü
    |--------------------------------------------------------------------------
    */

    if ($response->failed()) {

        return redirect('/')->with(
            'cevap',
            'AI servisine bağlanırken bir hata oluştu.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 10. Cevabı al
    |--------------------------------------------------------------------------
    */

    $answer = $response->json(
        'response'
    );


    if (!$answer) {

        $answer =
            'AI tarafından cevap alınamadı.';
    }


    /*
    |--------------------------------------------------------------------------
    | 11. Cevabı göster
    |--------------------------------------------------------------------------
    */

    return view('pdf', [
    'answer' => $answer,
    'question' => $question,
]);
});


/*
|--------------------------------------------------------------------------
| YENİ PDF
|--------------------------------------------------------------------------
*/

Route::get('/yeni-pdf', function () {


    session()->forget([

        'pdf_adi',

        'pdf_metni',

        'pdf_chunklar',

        'cevap',

    ]);


    return redirect('/');
});