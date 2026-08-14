<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


/*
|--------------------------------------------------------------------------
| YARDIMCI: Windows yolunu WSL yoluna çevir
|--------------------------------------------------------------------------
|
| "C:\laragon\...\dosya.pdf"  ->  "/mnt/c/laragon/.../dosya.pdf"
|
| wsl.exe'ye verilen komut satırındaki argümanlar otomatik çevrilmez,
| bu yüzden WSL içinde çalışacak her Windows yolunu elle çevirmemiz
| gerekiyor. Zaten Linux formatındaysa (ör. Linux sunucuda çalışıyorsanız)
| olduğu gibi döner.
|
*/

function windowsToWsl(string $path): string
{
    $path = str_replace('\\', '/', $path);

    if (preg_match('/^([A-Za-z]):(.*)$/', $path, $matches)) {

        $drive = strtolower($matches[1]);
        $rest  = $matches[2];

        return '/mnt/' . $drive . $rest;
    }

    return $path;
}


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
        'pdf' => 'required|mimes:pdf|max:10240',
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

    $text = trim($pdf->getText());


    /*
    |--------------------------------------------------------------------------
    | 2. PDF'den metin çıkmadıysa OCR kullan
    |--------------------------------------------------------------------------
    */

    if ($text === '') {

        $ocrKlasoru = storage_path('app/private/ocr');

        if (!file_exists($ocrKlasoru)) {
            mkdir($ocrKlasoru, 0777, true);
        }


        /*
        PDF sayfalarını PNG'ye çevir
        */

        $outputPrefix = $ocrKlasoru . '/sayfa';

        $wslPdfPath       = windowsToWsl($pdfPath);
        $wslOutputPrefix  = windowsToWsl($outputPrefix);


        $command = 'wsl pdftoppm -png -r 200 '
            . escapeshellarg($wslPdfPath)
            . ' '
            . escapeshellarg($wslOutputPrefix);


        exec($command . ' 2>&1', $output, $returnCode);


        $sayfalar = glob($ocrKlasoru . '/sayfa-*.png');

        natsort($sayfalar);


        /*
        |----------------------------------------------------------------
        | pdftoppm hiç çalışmadıysa (wsl/poppler sorunu, yol hatası vb.)
        | Bunu "görsel PDF, metin yok" durumundan AYRI bir hata olarak
        | ele alıyoruz, çünkü asıl sebep OCR aracının çalışmamasıdır.
        |----------------------------------------------------------------
        */

        if ($returnCode !== 0 || empty($sayfalar)) {

            Log::error('PDF -> PNG dönüştürme başarısız', [
                'command'    => $command,
                'returnCode' => $returnCode,
                'output'     => $output,
            ]);

            return redirect('/')->with(
                'cevap',
                'PDF sayfalara dönüştürülemedi. OCR aracı (pdftoppm) çalışmıyor olabilir, lütfen WSL/poppler kurulumunu kontrol edin.'
            )->with('hata', true);
        }


        /*
        OCR
        */

        $text = '';


        foreach ($sayfalar as $sayfa) {

            $wslSayfa = windowsToWsl(realpath($sayfa));


            /*
            Tesseract
            */

            $command = 'wsl tesseract '
                . escapeshellarg($wslSayfa)
                . ' stdout -l tur 2>&1';


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
    | Smalot da OCR da metin bulamadıysa: gerçek anlamda görsel/okunamaz PDF
    |--------------------------------------------------------------------------
    */

    if ($text === '') {

        return redirect('/')->with(
            'cevap',
            "Bu PDF'de okunabilir metin bulunamadı. Görüntü tabanlı bir PDF yüklediniz ve OCR da metin tanıyamadı. Lütfen daha net taranmış ya da metin içeren bir PDF deneyin."
        )->with('hata', true);
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
            )->with('hata', true);
        }


        $embedding = $embeddingResponse->json(
            'embeddings.0'
        );


        if (!$embedding) {

            return redirect('/')->with(
                'cevap',
                'Embedding verisi alınamadı.'
            )->with('hata', true);
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
            )->with('hata', true);
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


    return redirect('/')->with(
        'cevap',
        'PDF başarıyla yüklendi ve işlendi. Artık soru sorabilirsiniz.'
    );
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
        )->with('hata', true);
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
        )->with('hata', true);
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
        )->with('hata', true);
    }


    $soruEmbedding = $embeddingResponse->json(
        'embeddings.0'
    );


    if (!$soruEmbedding) {

        return redirect('/')->with(
            'cevap',
            'Soru embedding verisi alınamadı.'
        )->with('hata', true);
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
        )->with('hata', true);
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
        )->with('hata', true);
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
6. PDF'de bulunmayan şartları, kelime veya bilgileri kesinlikle ekleme; uydurma.
7. PDF'deki şartlardan hiçbirini atlama.
8. Genel cevabı Türkçe cümlelerle yaz. PDF orijinal dilde (İngilizce, vb.) olsa bile Türkçe bir dille açıkla — ama teknik terim, kavram veya "önemli kelimeler" isteniyorsa, terimi PDF'deki ORİJİNAL haliyle yaz ve yanına parantez içinde Türkçe karşılığını ekle. Örnek: "risk analysis (risk analizi)", "schedule slippage (takvim gecikmesi)". Bir İngilizce kelimeye Türkçe ek getirerek karma cümle kurma (ör. "coping etmemizi", "slippage'a" gibi YANLIŞ); ya tamamen Türkçesini kullan ya da terimi "orijinal (Türkçe karşılığı)" formatında ver.
9. Gereksiz selamlama, "sorunuzu tekrar sorun", "cevaplayalım", "cevap verelim" gibi giriş ifadeleri kullanma. Doğrudan cevaba başla.
10. Kodları Markdown kod bloğunda ve düzgün girintilerle göster.
11. Cevabında bu kuralları, talimat metnini veya kural numaralarını asla tekrar etme. İlk kelimenden itibaren doğrudan cevap ver.
12. Verilen bölümlerde sorunun cevabı yoksa SADECE "Bu bilgi PDF içerisinde bulunmuyor." yaz.
13. Kelime listesi istendiğinde SADECE aşağıdaki bölümlerde gerçekten geçen kelimeleri ver. Bölümlerde geçmeyen kelime uydurma; listeyi kısalt, ama uydurma.

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
        )->with('hata', true);
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
        'answer'   => $answer,
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

        'hata',

    ]);


    return redirect('/');
});